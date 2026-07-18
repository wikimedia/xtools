<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Edit;
use App\Model\Page;
use App\Model\Project;
use App\Repository\EditRepository;
use App\Repository\PageInfoRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * PageInfoRepository is SQL-assembly and light row post-processing over what a replica hands back for a
 * single page. None of the queries touch a database: a test subclass overrides the executeProjectsQuery()
 * seam to record the built SQL and params and return a canned Result, so the branch logic (count vs. full
 * rows, limit vs. no limit, noBots subquery on/off) and the row transforms are asserted in isolation. A
 * real ArrayAdapter backs the cache so getCacheKey()/setCache() behave as in production. getAutoEditsCounts()
 * additionally leans on the AutoEditsRepository parent seams getTools(), getInnerAutomatedCountsSql(), and
 * getProjectsConnection(); the subclass stubs those to a tiny tool map and a mock Connection so the count>0
 * filter and the uasort ordering are exercised without tag/regex infrastructure.
 * @covers \App\Repository\PageInfoRepository
 */
class PageInfoRepositoryTest extends TestCase {

	/**
	 * In count mode getBotData() drops the `actor_name AS username` select and the `GROUP BY actor_user`,
	 * since the caller only wants the aggregate. It still returns the fetchAllAssociative() rows verbatim.
	 */
	public function testGetBotDataInCountModeOmitsUsernameSelectAndGroupBy(): void {
		$repo = $this->makeRepository();
		$rows = [ [ 'count' => 5, 'current' => '0' ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$result = $repo->getBotData( $this->makePage(), false, false, null, true );
		static::assertSame( $rows, $result );
		static::assertStringNotContainsString( 'actor_name AS username', $repo->lastSql );
		static::assertStringNotContainsString( 'GROUP BY actor_user', $repo->lastSql );
	}

	/**
	 * Non-count mode keeps the username select and the per-actor grouping so callers get one row per bot.
	 */
	public function testGetBotDataInRowModeKeepsUsernameSelectAndGroupBy(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getBotData( $this->makePage(), false, false, null, false );
		static::assertStringContainsString( 'actor_name AS username', $repo->lastSql );
		static::assertStringContainsString( 'GROUP BY actor_user', $repo->lastSql );
	}

	/**
	 * A non-null $limit caps each inner subquery with a LIMIT clause; null leaves it off entirely.
	 */
	public function testGetBotDataAddsLimitClauseOnlyWhenLimitGiven(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getBotData( $this->makePage(), false, false, 100, false );
		static::assertStringContainsString( 'LIMIT 100', $repo->lastSql );

		$repo->getBotData( $this->makePage(), false, false, null, false );
		static::assertStringNotContainsString( 'LIMIT', $repo->lastSql );
	}

	/**
	 * getLogEvents() passes rows through untouched, binds the DB form of the title (spaces to underscores),
	 * and scopes the query to the page's namespace and the delete/move/protect/stable log types.
	 */
	public function testGetLogEventsNormalisesTitleAndScopesToNamespaceAndLogTypes(): void {
		$repo = $this->makeRepository();
		$rows = [ [ 'log_action' => 'move', 'log_type' => 'move', 'timestamp' => '20200101000000' ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$result = $repo->getLogEvents( $this->makePage( 3, 'Talk:Some Page' ), false, false );
		static::assertSame( $rows, $result );
		static::assertSame( 'Talk:Some_Page', $repo->lastParams['title'] );
		static::assertStringContainsString( "log_namespace = '3'", $repo->lastSql );
		static::assertStringContainsString( "log_type IN ('delete', 'move', 'protect', 'stable')", $repo->lastSql );
	}

	/**
	 * getTransclusionData() walks the UNION result with fetchAssociative(), keying each row's `key` column
	 * to its int-cast `val`, so the caller gets a categories/templates/files map.
	 */
	public function testGetTransclusionDataKeysRowsAndCastsValuesToInt(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'key' => 'categories', 'val' => '2' ],
			[ 'key' => 'templates', 'val' => '10' ],
			[ 'key' => 'files', 'val' => '0' ],
		] ) ];

		$data = $repo->getTransclusionData( $this->makePage() );
		static::assertSame( [ 'categories' => 2, 'templates' => 10, 'files' => 0 ], $data );
	}

	/**
	 * getSubpageCount() returns the scalar count from the first row, matching subpage titles with a `/%`
	 * LIKE and filtering on the page's namespace. The replica hands COUNT() back as a string, so the
	 * canned row uses '7' to pin the int cast the `: int` return type depends on.
	 */
	public function testGetSubpageCountReturnsCountAndBindsLikeAndNamespace(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->assocResult( [ [ 'count' => '7' ] ] ) ];

		$count = $repo->getSubpageCount( $this->makePage( 2, 'User:Jimbo' ) );
		static::assertSame( 7, $count );
		static::assertSame( 'Jimbo/%', $repo->lastParams['title'] );
		static::assertSame( 2, $repo->lastParams['namespace'] );
		static::assertStringContainsString( 'page_title LIKE :title', $repo->lastSql );
	}

	/**
	 * With $noBots set, getTopEditorsByEditCount() adds the NOT EXISTS user_groups subquery that filters
	 * out accounts in the 'bot' group. The LIMIT reflects the requested cap.
	 */
	public function testGetTopEditorsByEditCountAddsBotExclusionSubqueryWhenNoBots(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getTopEditorsByEditCount( $this->makePage(), false, false, 10, true );
		static::assertStringContainsString( 'NOT EXISTS', $repo->lastSql );
		static::assertStringContainsString( 'user_groups', $repo->lastSql );
		static::assertStringContainsString( "ug_group = 'bot'", $repo->lastSql );
		static::assertStringContainsString( 'LIMIT 10', $repo->lastSql );
	}

	/**
	 * Without $noBots the bot-exclusion subquery is omitted, so bot accounts are counted like any other.
	 */
	public function testGetTopEditorsByEditCountOmitsBotExclusionSubqueryByDefault(): void {
		$repo = $this->makeRepository();
		$rows = [ [ 'username' => 'Jimbo', 'count' => 42 ] ];
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$result = $repo->getTopEditorsByEditCount( $this->makePage(), false, false, 20, false );
		static::assertSame( $rows, $result );
		static::assertStringNotContainsString( 'NOT EXISTS', $repo->lastSql );
		static::assertStringContainsString( 'LIMIT 20', $repo->lastSql );
	}

	/**
	 * getBasicEditingInfo() returns the single aggregate row from fetchAssociative(). The cache-write is
	 * conditional on the query taking over five seconds, which never fires under a mock; that branch stays
	 * uncovered by design.
	 */
	public function testGetBasicEditingInfoReturnsFoundRow(): void {
		$repo = $this->makeRepository();
		$row = [ 'num_edits' => 100, 'creator' => 'Jimbo' ];
		$repo->cannedQueries = [ '*' => $this->singleRowResult( $row ) ];

		static::assertSame( $row, $repo->getBasicEditingInfo( $this->makePage() ) );
	}

	/**
	 * When the page has no revisions the aggregate row comes back falsy, and getBasicEditingInfo() reports
	 * false so callers can treat the page as not found.
	 */
	public function testGetBasicEditingInfoReturnsFalseWhenRowFalsy(): void {
		$repo = $this->makeRepository();
		$repo->cannedQueries = [ '*' => $this->singleRowResult( false ) ];

		static::assertFalse( $repo->getBasicEditingInfo( $this->makePage() ) );
	}

	/**
	 * getMaxPageRevisions() casts the configured value to int and memoises it, so a second call reuses the
	 * stored value rather than hitting the parameter bag again.
	 */
	public function testGetMaxPageRevisionsCastsToIntAndMemoises(): void {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->expects( static::once() )
			->method( 'get' )
			->with( 'app.max_page_revisions' )
			->willReturn( '50000' );

		$repo = $this->makeRepository( $parameterBag );
		static::assertSame( 50000, $repo->getMaxPageRevisions() );
		// Second call must not re-hit the parameter bag (expects once() above enforces this).
		static::assertSame( 50000, $repo->getMaxPageRevisions() );
	}

	/**
	 * getEdit() is a thin factory: it wraps the injected repos, the Page, and the revision row in a new
	 * Edit without touching the database.
	 */
	public function testGetEditBuildsEditFromRevisionRow(): void {
		$repo = $this->makeRepository();
		$page = $this->makePage();
		$edit = $repo->getEdit( $page, [
			'rev_id' => 123,
			'timestamp' => '20200101000000',
			'minor' => 0,
			'username' => 'Jimbo',
		] );

		static::assertInstanceOf( Edit::class, $edit );
		static::assertSame( 123, $edit->getId() );
	}

	/**
	 * getAutoEditsCounts() runs one count query per configured tool, keeps only tools used at least once
	 * (count > 0), and returns them sorted by count descending. The zero-count tool is dropped and the
	 * higher-count tool sorts first. A tool whose inner SQL yields no condition (e.g. a tag-only tool with
	 * no local tag) is skipped entirely, so it never appears in the query or the results.
	 */
	public function testGetAutoEditsCountsFiltersZeroCountsAndSortsByCountDesc(): void {
		// 'NoTag' stands in for a tag-only tool with no local tag: its inner SQL comes back empty.
		$repo = $this->makeAutoEditsRepository( [
			'Twinkle' => [ 'link' => 'WP:Twinkle', 'label' => 'Twinkle' ],
			'Rollback' => [ 'link' => 'WP:Rollback', 'label' => 'Rollback' ],
			'Huggle' => [ 'link' => 'WP:Huggle', 'label' => 'Huggle' ],
			'NoTag' => [ 'link' => 'WP:NoTag', 'label' => 'NoTag' ],
		], 'NoTag' );
		$repo->cannedQueries = [ '*' => $this->assocResult( [
			[ 'toolname' => 'Twinkle', 'count' => 5 ],
			[ 'toolname' => 'Rollback', 'count' => 20 ],
			[ 'toolname' => 'Huggle', 'count' => 0 ],
		] ) ];

		$results = $repo->getAutoEditsCounts( $this->makePage(), false, false );

		// Huggle has a zero count, so it's dropped.
		static::assertArrayNotHasKey( 'Huggle', $results );
		// NoTag produced no condition, so it was skipped before ever reaching the query.
		static::assertArrayNotHasKey( 'NoTag', $results );
		static::assertStringNotContainsString( 'NoTag', $repo->lastSql );
		// Rollback (20) outranks Twinkle (5), so it comes first.
		static::assertSame( [ 'Rollback', 'Twinkle' ], array_keys( $results ) );
		static::assertSame( 20, $results['Rollback']['count'] );
		static::assertSame( 'WP:Twinkle', $results['Twinkle']['link'] );
	}

	/**
	 * A Doctrine Result whose fetchAllAssociative() returns all rows and whose fetchAssociative() yields
	 * them one at a time (then false), covering both the passthrough methods and the while-loop methods.
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
	 * A Doctrine Result whose fetchAssociative() returns a single row (or false), for getBasicEditingInfo().
	 * @param array<string, mixed>|false $row
	 */
	private function singleRowResult( array|false $row ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( $row );
		return $result;
	}

	/**
	 * A Page stubbed with the seams the query builders touch: getProject() supplies the table-name wiring,
	 * and getId()/getNamespace()/getTitle()/getTitleWithoutNamespace() feed the bound params and SQL.
	 */
	private function makePage( int $namespace = 0, string $title = 'Some Page' ): Page {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);

		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $project );
		$page->method( 'getId' )->willReturn( 42 );
		$page->method( 'getNamespace' )->willReturn( $namespace );
		$page->method( 'getTitle' )->willReturn( $title );
		$titleWithoutNs = strpos( $title, ':' ) !== false ? substr( $title, strpos( $title, ':' ) + 1 ) : $title;
		$page->method( 'getTitleWithoutNamespace' )->willReturn( $titleWithoutNs );
		return $page;
	}

	/**
	 * The generalised test repository: executeProjectsQuery() records the built SQL and params into public
	 * properties and returns whichever canned Result matches ('*' = any), the same substring lookup as the
	 * sibling repository tests. A real ArrayAdapter backs the cache so getCacheKey()/setCache() behave
	 * without infrastructure.
	 */
	private function makeRepository( ?ParameterBagInterface $parameterBag = null ): PageInfoRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag ?? $this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$this->createMock( EditRepository::class ),
			$this->createMock( UserRepository::class ),
			$this->createMock( ProjectRepository::class ),
			$this->createMock( AutomatedEditsHelper::class ),
			null
		) extends PageInfoRepository {

			/** @var array<string, Result> Canned Results keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

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
		};
	}

	/**
	 * A test repository for getAutoEditsCounts(): getTools() returns the given tool map, getProjectsConnection()
	 * hands back a mock Connection whose platform passes tool names through quoteStringLiteral(), and
	 * getInnerAutomatedCountsSql() returns a canned non-empty condition triple so tools contribute a
	 * subquery, except for $emptyCondTool, which comes back with no condition to exercise the skip branch.
	 * executeProjectsQuery() is inherited from the base harness via the same override pattern.
	 * @param array<string, array<string, string>> $tools
	 * @param ?string $emptyCondTool Tool name whose inner SQL yields an empty condition (skipped).
	 */
	private function makeAutoEditsRepository( array $tools, ?string $emptyCondTool = null ): PageInfoRepository {
		$platform = $this->createMock( AbstractPlatform::class );
		$platform->method( 'quoteStringLiteral' )->willReturnCallback(
			static fn ( string $str ): string => "'$str'"
		);
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getDatabasePlatform' )->willReturn( $platform );

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$this->createMock( EditRepository::class ),
			$this->createMock( UserRepository::class ),
			$this->createMock( ProjectRepository::class ),
			$this->createMock( AutomatedEditsHelper::class ),
			null
		) extends PageInfoRepository {

			/** @var array<string, Result> Canned Results keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

			/** @var string The SQL built by the last executeProjectsQuery() call. */
			public string $lastSql = '';

			/** @var array The params bound by the last executeProjectsQuery() call. */
			public array $lastParams = [];

			/** @var array<string, array<string, string>> Tool map returned by getTools(). */
			public array $testTools = [];

			/** @var Connection Mock Connection returned by getProjectsConnection(). */
			public Connection $testConnection;

			/** @var ?string Tool name whose inner SQL yields an empty condition (skipped). */
			public ?string $emptyCondTool = null;

			public function getTools( Project $project, int|string $namespace = 'all' ): array {
				return $this->testTools;
			}

			protected function getProjectsConnection(
				Project|string $project,
				bool $checkBreaker = true
			): Connection {
				return $this->testConnection;
			}

			protected function getInnerAutomatedCountsSql( Project $project, string $toolName, array $values ): array {
				if ( $toolName === $this->emptyCondTool ) {
					return [ '', '', '' ];
				}
				return [ 'comment_text REGEXP \'x\'', '', '' ];
			}

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
		};
		$repo->testTools = $tools;
		$repo->testConnection = $connection;
		$repo->emptyCondTool = $emptyCondTool;
		return $repo;
	}
}
