<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Page;
use App\Model\Project;
use App\Repository\LargestPagesRepository;
use App\Repository\PageRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * LargestPagesRepository is pure SQL-assembly plus a row-to-Page mapping. The branch logic lives in
 * two places: getLikeSql() glues an include and/or exclude pattern into the WHERE clause (and normalises
 * spaces to underscores in the bound patterns), and getData() decides whether a namespace condition, a
 * like condition, both, or neither produce a WHERE clause and the AND between them. Nothing runs against a
 * replica: a test subclass overrides the executeProjectsQuery() seam to record the built SQL and params
 * and hand back a canned Result, so the branches and the Page mapping are asserted in isolation. A real
 * ArrayAdapter backs the cache so the wiring behaves as in production.
 * @covers \App\Repository\LargestPagesRepository
 */
class LargestPagesRepositoryTest extends TestCase {

	/**
	 * Include-only: the LIKE clause is emitted, the NOT LIKE clause isn't, and the include pattern's
	 * spaces become underscores in the bound param.
	 */
	public function testGetLikeSqlEmitsIncludeClauseOnly(): void {
		$repo = $this->makeRepository();
		$include = 'Foo bar';
		$exclude = '';
		$sql = ( new ReflectionMethod( LargestPagesRepository::class, 'getLikeSql' ) )
			->invokeArgs( $repo, [ &$include, &$exclude ] );

		static::assertStringContainsString( 'page_title LIKE :include_pattern', $sql );
		static::assertStringNotContainsString( 'NOT LIKE', $sql );
		static::assertSame( 'Foo_bar', $include );
	}

	/**
	 * Exclude-only: the NOT LIKE clause is emitted with no leading AND (there's no include clause to
	 * join), and the exclude pattern's spaces become underscores.
	 */
	public function testGetLikeSqlEmitsExcludeClauseOnly(): void {
		$repo = $this->makeRepository();
		$include = '';
		$exclude = 'Draft talk';
		$sql = ( new ReflectionMethod( LargestPagesRepository::class, 'getLikeSql' ) )
			->invokeArgs( $repo, [ &$include, &$exclude ] );

		static::assertStringContainsString( 'page_title NOT LIKE :exclude_pattern', $sql );
		static::assertStringNotContainsString( 'AND', $sql );
		static::assertSame( 'Draft_talk', $exclude );
	}

	/**
	 * Both patterns: the include and exclude clauses are joined by an AND, and both patterns get their
	 * spaces normalised.
	 */
	public function testGetLikeSqlJoinsBothClausesWithAnd(): void {
		$repo = $this->makeRepository();
		$include = 'Foo bar';
		$exclude = 'Draft talk';
		$sql = ( new ReflectionMethod( LargestPagesRepository::class, 'getLikeSql' ) )
			->invokeArgs( $repo, [ &$include, &$exclude ] );

		static::assertStringContainsString( 'page_title LIKE :include_pattern', $sql );
		static::assertStringContainsString( 'AND', $sql );
		static::assertStringContainsString( 'page_title NOT LIKE :exclude_pattern', $sql );
		static::assertSame( 'Foo_bar', $include );
		static::assertSame( 'Draft_talk', $exclude );
	}

	/**
	 * namespace 'all' with no patterns: the query is bare, with neither a WHERE nor a namespace clause.
	 */
	public function testGetDataBuildsBareQueryForAllNamespacesWithoutPatterns(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getData( $this->makeProject(), 'all', '', '' );
		static::assertStringNotContainsString( 'WHERE', $repo->lastSql );
		static::assertStringNotContainsString( 'page_namespace = :namespace', $repo->lastSql );
		static::assertStringNotContainsString( 'LIKE', $repo->lastSql );
	}

	/**
	 * A specific namespace with no patterns: the namespace condition and the WHERE appear, but there's
	 * no LIKE clause and so no AND joining the two.
	 */
	public function testGetDataAddsNamespaceClauseForSpecificNamespace(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getData( $this->makeProject(), 0, '', '' );
		static::assertStringContainsString( 'WHERE', $repo->lastSql );
		static::assertStringContainsString( 'page_namespace = :namespace', $repo->lastSql );
		static::assertStringNotContainsString( 'LIKE', $repo->lastSql );
	}

	/**
	 * namespace 'all' with a pattern: the WHERE is present (the pattern needs it) but no namespace
	 * condition, so nothing to AND onto.
	 */
	public function testGetDataAddsWhereForPatternWithoutNamespace(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getData( $this->makeProject(), 'all', 'Foo%', '' );
		static::assertStringContainsString( 'WHERE', $repo->lastSql );
		static::assertStringContainsString( 'page_title LIKE :include_pattern', $repo->lastSql );
		static::assertStringNotContainsString( 'page_namespace = :namespace', $repo->lastSql );
	}

	/**
	 * A namespace and a pattern together: both conditions appear, joined by the AND that getData()
	 * inserts after the namespace condition when a like condition follows.
	 */
	public function testGetDataJoinsNamespaceAndPatternWithAnd(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getData( $this->makeProject(), 0, 'Foo%', '' );
		static::assertStringContainsString( 'page_namespace = :namespace', $repo->lastSql );
		static::assertStringContainsString( 'AND', $repo->lastSql );
		static::assertStringContainsString( 'page_title LIKE :include_pattern', $repo->lastSql );
	}

	/**
	 * getData() binds the namespace and the patterns getLikeSql() normalised by reference, so the
	 * underscored form (not the raw spaced input) is what reaches the query. Exercises the handoff the
	 * isolated getLikeSql() test can't see: that the mutated value actually flows into the bind.
	 */
	public function testGetDataBindsNamespaceAndNormalisedPatterns(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getData( $this->makeProject(), 5, 'Foo bar', 'Draft talk' );
		static::assertSame( 5, $repo->lastParams['namespace'] );
		static::assertSame( 'Foo_bar', $repo->lastParams['include_pattern'] );
		static::assertSame( 'Draft_talk', $repo->lastParams['exclude_pattern'] );
	}

	/**
	 * The row-to-Page mapping: each returned row is turned into a Page via Page::newFromRow(), carrying
	 * the aliased namespace/title/length columns through.
	 */
	public function testGetDataMapsRowsToPages(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [
			[ 'namespace' => 0, 'page_title' => 'Foo', 'length' => '1234' ],
		] );

		$pages = $repo->getData( $this->makeProject(), 'all', '', '' );
		static::assertCount( 1, $pages );
		static::assertInstanceOf( Page::class, $pages[0] );
		static::assertSame( 1234, $pages[0]->getLength() );
	}

	/**
	 * A Project stubbed with the one seam the query builder touches: getTableName() so the FROM clause
	 * resolves to a backticked table name.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A Doctrine Result returning the given rows from fetchAllAssociative().
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		return $result;
	}

	/**
	 * The test repository: executeProjectsQuery() records the built SQL and params (so branch tests can
	 * assert on the generated query) and returns the canned Result. A real ArrayAdapter backs the cache
	 * so the caching wiring behaves without infra.
	 */
	private function makeRepository(): LargestPagesRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$this->createMock( PageRepository::class )
		) extends LargestPagesRepository {

			/** @var Result The canned Result handed back by executeProjectsQuery(). */
			public Result $cannedResult;

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
				return $this->cannedResult;
			}
		};
	}
}
