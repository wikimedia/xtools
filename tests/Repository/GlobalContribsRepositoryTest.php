<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\GlobalContribsRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * GlobalContribsRepository fans a user's contributions out across every wiki, so it is heavier on
 * collaborators than its siblings: it talks to the CentralAuth API, resolves per-slice actor IDs,
 * and assembles cross-wiki UNION queries. None of that touches a replica or a live wiki here. A test
 * subclass overrides the base seams the reachable methods lean on: executeApiRequest() (CentralAuth),
 * executeProjectsQuery() (records slice + SQL, returns canned rows), getProjectsConnection() (only its
 * quoteStringLiteral() is used), and the getDbList()/getTableName() dblist wiring. A real ArrayAdapter
 * backs the cache so getCacheKey()/setCache() behave as in production.
 *
 * Left to integration: globalEditCounts()'s named-user branch and getProjectsWithEdits()'s project
 * hydration both build a fresh App\Model\Project and call ->exists() (or getAll()), which resolves
 * project metadata against a wiki; that isn't cleanly unit-isolable without stubbing the Project model
 * itself, which the subclass can't reach into (the objects are new'd internally). We cover the cheap
 * isIP() early-returns and the projectRepo->getProject() delegation, and document the rest here.
 * @covers \App\Repository\GlobalContribsRepository
 */
class GlobalContribsRepositoryTest extends TestCase {

	/**
	 * An IP editor has no CentralAuth account, so globalEditCountsFromCentralAuth() bails with null
	 * before ever hitting the API. Reached through the public globalEditCounts() wrapper, which shares
	 * the same isIP() guard.
	 */
	public function testGlobalEditCountsReturnsNullForIp(): void {
		$repo = $this->makeRepository();
		static::assertNull( $repo->globalEditCounts( $this->makeIpUser() ) );
	}

	/**
	 * When CentralAuth returns nothing usable (no query.globaluserinfo.merged key), the merged-wiki
	 * loop has nothing to walk and we return an empty list rather than erroring.
	 */
	public function testGlobalEditCountsFromCentralAuthReturnsEmptyOnMissingMerged(): void {
		$repo = $this->makeRepository();
		$repo->apiResult = [ 'query' => [ 'globaluserinfo' => [] ] ];

		static::assertSame( [], $repo->exposedGlobalEditCountsFromCentralAuth( $this->makeUser() ) );
	}

	/**
	 * globalEditCountsFromCentralAuth() shares the isIP() guard: an IP editor never has a CentralAuth
	 * account, so it returns null before consulting the API.
	 */
	public function testGlobalEditCountsFromCentralAuthReturnsNullForIp(): void {
		$repo = $this->makeRepository();
		static::assertNull( $repo->exposedGlobalEditCountsFromCentralAuth( $this->makeIpUser() ) );
	}

	/**
	 * A second call for the same user is served from cache: the API stub is emptied after the first
	 * call, yet the mapped rows still come back, proving setCache()/getCacheKey() round-trip.
	 */
	public function testGlobalEditCountsFromCentralAuthServesSecondCallFromCache(): void {
		$repo = $this->makeRepository();
		$repo->apiResult = [ 'query' => [ 'globaluserinfo' => [ 'merged' => [
			[ 'wiki' => 'enwiki', 'editcount' => 100 ],
		] ] ] ];
		$user = $this->makeUser();

		$first = $repo->exposedGlobalEditCountsFromCentralAuth( $user );
		$repo->apiResult = [];
		$second = $repo->exposedGlobalEditCountsFromCentralAuth( $user );
		static::assertSame( $first, $second );
	}

	/**
	 * A well-formed merged array maps each wiki entry to a [dbName, total] row.
	 */
	public function testGlobalEditCountsFromCentralAuthMapsMergedWikis(): void {
		$repo = $this->makeRepository();
		$repo->apiResult = [ 'query' => [ 'globaluserinfo' => [ 'merged' => [
			[ 'wiki' => 'enwiki', 'editcount' => 100 ],
			[ 'wiki' => 'commonswiki', 'editcount' => 5 ],
		] ] ] ];

		$out = $repo->exposedGlobalEditCountsFromCentralAuth( $this->makeUser() );
		static::assertSame( [
			[ 'dbName' => 'enwiki', 'total' => 100 ],
			[ 'dbName' => 'commonswiki', 'total' => 5 ],
		], $out );
	}

	/**
	 * For a named account getDbNamesAndActorIds() filters on an exact actor_name and groups the
	 * per-wiki SELECTs by slice, casting each returned actor_id to int keyed by dbName.
	 */
	public function testGetDbNamesAndActorIdsUsesEqualsForNamedUser(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [
			's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '42' ] ],
			's4' => [ [ 'dbName' => 'commonswiki', 'actor_id' => '7' ] ],
		];

		$actorIds = $repo->getDbNamesAndActorIds( $this->makeUser(), [ 'enwiki', 'commonswiki' ] );

		static::assertSame( [ 'enwiki' => 42, 'commonswiki' => 7 ], $actorIds );
		static::assertStringContainsString( 'actor_name = :actor', $repo->sqlBySlice['s1'] );
		static::assertSame( 'Jimbo', $repo->paramsBySlice['s1']['actor'] );

		// A repeat call with the same args is served from cache without re-querying.
		$repo->sqlBySlice = [];
		$cached = $repo->getDbNamesAndActorIds( $this->makeUser(), [ 'enwiki', 'commonswiki' ] );
		static::assertSame( $actorIds, $cached );
		static::assertSame( [], $repo->sqlBySlice );
	}

	/**
	 * An IP range can't have an actor_name, so the query switches to a LIKE prefix match built from
	 * the CIDR's leading octets (getIpSubstringFromCidr()) with a trailing wildcard.
	 */
	public function testGetDbNamesAndActorIdsUsesLikeForIpRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [ 's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '9' ] ] ];

		$repo->getDbNamesAndActorIds( $this->makeIpRangeUser(), [ 'enwiki' ] );

		static::assertStringContainsString( 'actor_name LIKE :actor', $repo->sqlBySlice['s1'] );
		static::assertSame( '10.0.0.%', $repo->paramsBySlice['s1']['actor'] );
	}

	/**
	 * Only dbNames present in the dblist are queried; an unknown wiki is silently skipped rather than
	 * producing an SQL fragment against a slice that doesn't host it.
	 */
	public function testGetDbNamesAndActorIdsSkipsUnknownDbNames(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [ 's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '1' ] ] ];

		$repo->getDbNamesAndActorIds( $this->makeUser(), [ 'enwiki', 'nonexistentwiki' ] );

		static::assertArrayHasKey( 's1', $repo->sqlBySlice );
		static::assertStringNotContainsString( 'nonexistentwiki', $repo->sqlBySlice['s1'] );
	}

	/**
	 * When the user has edited nothing anywhere getDbNamesAndActorIds() yields no actors, so
	 * getRevisions() short-circuits to an empty array before assembling any revision query.
	 */
	public function testGetRevisionsReturnsEmptyWhenNoActorIds(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [];

		$out = $repo->getRevisions( [ 'enwiki' ], $this->makeUser() );
		static::assertSame( [], $out );
	}

	/**
	 * For a named account the revision query filters on the resolved actor ID and omits the ip_changes
	 * join; with namespace 'all' no page_namespace condition is added.
	 */
	public function testGetRevisionsUsesActorFilterForNamedUser(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [ 's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '42' ] ] ];
		$repo->revisionRows = [ 's1' => [] ];

		$repo->getRevisions( [ 'enwiki' ], $this->makeUser() );

		$sql = $repo->revisionSqlBySlice['s1'];
		static::assertStringContainsString( 'revs.rev_actor = 42', $sql );
		static::assertStringNotContainsString( 'ipc_hex BETWEEN', $sql );
		static::assertStringNotContainsString( 'page_namespace =', $sql );
		// The per-slice cap the DB enforces isn't observable through a stubbed query, so pin that the
		// clause is at least emitted; a dropped LIMIT would ship an unbounded per-slice fetch.
		static::assertStringContainsString( 'ORDER BY timestamp DESC LIMIT 31', $sql );
	}

	/**
	 * An IP range has no actor to filter on, so the query joins ip_changes and bounds on the quoted
	 * hex range instead. A specific namespace adds the page_namespace condition.
	 */
	public function testGetRevisionsUsesIpChangesJoinAndNamespaceForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [ 's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '9' ] ] ];
		$repo->revisionRows = [ 's1' => [] ];

		$repo->getRevisions( [ 'enwiki' ], $this->makeIpRangeUser(), 0 );

		$sql = $repo->revisionSqlBySlice['s1'];
		static::assertStringContainsString( 'JOIN', $sql );
		static::assertStringContainsString( 'ipc_hex BETWEEN', $sql );
		static::assertStringContainsString( 'ipc_rev_id', $sql );
		static::assertStringContainsString( 'page_namespace = 0', $sql );
	}

	/**
	 * When more than $limit rows come back across slices, getRevisions() re-sorts the merged set by
	 * descending unix_timestamp and truncates to $limit, so the newest edits win regardless of which
	 * slice they came from.
	 */
	public function testGetRevisionsResortsAndTruncatesOverLimit(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [
			's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '1' ] ],
			's4' => [ [ 'dbName' => 'commonswiki', 'actor_id' => '2' ] ],
		];
		// Two slices, three rows total, out of timestamp order across slices.
		$repo->revisionRows = [
			's1' => [
				[ 'id' => 'a', 'unix_timestamp' => 300 ],
				[ 'id' => 'c', 'unix_timestamp' => 100 ],
			],
			's4' => [
				[ 'id' => 'b', 'unix_timestamp' => 200 ],
			],
		];

		$out = $repo->getRevisions( [ 'enwiki', 'commonswiki' ], $this->makeUser(), 'all', false, false, 2 );

		static::assertCount( 2, $out );
		static::assertSame( 'a', $out[0]['id'] );
		static::assertSame( 'b', $out[1]['id'] );
	}

	/**
	 * The re-sort comparator returns 0 for equal unix_timestamps, so usort (stable on PHP 8) leaves
	 * tied rows in their original merged order. With three equally timestamped rows over a limit of
	 * two, that means the first two by input order (a, b) survive the truncation. Asserting the
	 * surviving ids, not just the count, is what exercises the tie branch: drop the `=== return 0`
	 * arm and the inconsistent comparator no longer guarantees a, b come out on top.
	 */
	public function testGetRevisionsHandlesTiedTimestampsWhenTruncating(): void {
		$repo = $this->makeRepository();
		$repo->cannedRows = [
			's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '1' ] ],
			's4' => [ [ 'dbName' => 'commonswiki', 'actor_id' => '2' ] ],
		];
		$repo->revisionRows = [
			's1' => [
				[ 'id' => 'a', 'unix_timestamp' => 100 ],
				[ 'id' => 'b', 'unix_timestamp' => 100 ],
			],
			's4' => [
				[ 'id' => 'c', 'unix_timestamp' => 100 ],
			],
		];

		$out = $repo->getRevisions( [ 'enwiki', 'commonswiki' ], $this->makeUser(), 'all', false, false, 2 );
		static::assertSame( [ 'a', 'b' ], array_column( $out, 'id' ) );

		// A repeat call with the same args comes straight from cache, running no revision query.
		$repo->revisionSqlBySlice = [];
		$cached = $repo->getRevisions( [ 'enwiki', 'commonswiki' ], $this->makeUser(), 'all', false, false, 2 );
		static::assertSame( $out, $cached );
		static::assertSame( [], $repo->revisionSqlBySlice );
	}

	/**
	 * getProjectsWithEdits() for an IP resolves the wikis from getDbNamesAndActorIds()'s keys and
	 * hydrates each through projectRepo->getProject().
	 */
	public function testGetProjectsWithEditsUsesActorKeysForIp(): void {
		$enwiki = $this->createMock( Project::class );
		$projectRepo = $this->createMock( ProjectRepository::class );
		$projectRepo->expects( static::once() )
			->method( 'getProject' )
			->with( 'enwiki' )
			->willReturn( $enwiki );
		// With no explicit dbNames, getDbNamesAndActorIds() derives the candidate list from getAll().
		$projectRepo->method( 'getAll' )->willReturn( [
			[ 'dbName' => 'enwiki' ],
			[ 'dbName' => 'commonswiki' ],
		] );

		$repo = $this->makeRepository( $projectRepo );
		// Only enwiki returns an actor row, so commonswiki drops out of the result.
		$repo->cannedRows = [ 's1' => [ [ 'dbName' => 'enwiki', 'actor_id' => '9' ] ] ];

		$projects = $repo->getProjectsWithEdits( $this->makeIpRangeUser() );
		static::assertSame( [ 'enwiki' => $enwiki ], $projects );
	}

	/**
	 * For a named account getProjectsWithEdits() keeps only wikis with a positive CentralAuth edit
	 * count, dropping the zero-edit entries before hydrating projects.
	 */
	public function testGetProjectsWithEditsKeepsOnlyPositiveTotalsForNamedUser(): void {
		$enwiki = $this->createMock( Project::class );
		$projectRepo = $this->createMock( ProjectRepository::class );
		$projectRepo->expects( static::once() )
			->method( 'getProject' )
			->with( 'enwiki' )
			->willReturn( $enwiki );

		$repo = $this->makeRepository( $projectRepo );
		$repo->apiResult = [ 'query' => [ 'globaluserinfo' => [ 'merged' => [
			[ 'wiki' => 'enwiki', 'editcount' => 12 ],
			[ 'wiki' => 'dewiki', 'editcount' => 0 ],
		] ] ] ];

		$projects = $repo->getProjectsWithEdits( $this->makeUser() );
		static::assertSame( [ 'enwiki' => $enwiki ], $projects );
	}

	/**
	 * A named User: isIpRange()/isIP() false so the actor-based paths run, and getUsername() feeds the
	 * exact actor_name filter.
	 */
	private function makeUser( string $username = 'Jimbo' ): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( false );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( $username );
		return $user;
	}

	/**
	 * A single-IP User: isIP() true (so globalEditCounts() bails early), not a range.
	 */
	private function makeIpUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( true );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( '10.0.0.1' );
		return $user;
	}

	/**
	 * An IP-range User: getUsername() is a real CIDR so IPUtils::parseRange() yields hex bounds, and
	 * getIpSubstringFromCidr() supplies the LIKE prefix for the actor lookup.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( true );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		$user->method( 'getIpSubstringFromCidr' )->willReturn( '10.0.0.' );
		return $user;
	}

	/**
	 * The test repository: overrides every base seam the reachable methods touch. executeProjectsQuery()
	 * records the SQL and params per slice and returns canned actor rows or revision rows depending on
	 * which kind of query it recognises; getProjectsConnection() returns a Connection whose platform
	 * quotes literals; getDbList()/getTableName() stand in for the dblist wiring; executeApiRequest()
	 * returns a canned CentralAuth payload. A real ArrayAdapter backs the cache.
	 */
	private function makeRepository( ?ProjectRepository $projectRepo = null ): GlobalContribsRepository {
		$platform = $this->createMock( AbstractPlatform::class );
		$platform->method( 'quoteStringLiteral' )->willReturnCallback(
			static fn ( string $str ): string => "'$str'"
		);
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getDatabasePlatform' )->willReturn( $platform );

		// getRevisions() reads table names off the CentralAuth project's repository, so the injected
		// ProjectRepository mock must answer getTableName() as well as getProject().
		$projectRepo ??= $this->createMock( ProjectRepository::class );
		$projectRepo->method( 'getTableName' )->willReturnCallback(
			static fn ( string $db, string $table, ?string $ext = null ): string => "`{$db}_p`.`$table`"
		);

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$projectRepo,
			'meta.wikimedia.org'
		) extends GlobalContribsRepository {

			/** @var array CentralAuth API payload returned by executeApiRequest(). */
			public array $apiResult = [];

			/** @var array<string, array<array<string, mixed>>> Actor rows keyed by slice. */
			public array $cannedRows = [];

			/** @var array<string, array<array<string, mixed>>> Revision rows keyed by slice. */
			public array $revisionRows = [];

			/** @var array<string, string> SQL recorded from the actor lookup, keyed by slice. */
			public array $sqlBySlice = [];

			/** @var array<string, array> Params recorded from the actor lookup, keyed by slice. */
			public array $paramsBySlice = [];

			/** @var array<string, string> SQL recorded from the revision query, keyed by slice. */
			public array $revisionSqlBySlice = [];

			/** @var Connection The stub connection used only for its quoteStringLiteral(). */
			public Connection $stubConnection;

			/** @var callable Turns an array of rows into a Doctrine Result double. */
			public $resultFactory;

			public function executeApiRequest( Project $project, array $params ): array {
				return $this->apiResult;
			}

			public function getDbList(): array {
				return [ 'enwiki' => 's1', 'commonswiki' => 's4' ];
			}

			public function getTableName( string $db, string $table, ?string $ext = null ): string {
				return "`{$db}_p`.`$table`";
			}

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->stubConnection;
			}

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$slice = (string)$project;
				// The revision query selects rev_id; the actor lookup selects actor_id.
				if ( str_contains( $sql, 'rev_id AS id' ) ) {
					$this->revisionSqlBySlice[$slice] = $sql;
					return ( $this->resultFactory )( $this->revisionRows[$slice] ?? [] );
				}
				$this->sqlBySlice[$slice] = $sql;
				$this->paramsBySlice[$slice] = $params;
				return ( $this->resultFactory )( $this->cannedRows[$slice] ?? [] );
			}

			/**
			 * Expose the protected CentralAuth mapper for direct assertion.
			 */
			public function exposedGlobalEditCountsFromCentralAuth( User $user ): ?array {
				return $this->globalEditCountsFromCentralAuth( $user );
			}
		};
		$repo->stubConnection = $connection;
		$repo->resultFactory = fn ( array $rows ): Result => $this->makeResult( $rows );
		return $repo;
	}

	/**
	 * A Doctrine Result yielding the given rows from both fetch styles: fetchAllAssociative() for the
	 * revision query and fetchAssociative() (one row at a time, then false) for the actor loop.
	 * @param array<array<string, mixed>> $rows
	 */
	private function makeResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		$queue = $rows;
		$result->method( 'fetchAssociative' )->willReturnCallback(
			static function () use ( &$queue ) {
				return array_shift( $queue ) ?? false;
			}
		);
		return $result;
	}
}
