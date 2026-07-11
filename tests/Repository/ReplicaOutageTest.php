<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Repository\Repository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The connection-layer resilience: connect errors translated to a friendly 503, the per-slice
 * fail-fast breaker, and how getDbList() assembles the project->slice map from live probes
 * (skipping down or empty slices, caching only what it found). Repository is abstract, so these
 * drive it through a bare concrete subclass; the DB connection is mocked and the cache is a real
 * in-memory ArrayAdapter so the breaker's trip and the dblist cache genuinely round-trip.
 * @covers \App\Repository\Repository
 */
class ReplicaOutageTest extends TestCase {
	use ReplicaOutageTestTrait;

	protected function setUp(): void {
		$this->primeReplicaCache();
	}

	/**
	 * A connect failure to a replica is translated to a friendly 503, and the other driver
	 * errors keep their existing 503/504 mappings.
	 * @dataProvider driverErrorProvider
	 */
	public function testDriverErrorsAreTranslated(
		int $code,
		int $status,
		string $message,
		bool $isUnavailable
	): void {
		$repo = $this->makeRepository( $this->registryThrowing( $code ) );

		try {
			$repo->executeProjectsQuery( 'enwiki', 'SELECT 1' );
			$this->fail( 'Expected an HttpException to be thrown.' );
		} catch ( HttpException $e ) {
			static::assertSame( $status, $e->getStatusCode() );
			static::assertSame( $message, $e->getMessage() );
			static::assertSame( $isUnavailable, $e instanceof ServiceUnavailableHttpException );
		}
	}

	/**
	 * @return array<string, array{int, int, string, bool}>
	 */
	public static function driverErrorProvider(): array {
		return [
			// code, HTTP status, i18n message key, is-a-ServiceUnavailableHttpException
			'connection refused (2002)' => [ 2002, 503, 'error-replica-unavailable', true ],
			'cannot connect to host (2003)' => [ 2003, 503, 'error-replica-unavailable', true ],
			'unknown host (2005)' => [ 2005, 503, 'error-replica-unavailable', true ],
			'too many connections (1040)' => [ 1040, 503, 'error-service-overload', true ],
			'too many user connections (1203)' => [ 1203, 503, 'error-service-overload', true ],
			'resource overload (1226)' => [ 1226, 503, 'error-service-overload', true ],
			'lock wait timeout (1205)' => [ 1205, 503, 'error-lock-contention', true ],
			'server gone away (2006)' => [ 2006, 504, 'error-lost-connection', false ],
			'lost connection (2013)' => [ 2013, 504, 'error-lost-connection', false ],
			'query timeout (1969)' => [ 1969, 504, 'error-query-timeout', false ],
		];
	}

	/**
	 * An unrecognised driver error is not swallowed: the original exception propagates.
	 */
	public function testUnknownDriverErrorIsRethrown(): void {
		$repo = $this->makeRepository( $this->registryThrowing( 1146 ) );

		try {
			$repo->executeProjectsQuery( 'enwiki', 'SELECT 1' );
			$this->fail( 'Expected the DriverException to propagate.' );
		} catch ( DriverException $e ) {
			static::assertSame( 1146, $e->getCode() );
		}
	}

	/**
	 * A connect failure trips the breaker for that slice; an ordinary query error does not.
	 */
	public function testConnectFailureTripsTheBreaker(): void {
		$repo = $this->makeRepository( $this->registryThrowing( 2002 ) );

		$this->runExpectingHttpException( static fn () => $repo->executeProjectsQuery( 'enwiki', 'SELECT 1' ) );

		static::assertTrue( $this->cache->hasItem( 'replica-breaker.s1' ) );
	}

	public function testNonConnectErrorDoesNotTripTheBreaker(): void {
		$repo = $this->makeRepository( $this->registryThrowing( 1969 ) );

		$this->runExpectingHttpException( static fn () => $repo->executeProjectsQuery( 'enwiki', 'SELECT 1' ) );

		static::assertFalse( $this->cache->hasItem( 'replica-breaker.s1' ) );
	}

	/**
	 * An overload rejection sheds the one request as a 503 but must not trip the slice breaker:
	 * the server is up, so fast-failing the whole slice for 30s would over-shed a busy replica.
	 */
	public function testOverloadErrorDoesNotTripTheBreaker(): void {
		$repo = $this->makeRepository( $this->registryThrowing( 1203 ) );

		$this->runExpectingHttpException( static fn () => $repo->executeProjectsQuery( 'enwiki', 'SELECT 1' ) );

		static::assertFalse( $this->cache->hasItem( 'replica-breaker.s1' ) );
	}

	/**
	 * Once a slice's breaker is tripped, the next request fails fast without asking the
	 * registry for a connection, and without the slice resolution reaching the network.
	 */
	public function testTrippedBreakerFailsFastWithoutConnecting(): void {
		$this->tripBreaker( 's1' );
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnectionNames' )->willReturn( $this->connectionNames() );
		$registry->expects( static::never() )->method( 'getConnection' );
		$guzzle = $this->createMock( Client::class );
		$guzzle->expects( static::never() )->method( 'request' );
		$repo = $this->makeRepository( $registry, $guzzle );

		$this->expectException( ServiceUnavailableHttpException::class );
		$repo->executeProjectsQuery( 'enwiki', 'SELECT 1' );
	}

	/**
	 * The replication probe passes checkBreaker=false, so a tripped breaker must not
	 * short-circuit it: it still reaches the real connection (proven here by the query
	 * error surfacing as its own 504 rather than the breaker's 503).
	 */
	public function testCheckBreakerFalseBypassesTheBreaker(): void {
		$this->tripBreaker( 's1' );
		$repo = $this->makeRepository( $this->registryThrowing( 1969 ) );

		try {
			$repo->executeProjectsQuery( 'enwiki', 'SELECT 1', [], null, false );
			$this->fail( 'Expected an HttpException to be thrown.' );
		} catch ( HttpException $e ) {
			static::assertSame( 504, $e->getStatusCode() );
			static::assertSame( 'error-query-timeout', $e->getMessage() );
		}
	}

	/**
	 * A slice whose probe reports unavailable is skipped: its projects never enter the dblist,
	 * while the reachable slices still resolve.
	 */
	public function testDownSliceIsSkippedButHealthySlicesResolve(): void {
		$this->emptyCache();
		$repo = $this->makeRepository( $this->registryReturning( [
			's1' => [ 'enwiki' ],
			's4' => $this->driverError( 2002 ),
			's7' => [ 'meta' ],
		] ) );

		static::assertTrue( $repo->isInDbLists( 'enwiki' ) );
		static::assertFalse( $repo->isInDbLists( 'commonswiki' ) );
		static::assertTrue( $repo->isInDbLists( 'meta' ) );
	}

	/**
	 * An empty probe (slice reachable but returning nothing) is the stale/empty-dblist failure
	 * mode: the slice is skipped and, crucially, nothing is cached, so the next request re-probes.
	 */
	public function testEmptyProbeIsNotCached(): void {
		$this->emptyCache();
		$repo = $this->makeRepository( $this->registryReturning( [ 's1' => [], 's4' => [], 's7' => [] ] ) );

		static::assertFalse( $repo->isInDbLists( 'enwiki' ) );
		static::assertFalse( $this->cache->hasItem( 'dblist_s1' ) );
	}

	/**
	 * A non-empty probe is cached per slice for reuse across requests.
	 */
	public function testNonEmptyProbeIsCached(): void {
		$this->emptyCache();
		$repo = $this->makeRepository( $this->registryReturning( [ 's1' => [ 'enwiki' ] ] ) );

		static::assertTrue( $repo->isInDbLists( 'enwiki' ) );
		static::assertTrue( $this->cache->hasItem( 'dblist_s1' ) );
		static::assertSame( [ 'enwiki' ], $this->cache->getItem( 'dblist_s1' )->get() );
	}

	/**
	 * The assembled dblist is memoized for the request: a second lookup doesn't reassemble it.
	 * The persistent cache is wiped between the two lookups, so only the in-memory memo can keep
	 * the second one from re-probing the replicas.
	 */
	public function testDbListIsMemoizedWithinRequest(): void {
		$this->emptyCache();
		$probes = 0;
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnectionNames' )->willReturn( $this->connectionNames() );
		$registry->method( 'getConnection' )->willReturnCallback(
			function () use ( &$probes ): Connection {
				$probes++;
				return $this->connectionReturning( [ 'enwiki' ] );
			}
		);
		$repo = $this->makeRepository( $registry );

		$repo->isInDbLists( 'enwiki' );
		$afterFirst = $probes;
		$this->cache->clear();
		$repo->isInDbLists( 'enwiki' );

		static::assertSame( $afterFirst, $probes, 'the memoized second lookup re-probed the replicas' );
	}

	/**
	 * A project that resolves to no slice (its slice was skipped, or it isn't replicated) fails
	 * fast as a retryable 503 rather than a cryptic getConnection('') error.
	 */
	public function testUnresolvableProjectFailsAsServiceUnavailable(): void {
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnectionNames' )->willReturn( $this->connectionNames() );
		$repo = $this->makeRepository( $registry );

		try {
			$repo->executeProjectsQuery( 'nonexistentwiki', 'SELECT 1' );
			$this->fail( 'Expected a ServiceUnavailableHttpException.' );
		} catch ( ServiceUnavailableHttpException $e ) {
			static::assertSame( 503, $e->getStatusCode() );
			static::assertSame( 'error-replica-unavailable', $e->getMessage() );
		}
	}

	/**
	 * A connection name that doesn't match the WMF s1..sN pattern still resolves as its own slice, without
	 * re-entering getDbList() (which the old s\d+-only check would recurse into on such a name,
	 * eventually overflowing the stack). This is what lets a third-party install name its own
	 * connections.
	 */
	public function testArbitraryConnectionNameResolvesWithoutRecursing(): void {
		$this->emptyCache();
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnectionNames' )->willReturn( [
			'default' => 'doctrine.dbal.default_connection',
			'toolsdb' => 'doctrine.dbal.toolsdb_connection',
			'main' => 'doctrine.dbal.main_connection',
		] );
		$registry->method( 'getConnection' )->willReturnCallback( fn () => $this->connectionReturning( [ 'mywiki' ] ) );
		$repo = $this->makeRepository( $registry );

		static::assertTrue( $repo->isInDbLists( 'mywiki' ) );
		static::assertSame( [ 'mywiki' ], $this->cache->getItem( 'dblist_main' )->get() );
	}

	/**
	 * A registry that enumerates the replica slices and gives each connection a canned probe
	 * outcome: a list of project names to return, [] for an empty probe, or a Throwable to throw.
	 * @param array<string, string[]|\Throwable> $sliceResults Keyed by slice name.
	 */
	private function registryReturning( array $sliceResults ): ManagerRegistry {
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnectionNames' )->willReturn( $this->connectionNames() );
		$registry->method( 'getConnection' )->willReturnCallback(
			function ( string $name ) use ( $sliceResults ): Connection {
				$outcome = $sliceResults[$name] ?? [];
				if ( $outcome instanceof \Throwable ) {
					$connection = $this->createMock( Connection::class );
					$connection->method( 'executeQuery' )->willThrowException( $outcome );
					return $connection;
				}
				return $this->connectionReturning( $outcome );
			}
		);
		return $registry;
	}

	/**
	 * A connection whose probe query returns the given project names as its first column.
	 * @param string[] $projects
	 */
	private function connectionReturning( array $projects ): Connection {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchFirstColumn' )->willReturn( $projects );
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willReturn( $result );
		return $connection;
	}

	/**
	 * A bare concrete Repository: the class is abstract but declares no abstract methods, so
	 * an empty subclass is enough to exercise the connection-layer behaviour in isolation
	 * (no ProjectRepository, whose constructor differs across this arc's commits).
	 */
	private function makeRepository( ManagerRegistry $registry, ?Client $guzzle = null ): Repository {
		return new class(
			$registry,
			$this->cache,
			$guzzle ?? $this->createMock( Client::class ),
			new NullLogger(),
			new ParameterBag( [] ),
			true,
			30
		) extends Repository {
		};
	}
}
