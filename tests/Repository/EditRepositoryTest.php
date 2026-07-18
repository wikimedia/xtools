<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Edit;
use App\Model\Page;
use App\Model\Project;
use App\Repository\EditRepository;
use App\Repository\PageRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * EditRepository fetches a single revision. Two branches carry the method: getEditFromRevIdForPage()
 * either builds an Edit from the fetched row or returns null when nothing comes back, and within the
 * found path the SQL grows a page join/select only when no Page was passed in (so the repo has to
 * resolve the title itself). getDiffHtml() digs the compare HTML out of an API response, falling back
 * to null when the compare block is absent. Neither touches a database or the network: a test subclass
 * overrides the executeProjectsQuery() and executeApiRequest() seams to hand back canned data, so the
 * branch logic is asserted in isolation. A real ArrayAdapter backs the cache as in production.
 * @covers \App\Repository\EditRepository
 */
class EditRepositoryTest extends TestCase {

	/**
	 * With a Page passed in, the found path skips the page join/select and returns an Edit built from
	 * the fetched row, pointing at the same Page instance.
	 */
	public function testGetEditFromRevIdForPageReturnsEditWhenRowFound(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocRowResult( $this->editRow() );
		$page = $this->makePage();

		$edit = $repo->getEditFromRevIdForPage(
			$this->createMock( UserRepository::class ),
			$this->makeProject(),
			123,
			$page
		);
		static::assertInstanceOf( Edit::class, $edit );
		static::assertSame( 123, $edit->getId() );
		static::assertSame( $page, $edit->getPage() );
		static::assertStringContainsString( 'WHERE revs.rev_id = :revId', $repo->lastSql );
		static::assertStringNotContainsString( 'page_title,', $repo->lastSql );
		static::assertSame( 123, $repo->lastParams['revId'] );
	}

	/**
	 * With no Page passed in, the SQL grows the page join and selects page_title so the repo can build
	 * a fresh Page from the returned row.
	 */
	public function testGetEditFromRevIdForPageBuildsPageWhenNoneGiven(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocRowResult( $this->editRow( [ 'page_title' => 'Foobar' ] ) );

		$edit = $repo->getEditFromRevIdForPage(
			$this->createMock( UserRepository::class ),
			$this->makeProject(),
			123
		);
		static::assertInstanceOf( Edit::class, $edit );
		static::assertStringContainsString( 'page_title,', $repo->lastSql );
		static::assertStringContainsString( 'JOIN `enwiki_p`.`page` ON revs.rev_page = page_id', $repo->lastSql );
	}

	/**
	 * When fetchAssociative() yields nothing, the method short-circuits to null before touching the
	 * Edit/Page constructors.
	 */
	public function testGetEditFromRevIdForPageReturnsNullWhenNoRow(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->emptyResult();

		$edit = $repo->getEditFromRevIdForPage(
			$this->createMock( UserRepository::class ),
			$this->makeProject(),
			123
		);
		static::assertNull( $edit );
	}

	/**
	 * getDiffHtml() pulls the compare HTML out of the API response's compare.* key.
	 */
	public function testGetDiffHtmlExtractsCompareHtmlFromApiResponse(): void {
		$repo = $this->makeRepository();
		$repo->cannedApiResponse = [ 'compare' => [ '*' => '<tr><td>diff</td></tr>' ] ];

		static::assertSame( '<tr><td>diff</td></tr>', $repo->getDiffHtml( $this->makeEdit() ) );
	}

	/**
	 * With no compare block in the response (no diff found), getDiffHtml() falls back to null.
	 */
	public function testGetDiffHtmlReturnsNullWhenCompareMissing(): void {
		$repo = $this->makeRepository();
		$repo->cannedApiResponse = [];

		static::assertNull( $repo->getDiffHtml( $this->makeEdit() ) );
	}

	/**
	 * getAutoEditsHelper() is a plain getter for the injected helper.
	 */
	public function testGetAutoEditsHelperReturnsInjectedHelper(): void {
		$helper = $this->createMock( AutomatedEditsHelper::class );
		$repo = $this->makeRepository( $helper );
		static::assertSame( $helper, $repo->getAutoEditsHelper() );
	}

	/**
	 * A Project stubbed with the seams the query builder touches. getTableName() quotes the labs-style
	 * name and getCacheKey() keeps the cache wiring happy.
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
	 * A Page double for the passed-in-Page path; getProject() feeds the Edit's project lookup.
	 */
	private function makePage(): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $this->makeProject() );
		return $page;
	}

	/**
	 * An Edit double for the getDiffHtml() tests; only getId()/getProject() are reached.
	 */
	private function makeEdit(): Edit {
		$edit = $this->createMock( Edit::class );
		$edit->method( 'getId' )->willReturn( 123 );
		$edit->method( 'getProject' )->willReturn( $this->makeProject() );
		return $edit;
	}

	/**
	 * The row returned for a found revision, matching the aliases the SELECT assigns. The Edit
	 * constructor reads id/timestamp/minor/length/length_change/comment/username off it.
	 * @param array<string, mixed> $extra Merged over the defaults (e.g. page_title for the no-Page path).
	 * @return array<string, mixed>
	 */
	private function editRow( array $extra = [] ): array {
		return array_merge( [
			'id' => '123',
			'username' => 'Jimbo',
			'timestamp' => '20200101000000',
			'minor' => '0',
			'length' => '100',
			'length_change' => '10',
			'comment' => 'an edit',
		], $extra );
	}

	/**
	 * A Doctrine Result whose fetchAssociative() returns the given row.
	 * @param array<string, mixed> $row
	 */
	private function assocRowResult( array $row ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( $row );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchAssociative() returns false, as a real driver does on no match.
	 */
	private function emptyResult(): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAssociative' )->willReturn( false );
		return $result;
	}

	/**
	 * The test repository: executeProjectsQuery() records the built SQL and params and returns the
	 * canned Result; executeApiRequest() returns the canned API array so getDiffHtml() never hits the
	 * network. A real ArrayAdapter backs the cache so getCacheKey()/setCache() behave without infra.
	 */
	private function makeRepository( ?AutomatedEditsHelper $helper = null ): EditRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$helper ?? $this->createMock( AutomatedEditsHelper::class ),
			$this->createMock( PageRepository::class )
		) extends EditRepository {

			/** @var Result Canned Result returned by executeProjectsQuery(). */
			public Result $cannedResult;

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
				return $this->cannedResult;
			}

			public function executeApiRequest( Project $project, array $params ): array {
				return $this->cannedApiResponse;
			}
		};
	}
}
