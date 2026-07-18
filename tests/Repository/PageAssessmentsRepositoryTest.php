<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Page;
use App\Model\Project;
use App\Repository\PageAssessmentsRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * PageAssessmentsRepository has two jobs: hand back the per-project assessments config from the
 * ParameterBag (getConfig), and run the page_assessments join for a page (getAssessments). getConfig
 * memoises the whole config and then indexes by domain, so the present/absent branches turn on whether
 * the domain key exists. getAssessments builds its SQL and, when $first is set, appends the class
 * filter and LIMIT 1; a test subclass records the built SQL and returns canned rows over the
 * executeProjectsQuery() seam, and a real (cold) ArrayAdapter means the cache-hit early return never
 * fires here. None of this touches a replica.
 * @covers \App\Repository\PageAssessmentsRepository
 */
class PageAssessmentsRepositoryTest extends TestCase {

	/**
	 * getConfig() returns the config slice for the project's domain when one is present.
	 */
	public function testGetConfigReturnsConfigForKnownDomain(): void {
		$config = [ 'class' => [ 'FA' => [] ] ];
		$repo = $this->makeRepository( [ 'en.wikipedia.org' => $config ] );

		static::assertSame( $config, $repo->getConfig( $this->makeProject( 'en.wikipedia.org' ) ) );
	}

	/**
	 * A domain with no entry in the assessments config yields null, not an error.
	 */
	public function testGetConfigReturnsNullForUnknownDomain(): void {
		$repo = $this->makeRepository( [ 'en.wikipedia.org' => [ 'class' => [] ] ] );

		static::assertNull( $repo->getConfig( $this->makeProject( 'de.wikipedia.org' ) ) );
	}

	/**
	 * The ParameterBag is consulted once: the config is memoised on the first getConfig() call and
	 * reused on subsequent ones, even across different projects.
	 */
	public function testGetConfigMemoisesTheParameterBagLookup(): void {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->expects( static::once() )
			->method( 'get' )
			->with( 'assessments' )
			->willReturn( [ 'en.wikipedia.org' => [ 'class' => [] ] ] );

		$repo = $this->makeRepositoryWithBag( $parameterBag );
		$repo->getConfig( $this->makeProject( 'en.wikipedia.org' ) );
		$repo->getConfig( $this->makeProject( 'de.wikipedia.org' ) );
	}

	/**
	 * With $first defaulting to false, getAssessments() runs the plain join and returns every row.
	 */
	public function testGetAssessmentsReturnsAllRowsByDefault(): void {
		$rows = [
			[ 'wikiproject' => 'Biography', 'class' => 'FA', 'importance' => 'High' ],
			[ 'wikiproject' => 'Physics', 'class' => 'B', 'importance' => 'Mid' ],
		];
		$repo = $this->makeRepository( [] );
		$repo->cannedResult = $this->assocResult( $rows );

		$result = $repo->getAssessments( $this->makePage( 42 ) );
		static::assertSame( $rows, $result );
		static::assertStringContainsString( 'page_assessments', $repo->lastSql );
		static::assertStringContainsString( 'pa_page_id = 42', $repo->lastSql );
		// Without $first the class filter and row limit are absent.
		static::assertStringNotContainsString( 'LIMIT 1', $repo->lastSql );
	}

	/**
	 * With $first set, getAssessments() appends the non-empty-class filter and a LIMIT 1.
	 */
	public function testGetAssessmentsLimitsToFirstWhenRequested(): void {
		$repo = $this->makeRepository( [] );
		$repo->cannedResult = $this->assocResult( [] );

		$repo->getAssessments( $this->makePage( 7 ), true );
		static::assertStringContainsString( "pa_class != ''", $repo->lastSql );
		static::assertStringContainsString( 'LIMIT 1', $repo->lastSql );
	}

	/**
	 * A Project whose domain keys the config lookup and whose getTableName() drives the join SQL.
	 */
	private function makeProject( string $domain = 'en.wikipedia.org' ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( $domain );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A Page carrying the given id and a project stub for the table-name lookups.
	 */
	private function makePage( int $id ): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getId' )->willReturn( $id );
		$page->method( 'getProject' )->willReturn( $this->makeProject() );
		return $page;
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
	 * A repository whose ParameterBag returns the given assessments config.
	 * @param array<string, mixed> $assessments Config keyed by domain, as config/assessments.yaml.
	 */
	private function makeRepository( array $assessments ): PageAssessmentsRepository {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'get' )->with( 'assessments' )->willReturn( $assessments );
		return $this->makeRepositoryWithBag( $parameterBag );
	}

	/**
	 * The test repository: executeProjectsQuery() records the built SQL and returns the canned Result,
	 * so getAssessments() is exercised without a replica. A real (cold) ArrayAdapter backs the cache,
	 * so the cache-hit early return in getAssessments() never fires and setCache() behaves normally.
	 */
	private function makeRepositoryWithBag( ParameterBagInterface $parameterBag ): PageAssessmentsRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag,
			true,
			30
		) extends PageAssessmentsRepository {

			/** @var Result Canned rows handed back by executeProjectsQuery(). */
			public Result $cannedResult;

			/** @var string The SQL built by the last executeProjectsQuery() call. */
			public string $lastSql = '';

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				$this->lastSql = $sql;
				return $this->cannedResult;
			}
		};
	}
}
