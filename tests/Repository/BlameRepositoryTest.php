<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Edit;
use App\Model\Page;
use App\Model\Project;
use App\Repository\BlameRepository;
use App\Repository\EditRepository;
use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * BlameRepository is a thin delegator: its one own method hands the revision lookup off to the
 * injected EditRepository, passing along its own UserRepository and the page's Project so the returned
 * Edit points at the same Page instance. The test pins that delegation with mocked collaborators, so
 * nothing touches a database or the network.
 * @covers \App\Repository\BlameRepository
 */
class BlameRepositoryTest extends TestCase {

	/**
	 * getEditFromRevId() forwards to EditRepository::getEditFromRevIdForPage() with the injected
	 * UserRepository, the page's Project, the revId and the Page itself, and passes the result back
	 * unchanged.
	 */
	public function testGetEditFromRevIdDelegatesToEditRepository(): void {
		$project = $this->createMock( Project::class );
		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $project );

		$userRepo = $this->createMock( UserRepository::class );
		$edit = $this->createMock( Edit::class );

		$editRepo = $this->createMock( EditRepository::class );
		$editRepo->expects( static::once() )
			->method( 'getEditFromRevIdForPage' )
			->with( $userRepo, $project, 456, $page )
			->willReturn( $edit );

		$repo = $this->makeRepository( $editRepo, $userRepo );
		static::assertSame( $edit, $repo->getEditFromRevId( $page, 456 ) );
	}

	/**
	 * A BlameRepository wired with mocked EditRepository/UserRepository collaborators and infra-free
	 * dependencies (real ArrayAdapter cache, NullLogger).
	 */
	private function makeRepository( EditRepository $editRepo, UserRepository $userRepo ): BlameRepository {
		return new BlameRepository(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30,
			$editRepo,
			$userRepo
		);
	}
}
