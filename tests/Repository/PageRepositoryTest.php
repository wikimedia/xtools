<?php

declare( strict_types = 1 );

namespace App\Tests\Repository;

use App\Model\Page;
use App\Model\Project;
use App\Repository\PageRepository;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The WMF-only branches in PageRepository: CheckWiki errors live in a Toolforge-only database
 * (s51080__checkwiki_p), so off-WMF getCheckWikiErrors() short-circuits to [] without touching a
 * connection; and getHTMLContent() targets the Wikimedia REST API on WMF but falls back to the raw
 * page URL elsewhere. isWMF is a constructor argument, so injecting it both ways covers both
 * branches. getHTMLContent's only I/O seam is the Guzzle client, which we mock to capture the URL
 * the branch built instead of making a request.
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
	 * A PageRepository with the given isWMF flag and Guzzle client; the remaining base-constructor
	 * dependencies are inert mocks, since these branches never reach them.
	 */
	private function makeRepository( bool $isWMF, Client $guzzle ): PageRepository {
		return new PageRepository(
			$this->createMock( ManagerRegistry::class ),
			$this->createMock( CacheItemPoolInterface::class ),
			$guzzle,
			new NullLogger(),
			$this->createMock( ParameterBagInterface::class ),
			$isWMF,
			30
		);
	}
}
