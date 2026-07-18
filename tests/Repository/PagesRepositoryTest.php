<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\PagesRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branch in PagesRepository::getPagesCreated(): the page-assessments
 * subquery (pa_class / pap_project_title) is only stitched into the generated SQL when
 * we're on WMF and the project actually has PageAssessments. isWMF is a constructor
 * argument, so injecting it both ways covers both sides of the branch in one run. The
 * query is never run: a test subclass overrides executeQuery() to capture the SQL string
 * we built and hand back a canned Result, so we assert the branch by inspecting that SQL
 * rather than exercising a replica.
 * @covers \App\Repository\PagesRepository
 */
class PagesRepositoryTest extends TestCase {

	/**
	 * On WMF with a PageAssessments-enabled project, the assessments subquery is merged
	 * into the SELECT; the captured SQL carries the pa_class marker.
	 */
	public function testGetPagesCreatedAddsAssessmentSubqueryOnWmf(): void {
		$repo = $this->makeRepository( true );
		$repo->getPagesCreated( $this->makeProject( true ), $this->makeUser(), 'all', 'noRedirects', 'live' );
		static::assertStringContainsString( 'pa_class', $repo->capturedSql );
		static::assertStringContainsString( 'pap_project_title', $repo->capturedSql );
	}

	/**
	 * Off WMF the assessments subquery is skipped even when the project supports it, so
	 * pa_class never appears in the captured SQL.
	 */
	public function testGetPagesCreatedSkipsAssessmentSubqueryOffWmf(): void {
		$repo = $this->makeRepository( false );
		$repo->getPagesCreated( $this->makeProject( true ), $this->makeUser(), 'all', 'noRedirects', 'live' );
		static::assertStringNotContainsString( 'pa_class', $repo->capturedSql );
	}

	/**
	 * The second half of the condition: on WMF, but with a project that has no PageAssessments,
	 * the subquery is still skipped.
	 */
	public function testGetPagesCreatedSkipsAssessmentSubqueryWithoutPageAssessments(): void {
		$repo = $this->makeRepository( true );
		$repo->getPagesCreated( $this->makeProject( false ), $this->makeUser(), 'all', 'noRedirects', 'live' );
		static::assertStringNotContainsString( 'pa_class', $repo->capturedSql );
	}

	/**
	 * A Project stubbed only as far as getPagesCreated() reaches: hasPageAssessments()
	 * drives the branch, getTableName() feeds the SQL builder, and isPrpPage() defaults
	 * false so the ProofreadPage branch stays out of the way.
	 */
	private function makeProject( bool $hasPageAssessments ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'hasPageAssessments' )->willReturn( $hasPageAssessments );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A User whose actor ID resolves so executeQuery()'s param binding has something to
	 * bind; the query is captured rather than run, so the value is immaterial.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A PagesRepository whose executeQuery() captures the generated SQL and returns an
	 * empty canned Result instead of hitting a replica. A real ArrayAdapter backs the
	 * cache so getCacheKey()/setCache() behave without any infra.
	 */
	private function makeRepository( bool $isWMF ): PagesRepository {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( [] );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			null,
			$result
		) extends PagesRepository {
			/** @var string The SQL string built by the last getPagesCreated() call. */
			public string $capturedSql = '';

			private Result $cannedResult;

			public function __construct(
				ManagerRegistry $managerRegistry,
				\Psr\Cache\CacheItemPoolInterface $cache,
				Client $guzzle,
				NullLogger $logger,
				ParameterBagInterface $parameterBag,
				bool $isWMF,
				int $queryTimeout,
				ProjectRepository $projectRepo,
				$requestStack,
				Result $cannedResult
			) {
				parent::__construct(
					$managerRegistry, $cache, $guzzle, $logger, $parameterBag,
					$isWMF, $queryTimeout, $projectRepo, $requestStack
				);
				$this->cannedResult = $cannedResult;
			}

			protected function executeQuery(
				string $sql,
				Project $project,
				User $user,
				int|string|null $namespace = 'all',
				array $extraParams = []
			): Result {
				$this->capturedSql = $sql;
				return $this->cannedResult;
			}
		};
	}
}
