<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\AutoEditsRepository;
use App\Repository\EditCounterRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branch in EditCounterRepository::getFileCounts(): on WMF installs we fold in a
 * user's Commons file moves and uploads, because most Wikimedia file work happens on Commons rather
 * than the local wiki. Off-WMF there is no shared media repository to consult, and on Commons itself
 * the local query already covers those rows, so folding Commons back in would double-count. isWMF is
 * a constructor argument, so injecting it both ways plus varying the domain covers all three paths in
 * one run. The queries themselves aren't exercised: a test subclass overrides executeProjectsQuery()
 * for the local rows and getFileCountsCommons() for the merged-in rows, so these assert the branch
 * logic in isolation.
 * @covers \App\Repository\EditCounterRepository
 */
class EditCounterRepositoryTest extends TestCase {

	/**
	 * On WMF, a non-Commons project's file counts are merged with the Commons file counts.
	 */
	public function testGetFileCountsMergesCommonsCountsOnWmf(): void {
		$repo = $this->makeFileCountsRepository( true );
		$counts = $repo->getFileCounts( $this->makeProject( 'en.wikipedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertSame( 5, $counts['files_moved_commons'] );
	}

	/**
	 * Off-WMF there is no Commons to consult, so only the local file counts are returned.
	 */
	public function testGetFileCountsOmitsCommonsCountsOffWmf(): void {
		$repo = $this->makeFileCountsRepository( false );
		$counts = $repo->getFileCounts( $this->makeProject( 'en.wikipedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertArrayNotHasKey( 'files_moved_commons', $counts );
	}

	/**
	 * On Commons itself the local query already covers the Commons rows, so we don't fold them in
	 * again and double-count.
	 */
	public function testGetFileCountsOmitsCommonsCountsOnCommonsItself(): void {
		$repo = $this->makeFileCountsRepository( true );
		$counts = $repo->getFileCounts( $this->makeProject( 'commons.wikimedia.org' ), $this->makeUser() );
		static::assertSame( 3, $counts['files_moved'] );
		static::assertArrayNotHasKey( 'files_moved_commons', $counts );
	}

	/**
	 * A Project stubbed with the seams getFileCounts() touches: its domain guards the Commons branch,
	 * and getCacheKey()/getTableName() keep the cache and query wiring happy.
	 */
	private function makeProject( string $domain ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( $domain );
		$project->method( 'getCacheKey' )->willReturn( $domain );
		$project->method( 'getTableName' )->willReturn( 'logging' );
		return $project;
	}

	/**
	 * A non-anon User: getFileCounts() short-circuits for anons, so isAnon() must return false.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'isAnon' )->willReturn( false );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * An EditCounterRepository whose local file-count query returns a canned row, and whose Commons
	 * fetch returns a canned row, so the merge branch is exercised without a replica. A real
	 * ArrayAdapter backs the cache so setCache()/hasItem() behave as in production.
	 */
	private function makeFileCountsRepository( bool $isWMF ): EditCounterRepository {
		$localResult = $this->createMock( Result::class );
		$localResult->method( 'fetchAllAssociative' )->willReturn( [
			[ 'key' => 'files_moved', 'val' => 3 ],
		] );

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$this->createMock( ProjectRepository::class ),
			$this->createMock( AutoEditsRepository::class )
		) extends EditCounterRepository {

			/** @var Result Canned rows for the local file-count query. */
			public Result $localResult;

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				return $this->localResult;
			}

			protected function getFileCountsCommons( User $user ): array {
				return [
					[ 'key' => 'files_moved_commons', 'val' => 5 ],
				];
			}
		};
		$repo->localResult = $localResult;
		return $repo;
	}
}
