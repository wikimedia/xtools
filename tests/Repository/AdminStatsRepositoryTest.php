<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Repository\AdminStatsRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branch in AdminStatsRepository::getUserGroups(): global (CentralAuth) user groups
 * are only unioned into the 'global' key on WMF installs; off-WMF that key is a flat []. isWMF is a
 * constructor argument, so injecting it both ways covers both sides in one run. The API request
 * isn't exercised: a test subclass overrides executeApiRequest() to hand back a canned siteinfo
 * response, so these assert the branch logic in isolation. A real ArrayAdapter backs the cache seam
 * (getCacheKey/hasItem/setCache) so we don't have to stub each cache call.
 * @covers \App\Repository\AdminStatsRepository
 */
class AdminStatsRepositoryTest extends TestCase {

	/**
	 * On WMF, a global group holding one of the type's permissions lands in the 'global' key
	 * alongside the local groups.
	 */
	public function testGetUserGroupsPopulatesGlobalOnWmf(): void {
		$repo = $this->makeRepository( true );
		$groups = $repo->getUserGroups( $this->makeProject(), 'admin' );

		static::assertContains( 'sysop', $groups['local'] );
		static::assertContains( 'steward', $groups['global'] );
		static::assertNotContains( 'bot', $groups['global'] );
	}

	/**
	 * Off-WMF the 'global' key short-circuits to an empty array, even though the (canned) API
	 * response still carries global groups.
	 */
	public function testGetUserGroupsGlobalIsEmptyOffWmf(): void {
		$repo = $this->makeRepository( false );
		$groups = $repo->getUserGroups( $this->makeProject(), 'admin' );

		static::assertContains( 'sysop', $groups['local'] );
		static::assertSame( [], $groups['global'] );
	}

	/**
	 * A Project stub whose getCacheKey() lets Repository::getCacheKey() build a key without a real
	 * database connection.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );
		return $project;
	}

	/**
	 * An AdminStatsRepository whose executeApiRequest() returns a canned usergroups/globalgroups
	 * siteinfo response instead of hitting the API. The parameterBag serves the 'admin_stats' config
	 * the method reads (permissions and extra_user_groups for the 'admin' type), and a real
	 * ArrayAdapter backs the cache.
	 */
	private function makeRepository( bool $isWMF ): AdminStatsRepository {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'get' )->with( 'admin_stats' )->willReturn( [
			'admin' => [
				'permissions' => [ 'block', 'delete' ],
				'extra_user_groups' => [],
			],
		] );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag,
			$isWMF,
			30
		) extends AdminStatsRepository {
			public function executeApiRequest( Project $project, array $params ): array {
				return [
					'query' => [
						'usergroups' => [
							[ 'name' => 'sysop', 'rights' => [ 'block', 'delete' ] ],
							[ 'name' => 'bot', 'rights' => [ 'writeapi' ] ],
						],
						'globalgroups' => [
							[ 'name' => 'steward', 'rights' => [ 'block', 'delete' ] ],
							[ 'name' => 'global-bot', 'rights' => [ 'writeapi' ] ],
						],
					],
				];
			}
		};
	}
}
