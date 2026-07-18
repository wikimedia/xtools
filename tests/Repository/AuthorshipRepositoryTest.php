<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Exception\BadGatewayException;
use App\Model\Page;
use App\Model\Project;
use App\Repository\AuthorshipRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * AuthorshipRepository has two seams neither of which needs infrastructure. getData() talks to the
 * WikiWho HTTP service through the injected Guzzle client, so a mocked Client whose request() returns
 * a canned PSR-7 Response (or throws a Guzzle exception) exercises the happy path, the ServerException
 * -> BadGatewayException translation, and the revId/o_rev_id URL assembly without a network. The URL
 * is captured off the request() call so the assembly branches are assertable directly.
 * getUsernamesFromIds() is pure SQL assembly over the executeProjectsQuery() seam, overridden here as
 * in EditCounterRepositoryTest to record the built SQL and hand back a canned Result, so the IN-list
 * dedup/filter and row passthrough are asserted in isolation. A real ArrayAdapter backs the cache so
 * setCache() behaves as in production. The cache-hit early return in getData() is left uncovered.
 * @covers \App\Repository\AuthorshipRepository
 */
class AuthorshipRepositoryTest extends TestCase {

	/**
	 * The happy path: request() hands back a 200 whose JSON body decodes to an array, and getData()
	 * returns that decoded array.
	 */
	public function testGetDataReturnsDecodedResponseBody(): void {
		$body = [ 'revisions' => [ [ 'tokens' => [] ] ], 'success' => true ];
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willReturn( new Response( 200, [], json_encode( $body ) ) );

		$repo = $this->makeRepository( $guzzle );
		static::assertSame( $body, $repo->getData( $this->makePage(), null ) );
	}

	/**
	 * A ServerException out of Guzzle (WikiWho unreachable / erroring) is translated to a
	 * BadGatewayException rather than bubbling the raw Guzzle failure to the caller.
	 */
	public function testGetDataThrowsBadGatewayOnServerException(): void {
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willThrowException( new ServerException(
			'503 from WikiWho',
			$this->createMock( RequestInterface::class ),
			new Response( 503 )
		) );

		$repo = $this->makeRepository( $guzzle );
		$this->expectException( BadGatewayException::class );
		$repo->getData( $this->makePage(), null );
	}

	/**
	 * A ConnectException (WikiWho DNS failure / connection refused) is the other arm of the source
	 * catch, and is translated to BadGatewayException just like a ServerException.
	 */
	public function testGetDataThrowsBadGatewayOnConnectException(): void {
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willThrowException( new ConnectException(
			'Connection refused',
			$this->createMock( RequestInterface::class )
		) );

		$repo = $this->makeRepository( $guzzle );
		$this->expectException( BadGatewayException::class );
		$repo->getData( $this->makePage(), null );
	}

	/**
	 * With a revId and $returnRevId true, the URL carries the /$revId segment and o_rev_id=true.
	 */
	public function testGetDataBuildsUrlWithRevIdAndReturnRevId(): void {
		$repo = $this->makeRepository( $this->recordingGuzzle( $url ) );
		$repo->getData( $this->makePage(), 12345, true );

		static::assertStringContainsString( '/rev_content/Test_Page/12345/', $url );
		static::assertStringContainsString( 'o_rev_id=true', $url );
		static::assertStringContainsString( '/en/', $url );
	}

	/**
	 * With a null revId and $returnRevId defaulting to false, the /$revId segment is dropped and the
	 * URL carries o_rev_id=false.
	 */
	public function testGetDataBuildsUrlWithoutRevIdAndReturnRevIdFalse(): void {
		$repo = $this->makeRepository( $this->recordingGuzzle( $url ) );
		$repo->getData( $this->makePage(), null );

		// The title runs straight into the query string with no rev segment between.
		static::assertStringContainsString( '/rev_content/Test_Page/?', $url );
		static::assertStringContainsString( 'o_rev_id=false', $url );
	}

	/**
	 * o_rev_id follows $returnRevId, not revId-presence: with a revId set but $returnRevId false the URL
	 * still carries the /$revId segment yet o_rev_id=false. Isolates the two so a regression keying
	 * o_rev_id off revId can't hide.
	 */
	public function testGetDataKeepsORevIdFalseWithRevIdWhenReturnRevIdFalse(): void {
		$repo = $this->makeRepository( $this->recordingGuzzle( $url ) );
		$repo->getData( $this->makePage(), 12345, false );

		static::assertStringContainsString( '/rev_content/Test_Page/12345/', $url );
		static::assertStringContainsString( 'o_rev_id=false', $url );
	}

	/**
	 * getUsernamesFromIds() builds a user_id IN (...) query and passes the returned rows through
	 * untouched.
	 */
	public function testGetUsernamesFromIdsBuildsInQueryAndPassesRowsThrough(): void {
		$rows = [ [ 'user_id' => 5, 'user_name' => 'Alice' ], [ 'user_id' => 7, 'user_name' => 'Bob' ] ];
		$repo = $this->makeRepository( $this->createMock( Client::class ), $rows );

		$result = $repo->getUsernamesFromIds( $this->makeProject(), [ 5, 7 ] );
		static::assertSame( $rows, $result );
		static::assertStringContainsString( 'user_id IN (', $repo->lastSql );
	}

	/**
	 * The input IDs are array_filter()ed (0 and null dropped) and array_unique()d before the IN list,
	 * so [5, 0, 5, 7, null] collapses to 5,7.
	 */
	public function testGetUsernamesFromIdsFiltersAndDedupesIds(): void {
		$repo = $this->makeRepository( $this->createMock( Client::class ), [] );
		$repo->getUsernamesFromIds( $this->makeProject(), [ 5, 0, 5, 7, null ] );

		static::assertStringContainsString( 'user_id IN (5,7)', $repo->lastSql );
	}

	/**
	 * A Page stubbed with the seams getData() touches: getTitle() drives the (space-to-underscore,
	 * urlencoded) title segment, and getProject()->getServerSubdomain() drives the lang segment.
	 */
	private function makePage(): Page {
		$project = $this->createMock( Project::class );
		$project->method( 'getServerSubdomain' )->willReturn( 'en' );

		$page = $this->createMock( Page::class );
		$page->method( 'getTitle' )->willReturn( 'Test Page' );
		$page->method( 'getProject' )->willReturn( $project );
		return $page;
	}

	/**
	 * A Project stubbed for getUsernamesFromIds(): getTableName() resolves the user table so the SQL
	 * assembles.
	 */
	private function makeProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A Guzzle client mock that records the URL (2nd arg) of the request() call into $url and returns
	 * an empty-array JSON body, so the URL-assembly branches can be asserted.
	 */
	private function recordingGuzzle( ?string &$url ): Client {
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willReturnCallback(
			static function ( string $method, string $requestUrl ) use ( &$url ): Response {
				$url = $requestUrl;
				return new Response( 200, [], '[]' );
			}
		);
		return $guzzle;
	}

	/**
	 * The test repository: executeProjectsQuery() is overridden to record the built SQL into a public
	 * property and return a canned Result, and the given Guzzle mock is injected as the 3rd constructor
	 * arg so getData() drives it. A real ArrayAdapter backs the cache so setCache() behaves as in
	 * production. $rows are the rows the canned Result hands back from fetchAllAssociative().
	 */
	private function makeRepository( Client $guzzle, array $rows = [] ): AuthorshipRepository {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );

		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$guzzle,
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			false,
			30
		) extends AuthorshipRepository {

			/** @var Result Canned Result handed back from the executeProjectsQuery() seam. */
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
		$repo->cannedResult = $result;
		return $repo;
	}
}
