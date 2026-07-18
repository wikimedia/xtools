<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Project;
use App\Model\User;
use App\Repository\AdminScoreRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * AdminScoreRepository is a single fetchData() method: one big UNION query and a straight
 * fetchAllAssociative() passthrough, with no post-processing. There is no isIP or isWMF branch,
 * so the only things worth pinning are the query assembly (every UNION branch is present, tables
 * resolve through getTableName(), the actor and username params bind) and that the rows come back
 * untouched. A test subclass overrides the executeProjectsQuery() seam to record the built SQL and
 * params and hand back a canned Result, so none of this touches a replica. A real ArrayAdapter
 * backs the cache so the wiring matches production.
 * @covers \App\Repository\AdminScoreRepository
 */
class AdminScoreRepositoryTest extends TestCase {

	/**
	 * fetchData() returns whatever fetchAllAssociative() hands back, with no transform.
	 */
	public function testFetchDataReturnsRowsUnchanged(): void {
		$rows = [
			[ 'source' => 'edit-count', 'value' => '4200' ],
			[ 'source' => 'account-age', 'value' => '20200101000000' ],
		];
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( $rows );

		static::assertSame( $rows, $repo->fetchData( $this->makeProject(), $this->makeUser() ) );
	}

	/**
	 * The query names each score source and folds all thirteen sub-selects into one UNION.
	 */
	public function testFetchDataUnionsEveryScoreSource(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeUser() );
		foreach ( [
			'account-age', 'edit-count', 'user-page', 'patrols', 'blocks', 'afd', 'recent-activity',
			'aiv', 'edit-summaries', 'namespaces', 'pages-created-live', 'pages-created-deleted', 'rpp',
		] as $source ) {
			static::assertStringContainsString( "'$source'", $repo->lastSql );
		}
	}

	/**
	 * Table names are resolved through the project (getTableName), including the userindex extension
	 * that the logging sub-selects ride on.
	 */
	public function testFetchDataResolvesTablesThroughProject(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeUser() );
		static::assertStringContainsString( '`enwiki_p`.`user`', $repo->lastSql );
		static::assertStringContainsString( '`enwiki_p`.`revision`', $repo->lastSql );
		static::assertStringContainsString( '`enwiki_p`.`archive`', $repo->lastSql );
		// The logging table is requested with the userindex extension.
		static::assertStringContainsString( '`enwiki_p`.`logging_userindex`', $repo->lastSql );
	}

	/**
	 * Both the username and actor filters are parameterised, not inlined, so binding is safe.
	 */
	public function testFetchDataBindsUsernameAndActorParams(): void {
		$repo = $this->makeRepository();
		$repo->cannedResult = $this->assocResult( [] );

		$repo->fetchData( $this->makeProject(), $this->makeUser( 'Jimbo' ) );
		static::assertSame( 'Jimbo', $repo->lastParams['username'] );
		static::assertSame( 1, $repo->lastParams['actorId'] );
		static::assertStringContainsString( 'user_name = :username', $repo->lastSql );
		static::assertStringContainsString( 'rev_actor = :actorId', $repo->lastSql );
	}

	/**
	 * A Project stubbed only as far as fetchData() reaches: getTableName() drives the SQL, and the
	 * userindex extension appends to the table name.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturnCallback(
			static function ( string $table, ?string $ext = null ): string {
				$name = $ext ? "{$table}_{$ext}" : $table;
				return "`enwiki_p`.`$name`";
			}
		);
		return $project;
	}

	/**
	 * A named User whose getUsername()/getActorId() feed the bound params.
	 */
	private function makeUser( string $username = 'Jimbo' ): User {
		$user = $this->createMock( User::class );
		$user->method( 'getUsername' )->willReturn( $username );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
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
	 * The test repository: executeProjectsQuery() records the built SQL and params into public
	 * properties and returns the canned Result, so the query assembly is asserted without a replica.
	 * A real ArrayAdapter backs the cache so the wiring behaves as in production.
	 */
	private function makeRepository(): AdminScoreRepository {
		return new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$this->createMock( Client::class ),
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			true,
			30
		) extends AdminScoreRepository {

			/** @var Result Canned rows handed back by executeProjectsQuery(). */
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
