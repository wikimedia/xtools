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
	 * Fresh cache, pre-primed with a per-slice dblist so resolveSlice() maps db -> slice
	 * without probing the network. getDbList() reads one cache entry per replica connection.
	 */
	private function primeReplicaCache(): void {
		$this->cache = new ArrayAdapter();
		foreach ( [ 's1' => [ 'enwiki' ], 's4' => [ 'commonswiki' ], 's7' => [ 'meta' ] ] as $slice => $projects ) {
			$item = $this->cache->getItem( 'dblist_' . $slice );
			$item->set( $projects );
			$this->cache->save( $item );
		}
	}

	/**
	 * An empty cache, for the tests that want getDbList() to actually probe the replicas rather
	 * than read the entries primeReplicaCache() seeded.
	 */
	private function emptyCache(): void {
		$this->cache = new ArrayAdapter();
	}

	/**
	 * The Doctrine connection names getDbList() iterates: two it ignores (default, toolsdb) and
	 * the three replica slices the primed cache describes. Only the keys matter to getDbList().
	 * @return array<string, string>
	 */
	private function connectionNames(): array {
		return [
			'default' => 'doctrine.dbal.default_connection',
			'toolsdb' => 'doctrine.dbal.toolsdb_connection',
			's1' => 'doctrine.dbal.s1_connection',
			's4' => 'doctrine.dbal.s4_connection',
			's7' => 'doctrine.dbal.s7_connection',
		];
	}

	/**
	 * A registry whose sole connection throws a DriverException carrying $code, and which
	 * enumerates the replica slices so getDbList()'s connection loop has something to walk.
	 */
	private function registryThrowing( int $code ): ManagerRegistry {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willThrowException( $this->driverError( $code ) );
		$registry = $this->createMock( ManagerRegistry::class );
		$registry->method( 'getConnection' )->willReturn( $connection );
		$registry->method( 'getConnectionNames' )->willReturn( $this->connectionNames() );
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
