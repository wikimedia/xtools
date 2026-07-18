<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Project;
use App\Model\User;
use App\Repository\AutoEditsRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * AutoEditsRepository is SQL-assembly over a config-driven list of semi-automated tools; nothing here
 * touches a replica. The tool list comes from an injected AutomatedEditsHelper (mocked to return a
 * canned config), and three seams are overridden in a test subclass: executeQuery() for the big
 * per-user queries, executeProjectsQuery() for the tag-ID lookup in getTags(), and
 * getProjectsConnection() for the quoting the SQL builders do. Each seam records the built SQL/params
 * so branch logic (regex vs tags, IP-range branch, tool-specific tag_excludes, namespace filtering) can
 * be asserted against the generated query. A real ArrayAdapter backs the cache so
 * getCacheKey()/setCache() behave as in production; the sandbox short-circuit in setCache() is the one
 * behaviour we check against the adapter directly.
 * @covers \App\Repository\AutoEditsRepository
 */
class AutoEditsRepositoryTest extends TestCase {

	/**
	 * setUseSandbox() is a fluent setter; getUseSandbox() reads it back.
	 */
	public function testUseSandboxRoundTrips(): void {
		$repo = $this->makeRepository();
		static::assertFalse( $repo->getUseSandbox() );
		static::assertSame( $repo, $repo->setUseSandbox( true ) );
		static::assertTrue( $repo->getUseSandbox() );
	}

	/**
	 * With namespace 'all', getTools() returns the whole config untouched.
	 */
	public function testGetToolsReturnsFullListForAllNamespaces(): void {
		$repo = $this->makeRepository();
		$tools = $repo->getTools( $this->makeProject(), 'all' );
		static::assertArrayHasKey( 'Twinkle', $tools );
		static::assertArrayHasKey( 'Huggle', $tools );
		static::assertArrayHasKey( 'invalid', $tools );
	}

	/**
	 * A numeric namespace keeps tools with no namespace restriction and tools that list the given
	 * namespace; Twinkle is restricted to [0,1] so it survives ns 0 and drops from ns 2.
	 */
	public function testGetToolsFiltersByNamespace(): void {
		$repo = $this->makeRepository();
		$ns0 = $repo->getTools( $this->makeProject(), 0 );
		static::assertArrayHasKey( 'Twinkle', $ns0 );

		$repo2 = $this->makeRepository();
		$ns2 = $repo2->getTools( $this->makeProject(), 2 );
		static::assertArrayNotHasKey( 'Twinkle', $ns2 );
		// Huggle has no 'namespaces' restriction, so it survives any namespace.
		static::assertArrayHasKey( 'Huggle', $ns2 );
	}

	/**
	 * The odd-namespace talk branch: a tool with 'talk_namespaces' set is kept for an odd (talk)
	 * namespace even when the numeric namespace isn't in its 'namespaces' list.
	 */
	public function testGetToolsKeepsTalkNamespaceToolForOddNamespace(): void {
		$repo = $this->makeRepository();
		$tools = $repo->getTools( $this->makeProject(), 3 );
		static::assertArrayHasKey( 'TalkOnly', $tools );
	}

	/**
	 * getTools() memoises the helper lookup: calling it twice must hit AutomatedEditsHelper only once.
	 */
	public function testGetToolsCachesHelperLookup(): void {
		$helper = $this->createMock( AutomatedEditsHelper::class );
		$helper->expects( static::once() )
			->method( 'getTools' )
			->willReturn( $this->toolConfig() );

		$repo = $this->makeRepository( false, $helper );
		$project = $this->makeProject();
		$repo->getTools( $project );
		$repo->getTools( $project );
	}

	/**
	 * getInvalidTools() returns the 'invalid' entry and strips it from the memoised list, so a later
	 * getTools() no longer carries it.
	 */
	public function testGetInvalidToolsReturnsAndRemovesInvalidEntry(): void {
		$repo = $this->makeRepository();
		$project = $this->makeProject();
		$invalid = $repo->getInvalidTools( $project );
		static::assertSame( [ 'Bad Tool Label' ], $invalid );
		static::assertArrayNotHasKey( 'invalid', $repo->getTools( $project ) );
	}

	/**
	 * In sandbox mode setCache() returns the value without persisting, so the cache stays empty.
	 */
	public function testSetCacheSkipsPersistInSandboxMode(): void {
		$cache = new ArrayAdapter();
		$repo = $this->makeRepository( false, null, $cache );
		$repo->setUseSandbox( true );
		static::assertSame( 'v', $repo->setCache( 'k', 'v' ) );
		static::assertFalse( $cache->getItem( 'k' )->isHit() );
	}

	/**
	 * Off sandbox, setCache() delegates to the parent and the value is stored in the adapter.
	 */
	public function testSetCachePersistsOutsideSandboxMode(): void {
		$cache = new ArrayAdapter();
		$repo = $this->makeRepository( false, null, $cache );
		static::assertSame( 'v', $repo->setCache( 'k', 'v' ) );
		static::assertTrue( $cache->getItem( 'k' )->isHit() );
		static::assertSame( 'v', $cache->getItem( 'k' )->get() );
	}

	/**
	 * For a named account, countAutomatedEdits() filters by actor and assembles both the regex clause
	 * (comment_text REGEXP :tools) and the tag clause (ct_tag_id IN ...) OR'd together.
	 */
	public function testCountAutomatedEditsBuildsRegexAndTagClausesForActor(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->scalarResult( '42' );

		$count = $repo->countAutomatedEdits( $this->makeProject(), $this->makeUser() );
		static::assertSame( 42, $count );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertStringContainsString( 'comment_text REGEXP :tools', $repo->lastSql );
		static::assertStringContainsString( 'ct_tag_id IN (', $repo->lastSql );
		static::assertArrayHasKey( 'tools', $repo->lastParams );
	}

	/**
	 * An IP range has no actor, so countAutomatedEdits() joins ip_changes over the hex range and binds
	 * the start/end bounds from IPUtils::parseRange().
	 */
	public function testCountAutomatedEditsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->scalarResult( '3' );

		$repo->countAutomatedEdits( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
		static::assertArrayHasKey( 'endIp', $repo->lastParams );
	}

	/**
	 * getNonAutomatedEdits() inverts the regex (NOT RLIKE :tools) and, when any tags resolve, adds the
	 * NOT EXISTS tag-exclusion subquery so tagged automated edits are filtered out.
	 */
	public function testGetNonAutomatedEditsBuildsNotRlikeAndTagExclusion(): void {
		$repo = $this->makeRepository();
		$rows = [ [ 'rev_id' => 1 ] ];
		$repo->cannedResult = $this->assocResult( $rows );

		$result = $repo->getNonAutomatedEdits( $this->makeProject(), $this->makeUser(), 0 );
		static::assertSame( $rows, $result );
		static::assertStringContainsString( 'comment_text NOT RLIKE :tools', $repo->lastSql );
		// NOT RLIKE only excludes automated edits if :tools actually binds the resolved regex;
		// an empty bind would make NOT RLIKE '' match every row and admit automated edits.
		static::assertStringContainsString( 'Twinkle', $repo->lastParams['tools'] );
		static::assertStringContainsString( 'NOT EXISTS', $repo->lastSql );
		static::assertStringContainsString( 'AND page_namespace = :namespace', $repo->lastSql );
	}

	/**
	 * The IP-range branch of getNonAutomatedEdits() swaps the actor filter for the ip_changes hex range.
	 */
	public function testGetNonAutomatedEditsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getNonAutomatedEdits( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
	}

	/**
	 * With no start date, getAutomatedEdits() takes the index-forcing shortcut (rev_timestamp > 0)
	 * rather than a date range, and OR's the regex clause with the tag clause.
	 */
	public function testGetAutomatedEditsUsesTimestampShortcutWithoutStartDate(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getAutomatedEdits( $this->makeProject(), $this->makeUser() );
		static::assertStringContainsString( 'AND revs.rev_timestamp > 0', $repo->lastSql );
		static::assertStringContainsString( 'comment_text RLIKE :tools', $repo->lastSql );
		static::assertStringContainsString( 'ct_tag_id IN (', $repo->lastSql );
	}

	/**
	 * Passing a specific tool with tag_excludes (Huggle) triggers the tool-scoped NOT EXISTS branch, so
	 * the query excludes revisions carrying the excluded tag (rollback) as well as matching the tool tag.
	 */
	public function testGetAutomatedEditsAppliesTagExcludesForNamedTool(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getAutomatedEdits( $this->makeProject(), $this->makeUser(), 'all', false, false, 'Huggle' );
		static::assertStringContainsString( 'NOT EXISTS', $repo->lastSql );
		static::assertStringContainsString( 'ct_tag_id IN (', $repo->lastSql );
	}

	/**
	 * The IP-range branch of getAutomatedEdits() switches to the ip_changes hex range.
	 */
	public function testGetAutomatedEditsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getAutomatedEdits( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
	}

	/**
	 * getToolCounts() builds a UNION-of-counts query, then folds the returned rows into a map keyed by
	 * tool (with link/label/count), dropping tools with a zero count and sorting the rest by count desc.
	 */
	public function testGetToolCountsDropsZeroCountsAndSortsByCount(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [
			[ 'toolname' => 'Twinkle', 'count' => 5 ],
			[ 'toolname' => 'AWB', 'count' => 9 ],
			[ 'toolname' => 'Huggle', 'count' => 0 ],
		] );

		$counts = $repo->getToolCounts( $this->makeProject(), $this->makeUser() );
		// Zero-count tool is dropped.
		static::assertArrayNotHasKey( 'Huggle', $counts );
		// Sorted by count descending: AWB (9) before Twinkle (5).
		static::assertSame( [ 'AWB', 'Twinkle' ], array_keys( $counts ) );
		static::assertSame( 'WP:Twinkle', $counts['Twinkle']['link'] );
		static::assertSame( 'Twinkle', $counts['Twinkle']['label'] );
		// The UNION structure comes from getAutomatedCountsSql().
		static::assertStringContainsString( 'UNION', $repo->lastSql );
	}

	/**
	 * The IP-range branch of getToolCounts()/getAutomatedCountsSql(): the per-tool count queries join
	 * ip_changes over the hex range, and the start/end bounds are bound as params.
	 */
	public function testGetToolCountsUsesIpChangesJoinForRange(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [ [ 'toolname' => 'AWB', 'count' => 4 ] ] );

		$counts = $repo->getToolCounts( $this->makeProject(), $this->makeIpRangeUser() );
		static::assertSame( 4, $counts['AWB']['count'] );
		static::assertStringContainsString( 'ipc_hex BETWEEN :startIp AND :endIp', $repo->lastSql );
		static::assertArrayHasKey( 'startIp', $repo->lastParams );
		static::assertArrayHasKey( 'endIp', $repo->lastParams );
	}

	/**
	 * When the requested tool contributes neither a regex nor a resolvable tag (a contribs-only tool),
	 * getAutomatedEdits() falls through to the empty tool-condition branch, so the query carries no
	 * comment_text or ct_tag_id filter.
	 */
	public function testGetAutomatedEditsOmitsToolConditionForContribsTool(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getAutomatedEdits( $this->makeProject(), $this->makeUser(), 'all', false, false, 'SomeContribTool' );
		static::assertStringNotContainsString( 'comment_text RLIKE :tools', $repo->lastSql );
		static::assertStringNotContainsString( 'ct_tag_id IN (', $repo->lastSql );
	}

	/**
	 * getInnerAutomatedCountsSql() with a regex-only tool emits a comment_text REGEXP clause and the
	 * comment-table join, and no tag join.
	 */
	public function testGetInnerAutomatedCountsSqlRegexOnly(): void {
		$repo = $this->makeRepository();
		[ $condTool, $commentJoin, $tagJoin ] = $this->invokePrivate(
			$repo, 'getInnerAutomatedCountsSql', $this->makeProject(), 'RegexOnly', [ 'regex' => 'Foo' ]
		);
		static::assertStringContainsString( "comment_text REGEXP 'Foo'", $condTool );
		static::assertStringContainsString( 'comment', $commentJoin );
		static::assertSame( '', $tagJoin );
	}

	/**
	 * A tags-only tool emits a tag clause (ct_tag_id IN ...) and the change_tag join, with no regex.
	 */
	public function testGetInnerAutomatedCountsSqlTagsOnly(): void {
		$repo = $this->makeRepository();
		[ $condTool, $commentJoin, $tagJoin ] = $this->invokePrivate(
			$repo, 'getInnerAutomatedCountsSql', $this->makeProject(), 'AWB', [ 'tags' => [ 'awb' ] ]
		);
		static::assertStringContainsString( 'ct_tag_id IN (', $condTool );
		static::assertStringContainsString( 'change_tag', $tagJoin );
		static::assertSame( '', $commentJoin );
	}

	/**
	 * A tool carrying both a regex and a resolvable tag combines them as ($regex OR $tags).
	 */
	public function testGetInnerAutomatedCountsSqlCombinesRegexAndTags(): void {
		$repo = $this->makeRepository();
		[ $condTool ] = $this->invokePrivate(
			$repo, 'getInnerAutomatedCountsSql', $this->makeProject(), 'AWB', [ 'regex' => 'AWB', 'tags' => [ 'awb' ] ]
		);
		static::assertStringContainsString( 'OR', $condTool );
		static::assertStringContainsString( "comment_text REGEXP 'AWB'", $condTool );
		static::assertStringContainsString( 'ct_tag_id IN (', $condTool );
	}

	/**
	 * getTags() runs the ctd_name lookup, keys the returned rows name=>id, and caches them; the built
	 * SQL carries the quoted-name IN clause.
	 */
	public function testGetTagsQueriesTagDefsAndCaches(): void {
		$repo = $this->makeRepository();
		$tags = $repo->getTags( $this->makeProject() );
		static::assertSame( [ 'huggle' => 11, 'rollback' => 12, 'awb' => 13 ], $tags );
		static::assertStringContainsString( 'ctd_name IN (', $repo->lastProjectsSql );
	}

	/**
	 * getTags() memoises within the request: a second call returns the process-cached map without
	 * re-querying, so executeProjectsQuery() fires only once.
	 */
	public function testGetTagsUsesProcessCacheOnSecondCall(): void {
		$repo = $this->makeRepository();
		$project = $this->makeProject();
		$repo->getTags( $project );
		$repo->lastProjectsSql = '';
		$repo->getTags( $project );
		// No second query ran, so the recorded SQL stayed empty.
		static::assertSame( '', $repo->lastProjectsSql );
	}

	/**
	 * getTagsExclusionsSql() for a tool with tag_excludes (Huggle excludes rollback) appends the
	 * NOT EXISTS subquery filtering the excluded tag ID.
	 */
	public function testGetTagsExclusionsSqlEmitsNotExistsWhenExcludesPresent(): void {
		$repo = $this->makeRepository();
		$sql = $this->invokePrivate( $repo, 'getTagsExclusionsSql', $this->makeProject(), 'Huggle', [ 11 ] );
		static::assertStringContainsString( 'ct_tag_id IN (11)', $sql );
		static::assertStringContainsString( 'NOT EXISTS', $sql );
	}

	/**
	 * A tool with no tag_excludes gets the bare IN clause and no exclusion subquery.
	 */
	public function testGetTagsExclusionsSqlOmitsNotExistsWithoutExcludes(): void {
		$repo = $this->makeRepository();
		$sql = $this->invokePrivate( $repo, 'getTagsExclusionsSql', $this->makeProject(), 'AWB', [ 13 ] );
		static::assertStringContainsString( 'ct_tag_id IN (13)', $sql );
		static::assertStringNotContainsString( 'NOT EXISTS', $sql );
	}

	/**
	 * getTagIdsFromNames() maps known names to their IDs and silently skips names with no local tag.
	 */
	public function testGetTagIdsFromNamesMapsKnownAndSkipsUnknown(): void {
		$repo = $this->makeRepository();
		$ids = $this->invokePrivate(
			$repo, 'getTagIdsFromNames', $this->makeProject(), [ 'huggle', 'nonexistent', 'awb' ]
		);
		static::assertSame( [ 11, 13 ], $ids );
	}

	/**
	 * getToolRegexAndTags() skips contribs-only tools (they show in the list but aren't counted) and,
	 * for a numeric namespace, skips tools restricted to other namespaces. Reached directly so both
	 * skip branches are pinned in one call.
	 */
	public function testGetToolRegexAndTagsSkipsContribsAndNamespaceMismatch(): void {
		$repo = $this->makeRepository();
		// Namespace 2: Twinkle (restricted to [0,1]) is skipped; SomeContribTool (contribs) is skipped.
		[ $regex, $tagIds ] = $this->invokePrivate( $repo, 'getToolRegexAndTags', $this->makeProject(), null, 2 );
		// Twinkle's regex is out (namespace mismatch), AWB's regex remains.
		static::assertStringNotContainsString( 'Twinkle', $regex );
		static::assertStringContainsString( 'AWB', $regex );
		// AWB and Huggle tags resolved.
		static::assertNotSame( '', $tagIds );
	}

	/**
	 * Passing a single tool name narrows getToolRegexAndTags() to just that tool's regex/tags.
	 */
	public function testGetToolRegexAndTagsSelectsSingleTool(): void {
		$repo = $this->makeRepository();
		[ $regex, $tagIds, $tagExcludesIds ] = $this->invokePrivate(
			$repo, 'getToolRegexAndTags', $this->makeProject(), 'Huggle', 'all'
		);
		// Huggle is tags-only with a tag_exclude, so no regex but tag + exclude IDs.
		static::assertSame( '', $regex );
		static::assertSame( '11', $tagIds );
		static::assertSame( '12', $tagExcludesIds );
	}

	/**
	 * Invoke a private/protected method on the repository. Several branches (the tag/exclusion helpers,
	 * getToolRegexAndTags, getInnerAutomatedCountsSql) are only reachable indirectly through the public
	 * query methods; reflection reaches them straight so each branch gets its own focused assertion.
	 */
	private function invokePrivate( AutoEditsRepository $repo, string $method, mixed ...$args ): mixed {
		return ( new ReflectionMethod( AutoEditsRepository::class, $method ) )->invoke( $repo, ...$args );
	}

	/**
	 * The canned tool config the injected AutomatedEditsHelper hands back. Twinkle is regex + namespaced;
	 * Huggle is tags-only with a tag_exclude; AWB is regex + tags; SomeContribTool exercises the
	 * contribs-skip branch; TalkOnly exercises the odd-namespace talk branch; 'invalid' is a malformed
	 * entry for getInvalidTools().
	 * @return array<string, array>
	 */
	private function toolConfig(): array {
		return [
			'Twinkle' => [
				'link' => 'WP:Twinkle', 'label' => 'Twinkle', 'regex' => 'Twinkle', 'namespaces' => [ 0, 1 ],
			],
			'Huggle' => [ 'link' => 'WP:Huggle', 'tags' => [ 'huggle' ], 'tag_excludes' => [ 'rollback' ] ],
			'AWB' => [ 'link' => 'WP:AWB', 'regex' => 'AWB', 'tags' => [ 'awb' ] ],
			'SomeContribTool' => [ 'link' => 'x', 'contribs' => true ],
			'TalkOnly' => [ 'link' => 'WP:TalkOnly', 'namespaces' => [ 0 ], 'talk_namespaces' => [ 1 ] ],
			'invalid' => [ 'Bad Tool Label' ],
		];
	}

	/**
	 * A Project stubbed only as far as the SQL builders reach: getTableName() feeds the query, and
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
	 * A named account: isIpRange() is false so the actor-based query paths run; getActorId() resolves so
	 * param binding has something to bind.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A User representing an IP range: getUsername() returns a real CIDR so IPUtils::parseRange() yields
	 * the start/end hex bounds the ip_changes queries bind.
	 */
	private function makeIpRangeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isIpRange' )->willReturn( true );
		$user->method( 'getUsername' )->willReturn( '10.0.0.0/24' );
		$user->method( 'getActorId' )->willReturn( 0 );
		return $user;
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAllAssociative(), and dequeuing them one at a
	 * time from fetchAssociative() (then false) for the getToolCounts() while-loop.
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		$queue = $rows;
		$result->method( 'fetchAssociative' )->willReturnCallback(
			static function () use ( &$queue ) {
				return array_shift( $queue ) ?? false;
			}
		);
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchOne() returns the given scalar, for countAutomatedEdits().
	 */
	private function scalarResult( mixed $value ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( $value );
		return $result;
	}

	/**
	 * A test AutoEditsRepository with all three DB-touching seams overridden. executeQuery() records the
	 * per-user SQL/params and returns $cannedResult; executeProjectsQuery() records the getTags() lookup
	 * SQL and returns a fixed name=>id map; getProjectsConnection() returns a Connection whose quoting
	 * wraps the value in single quotes so the SQL builders produce inspectable output. A real
	 * ArrayAdapter backs the cache.
	 */
	private function makeRepository(
		bool $isWMF = false,
		?AutomatedEditsHelper $helper = null,
		?ArrayAdapter $cache = null
	): AutoEditsRepository {
		$helper ??= $this->makeHelper();
		$conn = $this->makeConnection();
		$tagResult = $this->createMock( Result::class );
		$tagResult->method( 'fetchAllKeyValue' )->willReturn( [ 'huggle' => 11, 'rollback' => 12, 'awb' => 13 ] );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			$cache ?? new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			$helper,
			null,
			$conn,
			$tagResult
		) extends AutoEditsRepository {

			/** @var Result Canned Result returned from the per-user executeQuery() seam. */
			public Result $cannedResult;

			/** @var string The SQL built by the last executeQuery() call. */
			public string $lastSql = '';

			/** @var array The params bound by the last executeQuery() call. */
			public array $lastParams = [];

			/** @var string The SQL built by the last executeProjectsQuery() call (getTags()). */
			public string $lastProjectsSql = '';

			private Connection $conn;

			private Result $tagResult;

			public function __construct(
				ManagerRegistry $managerRegistry,
				$cache,
				Client $guzzle,
				NullLogger $logger,
				ParameterBagInterface $parameterBag,
				bool $isWMF,
				int $queryTimeout,
				ProjectRepository $projectRepo,
				AutomatedEditsHelper $autoEditsHelper,
				$requestStack,
				Connection $conn,
				Result $tagResult
			) {
				parent::__construct(
					$managerRegistry, $cache, $guzzle, $logger, $parameterBag,
					$isWMF, $queryTimeout, $projectRepo, $autoEditsHelper, $requestStack
				);
				$this->conn = $conn;
				$this->tagResult = $tagResult;
			}

			protected function executeQuery(
				string $sql,
				Project $project,
				User $user,
				int|string|null $namespace = 'all',
				array $extraParams = []
			): Result {
				$this->lastSql = $sql;
				$this->lastParams = $extraParams;
				return $this->cannedResult;
			}

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$this->lastProjectsSql = $sql;
				return $this->tagResult;
			}

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->conn;
			}
		};
	}

	/**
	 * An AutomatedEditsHelper mock returning the canned tool config.
	 */
	private function makeHelper(): AutomatedEditsHelper {
		$helper = $this->createMock( AutomatedEditsHelper::class );
		$helper->method( 'getTools' )->willReturn( $this->toolConfig() );
		return $helper;
	}

	/**
	 * A Doctrine Connection whose quoting helpers wrap the string in single quotes, standing in for the
	 * platform's real quoting so getAutomatedCountsSql()/getTags() produce inspectable SQL.
	 */
	private function makeConnection(): Connection {
		$platform = $this->createMock( AbstractPlatform::class );
		$platform->method( 'quoteStringLiteral' )->willReturnCallback(
			static fn ( string $s ): string => "'" . $s . "'"
		);
		$conn = $this->createMock( Connection::class );
		$conn->method( 'getDatabasePlatform' )->willReturn( $platform );
		$conn->method( 'quote' )->willReturnCallback(
			static fn ( string $s ): string => "'" . $s . "'"
		);
		return $conn;
	}
}
