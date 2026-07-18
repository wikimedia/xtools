<?php

declare( strict_types = 1 );

namespace App\Repository;

use App\Exception\BadGatewayException;
use App\Model\Project;
use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use MediaWiki\OAuthClient\Exception as OAuthException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * A repository is responsible for retrieving data from wherever it lives (databases, APIs, filesystems, etc.)
 */
abstract class Repository {
	/** @var Connection The database connection to other tools' databases. */
	private Connection $toolsConnection;

	/** @var string[]|null Request-lifetime memo of the assembled dblist, keyed by database name. */
	private ?array $dbListCache = null;

	/** @var array<string,string>|null Request-lifetime memo of the configured connection names. */
	private ?array $connectionNames = null;

	/** @var string[] Connections that aren't replica slices: the internal metadata db and toolsdb. */
	private const NON_REPLICA_CONNECTIONS = [ 'default', 'toolsdb' ];

	/** @var string Cache-key prefix for the per-slice connection-failure breaker. */
	private const REPLICA_BREAKER_PREFIX = 'replica-breaker.';

	/** @var string How long a slice's breaker stays tripped after a failed connection. */
	private const REPLICA_BREAKER_COOLDOWN = 'PT30S';

	/** @var int[] Driver codes meaning "couldn't connect to the host at all" (vs. dropped mid-query). */
	private const CONNECT_ERROR_CODES = [ 2002, 2003, 2005 ];

	/**
	 * @var int[] Driver codes meaning the server is up but shedding load: a connection or
	 *   resource limit is maxed (1040 global, 1203 per-user, 1226 GRANT resource limit).
	 */
	private const OVERLOAD_ERROR_CODES = [ 1040, 1203, 1226 ];

	/**
	 * Create a new Repository.
	 */
	public function __construct(
		protected ManagerRegistry $managerRegistry,
		protected CacheItemPoolInterface $cache,
		protected Client $guzzle,
		protected LoggerInterface $logger,
		protected ParameterBagInterface $parameterBag,
		protected bool $isWMF,
		protected int $queryTimeout,
		protected ?RequestStack $requestStack = null
	) {
	}

	/***************
	 * CONNECTIONS *
	 */

	/**
	 * @param string $name
	 * @return Connection
	 */
	private function getConnection( string $name ): Connection {
		/** @type Connection */
		return $this->managerRegistry->getConnection( $name );
	}

	/**
	 * Get whether XTools is running against WMF wikis.
	 * @return bool
	 * @codeCoverageIgnore
	 */
	public function isWMF(): bool {
		return $this->isWMF;
	}

	/**
	 * Get a database connection for the given database.
	 * @param Project|string $project Project instance, database name (i.e. 'enwiki'), or slice (i.e. 's1').
	 * @return Connection
	 */
	protected function getProjectsConnection( Project|string $project, bool $checkBreaker = true ): Connection {
		$slice = $this->resolveSlice( $project );
		if ( $slice === '' ) {
			// The project resolved to no slice: either its slice was skipped this request
			// (down, or an empty probe) or it isn't a replicated project. Fail fast as a
			// retryable 503 rather than letting getConnection('') throw a cryptic Doctrine error.
			throw new ServiceUnavailableHttpException( 30, 'error-replica-unavailable', null, 503 );
		}
		if ( $checkBreaker ) {
			$this->assertReplicaReachable( $slice );
		}
		return $this->getConnection( $slice );
	}

	/**
	 * Resolve which replica connection (i.e. 's1') a project or database lives on.
	 * @param Project|string $project Project instance, database name (i.e. 'enwiki'), or the name
	 *   of a configured connection (i.e. 's1'), which is returned as-is.
	 * @return string
	 */
	private function resolveSlice( Project|string $project ): string {
		if ( is_string( $project ) ) {
			// A replica connection name is already a slice: return it unchanged, whatever it's
			// called (not just the WMF s1..sN shape). This is also what keeps getDbList()'s probe,
			// which passes connection names, from re-entering getDbList() here and recursing: the
			// memo isn't set mid-assembly, so the lookup below would call getDbList() again.
			// Assumes a connection is never named the same as a wiki database.
			if ( $this->isReplicaConnection( $project ) ) {
				return $project;
			}
			return $this->getDbList()[$project] ?? '';
		}
		return $this->getDbList()[$project->getDatabaseName()] ?? '';
	}

	/**
	 * Whether $name is a configured replica-slice connection (i.e. not the metadata db or toolsdb).
	 * @param string $name
	 * @return bool
	 */
	private function isReplicaConnection( string $name ): bool {
		return !in_array( $name, self::NON_REPLICA_CONNECTIONS, true )
			&& array_key_exists( $name, $this->connectionNames() );
	}

	/**
	 * The configured Doctrine connection names, memoized for the request. Keyed by name.
	 * @return array<string, string>
	 */
	private function connectionNames(): array {
		$this->connectionNames ??= $this->managerRegistry->getConnectionNames();
		return $this->connectionNames;
	}

	/**
	 * Fail fast when a slice refused a connection very recently, rather than spending
	 * another connection timeout on a replica that's likely still down (and piling more
	 * attempts onto it). The breaker is tripped in executeProjectsQuery() and clears itself
	 * after a short cooldown, after which the next request probes the slice again.
	 * @param string $slice i.e. 's1'.
	 * @throws ServiceUnavailableHttpException if the slice's breaker is tripped.
	 */
	private function assertReplicaReachable( string $slice ): void {
		if ( $this->cache->hasItem( self::REPLICA_BREAKER_PREFIX . $slice ) ) {
			throw new ServiceUnavailableHttpException( 30, 'error-replica-unavailable', null, 503 );
		}
	}

	/**
	 * Get the database connection for the 'tools' database (the one that other tools store data in).
	 * @return Connection
	 * @codeCoverageIgnore
	 */
	protected function getToolsConnection(): Connection {
		if ( !isset( $this->toolsConnection ) ) {
			$this->toolsConnection = $this->getConnection( 'toolsdb' );
		}
		return $this->toolsConnection;
	}

	/**
	 * Fetch and concatenate all the dblists into one array.
	 * Based on ToolforgeBundle https://github.com/wikimedia/ToolforgeBundle/blob/master/Service/ReplicasClient.php
	 * License: GPL 3.0 or later
	 * @return string[] Keys are database names (i.e. 'enwiki_p'), values are the slices (i.e. 's1').
	 */
	protected function getDbList(): array {
		if ( isset( $this->dbListCache ) ) {
			return $this->dbListCache;
		}

		$dbList = [];
		// Enumerate the replica connections: everything but the internal metadata db and toolsdb.
		$replicaConns = array_diff_key( $this->connectionNames(), array_flip( self::NON_REPLICA_CONNECTIONS ) );
		// Exclude MySQL's own metadata schemas.
		$sql = "SELECT DISTINCT schema_name
				FROM information_schema.schemata
				WHERE schema_name NOT IN ('information_schema','performance_schema','mysql','sys')";
		// Loop through the relevant connections to build the project db list.
		foreach ( array_keys( $replicaConns ) as $conn ) {
			$cacheKey = 'dblist_' . $conn;
			if ( $this->cache->hasItem( $cacheKey ) ) {
				$projectList = $this->cache->getItem( $cacheKey )->get();
			} else {
				// We only check the presence of the actual replicas, due to a
				// certain number of incidents: T322466, T420632, etc.
				// noc.wikimedia.org is less accurate and obviously not
				// available for non-WMF installations.
				try {
					$projectList = $this->executeProjectsQuery( $conn, $sql, checkBreaker: false )
						->fetchFirstColumn();
				} catch ( HttpException ) {
					// Slice reported unavailable, overloaded, or timed-out: skip it so the
					// healthy slices still resolve, and let a later request re-probe. Anything
					// else (an unexpected driver error, a programming bug) propagates rather
					// than silently dropping the slice's projects.
					continue;
				}
				// An empty probe means the slice answered but returned nothing: the
				// stale/empty-dblist failure mode. Skip it without caching, so the next request
				// re-probes rather than serving a poisoned list for a week.
				if ( $projectList === [] ) {
					continue;
				}
				// Cache the slice's project list for one week.
				$this->setCache( $cacheKey, $projectList, 'P1W' );
			}
			foreach ( $projectList as $project ) {
				$dbList[$project] = $conn;
			}
		}

		// Memoize only a non-empty result. A request that assembled nothing (every slice skipped)
		// must stay free to re-probe on its next call rather than serving [] for its whole lifetime.
		if ( $dbList !== [] ) {
			$this->dbListCache = $dbList;
		}
		return $dbList;
	}

	/**
	 * Check if a project is in one of the slices.
	 * (Separate function for easier mocking).
	 * @param string $dbName
	 * @return bool
	 */
	public function isInDbLists( string $dbName ): bool {
		return $dbName !== '' && ( $this->getDbList()[ $dbName ] ?? false );
	}

	/*****************
	 * QUERY HELPERS *
	 */

	/**
	 * Make a request to the MediaWiki API.
	 * @param Project $project
	 * @param array $params
	 * @return array
	 * @throws BadGatewayException
	 */
	public function executeApiRequest( Project $project, array $params ): array {
		try {
			$fullParams = array_merge( [
				'action' => 'query',
				'format' => 'json',
			], $params );
			if ( $this->requestStack === null ) {
				$session = false;
			} else {
				$session = $this->requestStack->getSession();
			}
			if ( $session && $session->get( 'logged_in_user' ) ) {
				$oauthClient = $session->get( 'oauth_client' );
				$queryString = http_build_query( $fullParams );
				$requestUrl = $project->getApiUrl() . '?' . $queryString;
				$body = $oauthClient->makeOAuthCall(
					$session->get( 'oauth_access_token' ),
					$requestUrl
				);
				return json_decode( $body, true );
			} else {
				// Not logged in, default to a not-logged-in query
				$req = $this->guzzle->request(
					'GET',
					$project->getApiUrl(),
					[ 'query' => $fullParams ]
				);
				$body = $req->getBody()->getContents();
				return json_decode( $body, true );
			}
		} catch ( ConnectException | ServerException | OAuthException $e ) {
			throw new BadGatewayException( 'api-error-wikimedia', [ 'Wikimedia' ], $e );
		}
	}

	/**
	 * Normalize and quote a table name for use in SQL.
	 * @param string $databaseName
	 * @param string $tableName
	 * @param string|null $tableExtension Optional table extension, which will only get used if we're on labs.
	 *   If null, table extensions are added as defined in table_map.yml. If a blank string, no extension is added.
	 * @return string Fully-qualified and quoted table name.
	 */
	public function getTableName( string $databaseName, string $tableName, ?string $tableExtension = null ): string {
		$mapped = false;

		// This is a workaround for a one-to-many mapping
		// as required by Labs. We combine $tableName with
		// $tableExtension in order to generate the new table name
		if ( $this->isWMF && $tableExtension !== null ) {
			$mapped = true;
			$tableName .= ( $tableExtension === '' ? '' : '_' . $tableExtension );
		} elseif ( $this->parameterBag->has( "app.table.$tableName" ) ) {
			// Use the table specified in the table mapping configuration, if present.
			$mapped = true;
			$tableName = $this->parameterBag->get( "app.table.$tableName" );
		}

		// For 'revision' and 'logging' tables (actually views) on Labs, use the indexed versions
		// (that have some rows hidden, e.g. for revdeleted users).
		// This is a safeguard in case table mapping isn't properly set up.
		$isLoggingOrRevision = in_array( $tableName, [ 'revision', 'logging', 'archive' ] );
		if ( !$mapped && $isLoggingOrRevision && $this->isWMF ) {
			$tableName .= "_userindex";
		}

		return "`$databaseName`.`$tableName`";
	}

	/**
	 * Get a unique cache key for the given list of arguments. Assuming each argument of
	 * your function should be accounted for, you can pass in them all with func_get_args:
	 *   $this->getCacheKey(func_get_args(), 'unique key for function');
	 * Arguments that are a model should implement their own getCacheKey() that returns
	 * a unique identifier for an instance of that model. See User::getCacheKey() for example.
	 * @param array|mixed $args Array of arguments or a single argument.
	 * @param string|null $key Unique key for this function. If omitted the function name itself
	 *   is used, which is determined using `debug_backtrace`.
	 * @return string
	 */
	public function getCacheKey( mixed $args, ?string $key = null ): string {
		if ( $key === null ) {
			$key = debug_backtrace()[1]['function'];
		}

		if ( !is_array( $args ) ) {
			$args = [ $args ];
		}

		// Start with base key.
		$cacheKey = $key;

		// Loop through and determine what values to use based on type of object.
		foreach ( $args as $arg ) {
			// Zero is an acceptable value.
			if ( $arg === '' || $arg === null ) {
				continue;
			}

			$cacheKey .= $this->getCacheKeyFromArg( $arg );
		}

		// Remove reserved characters.
		return preg_replace( '/[{}()\/@:"]/', '', $cacheKey );
	}

	/**
	 * Get a cache-friendly string given an argument.
	 * @param mixed $arg
	 * @return string
	 */
	private function getCacheKeyFromArg( mixed $arg ): string {
		if ( is_object( $arg ) && method_exists( $arg, 'getCacheKey' ) ) {
			return '.' . $arg->getCacheKey();
		} elseif ( is_array( $arg ) ) {
			// Assumed to be an array of objects that can be parsed into a string.
			return '.' . md5( implode( '', $arg ) );
		} else {
			// Assumed to be a string, number or boolean.
			return '.' . md5( (string)$arg );
		}
	}

	/**
	 * Set the cache with given options.
	 * @param string $cacheKey
	 * @param mixed $value
	 * @param string $duration Valid DateInterval string.
	 * @return mixed The given $value.
	 */
	public function setCache( string $cacheKey, mixed $value, string $duration = 'PT20M' ): mixed {
		$cacheItem = $this->cache
			->getItem( $cacheKey )
			->set( $value )
			->expiresAfter( new DateInterval( $duration ) );
		$this->cache->save( $cacheItem );
		return $value;
	}

	/********************************
	 * DATABASE INTERACTION HELPERS *
	 */

	/**
	 * Creates WHERE conditions with date range to be put in query.
	 * @param false|int $start Unix timestamp.
	 * @param false|int $end Unix timestamp.
	 * @param false|int $offset Unix timestamp. Used for pagination, will end up replacing $end.
	 * @param string $tableAlias Alias of table FOLLOWED BY DOT.
	 * @param string $field
	 * @return string
	 */
	public function getDateConditions(
		false|int $start,
		false|int $end,
		false|int $offset = false,
		string $tableAlias = '',
		string $field = 'rev_timestamp'
	): string {
		$datesConditions = '';

		if ( is_int( $start ) ) {
			// Convert to YYYYMMDDHHMMSS.
			$start = date( 'Ymd', $start ) . '000000';
			$datesConditions .= " AND $tableAlias{$field} >= '$start'";
		}

		// When we're given an $offset, it basically replaces $end, except it's also a full timestamp.
		if ( is_int( $offset ) ) {
			$offset = date( 'YmdHis', $offset );
			$datesConditions .= " AND $tableAlias{$field} <= '$offset'";
		} elseif ( is_int( $end ) ) {
			$end = date( 'Ymd', $end ) . '235959';
			$datesConditions .= " AND $tableAlias{$field} <= '$end'";
		}

		return $datesConditions;
	}

	/**
	 * Execute a query using the projects connection, handling certain Exceptions.
	 * @param Project|string $project Project instance, database name (i.e. 'enwiki'), or slice (i.e. 's1').
	 * @param string $sql
	 * @param array $params Parameters to bound to the prepared query.
	 * @param int|null $timeout Maximum statement time in seconds. null will use the
	 *   default specified by the APP_QUERY_TIMEOUT env variable.
	 * @param bool $checkBreaker Whether to honor the fail-fast breaker. Pass false for the
	 *   dblist replication probe, which must test the real connection to keep its safety check.
	 * @return Result
	 * @throws DriverException
	 */
	public function executeProjectsQuery(
		Project|string $project,
		string $sql,
		array $params = [],
		?int $timeout = null,
		bool $checkBreaker = true
	): Result {
		try {
			$timeout = $timeout ?? $this->queryTimeout;
			$sql = "SET STATEMENT max_statement_time = $timeout FOR\n" . $sql;

			return $this->getProjectsConnection( $project, $checkBreaker )->executeQuery( $sql, $params );
		} catch ( DriverException $e ) {
			if ( in_array( $e->getCode(), self::CONNECT_ERROR_CODES ) ) {
				// Connection refused/unreachable: trip the breaker so subsequent requests
				// stop hammering this slice until it's had a moment to recover.
				$this->setCache(
					self::REPLICA_BREAKER_PREFIX . $this->resolveSlice( $project ),
					true,
					self::REPLICA_BREAKER_COOLDOWN
				);
			}
			$this->handleDriverError( $e, $timeout );
		}
	}

	/**
	 * Execute a query using the projects connection, handling certain Exceptions.
	 * @param QueryBuilder $qb
	 * @param int|null $timeout Maximum statement time in seconds. null will use the
	 *   default specified by the APP_QUERY_TIMEOUT env variable.
	 * @return Result
	 * @throws HttpException
	 * @throws DriverException
	 * @codeCoverageIgnore
	 */
	public function executeQueryBuilder( QueryBuilder $qb, ?int $timeout = null ): Result {
		try {
			$timeout = $timeout ?? $this->queryTimeout;
			$sql = "SET STATEMENT max_statement_time = $timeout FOR\n" . $qb->getSQL();
			// FIXME
			return $qb->executeQuery( $sql, $qb->getParameters(), $qb->getParameterTypes() );
		} catch ( DriverException $e ) {
			$this->handleDriverError( $e, $timeout );
		}
	}

	/**
	 * Special handling of some DriverExceptions, otherwise original Exception is thrown.
	 * @param DriverException $e
	 * @param int|null $timeout Timeout value, if applicable. This is passed to the i18n message.
	 * @throws HttpException
	 * @throws DriverException
	 */
	private function handleDriverError( DriverException $e, ?int $timeout ): void {
		// If no value was passed for the $timeout, it must be the default.
		if ( $timeout === null ) {
			$timeout = $this->queryTimeout;
		}

		if ( in_array( $e->getCode(), self::OVERLOAD_ERROR_CODES ) ) {
			// Server is up but rejecting: a connection or resource limit is maxed. Unlike a
			// dead host these reject instantly (no wasted connection timeout), so shed this one
			// request as a retryable 503 rather than tripping the breaker, which exists to spare
			// us repeated timeouts on a host that's actually down.
			throw new ServiceUnavailableHttpException( 30, 'error-service-overload', null, 503 );
		} elseif ( in_array( $e->getCode(), self::CONNECT_ERROR_CODES ) ) {
			// Can't establish a connection at all: the replica host is down, refusing
			// connections, or unresolvable. Distinct from 2006/2013 below, which are a
			// connection dropping mid-query. Retryable, so 503 rather than a bare 500.
			throw new ServiceUnavailableHttpException( 30, 'error-replica-unavailable', null, 503 );
		} elseif ( $e->getCode() === 1205 ) {
			// Lock wait timeout: the query waited out innodb_lock_wait_timeout for a row lock
			// held by another transaction. Transient contention on specific rows, not a sick
			// host, and a retry usually wins the lock, so a retryable 503 beats a bare 500.
			throw new ServiceUnavailableHttpException( 30, 'error-lock-contention', null, 503 );
		} elseif ( in_array( $e->getCode(), [ 2006, 2013 ] ) ) {
			// FIXME: Attempt to reestablish connection on 2006 error (MySQL server has gone away).
			throw new HttpException(
				Response::HTTP_GATEWAY_TIMEOUT,
				'error-lost-connection',
				null,
				[],
				Response::HTTP_GATEWAY_TIMEOUT
			);
		} elseif ( $e->getCode() === 1969 ) {
			throw new HttpException(
				Response::HTTP_GATEWAY_TIMEOUT,
				'error-query-timeout',
				null,
				[ $timeout ],
				Response::HTTP_GATEWAY_TIMEOUT
			);
		} else {
			throw $e;
		}
	}

	/**
	 * MISCELLANEOUS
	 */

	/**
	 * Get the full hash of the currently checked-out Git commit.
	 * @return string
	 */
	public function gitHash(): string {
		$cacheKey = $this->getCacheKey( 'git_short_hash' );
		if ( $this->cache->hasItem( $cacheKey ) ) {
			return $this->cache->getItem( $cacheKey )->get();
		}

		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.exec
		$hash = exec( 'git rev-parse HEAD' );

		return $this->setCache( $cacheKey, $hash, 'P7D' );
	}
}
