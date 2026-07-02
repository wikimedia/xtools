<?php

declare( strict_types = 1 );

namespace App\Controller;

use App\Helper\I18nHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anon lang cookie setter (avoids I18nHelper's session write). Extends
 * AbstractController to skip XtoolsController's per-request project validation.
 */
class LangController extends AbstractController {

	private const COOKIE_LIFETIME_SECONDS = 60 * 60 * 24 * 365;

	#[Route(
		'/setlang/{code}',
		name: 'xtools_setlang',
		requirements: [ 'code' => '[a-z][a-z0-9-]{0,19}' ]
	)]
	public function setlangAction(
		Request $request,
		I18nHelper $i18n,
		string $code
	): Response {
		$return = $this->safeReturnPath( $request->query->get( 'return' ) );

		$response = new RedirectResponse( $return );
		// Accept any code Intuition recognizes (matching getIntuition()'s render-path
		// check) so variants like en-gb / zh-hant persist and fall back at render.
		if ( $i18n->getIntuition()->getLangName( $code ) ) {
			$response->headers->setCookie( Cookie::create(
				I18nHelper::LANG_COOKIE_NAME,
				$code,
				time() + self::COOKIE_LIFETIME_SECONDS,
				secure: $request->isSecure(),
			) );
		}
		return $response;
	}

	/**
	 * Reject any return value that isn't a same-origin relative path; fall back
	 * to the homepage otherwise. Also strip ?uselang= from the return so it
	 * doesn't override the freshly-set cookie on the next render.
	 */
	private function safeReturnPath( mixed $return ): string {
		if (
			!is_string( $return )
			|| !str_starts_with( $return, '/' )
			|| str_starts_with( $return, '//' )
			|| str_contains( $return, '\\' )
		) {
			return '/';
		}
		$parts = parse_url( $return ) ?: [];
		$path = $parts['path'] ?? '/';
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $params );
			unset( $params['uselang'] );
			if ( $params ) {
				$path .= '?' . http_build_query( $params );
			}
		}
		if ( isset( $parts['fragment'] ) ) {
			$path .= '#' . $parts['fragment'];
		}
		return $path;
	}
}
