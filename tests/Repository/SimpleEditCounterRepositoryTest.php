<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\SimpleEditCounterRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * SimpleEditCounterRepository is pure SQL assembly: fetchData() picks between a named-account query
 * (fetchDataNormal) and an IP-range query (fetchDataIpRange) on User::isIpRange(), and each of those
 * folds the namespace filter in or out depending on whether a specific namespace was requested. No
 * database is touched: a test subclass overrides the executeProjectsQuery() seam to record the built
 * SQL and params and hand back a canned Result, so the branch logic is asserted in isolation. A real
 * ArrayAdapter backs the cache so getCacheKey()/setCache() (and the cache round-trip) behave as in
 * production.
 * @covers \App\Repository\SimpleEditCounterRepository
 */
class SimpleEditCounterRepositoryTest extends TestCase {

	/**
	 * A named account has an actor ID, so fetchData() runs fetchDataNormal(): the query filters on
	 * user_name/actor and UNIONs the archive, revision and user_groups selects.
	 */
	public function testFetchDataUsesActorQueryForNamedUser(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [ [ 'source' => 'rev', 'value' => '5;2' ] ] );

		$rows = $repo->fetchData( $this->makeProject(), $this->makeUser() );
		static::assertSame( [ [ 'source' => 'rev', 'value' => '5;2' ] ], $rows );
		static::assertStringContainsString( 'user_name = :username', $repo->lastSql );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertStringContainsString( 'user_groups', $repo->lastSql );
		static::assertArrayHasKey( 'username', $repo->lastParams );
		static::assertArrayHasKey( 'actorId', $repo->lastParams );
	}

	/**
	 * An IP range has no actor ID, so fetchData() runs fetchDataIpRange(): the query switches to
	 * ip_changes over the hex range and drops the actor/archive/user_groups clauses.
	 */
	public function testFetchDataUsesIpChangesQueryForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [ [ 'source' => 'rev', 'value' => '3' ] ] );

		$repo->fetchData( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ip_changes', $repo->lastSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :start AND :end', $repo->lastSql );
		static::assertStringNotContainsString( ':actorId', $repo->lastSql );
		static::assertStringNotContainsString( 'user_groups', $repo->lastSql );
		static::assertArrayHasKey( 'start', $repo->lastParams );
		static::assertArrayHasKey( 'end', $repo->lastParams );
	}

	/**
	 * The hex bounds bound for an IP range come from IPUtils::parseRange() over the CIDR, so
	 * 10.0.0.0/24 yields the /24's start and end hex values.
	 */
	public function testFetchDataBindsHexBoundsFromCidr(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertSame( '0A000000', $repo->lastParams['start'] );
		static::assertSame( '0A0000FF', $repo->lastParams['end'] );
	}

	/**
	 * With namespace 'all' the named-account query carries no namespace filter (the page JOIN is still
	 * always present per T325492, but no page_namespace/ar_namespace WHERE is added).
	 */
	public function testFetchDataNormalOmitsNamespaceFilterForAll(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeUser(), 'all' );
		static::assertStringNotContainsString( 'page_namespace =', $repo->lastSql );
		static::assertStringNotContainsString( 'ar_namespace =', $repo->lastSql );
	}

	/**
	 * A specific namespace adds the page_namespace and ar_namespace WHERE clauses to the named-account
	 * query.
	 */
	public function testFetchDataNormalAddsNamespaceFilterForSpecificNamespace(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeUser(), 3 );
		static::assertStringContainsString( 'page_namespace = 3', $repo->lastSql );
		static::assertStringContainsString( 'ar_namespace = 3', $repo->lastSql );
	}

	/**
	 * For an IP range, namespace 'all' skips the revision/page JOIN entirely (ip_changes alone answers
	 * the count) and adds no namespace WHERE.
	 */
	public function testFetchDataIpRangeOmitsJoinForAll(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeIpRangeUser(), 'all' );
		static::assertStringNotContainsString( 'JOIN', $repo->lastSql );
		static::assertStringNotContainsString( 'page_namespace =', $repo->lastSql );
	}

	/**
	 * A specific namespace forces the IP-range query to JOIN through revision and page so it can filter
	 * on page_namespace.
	 */
	public function testFetchDataIpRangeAddsJoinForSpecificNamespace(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeIpRangeUser(), 3 );
		static::assertStringContainsString( 'JOIN', $repo->lastSql );
		static::assertStringContainsString( 'rev_id = ipc_rev_id', $repo->lastSql );
		static::assertStringContainsString( 'page_namespace = 3', $repo->lastSql );
	}

	/**
	 * A second call with identical args returns the cached result and never re-enters the query seam,
	 * so the ArrayAdapter-backed getCacheKey()/setCache() round-trip is exercised.
	 */
	public function testFetchDataReturnsCachedResultOnSecondCall(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [ [ 'source' => 'rev', 'value' => '5;2' ] ] );
		$project = $this->makeProject();
		$user = $this->makeUser();

		$first = $repo->fetchData( $project, $user );
		static::assertSame( 1, $repo->queryCount );

		// Swap the canned result so a cache miss would surface as different rows.
		$repo->cannedResult = $this->assocResult( [ [ 'source' => 'rev', 'value' => '999' ] ] );
		$second = $repo->fetchData( $project, $user );
		static::assertSame( $first, $second );
		static::assertSame( 1, $repo->queryCount );
	}

	/**
	 * A Project stubbed with the seams the query builders touch: getTableName() feeds the SQL and
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
	 * A non-anon named User: isIpRange() is false so the actor query path runs, and getActorId()
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
	 * yields the start/end hex bounds the ip_changes query binds.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * A Doctrine Result whose fetchAllAssociative() returns the given rows.
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		return $result;
	}

	/**
	 * The test repository: executeProjectsQuery() records the built SQL and params (so branch tests can
	 * assert on the generated query), counts its invocations (so the cache round-trip can prove the
	 * second call didn't re-query), and returns the canned Result. A real ArrayAdapter backs the cache.
	 */
	private function makeRepository(): SimpleEditCounterRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30
		) extends SimpleEditCounterRepository {

			/** @var ?Result The canned Result handed back by executeProjectsQuery(). */
			public ?Result $cannedResult = null;

			/** @var string The SQL built by the last executeProjectsQuery() call. */
			public string $lastSql = '';

			/** @var array The params bound by the last executeProjectsQuery() call. */
			public array $lastParams = [];

			/** @var int Number of times the query seam was entered, for the cache round-trip assertion. */
			public int $queryCount = 0;

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$this->queryCount++;
				$this->lastSql = $sql;
				$this->lastParams = $params;
				return $this->cannedResult;
			}
		};
	}
}
