<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Project;
use App\Model\User;
use App\Repository\CategoryEditsRepository;
use App\Repository\EditRepository;
use App\Repository\PageRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Wikimedia\IPUtils;

/**
 * Once a wiki's links tables move to their own section (Commons, T398709), categorylinks can no
 * longer be JOINed to revision, so CategoryEdits intersects the two sides in PHP instead. These
 * tests drive that: which side is chosen to drive the intersection, how the halves are folded
 * back together, and that the three public methods still report what they used to.
 *
 * The seam is getProjectsConnection(), which every query reaches through — including the ones
 * that go via executeQueryBuilder(), since the builder only renders SQL and the connection is
 * resolved separately. The test subclass hands back one mock Connection for every slice, and
 * that connection dispatches on the shape of the SQL it is given.
 * @covers \App\Repository\CategoryEditsRepository
 */
class CategoryEditsRepositoryTest extends TestCase {

	/** Category titles mapped to the linktarget IDs categorylinks refers to them by. */
	private const LINKTARGET_MAP = [ 10 => 'Living_people', 20 => 'American_writers' ];

	/**
	 * A user with few enough edits to sit under USER_PROBE_CAP drives the intersection from the
	 * wiki side, and the category side is never probed: enumerating a handful of revisions is
	 * cheap whatever the categories look like, and the category probe is the expensive one.
	 */
	public function testSmallUserDrivesFromTheWikiSideWithoutProbingCategories(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 12,
			'userCounts' => [ 101 => '3', 102 => '1' ],
			'membership' => [ [ 10, 101 ], [ 20, 102 ] ],
		] );

		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( 4, $count );
		static::assertSame( 0, $repo->countQueries( 'categoryProbe' ) );
		// The membership query is constrained by the user's pages, not the other way round.
		static::assertStringContainsString( 'cl_from IN (', $repo->sqlFor( 'membership' ) );
	}

	/**
	 * A prolific user against a small category flips the direction: the categories are
	 * enumerated and their page IDs constrain the revision query.
	 */
	public function testProlificUserWithSmallCategoryDrivesFromTheCategorySide(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 10001,
			'categoryProbe' => 50,
			'membership' => [ [ 10, 101 ], [ 10, 102 ] ],
			'userCounts' => [ 101 => '2' ],
		] );

		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( 1, $repo->countQueries( 'categoryProbe' ) );
		// Membership is unconstrained by page, and the revision query carries the page IDs.
		static::assertStringNotContainsString( 'cl_from IN (', $repo->sqlFor( 'membership' ) );
		static::assertStringContainsString( 'rev_page IN (', $repo->sqlFor( 'userCounts' ) );
		// Page 102 is in the category but the user never edited it, so it contributes nothing.
		static::assertSame( 2, $count );
	}

	/**
	 * A prolific user against a category too large to enumerate falls back to the wiki side.
	 */
	public function testProlificUserWithHugeCategoryDrivesFromTheWikiSide(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 10001,
			'categoryProbe' => 20001,
			'userCounts' => [ 101 => '7' ],
			'membership' => [ [ 10, 101 ] ],
		] );

		$repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( 1, $repo->countQueries( 'categoryProbe' ) );
		static::assertStringContainsString( 'cl_from IN (', $repo->sqlFor( 'membership' ) );
	}

	/**
	 * A page in two of the selected categories is counted once in the total. The old SQL needed
	 * COUNT(DISTINCT rev_id) to suppress the categorylinks fan-out; here the duplication lives
	 * in the membership map instead, so it can't reach the total at all.
	 */
	public function testMultiCategoryPageIsNotDoubleCountedInTheTotal(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '4' ],
			'membership' => [ [ 10, 101 ], [ 20, 101 ] ],
		] );

		$count = $repo->countCategoryEdits(
			$this->makeProject(), $this->makeUser(), [ 'Living people', 'American writers' ]
		);

		static::assertSame( 4, $count );
	}

	/**
	 * getCategoryCounts() reports per-category totals, orders them by edit count descending as
	 * the old ORDER BY did, and — because the old query INNER JOINed — omits categories the user
	 * never edited in. The template counts the array to say "in N categories", so an empty
	 * entry would change what the page reports.
	 */
	public function testGetCategoryCountsFoldsPerCategoryAndOrdersByEditCount(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 20,
			'userCounts' => [ 101 => '2', 102 => '9' ],
			// 10 gets the quieter page, 20 the busier one, so the input order is not the output.
			'membership' => [ [ 10, 101 ], [ 20, 102 ], [ 20, 101 ] ],
		] );

		$counts = $repo->getCategoryCounts(
			$this->makeProject(), $this->makeUser(), [ 'Living people', 'American writers' ]
		);

		static::assertSame( [ 'American_writers', 'Living_people' ], array_keys( $counts ) );
		static::assertSame( [ 'editCount' => 11, 'pageCount' => 2 ], $counts['American_writers'] );
		static::assertSame( [ 'editCount' => 2, 'pageCount' => 1 ], $counts['Living_people'] );
	}

	/**
	 * A category whose pages the user never edited produces no entry at all.
	 */
	public function testGetCategoryCountsOmitsCategoriesWithNoEdits(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '1' ],
			'membership' => [ [ 10, 101 ] ],
		] );

		$counts = $repo->getCategoryCounts(
			$this->makeProject(), $this->makeUser(), [ 'Living people', 'American writers' ]
		);

		static::assertSame( [ 'Living_people' ], array_keys( $counts ) );
	}

	/**
	 * getCategoryEdits() sorts the rows it fetched by timestamp then rev_id, both descending,
	 * and takes the top 50. That ordering is what makes chunked queries safe to merge: each
	 * chunk returns its own top 50, and any row in the global top 50 is in its chunk's top 50.
	 */
	public function testGetCategoryEditsSortsRowsByTimestampThenRevId(): void {
		$rows = [
			[ 'rev_id' => 5, 'timestamp' => '20200101000000', 'page_title' => 'B' ],
			[ 'rev_id' => 9, 'timestamp' => '20220101000000', 'page_title' => 'A' ],
			// Same timestamp as rev 9: rev_id breaks the tie, so 9 precedes 7.
			[ 'rev_id' => 7, 'timestamp' => '20220101000000', 'page_title' => 'C' ],
		];
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '3' ],
			'membership' => [ [ 10, 101 ] ],
			'rows' => $rows,
		] );

		$edits = $repo->getCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( [ 9, 7, 5 ], array_column( $edits, 'rev_id' ) );
	}

	/**
	 * A user with no edits in range short-circuits: the links section is never queried, because
	 * no category membership can intersect an empty set of revisions.
	 */
	public function testNoEditsInRangeSkipsTheLinksQueryEntirely(): void {
		$repo = $this->makeRepository( [ 'userProbe' => 0 ] );

		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( 0, $count );
		static::assertSame( 0, $repo->countQueries( 'membership' ) );
		static::assertSame( 0, $repo->countQueries( 'categoryProbe' ) );
	}

	/**
	 * Categories that don't exist resolve to no linktarget IDs. Without the short-circuit the
	 * IN list would be empty, which Doctrine renders as IN (NULL).
	 */
	public function testUnknownCategoriesSkipEveryQuery(): void {
		$repo = $this->makeRepository( [ 'linktarget' => [], 'userProbe' => 99 ] );

		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Nonexistent' ] );

		static::assertSame( 0, $count );
		static::assertSame( 0, $repo->countQueries( 'userProbe' ) );
		static::assertSame( 0, $repo->countQueries( 'membership' ) );
	}

	/**
	 * An IP range has no actor ID, so the revision side joins ip_changes on the hex bounds of
	 * the parsed CIDR instead. ip_changes lives with revision on the wiki section, so the links
	 * split leaves this branch alone.
	 */
	public function testIpRangeJoinsIpChangesOnTheWikiSide(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 3,
			'userCounts' => [ 101 => '3' ],
			'membership' => [ [ 10, 101 ] ],
		] );

		$repo->countCategoryEdits( $this->makeProject(), $this->makeIpRangeUser(), [ 'Living people' ] );

		[ $hexStart, $hexEnd ] = IPUtils::parseRange( '10.0.0.0/24' );
		$sql = $repo->sqlFor( 'userCounts' );
		static::assertStringContainsString( 'ipc_hex BETWEEN', $sql );
		static::assertStringContainsString( '`enwiki_p`.`ip_changes`', $sql );
		static::assertContains( $hexStart, $repo->paramsFor( 'userCounts' ) );
		static::assertContains( $hexEnd, $repo->paramsFor( 'userCounts' ) );
		// The probe takes the same branch rather than filtering on an actor that doesn't exist.
		static::assertStringContainsString( 'ipc_hex BETWEEN', $repo->sqlFor( 'userProbe' ) );
	}

	/**
	 * The links tables resolve to their own section's database and connection. Here nothing is
	 * split, so they fall back to the project's own database — which is what keeps third-party
	 * installs and every unsplit wiki working unchanged.
	 */
	public function testLinksTablesFallBackToTheProjectDatabaseWhenNotSplit(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '1' ],
			'membership' => [ [ 10, 101 ] ],
		] );

		$repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertStringContainsString( '`enwiki_p`.`categorylinks`', $repo->sqlFor( 'membership' ) );
		static::assertStringContainsString( '`enwiki_p`.`linktarget`', $repo->sqlFor( 'linktarget' ) );
	}

	/**
	 * A prolific user against several large categories can match millions of (page, category)
	 * pairs. Chunking bounds each statement but not the total held in memory, so APP_MAX_LINKS
	 * is a hard ceiling on rows read.
	 */
	public function testExceedingMaxLinksIsRefused(): void {
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '1', 102 => '1', 103 => '1' ],
			'membership' => [ [ 10, 101 ], [ 10, 102 ], [ 10, 103 ] ],
		], maxLinks: 2 );

		$this->expectException( HttpException::class );
		$repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );
	}

	/**
	 * Each public method caches its result and short-circuits on a second call, and the shared
	 * intersection is cached too — so getCategoryCounts() after countCategoryEdits() re-runs
	 * neither the probe nor either half of the intersection.
	 */
	public function testResultsAndTheIntersectionAreCached(): void {
		$project = $this->makeProject();
		$user = $this->makeUser();
		$categories = [ 'Living people' ];
		$repo = $this->makeRepository( [
			'userProbe' => 5,
			'userCounts' => [ 101 => '4' ],
			'membership' => [ [ 10, 101 ] ],
		] );

		static::assertSame( 4, $repo->countCategoryEdits( $project, $user, $categories ) );
		static::assertSame( 4, $repo->countCategoryEdits( $project, $user, $categories ) );
		static::assertSame( 1, $repo->countQueries( 'userProbe' ) );

		// A different method over the same arguments reuses the cached intersection.
		$repo->getCategoryCounts( $project, $user, $categories );
		static::assertSame( 1, $repo->countQueries( 'userProbe' ) );
		static::assertSame( 1, $repo->countQueries( 'membership' ) );
	}

	/**
	 * getEditsFromRevs() is a thin delegator to Edit::getEditsFromRevs(); with no revs the static
	 * maps over an empty array and returns [] without touching the injected repositories.
	 */
	public function testGetEditsFromRevsReturnsEmptyForNoRevs(): void {
		$repo = $this->makeRepository( [ 'userProbe' => 0 ] );
		static::assertSame( [], $repo->getEditsFromRevs( $this->makeProject(), $this->makeUser(), [] ) );
	}

	/**
	 * A Project stubbed as far as the queries reach: getDatabaseName() resolves the links
	 * database, getTableName() feeds the wiki-side SQL, getCacheKey() keeps the cache happy.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'en.wikipedia.org' );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki_p' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A named account: isIpRange() is false so the actor-based paths run, and getActorId()
	 * resolves so param binding has something to bind.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A User representing an IP range. getUsername() returns a real CIDR so IPUtils::parseRange()
	 * yields the hex bounds the ip_changes queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * Classify a statement by the shape of its SQL, so the mock connection can answer each of
	 * the five queries the repository issues. Order matters: the capped probes mention the same
	 * tables as the queries they precede, so they're matched first.
	 * @param string $sql
	 * @return string One of linktarget, userProbe, categoryProbe, membership, userCounts, rows.
	 */
	private static function classify( string $sql ): string {
		if ( str_contains( $sql, 'lt_title' ) ) {
			return 'linktarget';
		}
		if ( str_contains( $sql, ') probe' ) ) {
			return str_contains( $sql, 'categorylinks' ) ? 'categoryProbe' : 'userProbe';
		}
		if ( str_contains( $sql, 'cl_target_id' ) ) {
			return 'membership';
		}
		return str_contains( $sql, 'edit_count' ) ? 'userCounts' : 'rows';
	}

	/**
	 * A CategoryEditsRepository wired to one mock Connection that answers every query from
	 * $responses and records what it was asked. createQueryBuilder() hands back a real
	 * QueryBuilder bound to that same connection, so the SQL under assertion is the SQL the
	 * repository actually built.
	 * @param array $responses Keyed by the classify() name; 'linktarget' defaults to the map.
	 * @param int|null $maxLinks APP_MAX_LINKS, or null to leave it unconfigured.
	 * @return CategoryEditsRepository
	 */
	private function makeRepository( array $responses, ?int $maxLinks = null ): CategoryEditsRepository {
		$responses += [ 'linktarget' => self::LINKTARGET_MAP ];
		$params = $maxLinks === null ? [] : [ 'app.max_links' => $maxLinks ];

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			new ParameterBag( $params ),
			true,
			30,
			$this->createMock( AutomatedEditsHelper::class ),
			$this->createMock( EditRepository::class ),
			$this->createMock( PageRepository::class ),
			$this->createMock( UserRepository::class )
		) extends CategoryEditsRepository {

			/** @var Connection The mock Connection every getProjectsConnection() call returns. */
			public Connection $testConnection;

			/** @var array<string, array{sql:string,params:array}[]> Statements seen, by kind. */
			public array $seen = [];

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->testConnection;
			}

			public function countQueries( string $kind ): int {
				return count( $this->seen[$kind] ?? [] );
			}

			public function sqlFor( string $kind ): string {
				return ( $this->seen[$kind] ?? [] )[0]['sql'] ?? '';
			}

			public function paramsFor( string $kind ): array {
				return ( $this->seen[$kind] ?? [] )[0]['params'] ?? [];
			}
		};

		$connection = $this->createMock( Connection::class );
		// QueryBuilder::getSQL() asks the connection for its platform to render the statement,
		// so a bare mock would produce nothing to assert on.
		$connection->method( 'getDatabasePlatform' )->willReturn( new MariaDBPlatform() );
		$connection->method( 'createQueryBuilder' )->willReturnCallback(
			static fn (): QueryBuilder => new QueryBuilder( $connection )
		);
		$connection->method( 'executeQuery' )->willReturnCallback(
			function ( string $sql, array $params = [], array $types = [] )
				use ( $repo, $responses ): Result {
				$kind = self::classify( $sql );
				$repo->seen[$kind][] = [ 'sql' => $sql, 'params' => $params ];
				return $this->resultFor( $kind, $responses[$kind] ?? null );
			}
		);
		$repo->testConnection = $connection;
		return $repo;
	}

	/**
	 * Canned Result for one kind of statement, fetched the way the repository fetches it.
	 * @param string $kind
	 * @param mixed $canned
	 * @return Result
	 */
	private function resultFor( string $kind, mixed $canned ): Result {
		$result = $this->createMock( Result::class );
		switch ( $kind ) {
			case 'linktarget':
				$result->method( 'fetchAllKeyValue' )->willReturn( $canned ?? [] );
				break;
			case 'userProbe':
			case 'categoryProbe':
				$result->method( 'fetchOne' )->willReturn( (string)( $canned ?? 0 ) );
				break;
			case 'membership':
				// Read one row at a time, so the row ceiling can be checked as it goes.
				$queue = array_map(
					static fn ( array $pair ): array => [ 'cl_target_id' => $pair[0], 'cl_from' => $pair[1] ],
					$canned ?? []
				);
				// By reference: an arrow function would capture $queue by value and shift a
				// fresh copy every call, so the loop would never end.
				$result->method( 'fetchAssociative' )->willReturnCallback(
					static function () use ( &$queue ) {
						return array_shift( $queue ) ?? false;
					}
				);
				break;
			case 'userCounts':
				$result->method( 'fetchAllKeyValue' )->willReturn( $canned ?? [] );
				break;
			default:
				$result->method( 'fetchAllAssociative' )->willReturn( $canned ?? [] );
		}
		return $result;
	}
}
