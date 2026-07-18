<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Repository\EditRepository;
use App\Repository\ProjectRepository;
use App\Repository\TopEditsRepository;
use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
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
	 * A Project stubbed to report the given page-assessments availability, with table-name lookups
	 * echoing back a recognisable name so the built fragment is inspectable.
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
