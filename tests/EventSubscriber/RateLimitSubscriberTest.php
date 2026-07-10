<?php

declare( strict_types = 1 );

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\RateLimitSubscriber;
use App\Helper\I18nHelper;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @covers \App\EventSubscriber\RateLimitSubscriber
 */
class RateLimitSubscriberTest extends TestCase {

	/**
	 * Two addresses share a rate-limit bucket exactly when they fall in the same subnet
	 * (/24 for IPv4, /64 for IPv6); an IPv4-mapped IPv6 address buckets by its IPv4 /24.
	 * @dataProvider provideSubnetGrouping
	 */
	public function testSubnetGrouping( string $ipA, string $ipB, bool $expectSame ): void {
		$keyA = RateLimitSubscriber::subnetCacheKey( $ipA );
		$keyB = RateLimitSubscriber::subnetCacheKey( $ipB );
		static::assertNotNull( $keyA );
		static::assertNotNull( $keyB );
		if ( $expectSame ) {
			static::assertSame( $keyA, $keyB, "$ipA and $ipB should share a bucket" );
		} else {
			static::assertNotSame( $keyA, $keyB, "$ipA and $ipB should not share a bucket" );
		}
	}

	/**
	 * @return array[]
	 */
	public static function provideSubnetGrouping(): array {
		return [
			'IPv4, same /24' => [ '1.2.3.4', '1.2.3.250', true ],
			'IPv4, different /24' => [ '1.2.3.4', '1.2.4.4', false ],
			'IPv4-mapped IPv6 buckets as its IPv4 /24' => [ '::ffff:1.2.3.4', '1.2.3.250', true ],
			'IPv4-mapped IPv6 in hex notation buckets the same' => [ '::ffff:0102:0304', '1.2.3.4', true ],
			'IPv6, same /64' => [ '2001:db8::1', '2001:db8::ffff', true ],
			'IPv6, different /64' => [ '2001:db8:0:1::1', '2001:db8:0:2::1', false ],
			'IPv4 and IPv6 never collide' => [ '1.2.3.4', '2001:db8::1', false ],
		];
	}

	/**
	 * An unparseable address yields no key, so the caller skips rate limiting rather than
	 * bucketing every junk value together.
	 * @dataProvider provideUnparseable
	 */
	public function testUnparseableAddressYieldsNull( string $clientIp ): void {
		static::assertNull( RateLimitSubscriber::subnetCacheKey( $clientIp ) );
	}

	/**
	 * @return array[]
	 */
	public static function provideUnparseable(): array {
		return [
			'empty string' => [ '' ],
			'not an IP' => [ 'not-an-ip' ],
			'truncated IPv4' => [ '1.2.3' ],
		];
	}

	/**
	 * A rate-limited action from a resolvable client bumps its subnet counter.
	 */
	public function testRateLimitedActionIncrementsCounter(): void {
		$item = $this->createMock( CacheItemInterface::class );
		$item->method( 'isHit' )->willReturn( false );
		$item->method( 'set' )->willReturnSelf();
		$item->method( 'expiresAfter' )->willReturnSelf();

		$cache = $this->createMock( CacheItemPoolInterface::class );
		$cache->expects( static::once() )->method( 'getItem' )->willReturn( $item );
		$cache->expects( static::once() )->method( 'save' )->with( $item );

		$this->makeSubscriber( $cache )->onKernelRequest(
			$this->makeEvent( 'App\Controller\EditCounterController::resultAction' )
		);
	}

	/**
	 * Once the subnet is over its limit, the request is rejected with a 429.
	 */
	public function testExceedingLimitThrows(): void {
		$item = $this->createMock( CacheItemInterface::class );
		$item->method( 'isHit' )->willReturn( true );
		$item->method( 'get' )->willReturn( 60 );
		$item->method( 'set' )->willReturnSelf();
		$item->method( 'expiresAfter' )->willReturnSelf();

		$cache = $this->createMock( CacheItemPoolInterface::class );
		$cache->method( 'getItem' )->willReturn( $item );

		$i18n = $this->createMock( I18nHelper::class );
		$i18n->method( 'msg' )->willReturn( 'rate limited' );

		$this->expectException( TooManyRequestsHttpException::class );
		$this->makeSubscriber( $cache, 60, 60, $i18n )->onKernelRequest(
			$this->makeEvent( 'App\Controller\EditCounterController::resultAction' )
		);
	}

	/**
	 * The limiter derives its target from the _controller string; a non-XtoolsController
	 * route, an allowlisted action, and a sub-request are all left alone (counter untouched).
	 * @dataProvider provideSkipped
	 */
	public function testSkippedRequestsTouchNoCounter( string $controller, bool $mainRequest ): void {
		$cache = $this->createMock( CacheItemPoolInterface::class );
		$cache->expects( static::never() )->method( 'getItem' );

		$this->makeSubscriber( $cache )->onKernelRequest(
			$this->makeEvent( $controller, '203.0.113.7', $mainRequest )
		);
	}

	/**
	 * @return array[]
	 */
	public static function provideSkipped(): array {
		return [
			'non-XtoolsController route' => [ 'stdClass::foo', true ],
			'allowlisted action' => [ 'App\Controller\EditCounterController::indexAction', true ],
			'sub-request' => [ 'App\Controller\EditCounterController::resultAction', false ],
		];
	}

	private function makeSubscriber(
		CacheItemPoolInterface $cache,
		int $rateLimit = 60,
		int $rateDuration = 60,
		?I18nHelper $i18n = null
	): RateLimitSubscriber {
		$parameterBag = $this->createMock( ParameterBagInterface::class );
		// No denylist configured, so checkDenylist() is a no-op.
		$parameterBag->method( 'has' )->willReturn( false );
		$logger = $this->createMock( LoggerInterface::class );

		return new RateLimitSubscriber(
			$i18n ?? $this->createMock( I18nHelper::class ),
			$cache,
			$parameterBag,
			new RequestStack(),
			$logger,
			$logger,
			$logger,
			$rateLimit,
			$rateDuration
		);
	}

	private function makeEvent(
		string $controller,
		string $remoteAddr = '203.0.113.7',
		bool $mainRequest = true
	): RequestEvent {
		$request = Request::create( '/', 'GET', [], [], [], [ 'REMOTE_ADDR' => $remoteAddr ] );
		$request->attributes->set( '_controller', $controller );

		return new RequestEvent(
			$this->createMock( HttpKernelInterface::class ),
			$request,
			$mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST
		);
	}
}
