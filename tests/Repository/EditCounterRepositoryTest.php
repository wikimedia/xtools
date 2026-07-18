<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\AutoEditsRepository;
use App\Repository\EditCounterRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * EditCounterRepository is almost entirely SQL-assembly and light post-processing over the rows a
 * replica hands back. Two branches matter for coverage: the WMF-only Commons merge in getFileCounts(),
 * and the isIpRange() branch that runs through nearly every other method (an IP range can't have an
 * actor ID, so those queries switch to ip_changes and drop the actor/archive clauses). None of the
 * queries are exercised against a database: a test subclass overrides the executeProjectsQuery() seam
 * to record the built SQL and params and hand back canned rows, so we assert the branch logic and the
 * row transforms in isolation. A real ArrayAdapter backs the cache so getCacheKey()/setCache() behave
 * as in production.
 * @covers \App\Repository\EditCounterRepository
 */
class EditCounterRepositoryTest extends TestCase {

	/**
	 * On WMF, a non-Commons project's file counts are merged with the Commons file counts.
	 */
	public function testGetFileCountsMergesCommonsCountsOnWmf(): void {
		$repo = $this->makeFileCountsRepository( true );
		$counts = $repo->getFileCounts( $this->makeProject( 'en.wikipedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertSame( 5, $counts['files_moved_commons'] );
	}

	/**
	 * Off-WMF there is no Commons to consult, so only the local file counts are returned.
	 */
	public function testGetFileCountsOmitsCommonsCountsOffWmf(): void {
		$repo = $this->makeFileCountsRepository( false );
		$counts = $repo->getFileCounts( $this->makeProject( 'en.wikipedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertArrayNotHasKey( 'files_moved_commons', $counts );
	}

	/**
	 * On Commons itself the local query already covers the Commons rows, so we don't fold them in
	 * again and double-count.
	 */
	public function testGetFileCountsOmitsCommonsCountsOnCommonsItself(): void {
		$repo = $this->makeFileCountsRepository( true );
		$counts = $repo->getFileCounts( $this->makeProject( 'commons.wikimedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertArrayNotHasKey( 'files_moved_commons', $counts );
	}

	/**
	 * getFileCountsCommons() resolves the shared media wiki through ProjectRepository::getProject()
	 * and queries there rather than on the local project. Reached directly (bypassing the
	 * getFileCounts merge that normally calls it) so we pin that it fetches Commons rows on its own.
	 */
	public function testGetFileCountsCommonsQueriesCommonsProject(): void {
		$commons = $this->createMock( Project::class );
		$commons->method( 'getTableName' )->willReturn( '`commonswiki_p`.`logging`' );
		$projectRepo = $this->createMock( ProjectRepository::class );
		$projectRepo->method( 'getProject' )->with( 'commonswiki' )->willReturn( $commons );

		$repo = $this->makeRepository( true, $projectRepo );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'key' => 'files_moved_commons', 'val' => 5 ],
			[ 'key' => 'files_uploaded_commons', 'val' => 8 ],
		] ) ];

		$rows = ( new ReflectionMethod( EditCounterRepository::class, 'getFileCountsCommons' ) )
			->invoke( $repo, $this->makeUser() );
		static::assertSame( 'files_moved_commons', $rows[0]['key'] );
		static::assertStringContainsString( 'commonswiki_p', $repo->lastSql );
	}

	/**
	 * getPairData() keys each row by its `key` column and casts `val` to int. For a named account the
	 * query counts by actor ID and folds in three archive (deleted-revision) sub-selects.
	 */
	public function testGetPairDataKeysRowsAndIncludesArchiveQueriesForActor(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'key' => 'live', 'val' => '42' ],
			[ 'key' => 'deleted', 'val' => '7' ],
		] ) ];

		$data = $repo->getPairData( $this->makeProject(), $this->makeUser() );
		static::assertSame( 42, $data['live'] );
		static::assertSame( 7, $data['deleted'] );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertStringContainsString( "'created-deleted'", $repo->lastSql );
		static::assertArrayHasKey( 'actorId', $repo->lastParams );
	}

	/**
	 * An IP range has no actor ID, so getPairData() switches to an ip_changes join over the hex range
	 * and drops the archive sub-selects (deleted revisions aren't reachable by IP).
	 */
	public function testGetPairDataUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [ [ 'key' => 'live', 'val' => '3' ] ] ) ];

		$repo->getPairData( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertStringNotContainsString( "'created-deleted'", $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
		static::assertArrayHasKey( 'endIp', $repo->lastParams );
	}

	/**
	 * getLogCounts() combines the returned source/value rows into an int map, then backfills every
	 * required count with 0 so callers can assume the full set of log keys is present.
	 */
	public function testGetLogCountsCombinesRowsAndDefaultsMissingKeysToZero(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'source' => 'thanks-thank', 'value' => '5' ],
		] ) ];

		$counts = $repo->getLogCounts( $this->makeProject(), $this->makeUser() );
		static::assertSame( 5, $counts['thanks-thank'] );
		// block-block is in the required list but wasn't returned, so it defaults to 0.
		static::assertSame( 0, $counts['block-block'] );
	}

	/**
	 * getFirstAndLatestActions() maps each row's `key` to an id/timestamp/type triple. For a named
	 * account it also unions in the latest logged action.
	 */
	public function testGetFirstAndLatestActionsMapsRowsAndIncludesLogQueryForActor(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'key' => 'rev_first', 'id' => '10', 'timestamp' => '20200101000000', 'type' => null ],
			[ 'key' => 'log_latest', 'id' => '99', 'timestamp' => '20220101000000', 'type' => 'move' ],
		] ) ];

		$actions = $repo->getFirstAndLatestActions( $this->makeProject(), $this->makeUser() );
		static::assertSame( '10', $actions['rev_first']['id'] );
		static::assertSame( 'move', $actions['log_latest']['type'] );
		static::assertStringContainsString( "'log_latest'", $repo->lastSql );
	}

	/**
	 * For an IP range the log sub-query is dropped (log rows aren't keyed by IP) and the revision
	 * bounds come from ip_changes instead of the actor.
	 */
	public function testGetFirstAndLatestActionsOmitsLogQueryForRange(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'key' => 'rev_first', 'id' => '1', 'timestamp' => '20200101000000', 'type' => null ],
		] ) ];

		$repo->getFirstAndLatestActions( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringNotContainsString( "'log_latest'", $repo->lastSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
	}

	/**
	 * getBlocksReceived() passes rows through untouched, but the log_title lookup uses the DB form of
	 * the name, so spaces in the username become underscores in the bound param.
	 */
	public function testGetBlocksReceivedNormalisesUsernameAndPassesRowsThrough(): void {
		$repo = $this->makeRepository( true );
		$rows = [ [ 'log_action' => 'block', 'log_timestamp' => '20200101000000', 'log_params' => '' ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$result = $repo->getBlocksReceived( $this->makeProject(), $this->makeUser( 'Some User' ) );
		static::assertSame( $rows, $result );
		static::assertSame( 'Some_User', $repo->lastParams['username'] );
	}

	/**
	 * getThanksReceived() returns the scalar count as an int, again keying on the underscored name.
	 */
	public function testGetThanksReceivedCastsCountAndNormalisesUsername(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '4' ) ];

		$count = $repo->getThanksReceived( $this->makeProject(), $this->makeUser( 'Some User' ) );
		static::assertSame( 4, $count );
		static::assertSame( 'Some_User', $repo->lastParams['username'] );
	}

	/**
	 * getNamespaceTotals() passes the namespace=>count map straight through; the IP-range branch swaps
	 * the actor filter for an ip_changes join.
	 */
	public function testGetNamespaceTotalsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->keyValueResult( [ 0 => 100, 1 => 20 ] ) ];

		$totals = $repo->getNamespaceTotals( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertSame( [ 0 => 100, 1 => 20 ], $totals );
		static::assertStringContainsString( 'JOIN', $repo->lastSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
	}

	/**
	 * Same for a named account: the map passes through, and the actor filter (not ip_changes)
	 * drives the query.
	 */
	public function testGetNamespaceTotalsUsesActorFilterForActor(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->keyValueResult( [ 0 => 100 ] ) ];

		$totals = $repo->getNamespaceTotals( $this->makeProject(), $this->makeUser() );
		static::assertSame( [ 0 => 100 ], $totals );
		static::assertStringContainsString( 'r.rev_actor = :actorId', $repo->lastSql );
		static::assertStringNotContainsString( 'ipc_hex', $repo->lastSql );
	}

	/**
	 * getMonthCounts() passes rows through; the IP-range branch adds the ip_changes join and the hex
	 * range params.
	 */
	public function testGetMonthCountsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository( true );
		$rows = [ [ 'year' => 2020, 'month' => 1, 'namespace' => 0, 'count' => 5 ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$totals = $repo->getMonthCounts( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertSame( $rows, $totals );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
	}

	/**
	 * getTimeCard() passes rows through; for an IP range the timestamps come from ip_changes
	 * (ipc_rev_timestamp) rather than the revision's own rev_timestamp.
	 */
	public function testGetTimeCardUsesIpcTimestampForRange(): void {
		$repo = $this->makeRepository( true );
		$rows = [ [ 'day_of_week' => 1, 'hour' => 12, 'value' => 3 ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$totals = $repo->getTimeCard( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertSame( $rows, $totals );
		static::assertStringContainsString( 'ipc_rev_timestamp', $repo->lastSql );
		static::assertStringNotContainsString( 'rev_actor = :actorId', $repo->lastSql );
	}

	/**
	 * The named-account path of getTimeCard() groups on rev_timestamp and filters by actor.
	 */
	public function testGetTimeCardUsesRevTimestampForActor(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getTimeCard( $this->makeProject(), $this->makeUser() );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertStringNotContainsString( 'ipc_rev_timestamp', $repo->lastSql );
	}

	/**
	 * getEditData() is the one method with real arithmetic: it json_decodes the aggregated size and
	 * tag arrays, averages the sizes, and counts the small (|n|<20) and large (|n|>1000) edits.
	 */
	public function testGetEditDataDecodesAndSummarisesSizes(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->singleRowResult( [
			'sizes' => '[10, 500, 2000, -5]',
			'tag_lists' => '["mobile edit"]',
		] ) ];

		$data = $repo->getEditData( $this->makeProject(), $this->makeUser() );
		static::assertSame( [ 10, 500, 2000, -5 ], $data['sizes'] );
		static::assertSame( [ 'mobile edit' ], $data['tag_lists'] );
		// (10 + 500 + 2000 - 5) / 4
		static::assertSame( 626.25, $data['average_size'] );
		// |10| and |-5| are < 20.
		static::assertSame( 2, $data['small_edits'] );
		// only |2000| is > 1000.
		static::assertSame( 1, $data['large_edits'] );
	}

	/**
	 * When the user has no edits the aggregate columns come back null; getEditData() must fall back to
	 * empty arrays and a zero average rather than dividing by zero.
	 */
	public function testGetEditDataHandlesEmptyResult(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->singleRowResult( [
			'sizes' => null,
			'tag_lists' => null,
		] ) ];

		$data = $repo->getEditData( $this->makeProject(), $this->makeUser() );
		static::assertSame( [], $data['sizes'] );
		static::assertSame( [], $data['tag_lists'] );
		static::assertSame( 0, $data['average_size'] );
		static::assertSame( 0, $data['small_edits'] );
		static::assertSame( 0, $data['large_edits'] );
	}

	/**
	 * countAutomatedEdits() is a deprecated passthrough: it simply hands the same project and user to
	 * the injected AutoEditsRepository and returns its count.
	 */
	public function testCountAutomatedEditsDelegatesToAutoEditsRepository(): void {
		$project = $this->makeProject();
		$user = $this->makeUser();
		$autoEditsRepo = $this->createMock( AutoEditsRepository::class );
		$autoEditsRepo->expects( static::once() )
			->method( 'countAutomatedEdits' )
			->with( $project, $user )
			->willReturn( 17 );

		$repo = $this->makeRepository( true, null, $autoEditsRepo );
		static::assertSame( 17, $repo->countAutomatedEdits( $project, $user ) );
	}

	/**
	 * A Project stubbed with the seams the query builders touch: the domain guards the Commons branch,
	 * and getTableName()/getDatabaseName()/getCacheKey() keep the SQL and cache wiring happy.
	 */
	private function makeProject( string $domain = 'en.wikipedia.org' ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( $domain );
		$project->method( 'getCacheKey' )->willReturn( $domain );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A non-anon named User. isIpRange() is false so the actor-based query paths run; getActorId()
	 * resolves so param binding has something to bind.
	 */
	private function makeUser( string $username = 'Jimbo' ): User {
		$user = $this->createMock( User::class );
		$user->method( 'isAnon' )->willReturn( false );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( $username );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A User representing an IP range. getUsername() returns a real CIDR so IPUtils::parseRange()
	 * yields the start/end hex bounds the ip_changes queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isAnon' )->willReturn( true );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAssociative() one at a time (then false),
	 * for the methods that loop with a while().
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
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

	/**
	 * A Doctrine Result whose fetchAssociative() returns a single row, for getEditData().
	 * @param array<string, mixed> $row
	 */
	private function singleRowResult( array $row ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( $row );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchAllKeyValue() returns the given map, for getNamespaceTotals().
	 * @param array<int|string, mixed> $map
	 */
	private function keyValueResult( array $map ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllKeyValue' )->willReturn( $map );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchOne() returns the given scalar, for getThanksReceived().
	 */
	private function scalarResult( mixed $value ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( $value );
		return $result;
	}

	/**
	 * The generalised test repository: executeProjectsQuery() records the built SQL and params into
	 * public properties (so branch tests can assert on the generated query) and returns whichever
	 * canned Result matches the SQL, using the same substring-keyed lookup as UserRightsRepositoryTest
	 * ('*' = any). A real ArrayAdapter backs the cache so getCacheKey()/setCache() behave without infra.
	 */
	private function makeRepository(
		bool $isWMF,
		?ProjectRepository $projectRepo = null,
		?AutoEditsRepository $autoEditsRepo = null
	): EditCounterRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$projectRepo ?? $this->createMock( ProjectRepository::class ),
			$autoEditsRepo ?? $this->createMock( AutoEditsRepository::class )
		) extends EditCounterRepository {

			/** @var array<string, Result> Canned Results keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

			/** @var string The SQL built by the last executeProjectsQuery() call. */
			public string $lastSql = '';

			/** @var array The params bound by the last executeProjectsQuery() call. */
			public array $lastParams = [];

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$this->lastSql = $sql;
				$this->lastParams = $params;
				foreach ( $this->cannedQueries as $needle => $result ) {
					if ( $needle === '*' || str_contains( $sql, $needle ) ) {
						return $result;
					}
				}
				throw new \LogicException( "No canned result for query: $sql" );
			}
		};
	}

	/**
	 * The original getFileCounts() harness: its local file-count query returns a canned row and its
	 * Commons fetch is overridden to a canned row, so the isWMF merge branch is exercised without a
	 * replica and without resolving a real Commons project.
	 */
	private function makeFileCountsRepository( bool $isWMF ): EditCounterRepository {
		$localResult = $this->createMock( Result::class );
		$localResult->method( 'fetchAllAssociative' )->willReturn( [
			[ 'key' => 'files_moved', 'val' => 3 ],
		] );

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			$this->createMock( AutoEditsRepository::class )
		) extends EditCounterRepository {

			/** @var Result Canned rows for the local file-count query. */
			public Result $localResult;

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				return $this->localResult;
			}

			protected function getFileCountsCommons( User $user ): array {
				return [
					[ 'key' => 'files_moved_commons', 'val' => 5 ],
				];
			}
		};
		$repo->localResult = $localResult;
		return $repo;
	}
}
