<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\EditSummaryRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * EditSummaryRepository is pure SQL assembly plus one loop that runs each returned row through the
 * EditSummary model's tally callback. getRevisions() has three branches worth pinning: namespace='all'
 * vs a concrete namespace (which stitches in a page join and a page_namespace filter), the IP-range
 * branch onto an ip_changes hex-range join, and the optional date bounds from getDateConditions(). None
 * of it touches a replica: a test subclass overrides the executeQuery() seam to record the built SQL
 * and params and hand back a canned Result, so the branches are asserted against the generated query.
 * prepareData() is exercised with a canned multi-row Result and a real callback to confirm it feeds
 * every row through and returns the callback's final tally. A real ArrayAdapter backs the cache so
 * getCacheKey()/setCache() behave without any infra.
 * @covers \App\Repository\EditSummaryRepository
 */
class EditSummaryRepositoryTest extends TestCase {

	/**
	 * getDateConditions() formats bounds with date(), which reads the ambient timezone. Pin it to UTC
	 * so the exact-timestamp assertions below are deterministic on any machine, the same way the
	 * project's ControllerTestAdapter does for its kernel tests.
	 */
	protected function setUp(): void {
		date_default_timezone_set( 'UTC' );
	}

	/**
	 * With namespace='all' the query spans every namespace: no page join and no page_namespace filter,
	 * and the actor filter (not an ip_changes join) drives it.
	 */
	public function testGetRevisionsAllNamespacesOmitsPageJoinAndNamespaceFilter(): void {
		$repo = $this->makeRepository();
		$repo->getRevisions( $this->makeProject(), $this->makeUser(), 'all' );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->capturedSql );
		static::assertStringNotContainsString( 'page_namespace = :namespace', $repo->capturedSql );
		static::assertStringNotContainsString( 'JOIN `enwiki_p`.`page`', $repo->capturedSql );
		static::assertSame( 'all', $repo->capturedNamespace );
	}

	/**
	 * A concrete namespace stitches in the page join and the page_namespace filter, and the namespace
	 * is passed through to the seam for param binding.
	 */
	public function testGetRevisionsConcreteNamespaceAddsPageJoinAndFilter(): void {
		$repo = $this->makeRepository();
		$repo->getRevisions( $this->makeProject(), $this->makeUser(), 3 );
		static::assertStringContainsString( 'AND page_namespace = :namespace', $repo->capturedSql );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`page` ON rev_page = page_id', $repo->capturedSql );
		static::assertSame( 3, $repo->capturedNamespace );
	}

	/**
	 * An IP range has no actor ID, so the query switches to an ip_changes join filtered on the hex range,
	 * and the start/end bounds are bound as extra params rather than an actor.
	 */
	public function testGetRevisionsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository();
		$repo->getRevisions( $this->makeProject(), $this->makeIpRangeUser(), 'all' );

		static::assertStringContainsString( 'JOIN `enwiki_p`.`ip_changes` ON rev_id = ipc_rev_id', $repo->capturedSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertStringNotContainsString( 'rev_actor = :actorId', $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
		static::assertArrayHasKey( 'endIp', $repo->capturedParams );
	}

	/**
	 * Passing start and end timestamps threads the bounds through getDateConditions(), which appends
	 * rev_timestamp >= / <= clauses to the generated SQL.
	 */
	public function testGetRevisionsAppliesDateBounds(): void {
		$repo = $this->makeRepository();
		// 2020-01-01 and 2020-12-31 as Unix timestamps.
		$repo->getRevisions( $this->makeProject(), $this->makeUser(), 'all', 1577836800, 1609372800 );
		static::assertStringContainsString( "rev_timestamp >= '20200101000000'", $repo->capturedSql );
		static::assertStringContainsString( "rev_timestamp <= '20201231235959'", $repo->capturedSql );
	}

	/**
	 * Without date bounds the generated SQL carries no rev_timestamp range clause.
	 */
	public function testGetRevisionsOmitsDateBoundsByDefault(): void {
		$repo = $this->makeRepository();
		$repo->getRevisions( $this->makeProject(), $this->makeUser(), 'all' );
		static::assertStringNotContainsString( "rev_timestamp >=", $repo->capturedSql );
		static::assertStringNotContainsString( "rev_timestamp <=", $repo->capturedSql );
	}

	/**
	 * prepareData() feeds every returned row through the callback and returns its final accumulated
	 * tally; here the callback counts rows with a non-empty comment against those without.
	 */
	public function testPrepareDataRunsEveryRowThroughCallback(): void {
		$rows = [
			[ 'comment' => 'fixed a typo', 'rev_timestamp' => '20200101000000', 'rev_minor_edit' => '0' ],
			[ 'comment' => '', 'rev_timestamp' => '20200102000000', 'rev_minor_edit' => '0' ],
			[ 'comment' => 'reverted vandalism', 'rev_timestamp' => '20200103000000', 'rev_minor_edit' => '1' ],
		];
		$repo = $this->makeRepository( $this->assocResult( $rows ) );

		$tally = [ 'withComment' => 0, 'withoutComment' => 0 ];
		$processRow = static function ( array $row ) use ( &$tally ): array {
			if ( $row['comment'] !== '' ) {
				$tally['withComment']++;
			} else {
				$tally['withoutComment']++;
			}
			return $tally;
		};

		$data = $repo->prepareData( $processRow, $this->makeProject(), $this->makeUser(), 'all' );
		static::assertSame( 2, $data['withComment'] );
		static::assertSame( 1, $data['withoutComment'] );
	}

	/**
	 * With no rows the callback never fires, so prepareData() returns the empty seed and caches it.
	 */
	public function testPrepareDataReturnsEmptyWhenNoRows(): void {
		$repo = $this->makeRepository( $this->assocResult( [] ) );

		$processRow = static function ( array $row ): array {
			return [ 'seen' => true ];
		};

		$data = $repo->prepareData( $processRow, $this->makeProject(), $this->makeUser(), 'all' );
		static::assertSame( [], $data );
	}

	/**
	 * A Project stubbed only as far as getRevisions()/prepareData() reach: getTableName() feeds the SQL
	 * builder and getCacheKey() keeps prepareData()'s cache key assembly happy.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A named User: isIpRange() is false so the actor-based query path runs, and getCacheKey() feeds
	 * prepareData()'s cache key. getActorId() is never reached because executeQuery() is overridden.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getCacheKey' )->willReturn( 'Jimbo' );
		return $user;
	}

	/**
	 * A User representing an IP range. getUsername() returns a real CIDR so IPUtils::parseRange() yields
	 * the start/end hex bounds the ip_changes branch binds.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		$user->method( 'getCacheKey' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAssociative() one at a time (then false),
	 * matching prepareData()'s while() loop.
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$queue = $rows;
		$result->method( 'fetchAssociative' )->willReturnCallback(
			static function () use ( &$queue ) {
				return array_shift( $queue ) ?? false;
			}
		);
		return $result;
	}

	/**
	 * An EditSummaryRepository whose executeQuery() records the generated SQL, the resolved namespace,
	 * and the extra params, then returns a canned Result instead of hitting a replica. A real
	 * ArrayAdapter backs the cache so getCacheKey()/setCache() behave without any infra.
	 */
	private function makeRepository( ?Result $cannedResult = null ): EditSummaryRepository {
		$result = $cannedResult ?? $this->createMock( Result::class );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$this->createMock( ProjectRepository::class ),
			null,
			$result
		) extends EditSummaryRepository {
			/** @var string The SQL built by the last getRevisions() call. */
			public string $capturedSql = '';

			/** @var int|string|null The namespace passed to the last executeQuery() call. */
			public int|string|null $capturedNamespace = null;

			/** @var array The extra params bound by the last executeQuery() call. */
			public array $capturedParams = [];

			private Result $cannedResult;

			public function __construct(
				ManagerRegistry $managerRegistry,
				\Psr\Cache\CacheItemPoolInterface $cache,
				Client $guzzle,
				NullLogger $logger,
				ParameterBagInterface $parameterBag,
				bool $isWMF,
				int $queryTimeout,
				ProjectRepository $projectRepo,
				$requestStack,
				Result $cannedResult
			) {
				parent::__construct(
					$managerRegistry, $cache, $guzzle, $logger, $parameterBag,
					$isWMF, $queryTimeout, $projectRepo, $requestStack
				);
				$this->cannedResult = $cannedResult;
			}

			protected function executeQuery(
				string $sql,
				Project $project,
				User $user,
				int|string|null $namespace = 'all',
				array $extraParams = []
			): Result {
				$this->capturedSql = $sql;
				$this->capturedNamespace = $namespace;
				$this->capturedParams = $extraParams;
				return $this->cannedResult;
			}
		};
	}
}
