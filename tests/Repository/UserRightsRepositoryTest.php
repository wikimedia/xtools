<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRightsRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branches in UserRightsRepository: Meta rights changes and global user groups are
 * only merged in on WMF installs, and the autoconfirmed-thresholds fetch (which scrapes noc.wm.o)
 * is skipped entirely off-WMF. isWMF is a constructor argument, so injecting it both ways covers
 * both branches in one run. The queries themselves aren't exercised: a test subclass overrides
 * executeProjectsQuery() to hand back canned rows, so these assert the branch logic in isolation.
 * @covers \App\Repository\UserRightsRepository
 */
class UserRightsRepositoryTest extends TestCase {

	/**
	 * On WMF, rights changes made on Meta are merged with the local log; off-WMF only the local
	 * log is queried.
	 */
	public function testGetRightsChangesMergesMetaChangesOnlyOnWmf(): void {
		$project = $this->createMock( Project::class );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki' );
		$user = $this->createMock( User::class );
		$user->method( 'getUsername' )->willReturn( 'Jimbo' );

		$wmf = $this->makeRepository( true );
		$wmf->cannedQueries = [
			"'meta' AS type" => $this->cannedResult( [ [ 'log_id' => 2, 'type' => 'meta' ] ] ),
			'*' => $this->cannedResult( [ [ 'log_id' => 1, 'type' => 'local' ] ] ),
		];
		$changes = $wmf->getRightsChanges( $project, $user );
		static::assertCount( 2, $changes );
		static::assertSame( [ 'local', 'meta' ], array_column( $changes, 'type' ) );

		$thirdParty = $this->makeRepository( false );
		$thirdParty->cannedQueries = [ '*' => $this->cannedResult( [ [ 'log_id' => 1, 'type' => 'local' ] ] ) ];
		static::assertCount( 1, $thirdParty->getRightsChanges( $project, $user ) );
	}

	/**
	 * On WMF, the global (CentralAuth) user groups are unioned into the rights-name list; off-WMF
	 * only the local groups appear. The special 'autoconfirmed' and 'temp' groups are always added.
	 */
	public function testGetRawRightsNamesMergesGlobalGroupsOnlyOnWmf(): void {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturn( '`enwiki_p`.`user_groups`' );

		$wmf = $this->makeRepository( true );
		$wmf->cannedQueries = [
			'gug_group' => $this->cannedResult( [], [ 'steward' ] ),
			'*' => $this->cannedResult( [], [ 'sysop', 'bot' ] ),
		];
		$wmfGroups = $this->getRawRightsNames( $wmf, $project );
		static::assertContains( 'steward', $wmfGroups );
		static::assertContains( 'sysop', $wmfGroups );
		static::assertContains( 'autoconfirmed', $wmfGroups );

		$thirdParty = $this->makeRepository( false );
		$thirdParty->cannedQueries = [ '*' => $this->cannedResult( [], [ 'sysop', 'bot' ] ) ];
		$localGroups = $this->getRawRightsNames( $thirdParty, $project );
		static::assertNotContains( 'steward', $localGroups );
		static::assertContains( 'sysop', $localGroups );
	}

	/**
	 * The autoconfirmed-thresholds are read from a WMF-only config dump (noc.wikimedia.org), so
	 * off-WMF the method short-circuits to null without any HTTP request.
	 */
	public function testGetAutoconfirmedAgeAndCountIsNullOffWmf(): void {
		$repo = $this->makeRepository( false );
		static::assertNull( $repo->getAutoconfirmedAgeAndCount( $this->createMock( Project::class ) ) );
	}

	/**
	 * Invoke the private getRawRightsNames() past its visibility.
	 * @return string[]
	 */
	private function getRawRightsNames( UserRightsRepository $repo, Project $project ): array {
		return ( new ReflectionMethod( UserRightsRepository::class, 'getRawRightsNames' ) )
			->invoke( $repo, $project );
	}

	/**
	 * A Doctrine Result returning the given associative rows and/or first-column values.
	 * @param array<array<string, mixed>> $rows
	 * @param string[] $firstColumn
	 */
	private function cannedResult( array $rows = [], array $firstColumn = [] ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		$result->method( 'fetchFirstColumn' )->willReturn( $firstColumn );
		return $result;
	}

	/**
	 * A UserRightsRepository whose executeProjectsQuery() returns canned rows instead of hitting a
	 * replica: each query's SQL is matched against the keys of $cannedQueries (a '*' key matches
	 * anything), so a test can hand back different rows for the local vs. Meta/global queries. The
	 * caProject metadata is stubbed on the injected ProjectRepository so the Meta path resolves.
	 */
	private function makeRepository( bool $isWMF ): UserRightsRepository {
		$projectRepo = $this->createMock( ProjectRepository::class );
		$projectRepo->method( 'getOne' )->willReturn( [
			'dbName' => 'metawiki',
			'url' => 'https://meta.wikimedia.org',
			'lang' => 'en',
		] );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			$this->createMock( CacheItemPoolInterface::class ),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30,
			$projectRepo,
			'meta'
		) extends UserRightsRepository {
			/** @var array<string, Result> Canned query rows keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

			public function executeProjectsQuery(
				Project|string $project,
				string $sql,
				array $params = [],
				?int $timeout = null,
				bool $checkBreaker = true
			): Result {
				foreach ( $this->cannedQueries as $needle => $result ) {
					if ( $needle === '*' || str_contains( $sql, $needle ) ) {
						return $result;
					}
				}
				throw new \LogicException( "No canned result for query: $sql" );
			}
		};
	}
}
