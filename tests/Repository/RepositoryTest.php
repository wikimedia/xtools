<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\Repository;
use App\Repository\SimpleEditCounterRepository;
use App\Repository\UserRepository;
use App\Tests\TestAdapter;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Tests for the Repository class.
 * @covers \App\Repository\Repository
 */
class RepositoryTest extends TestAdapter {
	protected SimpleEditCounterRepository $repository;
	protected UserRepository $userRepo;

	protected function setUp(): void {
		static::bootKernel();
		$this->repository = static::getContainer()->get( SimpleEditCounterRepository::class );
		$this->userRepo = static::getContainer()->get( UserRepository::class );
	}

	/**
	 * getTableName rewrites table names differently on WMF (Labs) installs than on third-party
	 * wikis: the _userindex views and the table-extension mapping are Labs-only. isWMF is a
	 * constructor argument, so injecting it both ways covers both branches in one run rather than
	 * only whichever one APP_IS_WMF happens to select. The database name is passed already
	 * qualified (getTableName no longer adds the _p suffix itself).
	 * @dataProvider provideGetTableName
	 */
	public function testGetTableName(
		bool $isWMF,
		array $tableMap,
		string $tableName,
		?string $tableExtension,
		string $expected
	): void {
		$repository = $this->makeRepository( $isWMF, $tableMap );
		static::assertSame(
			$expected,
			$repository->getTableName( 'enwiki_p', $tableName, $tableExtension )
		);
	}

	/**
	 * @return array<string, array{bool, string[], string, ?string, string}>
	 */
	public static function provideGetTableName(): array {
		return [
			// isWMF, app.table map, table, extension, expected
			// WMF: a non-null extension is appended verbatim, whatever it is.
			'WMF appends a non-index extension' => [ true, [], 'logging', 'logindex', '`enwiki_p`.`logging_logindex`' ],
			// WMF: the revision/logging/archive views default to their _userindex variants.
			'WMF revision view uses _userindex' => [ true, [], 'revision', null, '`enwiki_p`.`revision_userindex`' ],
			'WMF logging view uses _userindex' => [ true, [], 'logging', null, '`enwiki_p`.`logging_userindex`' ],
			'WMF archive view uses _userindex' => [ true, [], 'archive', null, '`enwiki_p`.`archive_userindex`' ],
			// WMF: a blank extension suppresses _userindex (SimpleEditCounter's IP-range query needs this).
			'WMF blank extension suppresses _userindex' => [ true, [], 'revision', '', '`enwiki_p`.`revision`' ],
			// WMF: an ordinary table gets no Labs suffix.
			'WMF ordinary table is untouched' => [ true, [], 'page', null, '`enwiki_p`.`page`' ],

			// Third-party: the extension is Labs-only, so it's ignored; names pass through untouched.
			'non-WMF ignores the extension' => [ false, [], 'logging', 'logindex', '`enwiki_p`.`logging`' ],
			'non-WMF leaves revision unindexed' => [ false, [], 'revision', null, '`enwiki_p`.`revision`' ],
			'non-WMF ordinary table is untouched' => [ false, [], 'page', null, '`enwiki_p`.`page`' ],

			// An app.table.* mapping wins over the default name, in either mode.
			'WMF honours an app.table mapping' => [ true, [ 'app.table.revision' => 'revision_custom' ],
				'revision', null, '`enwiki_p`.`revision_custom`' ],
			'non-WMF honours an app.table mapping' => [ false, [ 'app.table.revision' => 'revision_custom' ],
				'revision', null, '`enwiki_p`.`revision_custom`' ],
			// WMF: an explicit extension takes the mapping's place (the extension branch wins).
			'WMF extension wins over the mapping' =>
				[ true, [ 'app.table.revision' => 'revision_custom' ], 'revision', 'userindex',
					'`enwiki_p`.`revision_userindex`' ],
		];
	}

	/**
	 * Build a bare Repository with isWMF injected directly. getTableName reads only $isWMF and the
	 * parameter bag, so the other collaborators are inert mocks and no kernel boot is needed.
	 * @param bool $isWMF
	 * @param string[] $tableMap Optional app.table.<name> => mapped-name overrides.
	 */
	private function makeRepository( bool $isWMF, array $tableMap = [] ): Repository {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		$parameterBag->method( 'has' )
			->willReturnCallback( static fn ( string $key ) => isset( $tableMap[$key] ) );
		$parameterBag->method( 'get' )
			->willReturnCallback( static fn ( string $key ) => $tableMap[$key] ?? null );

		return new class(
			$this->createMock( ManagerRegistry::class ),
			$this->createMock( CacheItemPoolInterface::class ),
			$this->createMock( Client::class ),
			new NullLogger(),
			$parameterBag,
			$isWMF,
			30
		) extends Repository {
		};
	}

	/**
	 * Test getting a unique cache key for a given set of arguments.
	 */
	public function testCacheKey(): void {
		// Set up example Models that we'll pass to Repository::getCacheKey().
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'enwiki' );
		$user = new User( $this->userRepo, 'Test user (WMF)' );

		// Given explicit cache prefix.
		static::assertEquals(
			'cachePrefix.enwiki.f475a8ac7f25e162bba0eb1b4b245027.' .
				'a84e19e5268bf01623c8a130883df668.202cb962ac59075b964b07152d234b70',
			$this->repository->getCacheKey(
				[ $project, $user, '20170101', '', null, [ 1, 2, 3 ] ],
				'cachePrefix'
			)
		);

		// It will use the name of the caller, in this case testCacheKey.
		static::assertEquals(
			// The `false` argument generates the trailing `.`
			'testCacheKey.enwiki.f475a8ac7f25e162bba0eb1b4b245027.' .
				'a84e19e5268bf01623c8a130883df668.d41d8cd98f00b204e9800998ecf8427e',
			$this->repository->getCacheKey( [ $project, $user, '20170101', '', false, null ] )
		);

		// Single argument, no prefix.
		static::assertEquals(
			'testCacheKey.838763cbdc764f1740370a8ee1000c65',
			$this->repository->getCacheKey( 'mycache' )
		);
	}

	/**
	 * SQL date conditions helper.
	 */
	public function testDateConditions(): void {
		$start = strtotime( '20170101' );
		$end = strtotime( '20190201' );
		$offset = strtotime( '20180201235959' );

		static::assertEquals(
			" AND alias.rev_timestamp >= '20170101000000' AND alias.rev_timestamp <= '20190201235959'",
			$this->repository->getDateConditions( $start, $end, false, 'alias.' )
		);

		static::assertEquals(
			" AND rev_timestamp >= '20170101000000' AND rev_timestamp <= '20180201235959'",
			$this->repository->getDateConditions( $start, $end, $offset )
		);
	}
}
