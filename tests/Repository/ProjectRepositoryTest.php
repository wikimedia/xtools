<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Repository\PageAssessmentsRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branch in ProjectRepository::getUsersInGroups(): the local user-groups query
 * always runs, but the CentralAuth global-groups query is only unioned in on WMF installs, and
 * only when global groups were actually asked for. isWMF is a constructor argument, so injecting
 * it both ways covers both branches in one run. The queries themselves aren't exercised: a test
 * subclass overrides getProjectsConnection() (the narrowest I/O seam) to hand back a connection
 * whose executeQuery() returns canned rows, dispatching on the project argument so the local vs.
 * centralauth_p paths return distinct rows. The real cache round-trips through an ArrayAdapter so
 * the hasItem/setCache bookkeeping behaves like production without a backing store.
 * @covers \App\Repository\ProjectRepository
 */
class ProjectRepositoryTest extends TestCase {

	/**
	 * With global groups requested on WMF, the global (CentralAuth) rows are merged into the local
	 * results; off-WMF, or with no global groups, only the local rows come back. This is the branch
	 * that keeps third-party installs from querying a centralauth_p database that doesn't exist.
	 */
	public function testGetUsersInGroupsMergesGlobalGroupsOnlyOnWmf(): void {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturn( 'enwiki_p.user_groups' );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );

		$localRows = [ [ 'user_name' => 'LocalAdmin', 'user_group' => 'sysop' ] ];
		$globalRows = [ [ 'user_name' => 'GlobalSteward', 'user_group' => 'steward' ] ];

		$wmf = $this->makeRepository( true, $localRows, $globalRows );
		$merged = $wmf->getUsersInGroups( $project, [ 'sysop' ], [ 'steward' ] );
		static::assertContains( [ 'user_name' => 'LocalAdmin', 'user_group' => 'sysop' ], $merged );
		static::assertContains( [ 'user_name' => 'GlobalSteward', 'user_group' => 'steward' ], $merged );

		$thirdParty = $this->makeRepository( false, $localRows, $globalRows );
		$localOnly = $thirdParty->getUsersInGroups( $project, [ 'sysop' ], [ 'steward' ] );
		static::assertContains( [ 'user_name' => 'LocalAdmin', 'user_group' => 'sysop' ], $localOnly );
		static::assertNotContains( [ 'user_name' => 'GlobalSteward', 'user_group' => 'steward' ], $localOnly );
	}

	/**
	 * Even on WMF, an empty global-groups list means no CentralAuth query and no global rows: the
	 * count() guard short-circuits before the isWMF check ever matters.
	 */
	public function testGetUsersInGroupsSkipsGlobalQueryWithNoGlobalGroups(): void {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturn( 'enwiki_p.user_groups' );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );

		$localRows = [ [ 'user_name' => 'LocalAdmin', 'user_group' => 'sysop' ] ];
		$globalRows = [ [ 'user_name' => 'GlobalSteward', 'user_group' => 'steward' ] ];

		$wmf = $this->makeRepository( true, $localRows, $globalRows );
		$result = $wmf->getUsersInGroups( $project, [ 'sysop' ], [] );
		static::assertSame( $localRows, $result );
	}

	/**
	 * On WMF the caller may pass a database name with a trailing _p (the replica-view suffix), which
	 * getOne() strips before the meta.wiki lookup and re-appends to the dbName it returns, so callers
	 * consistently get the _p form back. Off-WMF neither the strip nor the append happens: the
	 * database name is used and returned verbatim.
	 */
	public function testGetOneHandlesReplicaSuffixOnlyOnWmf(): void {
		$row = [ 'dbName' => 'enwiki', 'url' => 'https://en.wikipedia.org', 'lang' => 'en' ];

		$wmf = $this->makeMetaRepository( true, $row );
		$resolved = $wmf->getOne( 'enwiki_p' );
		static::assertSame( 'enwiki_p', $resolved['dbName'] );

		$thirdParty = $this->makeMetaRepository( false, $row );
		$resolvedOffWmf = $thirdParty->getOne( 'enwiki' );
		static::assertSame( 'enwiki', $resolvedOffWmf['dbName'] );
	}

	/**
	 * A ProjectRepository whose executeProjectsQuery() returns the given meta.wiki row instead of
	 * hitting a replica, and whose parameter bag answers the meta database/table lookups getOne()
	 * needs to build its query. The _p strip/append logic around the query runs for real.
	 * @param bool $isWMF
	 * @param array<string, mixed> $row
	 */
	private function makeMetaRepository( bool $isWMF, array $row ): ProjectRepository {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( $row );

		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'get' )->willReturnMap( [
			[ 'database_meta_name', 'meta' ],
			[ 'database_meta_table', 'wiki' ],
		] );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag,
			$isWMF,
			30,
			$this->createMock( PageAssessmentsRepository::class ),
			'en.wikipedia.org',
			false,
			[],
			'/w/api.php',
			$result
		) extends ProjectRepository {
			private Result $cannedResult;

			public function __construct(
				ManagerRegistry $managerRegistry,
				$cache,
				Client $guzzle,
				$logger,
				ParameterBagInterface $parameterBag,
				bool $isWMF,
				int $queryTimeout,
				PageAssessmentsRepository $assessmentsRepo,
				string $defaultProject,
				bool $singleWiki,
				array $optedIn,
				string $apiPath,
				Result $cannedResult
			) {
				parent::__construct(
					$managerRegistry, $cache, $guzzle, $logger, $parameterBag, $isWMF, $queryTimeout,
					$assessmentsRepo, $defaultProject, $singleWiki, $optedIn, $apiPath
				);
				$this->cannedResult = $cannedResult;
			}

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				return $this->cannedResult;
			}
		};
	}

	/**
	 * A ProjectRepository whose getProjectsConnection() returns a stub connection instead of a real
	 * replica: the connection's executeQuery() hands back the global rows when asked for the string
	 * 'centralauth_p' project and the local rows otherwise, so the method-under-test runs its real
	 * query-and-merge logic without touching a database.
	 * @param bool $isWMF
	 * @param array<array<string, mixed>> $localRows
	 * @param array<array<string, mixed>> $globalRows
	 */
	private function makeRepository( bool $isWMF, array $localRows, array $globalRows ): ProjectRepository {
		$localConnection = $this->makeConnection( $localRows );
		$globalConnection = $this->makeConnection( $globalRows );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( PageAssessmentsRepository::class ),
			'en.wikipedia.org',
			false,
			[],
			'/w/api.php',
			$localConnection,
			$globalConnection
		) extends ProjectRepository {
			private Connection $localConnection;
			private Connection $globalConnection;

			public function __construct(
				ManagerRegistry $managerRegistry,
				$cache,
				Client $guzzle,
				$logger,
				ParameterBagInterface $parameterBag,
				bool $isWMF,
				int $queryTimeout,
				PageAssessmentsRepository $assessmentsRepo,
				string $defaultProject,
				bool $singleWiki,
				array $optedIn,
				string $apiPath,
				Connection $localConnection,
				Connection $globalConnection
			) {
				parent::__construct(
					$managerRegistry, $cache, $guzzle, $logger, $parameterBag, $isWMF, $queryTimeout,
					$assessmentsRepo, $defaultProject, $singleWiki, $optedIn, $apiPath
				);
				$this->localConnection = $localConnection;
				$this->globalConnection = $globalConnection;
			}

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $project === 'centralauth_p' ? $this->globalConnection : $this->localConnection;
			}
		};
	}

	/**
	 * A Doctrine Connection whose executeQuery() always returns a Result yielding the given rows.
	 * @param array<array<string, mixed>> $rows
	 */
	private function makeConnection( array $rows ): Connection {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );

		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willReturn( $result );
		return $connection;
	}
}
