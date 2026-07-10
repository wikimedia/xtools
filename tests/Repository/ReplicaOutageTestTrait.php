<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared fixtures for the replica-outage tests. Provides a real in-memory cache (so the
 * breaker's trip and check genuinely round-trip), a registry whose sole connection throws a
 * chosen driver error code, and the odd bits of state-priming both suites need.
 */
trait ReplicaOutageTestTrait {
	private ArrayAdapter $cache;

	/**
	 * Fresh cache, pre-primed with a dblist so resolveSlice() maps db -> slice without
	 * hitting the network.
	 */
	private function primeReplicaCache(): void {
		$this->cache = new ArrayAdapter();
		$item = $this->cache->getItem( 'dblists' );
		$item->set( [ 'enwiki' => 's1', 'commonswiki' => 's4', 'meta' => 's7' ] );
		$this->cache->save( $item );
	}

	/**
	 * A registry whose sole connection throws a DriverException carrying $code.
	 */
	private function registryThrowing( int $code ): ManagerRegistry {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willThrowException( $this->driverError( $code ) );
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnection' )->willReturn( $connection );
		return $registry;
	}

	/**
	 * A Doctrine DriverException whose getCode() returns $code, as the DBAL drivers report
	 * connection and query errors (2002/2003/2005 for connect failures, etc.).
	 */
	private function driverError( int $code ): DriverException {
		$inner = new class( 'driver error', $code ) extends \Exception implements DriverExceptionInterface {
			public function getSQLState(): ?string {
				return null;
			}
		};
		return new DriverException( $inner, null );
	}

	private function tripBreaker( string $slice ): void {
		$item = $this->cache->getItem( 'replica-breaker.' . $slice );
		$item->set( true );
		$this->cache->save( $item );
	}

	private function runExpectingHttpException( callable $fn ): void {
		try {
			$fn();
			$this->fail( 'Expected an HttpException to be thrown.' );
		} catch ( HttpException ) {
			// Expected; the assertion under test is on the side effect.
		}
	}
}
