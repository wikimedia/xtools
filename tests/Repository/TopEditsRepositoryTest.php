<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Edit;
use App\Model\Page;
use App\Model\Project;
use App\Model\User;
use App\Repository\EditRepository;
use App\Repository\ProjectRepository;
use App\Repository\TopEditsRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The PageAssessments selects that getPaSelects() builds are a WMF-only feature: only WMF wikis run
 * the PageAssessments extension, so off-WMF we must not emit the pa_class / pap_project_title
 * subqueries against tables that don't exist. isWMF is a constructor argument, so injecting it both
 * ways covers the branch in one run. The method is pure string construction with no I/O, so we reach
 * it through ReflectionMethod and stub the Project's table-name and hasPageAssessments() lookups.
 * @covers \App\Repository\TopEditsRepository
 */
class TopEditsRepositoryTest extends TestCase {

	/**
	 * On WMF with a project that carries page assessments, the fragment includes the pa_class
	 * subquery so the caller can surface assessment classes alongside the top edits.
	 */
	public function testGetPaSelectsIncludesAssessmentsOnWmf(): void {
		$project = $this->makeProject( true );
		$fragment = $this->getPaSelects( $this->makeRepository( true ), $project, 0 );
		static::assertStringContainsString( 'pa_class', $fragment );
		static::assertStringContainsString( 'pap_project_title', $fragment );
	}

	/**
	 * Off-WMF the branch short-circuits regardless of the project, so no assessment subqueries are
	 * emitted against tables the third-party install doesn't have.
	 */
	public function testGetPaSelectsOmitsAssessmentsOffWmf(): void {
		$project = $this->makeProject( true );
		$fragment = $this->getPaSelects( $this->makeRepository( false ), $project, 0 );
		static::assertStringNotContainsString( 'pa_class', $fragment );
		static::assertSame( '', $fragment );
	}

	/**
	 * Even on WMF, a project without page assessments (both halves of the condition must hold) yields no
	 * assessment subqueries.
	 */
	public function testGetPaSelectsOmitsAssessmentsWhenProjectHasNone(): void {
		$project = $this->makeProject( false );
		$fragment = $this->getPaSelects( $this->makeRepository( true ), $project, 0 );
		static::assertSame( '', $fragment );
	}

	/**
	 * getEdit() is a pure model factory: it hands the injected repositories, the page, and the
	 * revision row to a new Edit, so the returned object reflects the row's id/timestamp/comment
	 * without touching the database.
	 */
	public function testGetEditBuildsEditFromRevisionRow(): void {
		$repo = $this->makeQueryRepository( true );
		$page = $this->createMock( Page::class );
		$edit = $repo->getEdit( $page, [
			'id' => '4567',
			'timestamp' => '20200102030405',
			'minor' => '0',
			'length' => '120',
			'length_change' => '20',
			'comment' => 'tidy up',
			'username' => 'Jimbo',
		] );
		static::assertInstanceOf( Edit::class, $edit );
		static::assertSame( 4567, $edit->getId() );
		static::assertSame( 'tidy up', $edit->getComment() );
		static::assertSame( 20, $edit->getLengthChange() );
	}

	/**
	 * getTopEditsNamespace() for a named account filters by actor and pins the namespace, and on
	 * WMF with an assessments-enabled project it stitches in the pa_class subquery.
	 */
	public function testGetTopEditsNamespaceUsesActorAndAssessmentsOnWmf(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getTopEditsNamespace( $this->makeProject( true ), $this->makeUser(), 3 );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->capturedSql );
		static::assertStringContainsString( 'AND page_namespace = :namespace', $repo->capturedSql );
		static::assertStringContainsString( 'pa_class', $repo->capturedSql );
		static::assertStringNotContainsString( 'ipc_hex', $repo->capturedSql );
	}

	/**
	 * The IP-range branch of getTopEditsNamespace() switches to an ip_changes join over the hex range,
	 * and a start date threads getDateConditions() into the WHERE clause.
	 */
	public function testGetTopEditsNamespaceUsesIpChangesJoinAndDateBound(): void {
		$repo = $this->makeQueryRepository( true );
		$start = mktime( 0, 0, 0, 1, 1, 2020 );
		$repo->getTopEditsNamespace( $this->makeProject( true ), $this->makeIpRangeUser(), 0, $start );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`ip_changes` ON rev_id = ipc_rev_id', $repo->capturedSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertStringContainsString( "rev_timestamp >= '20200101000000'", $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
		static::assertArrayHasKey( 'endIp', $repo->capturedParams );
	}

	/**
	 * A numeric namespace adds the page_namespace filter to countPagesNamespace().
	 */
	public function testCountPagesNamespaceAddsNamespaceFilterWhenNumeric(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->countPagesNamespace( $this->makeProject( false ), $this->makeUser(), 2 );
		static::assertStringContainsString( 'COUNT(DISTINCT page_id)', $repo->capturedSql );
		static::assertStringContainsString( 'AND page_namespace = :namespace', $repo->capturedSql );
	}

	/**
	 * The 'all' pseudo-namespace drops the page_namespace filter from countPagesNamespace().
	 */
	public function testCountPagesNamespaceOmitsNamespaceFilterForAll(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->countPagesNamespace( $this->makeProject( false ), $this->makeUser(), 'all' );
		static::assertStringNotContainsString( 'AND page_namespace = :namespace', $repo->capturedSql );
	}

	/**
	 * The IP-range branch of countPagesNamespace() switches to the ip_changes join and binds the hex
	 * bounds instead of filtering by actor.
	 */
	public function testCountPagesNamespaceUsesIpChangesJoinForRange(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->countPagesNamespace( $this->makeProject( false ), $this->makeIpRangeUser(), 0 );
		static::assertStringContainsString( 'ON rev_id = ipc_rev_id', $repo->capturedSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
	}

	/**
	 * getProjectTotals() joins the page_assessments tables to aggregate per-Wikiproject edit counts
	 * for a named account, filtering by actor.
	 */
	public function testGetProjectTotalsJoinsAssessmentsForActor(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getProjectTotals( $this->makeProject( true ), $this->makeUser(), 0 );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`page_assessments`', $repo->capturedSql );
		static::assertStringContainsString( 'pa_page_id = page_id', $repo->capturedSql );
		static::assertStringContainsString( 'pap_project_title', $repo->capturedSql );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->capturedSql );
	}

	/**
	 * The IP-range branch of getProjectTotals() swaps the actor filter for the ip_changes hex range, and
	 * the hex bounds reach the query as bound params (the :startIp/:endIp placeholders would otherwise
	 * be sent unbound and the query would throw for an IP range).
	 */
	public function testGetProjectTotalsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getProjectTotals( $this->makeProject( true ), $this->makeIpRangeUser(), 0 );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`ip_changes` ON rev_id = ipc_rev_id', $repo->capturedSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertStringNotContainsString( 'rev_actor = :actorId', $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
		static::assertArrayHasKey( 'endIp', $repo->capturedParams );
	}

	/**
	 * getTopEditsAllNamespaces() partitions by namespace with a window function; on WMF with an
	 * assessments-enabled project it also folds in the pa_class subquery for a named account.
	 */
	public function testGetTopEditsAllNamespacesUsesWindowAndAssessmentsOnWmf(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getTopEditsAllNamespaces( $this->makeProject( true ), $this->makeUser() );
		static::assertStringContainsString( 'ROW_NUMBER() OVER', $repo->capturedSql );
		static::assertStringContainsString( 'pa_class', $repo->capturedSql );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->capturedSql );
	}

	/**
	 * The IP-range branch of getTopEditsAllNamespaces() switches to the ip_changes join.
	 */
	public function testGetTopEditsAllNamespacesUsesIpChangesJoinForRange(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getTopEditsAllNamespaces( $this->makeProject( false ), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
	}

	/**
	 * The ProofreadPage branch is reachable through getTopEditsAllNamespaces(): when the project
	 * reports the namespace as a proofread page, getPrpConditions() adds the prp_quality select and
	 * the page_props LEFT JOIN.
	 */
	public function testGetTopEditsAllNamespacesAddsProofreadJoinForPrpPage(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->getTopEditsAllNamespaces( $this->makeProject( false, true ), $this->makeUser() );
		static::assertStringContainsString( 'pp_value as `prp_quality`', $repo->capturedSql );
		static::assertStringContainsString( 'proofread_page_quality_level', $repo->capturedSql );
	}

	/**
	 * getTopEditsPage() unions the most-recent revision (queried without child revs) ahead of the
	 * child-rev result set so the latest edit isn't dropped by the childrevs exclusion.
	 */
	public function testGetTopEditsPagePrependsLatestRevision(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->cannedResults = [
			// First call (childRevs=true) returns the older edit; second call (childRevs=false)
			// returns the newest, which must be prepended.
			$this->assocResult( [ [ 'id' => '10' ] ] ),
			$this->assocResult( [ [ 'id' => '20' ] ] ),
		];
		$results = $repo->getTopEditsPage( $this->makePage(), $this->makeUser() );
		static::assertSame( [ [ 'id' => '20' ], [ 'id' => '10' ] ], $results );
	}

	/**
	 * When the most-recent revision is already the first child-rev row (same id), the dedupe guard
	 * skips the prepend so the latest edit isn't listed twice.
	 */
	public function testGetTopEditsPageDoesNotDuplicateLatestRevisionWhenAlreadyPresent(): void {
		$repo = $this->makeQueryRepository( true );
		$repo->cannedResults = [
			// Both calls return the same newest edit, so the merge guard must not prepend it again.
			$this->assocResult( [ [ 'id' => '20' ] ] ),
			$this->assocResult( [ [ 'id' => '20' ] ] ),
		];
		$results = $repo->getTopEditsPage( $this->makePage(), $this->makeUser() );
		static::assertSame( [ [ 'id' => '20' ] ], $results );
	}

	/**
	 * queryTopEditsPage() with child revisions on builds the reverted CASE and the childrevs join,
	 * binds the page id, and quotes the username through the connection.
	 */
	public function testQueryTopEditsPageWithChildRevsBuildsRevertedCase(): void {
		$repo = $this->makeQueryRepository( true );
		$this->invokeQueryTopEditsPage( $repo, $this->makePage(), $this->makeUser(), true );
		static::assertStringContainsString( '`reverted`', $repo->capturedSql );
		static::assertStringContainsString( 'childrevs', $repo->capturedSql );
		static::assertStringContainsString( "'mw-reverted'", $repo->capturedSql );
		static::assertStringNotContainsString( 'LIMIT 1', $repo->capturedSql );
		static::assertSame( 555, $repo->capturedParams['pageid'] );
	}

	/**
	 * Without child revisions queryTopEditsPage() drops the reverted machinery and limits to the
	 * single most recent row.
	 */
	public function testQueryTopEditsPageWithoutChildRevsLimitsToOne(): void {
		$repo = $this->makeQueryRepository( true );
		$this->invokeQueryTopEditsPage( $repo, $this->makePage(), $this->makeUser(), false );
		static::assertStringContainsString( '"" AS parent_comment, 0 AS reverted', $repo->capturedSql );
		static::assertStringContainsString( 'LIMIT 1', $repo->capturedSql );
		static::assertStringNotContainsString( 'childrevs', $repo->capturedSql );
	}

	/**
	 * The IP-range branch of queryTopEditsPage() switches to an ip_changes join on revs.rev_id and binds
	 * the hex bounds alongside the page id.
	 */
	public function testQueryTopEditsPageUsesIpChangesJoinForRange(): void {
		$repo = $this->makeQueryRepository( true );
		$this->invokeQueryTopEditsPage( $repo, $this->makePage(), $this->makeIpRangeUser(), true );
		static::assertStringContainsString( 'ON revs.rev_id = ipc_rev_id', $repo->capturedSql );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->capturedSql );
		static::assertArrayHasKey( 'startIp', $repo->capturedParams );
	}

	/**
	 * A Project stubbed to report the given page-assessments availability, with table-name lookups
	 * echoing back a recognisable name so the built fragment is inspectable.
	 * @param bool $hasPageAssessments Value hasPageAssessments() reports.
	 * @param bool $isPrpPage Whether the namespace is a ProofreadPage page.
	 */
	private function makeProject( bool $hasPageAssessments, bool $isPrpPage = false ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'hasPageAssessments' )->willReturn( $hasPageAssessments );
		$project->method( 'isPrpPage' )->willReturn( $isPrpPage );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki' );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A named account: isIpRange() is false so the actor-based query paths run, and getId() resolves
	 * so queryTopEditsPage()'s user_id select has a value to inline.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );
		$user->method( 'getActorId' )->willReturn( 1 );
		$user->method( 'getId' )->willReturn( 42 );
		return $user;
	}

	/**
	 * A User representing an IP range. getUsername() returns a real CIDR so IPUtils::parseRange()
	 * yields the start/end hex bounds the ip_changes queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		$user->method( 'getId' )->willReturn( null );
		return $user;
	}

	/**
	 * A Page whose project is a plain (non-assessments, non-PRP) project and whose id feeds the
	 * bound :pageid param.
	 */
	private function makePage(): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $this->makeProject( false ) );
		$page->method( 'getId' )->willReturn( 555 );
		return $page;
	}

	/**
	 * Invoke the private queryTopEditsPage() past its visibility, with $childRevs toggling the
	 * child-revision (reverted-detection) machinery.
	 */
	private function invokeQueryTopEditsPage(
		TopEditsRepository $repo,
		Page $page,
		User $user,
		bool $childRevs
	): array {
		return ( new ReflectionMethod( TopEditsRepository::class, 'queryTopEditsPage' ) )
			->invoke( $repo, $page, $user, false, false, $childRevs );
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAllAssociative(), with fetchOne() handing
	 * back the row count so the scalar-count methods have something to read.
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		$result->method( 'fetchOne' )->willReturn( count( $rows ) );
		return $result;
	}

	/**
	 * A TopEditsRepository whose executeQuery() captures the built SQL and params into public
	 * properties and returns Results off a per-call queue (falling back to an empty Result), so the
	 * branch logic and row handling run without a replica. getProjectsConnection() is overridden to
	 * a mock Connection whose quote() echoes the value, keeping queryTopEditsPage() off real infra.
	 * A real ArrayAdapter backs the cache so getCacheKey()/setCache() behave without infra.
	 */
	private function makeQueryRepository( bool $isWMF ): TopEditsRepository {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'quote' )->willReturnCallback(
			static fn ( string $value ): string => "'$value'"
		);

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			$this->createMock( EditRepository::class ),
			$this->createMock( UserRepository::class ),
			null
		) extends TopEditsRepository {

			/** @var string The SQL built by the last executeQuery() call. */
			public string $capturedSql = '';

			/** @var array The params bound by the last executeQuery() call. */
			public array $capturedParams = [];

			/** @var array<Result> Results returned per executeQuery() call, FIFO. */
			public array $cannedResults = [];

			public Connection $stubConnection;

			public Result $emptyResult;

			protected function executeQuery(
				string $sql,
				Project $project,
				User $user,
				int|string|null $namespace = 'all',
				array $extraParams = []
			): Result {
				$this->capturedSql = $sql;
				$this->capturedParams = $extraParams;
				return array_shift( $this->cannedResults ) ?? $this->emptyResult;
			}

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->stubConnection;
			}
		};
		$repo->stubConnection = $connection;
		$repo->emptyResult = $this->assocResult( [] );
		return $repo;
	}

	/**
	 * Invoke the private getPaSelects() past its visibility.
	 * @param TopEditsRepository $repo
	 * @param Project $project
	 * @param int|string $namespace
	 */
	private function getPaSelects( TopEditsRepository $repo, Project $project, $namespace ): string {
		return ( new ReflectionMethod( TopEditsRepository::class, 'getPaSelects' ) )
			->invoke( $repo, $project, $namespace );
	}

	/**
	 * A TopEditsRepository with all collaborators mocked. getPaSelects() does no I/O, so only isWMF
	 * matters here; the rest are supplied to satisfy the constructor.
	 */
	private function makeRepository( bool $isWMF ): TopEditsRepository {
		return new TopEditsRepository(
			$this->createMock( ManagerRegistry::class ),
			$this->createMock( CacheItemPoolInterface::class ),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			$this->createMock( EditRepository::class ),
			$this->createMock( UserRepository::class ),
			null
		);
	}
}
