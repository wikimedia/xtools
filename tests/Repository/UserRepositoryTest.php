<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * UserRepository is SQL-assembly and light post-processing over rows a replica hands back, plus a
 * couple of config lookups and a session read. The branches that matter for coverage: the isIP()
 * branches that move block/edit queries away from the actor and onto IP-index or ip_changes tables,
 * the isTemp()/isIP() short-circuits in the rights lookups, the namespace!='all' page join, and the
 * global-rights path that goes through the API rather than the replica. No query touches a database:
 * a test subclass overrides the executeProjectsQuery() and executeApiRequest() seams to record the
 * built SQL/params and hand back canned data, so we assert branch logic in isolation. A real
 * ArrayAdapter backs the cache so getCacheKey()/setCache() behave as in production.
 * @covers \App\Repository\UserRepository
 */
class UserRepositoryTest extends TestCase {

	/**
	 * getIdAndRegistration() runs the user lookup on a cache miss and returns the fetched row.
	 */
	public function testGetIdAndRegistrationRunsUserQueryOnCacheMiss(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->assocRowResult( [ 'userId' => 5, 'regDate' => '20200101000000' ] ) ];

		$row = $repo->getIdAndRegistration( 'enwiki', 'Jimbo' );
		static::assertSame( 5, $row['userId'] );
		static::assertStringContainsString( 'FROM `enwiki`.`user`', $repo->lastSql );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
	}

	/**
	 * getEditCount() casts the scalar user_editcount column to int on a cache miss.
	 */
	public function testGetEditCountCastsScalarOnCacheMiss(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '42' ) ];

		$count = $repo->getEditCount( 'enwiki', 'Jimbo' );
		static::assertSame( 42, $count );
		static::assertStringContainsString( 'user_editcount', $repo->lastSql );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
	}

	/**
	 * getActorId() returns null for an IP range before touching the cache or a query, because a range
	 * has no single actor.
	 */
	public function testGetActorIdReturnsNullForIpRange(): void {
		$repo = $this->makeRepository( true );
		static::assertNull( $repo->getActorId( 'enwiki', '10.0.0.0/24' ) );
		// No query ran.
		static::assertSame( '', $repo->lastSql );
	}

	/**
	 * For a named account getActorId() queries the actor table and casts the id to int.
	 */
	public function testGetActorIdQueriesActorTableForNamedUser(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '99' ) ];

		$actorId = $repo->getActorId( 'enwiki', 'Jimbo' );
		static::assertSame( 99, $actorId );
		static::assertStringContainsString( 'FROM `enwiki`.`actor`', $repo->lastSql );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
	}

	/**
	 * A named account's block count is looked up on block_target by numeric user id (bt_user).
	 */
	public function testCountActiveBlocksUsesBtUserForNamedUser(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '2' ) ];

		$count = $repo->countActiveBlocks( $this->makeProject(), $this->makeUser() );
		static::assertSame( 2, $count );
		static::assertStringContainsString( 'block_target', $repo->lastSql );
		static::assertStringContainsString( 'bt_user = :user', $repo->lastSql );
		static::assertSame( 7, $repo->lastParams['user'] );
	}

	/**
	 * A single IP switches to block_target_ipindex, filtered on the sanitised address (bt_address).
	 */
	public function testCountActiveBlocksUsesBtAddressForSingleIp(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '1' ) ];

		$repo->countActiveBlocks( $this->makeProject(), $this->makeIpUser( '10.0.0.5' ) );
		static::assertStringContainsString( 'block_target_ipindex', $repo->lastSql );
		static::assertStringContainsString( 'bt_address = :user', $repo->lastSql );
		static::assertSame( '10.0.0.5', $repo->lastParams['user'] );
	}

	/**
	 * An IP range also uses bt_address, but the bound value is the sanitised range rather than a
	 * single address.
	 */
	public function testCountActiveBlocksUsesSanitisedRangeForIpRange(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '0' ) ];

		$repo->countActiveBlocks( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'bt_address = :user', $repo->lastSql );
		static::assertSame( '10.0.0.0/24', $repo->lastParams['user'] );
	}

	/**
	 * For a named account countEdits() filters by actor and, for the default 'all' namespace, adds no
	 * page join.
	 */
	public function testCountEditsUsesActorFilterForNamedUser(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '10' ) ];

		$count = $repo->countEdits( $this->makeProject(), $this->makeUser() );
		static::assertSame( 10, $count );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertStringNotContainsString( 'ipc_hex', $repo->lastSql );
		static::assertSame( 1, $repo->lastParams['actorId'] );
	}

	/**
	 * An IP user moves countEdits() onto the ip_changes join over the hex range, binding start/end
	 * bounds instead of an actor id.
	 */
	public function testCountEditsUsesIpChangesJoinForIp(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '3' ) ];

		$repo->countEdits( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ip_changes', $repo->lastSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
		static::assertArrayHasKey( 'endIp', $repo->lastParams );
	}

	/**
	 * A concrete namespace adds the page join and namespace condition, and binds the namespace param.
	 */
	public function testCountEditsAddsPageJoinForNamespace(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '4' ) ];

		$repo->countEdits( $this->makeProject(), $this->makeUser(), 0 );
		static::assertStringContainsString( 'LEFT JOIN `enwiki_p`.`page` ON rev_page = page_id', $repo->lastSql );
		static::assertStringContainsString( 'page_namespace = :namespace', $repo->lastSql );
		static::assertSame( 0, $repo->lastParams['namespace'] );
	}

	/**
	 * getXtoolsUserInfo() reads the logged_in_user value straight off the session.
	 */
	public function testGetXtoolsUserInfoReadsLoggedInUserFromSession(): void {
		$session = $this->createMock( SessionInterface::class );
		$session->method( 'get' )->with( 'logged_in_user' )->willReturn( [ 'username' => 'Jimbo' ] );
		$requestStack = $this->createMock( RequestStack::class );
		$requestStack->method( 'getSession' )->willReturn( $session );

		$repo = $this->makeRepository( true, null, $requestStack );
		static::assertSame( [ 'username' => 'Jimbo' ], $repo->getXtoolsUserInfo() );
	}

	/**
	 * numEditsRequiringLogin() casts the configured value to int.
	 */
	public function testNumEditsRequiringLoginCastsConfigToInt(): void {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'get' )->with( 'app.num_edits_requiring_login' )->willReturn( '10000' );

		$repo = $this->makeRepository( true, null, null, $parameterBag );
		static::assertSame( 10000, $repo->numEditsRequiringLogin() );
	}

	/**
	 * maxEdits() casts the configured value to int.
	 */
	public function testMaxEditsCastsConfigToInt(): void {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'get' )->with( 'app.max_user_edits' )->willReturn( '50000' );

		$repo = $this->makeRepository( true, null, null, $parameterBag );
		static::assertSame( 50000, $repo->maxEdits() );
	}

	/**
	 * getPageAndNamespaceSql() returns a null pair for the catch-all 'all' namespace.
	 */
	public function testGetPageAndNamespaceSqlReturnsNullPairForAll(): void {
		$repo = $this->makeRepository( true );
		$result = ( new ReflectionMethod( UserRepository::class, 'getPageAndNamespaceSql' ) )
			->invoke( $repo, $this->makeProject(), 'all' );
		static::assertSame( [ null, null ], $result );
	}

	/**
	 * A concrete namespace yields the page join clause and the namespace condition.
	 */
	public function testGetPageAndNamespaceSqlReturnsJoinForNamespace(): void {
		$repo = $this->makeRepository( true );
		[ $join, $cond ] = ( new ReflectionMethod( UserRepository::class, 'getPageAndNamespaceSql' ) )
			->invoke( $repo, $this->makeProject(), 3 );
		static::assertStringContainsString( 'LEFT JOIN `enwiki_p`.`page` ON rev_page = page_id', $join );
		static::assertStringContainsString( 'page_namespace = :namespace', $cond );
	}

	/**
	 * Without date filtering, getUserConditions() adds the timestamp > 1 clause to force the timestamp
	 * index; with date filtering it omits it.
	 */
	public function testGetUserConditionsTogglesTimestampClauseOnDateFiltering(): void {
		$repo = $this->makeRepository( true );

		$noDates = $repo->getUserConditions( false );
		static::assertStringContainsString( 'rev_timestamp > 1', $noDates['whereRev'] );
		static::assertStringContainsString( 'ar_timestamp > 1', $noDates['whereArc'] );

		$withDates = $repo->getUserConditions( true );
		static::assertStringNotContainsString( 'rev_timestamp > 1', $withDates['whereRev'] );
		static::assertStringNotContainsString( 'ar_timestamp > 1', $withDates['whereArc'] );

		// The actor filter is the always-present half of both clauses, on either side of the toggle.
		static::assertStringContainsString( 'rev_actor = :actorId', $withDates['whereRev'] );
		static::assertStringContainsString( 'ar_actor = :actorId', $withDates['whereArc'] );
	}

	/**
	 * An IP is treated as existing globally without a query, since CentralAuth doesn't track IPs.
	 */
	public function testExistsGloballyReturnsTrueForIp(): void {
		$repo = $this->makeRepository( true );
		static::assertTrue( $repo->existsGlobally( $this->makeIpUser( '10.0.0.5' ) ) );
		static::assertSame( '', $repo->lastSql );
	}

	/**
	 * For a named account existsGlobally() queries centralauth and casts the first column to bool.
	 */
	public function testExistsGloballyQueriesCentralAuthForNamedUser(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->firstColumnResult( [ 1 ] ) ];

		static::assertTrue( $repo->existsGlobally( $this->makeUser() ) );
		static::assertStringContainsString( 'centralauth_p.globaluser', $repo->lastSql );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
	}

	/**
	 * getUserRights() returns an empty list for an IP, which has no local rights.
	 */
	public function testGetUserRightsReturnsEmptyForIp(): void {
		$repo = $this->makeRepository( true );
		static::assertSame( [], $repo->getUserRights( $this->makeProject(), $this->makeIpUser( '10.0.0.5' ) ) );
	}

	/**
	 * A temporary account short-circuits to the synthetic 'temp' right without a query.
	 */
	public function testGetUserRightsReturnsTempForTemporaryUser(): void {
		$repo = $this->makeRepository( true );
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( false );
		$user->method( 'isTemp' )->willReturn( true );

		static::assertSame( [ 'temp' ], $repo->getUserRights( $this->makeProject(), $user ) );
	}

	/**
	 * A named non-temp account's rights come from the user_groups query, returned as the first column.
	 */
	public function testGetUserRightsQueriesUserGroupsForNamedUser(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedQueries = [ '*' => $this->firstColumnResult( [ 'sysop', 'bureaucrat' ] ) ];

		$rights = $repo->getUserRights( $this->makeProject(), $this->makeUser() );
		static::assertSame( [ 'sysop', 'bureaucrat' ], $rights );
		static::assertStringContainsString( 'user_groups', $repo->lastSql );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
	}

	/**
	 * getGlobalUserRights() reads the groups list out of a batchcomplete API response.
	 */
	public function testGetGlobalUserRightsExtractsGroupsFromApiResponse(): void {
		$repo = $this->makeRepository( true );
		$repo->cannedApiResponse = [
			'batchcomplete' => '',
			'query' => [ 'globaluserinfo' => [ 'groups' => [ 'steward', 'sysadmin' ] ] ],
		];

		$groups = $repo->getGlobalUserRights( 'Jimbo', $this->makeProject() );
		static::assertSame( [ 'steward', 'sysadmin' ], $groups );
	}

	/**
	 * With no project passed, getGlobalUserRights() falls back to the default project from
	 * ProjectRepository before making the API request.
	 */
	public function testGetGlobalUserRightsFallsBackToDefaultProject(): void {
		$projectRepo = $this->createMock( ProjectRepository::class );
		$projectRepo->expects( static::once() )->method( 'getDefaultProject' )->willReturn( $this->makeProject() );

		$repo = $this->makeRepository( true, $projectRepo );
		$repo->cannedApiResponse = [];

		// No batchcomplete/groups in the response, so the result is the empty default.
		static::assertSame( [], $repo->getGlobalUserRights( 'Jimbo' ) );
	}

	/**
	 * A Project stubbed with the seams the query builders touch. getTableName() quotes the labs-style
	 * name and getCacheKey()/getDatabaseName() keep the cache and SQL wiring happy.
	 */
	private function makeProject( string $domain = 'en.wikipedia.org' ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( $domain );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A named account. isIP()/isIpRange()/isTemp() are false so the actor-based paths run; getId() and
	 * getActorId() resolve so param binding has values.
	 */
	private function makeUser( string $username = 'Jimbo' ): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( false );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'isTemp' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( $username );
		$user->method( 'getId' )->willReturn( 7 );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A single-IP User: isIP() true but isIpRange() false, so the IP-index queries bind the sanitised
	 * single address.
	 */
	private function makeIpUser( string $ip ): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( true );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( $ip );
		return $user;
	}

	/**
	 * An IP-range User. getUsername() returns a real CIDR so IPUtils::parseRange()/sanitizeRange()
	 * yield the hex bounds and sanitised range the queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIP' )->willReturn( true );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		return $user;
	}

	/**
	 * A Doctrine Result whose fetchAssociative() returns a single row.
	 * @param array<string, mixed> $row
	 */
	private function assocRowResult( array $row ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( $row );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchOne() returns the given scalar.
	 */
	private function scalarResult( mixed $value ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( $value );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchFirstColumn() returns the given list.
	 * @param array<int, mixed> $column
	 */
	private function firstColumnResult( array $column ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchFirstColumn' )->willReturn( $column );
		return $result;
	}

	/**
	 * The generalised test repository: executeProjectsQuery() records the built SQL and params into
	 * public properties and returns whichever canned Result matches the SQL, using a substring-keyed
	 * lookup ('*' = any) like the sibling repository tests. executeApiRequest() is overridden to return
	 * a canned array so getGlobalUserRights() never hits the network. A real ArrayAdapter backs the
	 * cache so getCacheKey()/setCache() behave without infra.
	 */
	private function makeRepository(
		bool $isWMF,
		?ProjectRepository $projectRepo = null,
		?RequestStack $requestStack = null,
		?ParameterBagInterface $parameterBag = null
	): UserRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag ?? $this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$projectRepo ?? $this->createMock( ProjectRepository::class ),
			$requestStack
		) extends UserRepository {

			/** @var array<string, Result> Canned Results keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

			/** @var array Canned API response returned by executeApiRequest(). */
			public array $cannedApiResponse = [];

			/** @var string The SQL built by the last executeProjectsQuery() call. */
			public string $lastSql = '';

			/** @var array The params bound by the last executeProjectsQuery() call. */
			public array $lastParams = [];

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$this->lastSql = $sql;
				$this->lastParams = $params;
				foreach ( $this->cannedQueries as $needle => $result ) {
					if ( $needle === '*' || str_contains( $sql, $needle ) ) {
						return $result;
					}
				}
				throw new \LogicException( "No canned result for query: $sql" );
			}

			public function executeApiRequest( Project $project, array $params ): array {
				return $this->cannedApiResponse;
			}
		};
	}
}
