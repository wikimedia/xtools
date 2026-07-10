<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Repository\Repository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The connection-layer resilience: connect errors translated to a friendly 503, and the
 * per-slice fail-fast breaker. Repository is abstract, so these drive it through a bare
 * concrete subclass; the DB connection is mocked and the cache is a real in-memory
 * ArrayAdapter so the breaker's trip and check round-trip.
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
			'resource overload (1226)' => [ 1226, 503, 'error-service-overload', true ],
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
	 * Once a slice's breaker is tripped, the next request fails fast without asking the
	 * registry for a connection, and without the slice resolution reaching the network.
	 */
	public function testTrippedBreakerFailsFastWithoutConnecting(): void {
		$this->tripBreaker( 's1' );
		$registry = $this->createMock( ManagerRegistry::class );
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
