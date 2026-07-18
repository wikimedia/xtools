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
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * CategoryEditsRepository is SQL-assembly plus light row post-processing over the replica. The one branch
 * that matters for coverage is the isIpRange() branch shared by all three public queries: a named account
 * filters on rev_actor and binds the actor ID, an IP range joins ip_changes and binds the hex bounds of
 * the parsed CIDR. Unlike EditCounterRepository, the seam here isn't executeProjectsQuery(); every query
 * goes through getProjectsConnection( $project )->executeQuery(...). So the test subclass overrides
 * getProjectsConnection() to hand back a mock Connection that records the SQL/params of the main query
 * and returns a canned Result.
 *
 * Static-cache gotcha: getCategoryTargetIds() memoises the linktarget map in a `static $result`, which
 * PHP binds to the (single, shared) anonymous-class definition — it survives across instances and across
 * test methods in this file. We defuse it by having every mock Connection answer the `lt_title` lookup
 * with the SAME map, so whichever test populates the static first, the cached value is correct for all of
 * them. The connection routes by SQL substring: the linktarget query gets the map, the main query gets the
 * recorded canned Result.
 * @covers \App\Repository\CategoryEditsRepository
 */
class CategoryEditsRepositoryTest extends TestCase {

	/**
	 * The shared linktarget map every mock Connection returns for the lt_title lookup. fetchAllKeyValue()
	 * keys on the first selected column (lt_id) with the second (lt_title) as value, so it comes back keyed
	 * lt_id => lt_title. array_keys() of this is the list of category link IDs the queries bind.
	 */
	private const LINKTARGET_MAP = [ 10 => 'Living_people', 20 => 'American_writers' ];

	/**
	 * For a named account, countCategoryEdits() filters on rev_actor and binds the actor ID plus the
	 * category link IDs; the scalar count is cast to int.
	 */
	public function testCountCategoryEditsUsesActorFilterForActor(): void {
		$repo = $this->makeRepository( $this->scalarResult( '42' ) );
		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( 42, $count );
		static::assertStringContainsString( 'revs.rev_actor = ?', $repo->lastSql );
		static::assertStringNotContainsString( 'ipc_hex', $repo->lastSql );
		static::assertSame( [ 1, [ 10, 20 ] ], $repo->lastParams );
	}

	/**
	 * An IP range has no actor ID, so countCategoryEdits() joins ip_changes and binds the parsed hex
	 * range ahead of the category link IDs.
	 */
	public function testCountCategoryEditsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository( $this->scalarResult( '3' ) );
		$count = $repo->countCategoryEdits( $this->makeProject(), $this->makeIpRangeUser(), [ 'Living people' ] );

		static::assertSame( 3, $count );
		static::assertStringContainsString( 'ipc_hex BETWEEN ? AND ?', $repo->lastSql );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`ip_changes`', $repo->lastSql );
		[ $hexStart, $hexEnd ] = \Wikimedia\IPUtils::parseRange( '10.0.0.0/24' );
		static::assertSame( [ $hexStart, $hexEnd, [ 10, 20 ] ], $repo->lastParams );
	}

	/**
	 * getCategoryCounts() maps each row's cl_target_id back to its category name via the linktarget map
	 * and casts the edit/page counts to int.
	 */
	public function testGetCategoryCountsMapsTargetIdsAndCastsCounts(): void {
		$rows = [
			[ 'cl_target_id' => 10, 'edit_count' => '5', 'page_count' => '3' ],
			[ 'cl_target_id' => 20, 'edit_count' => '2', 'page_count' => '2' ],
		];
		$repo = $this->makeRepository( $this->assocResult( $rows ) );
		$counts = $repo->getCategoryCounts( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( [ 'editCount' => 5, 'pageCount' => 3 ], $counts['Living_people'] );
		static::assertSame( [ 'editCount' => 2, 'pageCount' => 2 ], $counts['American_writers'] );
		static::assertStringContainsString( 'revs.rev_actor = ?', $repo->lastSql );
	}

	/**
	 * The IP-range branch of getCategoryCounts() takes the ip_changes join and hex-range params.
	 */
	public function testGetCategoryCountsUsesIpChangesJoinForRange(): void {
		$rows = [ [ 'cl_target_id' => 10, 'edit_count' => '1', 'page_count' => '1' ] ];
		$repo = $this->makeRepository( $this->assocResult( $rows ) );
		$counts = $repo->getCategoryCounts( $this->makeProject(), $this->makeIpRangeUser(), [ 'Living people' ] );

		static::assertSame( [ 'editCount' => 1, 'pageCount' => 1 ], $counts['Living_people'] );
		static::assertStringContainsString( 'ipc_hex BETWEEN ? AND ?', $repo->lastSql );
		[ $hexStart, $hexEnd ] = \Wikimedia\IPUtils::parseRange( '10.0.0.0/24' );
		static::assertSame( [ $hexStart, $hexEnd, [ 10, 20 ] ], $repo->lastParams );
	}

	/**
	 * getCategoryEdits() returns the fetched rows untouched; for a named account it filters on rev_actor.
	 */
	public function testGetCategoryEditsReturnsRowsForActor(): void {
		$rows = [ [ 'page_title' => 'Foo', 'namespace' => 0, 'rev_id' => 1 ] ];
		$repo = $this->makeRepository( $this->assocResult( $rows ) );
		$edits = $repo->getCategoryEdits( $this->makeProject(), $this->makeUser(), [ 'Living people' ] );

		static::assertSame( $rows, $edits );
		static::assertStringContainsString( 'revs.rev_actor = ?', $repo->lastSql );
		static::assertSame( [ 1, [ 10, 20 ] ], $repo->lastParams );
	}

	/**
	 * The IP-range branch of getCategoryEdits() joins ip_changes and binds the hex bounds.
	 */
	public function testGetCategoryEditsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository( $this->assocResult( [] ) );
		$repo->getCategoryEdits( $this->makeProject(), $this->makeIpRangeUser(), [ 'Living people' ] );

		static::assertStringContainsString( 'ipc_hex BETWEEN ? AND ?', $repo->lastSql );
		[ $hexStart, $hexEnd ] = \Wikimedia\IPUtils::parseRange( '10.0.0.0/24' );
		static::assertSame( [ $hexStart, $hexEnd, [ 10, 20 ] ], $repo->lastParams );
	}

	/**
	 * Each public method caches its result via setCache() and short-circuits on a second call. The
	 * mainQueryCount guard is what makes this load-bearing: with a real ArrayAdapter behind the cache
	 * the second call must return the cached value without a second query, so the count stays at 1.
	 * Drop the cache-hit branch and the repeat call re-queries, tripping the count assertion.
	 */
	public function testResultsAreServedFromCacheOnSecondCall(): void {
		$project = $this->makeProject();
		$user = $this->makeUser();
		$categories = [ 'Living people' ];

		$countRepo = $this->makeRepository( $this->scalarResult( '42' ) );
		static::assertSame( 42, $countRepo->countCategoryEdits( $project, $user, $categories ) );
		static::assertSame( 42, $countRepo->countCategoryEdits( $project, $user, $categories ) );
		static::assertSame( 1, $countRepo->mainQueryCount );

		$countsRepo = $this->makeRepository(
			$this->assocResult( [ [ 'cl_target_id' => 10, 'edit_count' => '5', 'page_count' => '3' ] ] )
		);
		$first = $countsRepo->getCategoryCounts( $project, $user, $categories );
		static::assertSame( $first, $countsRepo->getCategoryCounts( $project, $user, $categories ) );
		static::assertSame( 1, $countsRepo->mainQueryCount );

		$editsRepo = $this->makeRepository( $this->assocResult( [ [ 'page_title' => 'Foo' ] ] ) );
		$firstEdits = $editsRepo->getCategoryEdits( $project, $user, $categories );
		static::assertSame( $firstEdits, $editsRepo->getCategoryEdits( $project, $user, $categories ) );
		static::assertSame( 1, $editsRepo->mainQueryCount );
	}

	/**
	 * getEditsFromRevs() is a thin delegator to Edit::getEditsFromRevs(); with no revs the static maps
	 * over an empty array and returns [] without touching the injected repositories.
	 */
	public function testGetEditsFromRevsReturnsEmptyForNoRevs(): void {
		$repo = $this->makeRepository( $this->assocResult( [] ) );
		static::assertSame( [], $repo->getEditsFromRevs( $this->makeProject(), $this->makeUser(), [] ) );
	}

	/**
	 * A Project stubbed only as far as the query builders reach: getTableName() feeds the SQL, and
	 * getCacheKey() keeps the cache wiring happy.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'en.wikipedia.org' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A named account: isIpRange() is false so the actor-based paths run, and getActorId() resolves so
	 * param binding has something to bind.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A User representing an IP range. getUsername() returns a real CIDR so IPUtils::parseRange() yields
	 * the hex bounds the ip_changes queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * A Doctrine Result whose fetchOne() returns the given scalar, for countCategoryEdits().
	 */
	private function scalarResult( mixed $value ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( $value );
		return $result;
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAllAssociative(), and from fetchAssociative()
	 * one at a time (then false) for the getCategoryCounts() while-loop.
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
	 * A CategoryEditsRepository whose getProjectsConnection() returns a mock Connection. The connection
	 * answers the linktarget lookup (lt_title) with the shared category map — always the same value, so
	 * getCategoryTargetIds()'s cross-test static cache stays correct — and answers the main query by
	 * recording its SQL/params and returning $mainResult. A real ArrayAdapter backs the cache so
	 * getCacheKey()/setCache() behave without infra.
	 */
	private function makeRepository( Result $mainResult ): CategoryEditsRepository {
		$linktargetResult = $this->createMock( Result::class );
		$linktargetResult->method( 'fetchAllKeyValue' )->willReturn( self::LINKTARGET_MAP );

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$this->createMock( AutomatedEditsHelper::class ),
			$this->createMock( EditRepository::class ),
			$this->createMock( PageRepository::class ),
			$this->createMock( UserRepository::class )
		) extends CategoryEditsRepository {

			/** @var Connection The mock Connection every getProjectsConnection() call returns. */
			public Connection $testConnection;

			/** @var string The SQL built by the last main query. */
			public string $lastSql = '';

			/** @var array The params bound by the last main query. */
			public array $lastParams = [];

			/** @var int Number of main (non-linktarget) queries the connection has run. */
			public int $mainQueryCount = 0;

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->testConnection;
			}
		};

		// The mock Connection routes by SQL substring: the lt_title lookup gets the shared linktarget
		// map, the main query is recorded and answered with $mainResult.
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willReturnCallback(
			static function ( string $sql, array $params = [], array $types = [] )
				use ( $repo, $linktargetResult, $mainResult ): Result {
				if ( str_contains( $sql, 'lt_title' ) ) {
					return $linktargetResult;
				}
				$repo->mainQueryCount++;
				$repo->lastSql = $sql;
				$repo->lastParams = $params;
				return $mainResult;
			}
		);
		$repo->testConnection = $connection;
		return $repo;
	}
}
