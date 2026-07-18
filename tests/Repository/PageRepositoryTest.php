<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Exception\BadGatewayException;
use App\Model\Page;
use App\Model\Project;
use App\Model\User;
use App\Repository\PageRepository;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * PageRepository is a mix of three seams, none of which needs infrastructure. Its DB-query methods
 * (getRevisions*, getNumRevisions, countLinksAndRedirects, getWikidataItems) assemble SQL over the
 * executeProjectsQuery() seam, overridden here as in the sibling repository tests to record the built
 * SQL/params and hand back a canned Result, so the branch logic and row transforms are asserted in
 * isolation. Its API-query methods (getPagesInfo/getPageInfo/getPagesWikitext) go through the
 * executeApiRequest() seam, overridden to return a canned array. Its HTTP methods (getHTMLContent,
 * getPageviews, displayTitles) talk to the Wikimedia REST/MediaWiki API through the injected Guzzle
 * client, so a mocked Client whose request() returns a canned PSR-7 Response (or throws a Guzzle
 * exception) exercises the URL assembly, decode, and error translation without a network.
 * getRevisionIdAtDate() bypasses executeProjectsQuery() and drives the raw projects connection, so its
 * getProjectsConnection() seam is overridden instead. A real ArrayAdapter backs the cache so
 * getCacheKey()/setCache() behave as in production. Cache-hit early returns run against a cold cache and
 * are left uncovered.
 *
 * The WMF-only branches: CheckWiki errors live in a Toolforge-only database (s51080__checkwiki_p),
 * so off-WMF getCheckWikiErrors() short-circuits to [] without touching a connection; and
 * getHTMLContent() targets the Wikimedia REST API on WMF but falls back to the raw page URL elsewhere.
 * isWMF is a constructor argument, so injecting it both ways covers both branches.
 * @covers \App\Repository\PageRepository
 */
class PageRepositoryTest extends TestCase {

	/**
	 * Off-WMF the CheckWiki database doesn't exist, so the method returns early with no query. We
	 * assert the empty result without stubbing any connection: reaching one would be a failure.
	 */
	public function testGetCheckWikiErrorsIsEmptyOffWmf(): void {
		$page = $this->createMock( Page::class );
		$page->method( 'getNamespace' )->willReturn( 0 );

		$repo = $this->makeRepository( false, $this->createMock( Client::class ) );
		static::assertSame( [], $repo->getCheckWikiErrors( $page ) );
	}

	/**
	 * On WMF the CheckWiki query runs against the toolsdb connection. It strips a trailing _p from the
	 * database name and turns underscores in the title back into spaces before binding, and passes the
	 * returned error rows straight through.
	 */
	public function testGetCheckWikiErrorsQueriesToolsDbOnWmf(): void {
		$rows = [ [ 'error' => 1, 'name' => 'Broken link', 'prio' => 2 ] ];
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willReturnCallback(
			static function ( string $sql, array $params ) use ( &$captured, $result ) {
				$captured = $params;
				return $result;
			}
		);

		$project = $this->createMock( Project::class );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki_p' );
		$page = $this->createMock( Page::class );
		$page->method( 'getNamespace' )->willReturn( 0 );
		$page->method( 'getProject' )->willReturn( $project );
		$page->method( 'getTitle' )->willReturn( 'Some_Page' );

		$repo = $this->makeRepository( true, $this->createMock( Client::class ), $connection );
		static::assertSame( $rows, $repo->getCheckWikiErrors( $page ) );
		static::assertSame( 'enwiki', $captured['dbName'] );
		static::assertSame( 'Some Page', $captured['title'] );
	}

	/**
	 * On WMF we ask the Wikimedia REST API for the page HTML; off-WMF we hit the page's own URL with
	 * an oldid query string. We capture the URL Guzzle was handed to tell the two branches apart.
	 */
	public function testGetHTMLContentUsesRestApiOnlyOnWmf(): void {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( 'en.wikipedia.org' );
		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $project );
		$page->method( 'getTitle' )->willReturn( 'Foo bar' );
		$page->method( 'getUrl' )->willReturn( 'https://en.wikipedia.org/wiki/Foo_bar' );

		$wmf = $this->makeRepository( true, $this->guzzleCapturing( $capturedWmf ) );
		static::assertSame( 'canned', $wmf->getHTMLContent( $page, 123 ) );
		static::assertStringContainsString( '/api/rest_v1/page/html/', $capturedWmf );

		$thirdParty = $this->makeRepository( false, $this->guzzleCapturing( $capturedLocal ) );
		static::assertSame( 'canned', $thirdParty->getHTMLContent( $page, 123 ) );
		static::assertStringContainsString( '?oldid=', $capturedLocal );
	}

	/**
	 * A ServerException out of Guzzle (the REST API erroring) is translated to a BadGatewayException
	 * rather than bubbling the raw Guzzle failure to the caller.
	 */
	public function testGetHTMLContentThrowsBadGatewayOnServerException(): void {
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willThrowException( new ServerException(
			'503 from REST',
			$this->createMock( RequestInterface::class ),
			new Response( 503 )
		) );

		$repo = $this->makeRepository( true, $guzzle );
		$this->expectException( BadGatewayException::class );
		$repo->getHTMLContent( $this->makeHtmlPage() );
	}

	/**
	 * The REST API sometimes 404s pages that in fact exist; when the page exists that 404 is treated
	 * as an upstream fault and translated to BadGatewayException rather than surfaced as a real 404.
	 */
	public function testGetHTMLContentThrowsBadGatewayOn404WhenPageExists(): void {
		$page = $this->makeHtmlPage();
		$page->method( 'exists' )->willReturn( true );

		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willThrowException( new ClientException(
			'404 from REST',
			$this->createMock( RequestInterface::class ),
			new Response( HttpResponse::HTTP_NOT_FOUND )
		) );

		$repo = $this->makeRepository( true, $guzzle );
		$this->expectException( BadGatewayException::class );
		$repo->getHTMLContent( $page );
	}

	/**
	 * A genuine 404 for a page that doesn't exist is not laundered into a BadGateway: the raw
	 * ClientException is rethrown so the caller can distinguish a missing page from an upstream fault.
	 */
	public function testGetHTMLContentRethrowsClientExceptionWhenPageMissing(): void {
		$page = $this->makeHtmlPage();
		$page->method( 'exists' )->willReturn( false );

		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willThrowException( new ClientException(
			'404 from REST',
			$this->createMock( RequestInterface::class ),
			new Response( HttpResponse::HTTP_NOT_FOUND )
		) );

		$repo = $this->makeRepository( true, $guzzle );
		$this->expectException( ClientException::class );
		$repo->getHTMLContent( $page );
	}

	/**
	 * getPagesInfo() keys each returned page by its title. getPageInfo() is the single-page front end:
	 * it delegates to getPagesInfo() and array_shift()s the one result off.
	 */
	public function testGetPageInfoReturnsFirstPageKeyedByTitle(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'query' => [ 'pages' => [
			[ 'title' => 'Foo', 'pageid' => 5 ],
		] ] ];

		$info = $repo->getPageInfo( $this->makeProject(), 'Foo' );
		static::assertSame( 5, $info['pageid'] );
	}

	/**
	 * A response with no query.pages block means the page doesn't exist, so getPagesInfo() returns
	 * null (and getPageInfo() passes that null straight through).
	 */
	public function testGetPageInfoReturnsNullWhenNoPages(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'batchcomplete' => true ];

		static::assertNull( $repo->getPageInfo( $this->makeProject(), 'Nonexistent' ) );
	}

	/**
	 * getPagesInfo() rekeys the flat pages list into a title=>info map for multiple titles.
	 */
	public function testGetPagesInfoKeysResultsByTitle(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'query' => [ 'pages' => [
			[ 'title' => 'Foo', 'pageid' => 5 ],
			[ 'title' => 'Bar', 'pageid' => 7 ],
		] ] ];

		$info = $repo->getPagesInfo( $this->makeProject(), [ 'Foo', 'Bar' ] );
		static::assertSame( [ 'Foo', 'Bar' ], array_keys( $info ) );
		static::assertSame( 7, $info['Bar']['pageid'] );
	}

	/**
	 * getPagesWikitext() maps each page title to its first revision's content.
	 */
	public function testGetPagesWikitextExtractsRevisionContent(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'query' => [ 'pages' => [
			[ 'title' => 'Foo', 'revisions' => [ [ 'content' => 'Hello world' ] ] ],
		] ] ];

		$text = $repo->getPagesWikitext( $this->makeProject(), [ 'Foo' ] );
		static::assertSame( [ 'Foo' => 'Hello world' ], $text );
	}

	/**
	 * A page with no revision content (e.g. a missing page in the batch) maps to an empty string
	 * rather than being dropped, so callers get an entry for every requested title.
	 */
	public function testGetPagesWikitextDefaultsMissingContentToEmptyString(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'query' => [ 'pages' => [
			[ 'title' => 'Foo' ],
		] ] ];

		static::assertSame( [ 'Foo' => '' ], $repo->getPagesWikitext( $this->makeProject(), [ 'Foo' ] ) );
	}

	/**
	 * With no query.pages block getPagesWikitext() returns an empty array rather than erroring.
	 */
	public function testGetPagesWikitextReturnsEmptyWhenNoPages(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedApiResponse = [ 'batchcomplete' => true ];

		static::assertSame( [], $repo->getPagesWikitext( $this->makeProject(), [ 'Foo' ] ) );
	}

	/**
	 * getRevisions() runs the statement on a cache miss and returns its associative rows.
	 */
	public function testGetRevisionsReturnsFetchedRows(): void {
		$rows = [ [ 'id' => 1, 'timestamp' => '20200101000000' ] ];
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		static::assertSame( $rows, $repo->getRevisions( $this->makeRevisionsPage() ) );
	}

	/**
	 * Without a user, getRevisionsStmt() queries all revisions of the page (no actor clause) and binds
	 * only the page id.
	 */
	public function testGetRevisionsStmtOmitsActorClauseWithoutUser(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getRevisionsStmt( $this->makeRevisionsPage() );
		static::assertStringContainsString( 'revs.rev_page = :pageid', $repo->lastSql );
		static::assertStringNotContainsString( 'revs.rev_actor = :actorId', $repo->lastSql );
		static::assertSame( [ 'pageid' => 42 ], $repo->lastParams );
	}

	/**
	 * With a user, getRevisionsStmt() adds the actor filter and binds the resolved actor id alongside
	 * the page id.
	 */
	public function testGetRevisionsStmtAddsActorClauseWithUser(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getRevisionsStmt( $this->makeRevisionsPage(), $this->makeUser() );
		static::assertStringContainsString( 'revs.rev_actor = :actorId AND', $repo->lastSql );
		static::assertSame( 1, $repo->lastParams['actorId'] );
	}

	/**
	 * A positive limit becomes a LIMIT clause; the default (null) leaves the query unbounded.
	 */
	public function testGetRevisionsStmtAddsLimitClauseWhenLimitGiven(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( [] ) ];

		$repo->getRevisionsStmt( $this->makeRevisionsPage(), null, 50 );
		static::assertStringContainsString( 'LIMIT 50', $repo->lastSql );

		$repo->getRevisionsStmt( $this->makeRevisionsPage() );
		static::assertStringNotContainsString( 'LIMIT', $repo->lastSql );
	}

	/**
	 * Without a user, getNumRevisions() counts all revisions of the page and binds only the page id.
	 */
	public function testGetNumRevisionsCountsAllRevisionsWithoutUser(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '128' ) ];

		$count = $repo->getNumRevisions( $this->makeRevisionsPage() );
		static::assertSame( 128, $count );
		static::assertStringContainsString( 'COUNT(*)', $repo->lastSql );
		static::assertStringNotContainsString( 'rev_actor = :actorId', $repo->lastSql );
		static::assertSame( [ 'pageid' => 42 ], $repo->lastParams );
	}

	/**
	 * With a user, getNumRevisions() adds the actor filter and binds the resolved actor id.
	 */
	public function testGetNumRevisionsAddsActorClauseWithUser(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->scalarResult( '3' ) ];

		$count = $repo->getNumRevisions( $this->makeRevisionsPage(), $this->makeUser() );
		static::assertSame( 3, $count );
		static::assertStringContainsString( 'rev_actor = :actorId AND', $repo->lastSql );
		// The bound key must match the :actorId placeholder, or the query throws when a user is given.
		static::assertSame( 1, $repo->lastParams['actorId'] );
	}

	/**
	 * countLinksAndRedirects() unions the four link/redirect counts into a type=>value map and binds
	 * the page id, namespace and DB-form title.
	 */
	public function testCountLinksAndRedirectsBuildsUnionAndBindsParams(): void {
		$map = [
			'links_ext_count' => 2,
			'links_out_count' => 10,
			'links_in_count' => 5,
			'redirects_count' => 1,
		];
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->keyValueResult( $map ) ];

		$counts = $repo->countLinksAndRedirects( $this->makeLinksPage() );
		static::assertSame( $map, $counts );
		static::assertStringContainsString( 'links_ext_count', $repo->lastSql );
		static::assertStringContainsString( 'redirects_count', $repo->lastSql );
		static::assertSame( 42, $repo->lastParams['id'] );
		static::assertSame( 'Some_Page', $repo->lastParams['title'] );
		static::assertSame( 0, $repo->lastParams['namespace'] );
	}

	/**
	 * With no Wikidata id the method short-circuits before any query: the list form returns [] and the
	 * count form returns 0.
	 */
	public function testGetWikidataItemsShortCircuitsWithoutWikidataId(): void {
		$page = $this->createMock( Page::class );
		$page->method( 'getWikidataId' )->willReturn( null );

		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		static::assertSame( [], $repo->getWikidataItems( $page ) );
		static::assertSame( 0, $repo->getWikidataItems( $page, true ) );
		// No query ran.
		static::assertSame( '', $repo->lastSql );
	}

	/**
	 * The list form strips the leading Q, selects * from the sister-links table and returns the rows.
	 */
	public function testGetWikidataItemsReturnsRowsForListForm(): void {
		$rows = [ [ 'ips_site_id' => 'enwiki' ], [ 'ips_site_id' => 'dewiki' ] ];
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( $rows ) ];

		$result = $repo->getWikidataItems( $this->makeWikidataPage() );
		static::assertSame( $rows, $result );
		static::assertStringContainsString( 'SELECT *', $repo->lastSql );
		static::assertSame( '42', $repo->lastParams['wikidataId'] );
	}

	/**
	 * The count form selects COUNT(*) and returns the count column cast to int.
	 */
	public function testGetWikidataItemsReturnsCountForCountForm(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( [ [ 'count' => '3' ] ] ) ];

		static::assertSame( 3, $repo->getWikidataItems( $this->makeWikidataPage(), true ) );
		static::assertStringContainsString( 'COUNT(*) AS count', $repo->lastSql );
	}

	/**
	 * countWikidataItems() is the count-only front end: it calls getWikidataItems() with $count true.
	 */
	public function testCountWikidataItemsDelegatesToCountForm(): void {
		$repo = $this->makeRepository( true, $this->createMock( Client::class ) );
		$repo->cannedQueries = [ '*' => $this->assocResult( [ [ 'count' => '9' ] ] ) ];

		static::assertSame( 9, $repo->countWikidataItems( $this->makeWikidataPage() ) );
		static::assertStringContainsString( 'COUNT(*) AS count', $repo->lastSql );
	}

	/**
	 * getRevisionIdAtDate() runs a raw query on the projects connection (not the executeProjectsQuery
	 * seam) and casts the max rev id to int; the timestamp and page id are inlined into the SQL.
	 */
	public function testGetRevisionIdAtDateReturnsMaxRevIdAsInt(): void {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( '9001' );
		$connection = $this->createMock( Connection::class );
		$connection->method( 'executeQuery' )->willReturnCallback(
			static function ( string $sql ) use ( &$capturedSql, $result ) {
				$capturedSql = $sql;
				return $result;
			}
		);

		$repo = $this->makeRepository( true, $this->createMock( Client::class ), $connection );
		$revId = $repo->getRevisionIdAtDate(
			$this->makeRevisionsPage(),
			new DateTime( '2020-01-02 03:04:05' )
		);
		static::assertSame( 9001, $revId );
		static::assertStringContainsString( 'MAX(rev_id)', $capturedSql );
		static::assertStringContainsString( 'rev_timestamp <= 20200102030405', $capturedSql );
		static::assertStringContainsString( 'rev_page = 42', $capturedSql );
	}

	/**
	 * getPageviews() memoizes its result in a method-static across calls in one process, so the
	 * error-translation and happy paths must be exercised together in declaration order within one
	 * test: an upstream failure (which never sets the static) is translated to BadGatewayException,
	 * then a successful call with DateTime bounds builds the per-article REST URL, decodes the body,
	 * and populates the static. Splitting these into separate methods would let the first successful
	 * call anywhere in the suite poison every later getPageviews() invocation.
	 */
	public function testGetPageviewsTranslatesUpstreamErrorThenDecodesOnSuccess(): void {
		$failing = $this->createMock( Client::class );
		$failing->method( 'request' )->willThrowException( new ServerException(
			'503 from Pageviews',
			$this->createMock( RequestInterface::class ),
			new Response( 503 )
		) );
		$errorRepo = $this->makeRepository( true, $failing );
		try {
			$errorRepo->getPageviews( $this->makePageviewsPage(), '2020-01-01', '2020-01-31' );
			static::fail( 'Expected BadGatewayException' );
		} catch ( BadGatewayException $e ) {
			static::assertInstanceOf( BadGatewayException::class, $e );
		}

		$body = [ 'items' => [ [ 'views' => 10 ] ] ];
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willReturnCallback(
			static function ( string $method, string $url ) use ( &$captured, $body ): Response {
				$captured = $url;
				return new Response( 200, [], json_encode( $body ) );
			}
		);
		$repo = $this->makeRepository( true, $guzzle );
		$result = $repo->getPageviews(
			$this->makePageviewsPage(),
			new DateTime( '2020-01-01' ),
			new DateTime( '2020-01-31' )
		);
		static::assertSame( $body, $result );
		static::assertStringContainsString(
			'/metrics/pageviews/per-article/en.wikipedia.org/all-access/user/Foo_bar/daily/20200101/20200131',
			$captured
		);
	}

	/**
	 * displayTitles() prefers each page's pageprops.displaytitle and falls back to its plain title,
	 * keyed by the original (pre-normalization) title the caller passed in.
	 */
	public function testDisplayTitlesPrefersDisplayTitleAndKeysByOriginal(): void {
		$body = [ 'query' => [
			'normalized' => [ [ 'from' => 'foo bar', 'to' => 'Foo bar' ] ],
			'pages' => [
				[ 'title' => 'Foo bar', 'pageprops' => [ 'displaytitle' => '<i>Foo bar</i>' ] ],
				[ 'title' => 'Baz' ],
			],
		] ];
		$guzzle = $this->createMock( Client::class );
		$guzzle->method( 'request' )->willReturn( new Response( 200, [], json_encode( $body ) ) );

		$repo = $this->makeRepository( true, $guzzle );
		$titles = $repo->displayTitles( $this->makeApiProject(), [ 'foo bar', 'Baz' ] );
		// The normalized 'Foo bar' is keyed back to the original 'foo bar' the caller supplied.
		static::assertSame( '<i>Foo bar</i>', $titles['foo bar'] );
		// No display title, no normalization: falls back to the plain title, keyed by itself.
		static::assertSame( 'Baz', $titles['Baz'] );
	}

	/**
	 * A mocked Guzzle client that records the URL passed to request() into $captured and hands back a
	 * response whose body reads 'canned', so getHTMLContent() returns without any real request.
	 */
	private function guzzleCapturing( ?string &$captured ): Client {
		$stream = $this->createMock( StreamInterface::class );
		$stream->method( 'getContents' )->willReturn( 'canned' );
		$response = $this->createMock( ResponseInterface::class );
		$response->method( 'getBody' )->willReturn( $stream );

		$guzzle = $this->createMock( Client::class );
		$guzzle->expects( $this->once() )
			->method( 'request' )
			->willReturnCallback( static function ( string $method, string $url ) use ( &$captured, $response ) {
				$captured = $url;
				return $response;
			} );
		return $guzzle;
	}

	/**
	 * A Page stubbed for getHTMLContent()'s WMF branch: domain and title drive the REST URL, exists()
	 * is set per-test for the 404 branches.
	 */
	private function makeHtmlPage(): Page {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( 'en.wikipedia.org' );
		$page = $this->createMock( Page::class );
		$page->method( 'getProject' )->willReturn( $project );
		$page->method( 'getTitle' )->willReturn( 'Foo bar' );
		return $page;
	}

	/**
	 * A Page stubbed for the revision queries: getId() binds the pageid param and getProject() resolves
	 * the (labs-quoted) table names the SQL interpolates.
	 */
	private function makeRevisionsPage(): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getId' )->willReturn( 42 );
		$page->method( 'getProject' )->willReturn( $this->makeProject() );
		return $page;
	}

	/**
	 * A Page stubbed for countLinksAndRedirects(): getId()/getNamespace() bind params and
	 * getTitleWithoutNamespace() drives the space-to-underscore DB-form title.
	 */
	private function makeLinksPage(): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getId' )->willReturn( 42 );
		$page->method( 'getNamespace' )->willReturn( 0 );
		$page->method( 'getTitleWithoutNamespace' )->willReturn( 'Some Page' );
		$page->method( 'getProject' )->willReturn( $this->makeProject() );
		return $page;
	}

	/**
	 * A Page with a Wikidata id, whose leading Q getWikidataItems() strips before binding.
	 */
	private function makeWikidataPage(): Page {
		$page = $this->createMock( Page::class );
		$page->method( 'getWikidataId' )->willReturn( 'Q42' );
		return $page;
	}

	/**
	 * A Page stubbed for getPageviews(): getTitle() drives the space-to-underscore, urlencoded title
	 * segment and getProject()->getDomain() drives the project segment.
	 */
	private function makePageviewsPage(): Page {
		$project = $this->createMock( Project::class );
		$project->method( 'getDomain' )->willReturn( 'en.wikipedia.org' );
		$page = $this->createMock( Page::class );
		$page->method( 'getTitle' )->willReturn( 'Foo bar' );
		$page->method( 'getProject' )->willReturn( $project );
		return $page;
	}

	/**
	 * A Project stubbed for the SQL builders: getTableName() quotes the labs-style name and
	 * getDatabaseName()/getCacheKey() keep the SQL and cache wiring happy.
	 */
	private function makeProject( string $domain = 'en.wikipedia.org' ): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( $domain );
		$project->method( 'getDatabaseName' )->willReturn( 'enwiki' );
		$project->method( 'getTableName' )->willReturnCallback(
			static fn ( string $table ): string => "`enwiki_p`.`$table`"
		);
		return $project;
	}

	/**
	 * A Project stubbed for the API/HTTP methods: getApiUrl() and getCacheKey() are the only seams
	 * getPagesInfo()/displayTitles() touch.
	 */
	private function makeApiProject(): Project {
		$project = $this->createMock( Project::class );
		$project->method( 'getCacheKey' )->willReturn( 'en.wikipedia.org' );
		$project->method( 'getApiUrl' )->willReturn( 'https://en.wikipedia.org/w/api.php' );
		return $project;
	}

	/**
	 * A named account. getActorId() resolves so the actor-based revision queries have something to bind.
	 */
	private function makeUser(): User {
		$user = $this->createMock( User::class );
		$user->method( 'getActorId' )->willReturn( 1 );
		return $user;
	}

	/**
	 * A Doctrine Result whose fetchAllAssociative() returns the given rows.
	 * @param array<array<string, mixed>> $rows
	 */
	private function assocResult( array $rows ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllAssociative' )->willReturn( $rows );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchOne() returns the given scalar.
	 */
	private function scalarResult( mixed $value ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchOne' )->willReturn( $value );
		return $result;
	}

	/**
	 * A Doctrine Result whose fetchAllKeyValue() returns the given map.
	 * @param array<int|string, mixed> $map
	 */
	private function keyValueResult( array $map ): Result {
		$result = $this->createMock( Result::class );
		$result->method( 'fetchAllKeyValue' )->willReturn( $map );
		return $result;
	}

	/**
	 * The test repository: executeProjectsQuery() records the built SQL/params and returns whichever
	 * canned Result matches the SQL ('*' = any), executeApiRequest() returns a canned array, and
	 * getProjectsConnection() (for getRevisionIdAtDate's raw query) and getToolsConnection() (for the
	 * on-WMF getCheckWikiErrors query) return an injected Connection. A real ArrayAdapter backs the
	 * cache so getCacheKey()/setCache() behave without infra.
	 */
	private function makeRepository(
		bool $isWMF,
		Client $guzzle,
		?Connection $connection = null
	): PageRepository {
		$repo = new class(
			$this->createMock( ManagerRegistry::class ),
			new ArrayAdapter(),
			$guzzle,
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30
		) extends PageRepository {

			/** @var array<string, Result> Canned Results keyed by an SQL substring ('*' = any). */
			public array $cannedQueries = [];

			/** @var array Canned API response returned by executeApiRequest(). */
			public array $cannedApiResponse = [];

			/** @var Connection|null Injected connection for the raw getRevisionIdAtDate()/CheckWiki queries. */
			public ?Connection $connection = null;

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
				foreach ( $this->cannedQueries as $needle => $result ) {
					if ( $needle === '*' || str_contains( $sql, $needle ) ) {
						return $result;
					}
				}
				throw new \LogicException( "No canned result for query: $sql" );
			}

			public function executeApiRequest( Project $project, array $params ): array {
				return $this->cannedApiResponse;
			}

			protected function getProjectsConnection( Project|string $project, bool $checkBreaker = true ): Connection {
				return $this->connection;
			}

			protected function getToolsConnection(): Connection {
				return $this->connection;
			}
		};
		$repo->connection = $connection;
		return $repo;
	}
}
