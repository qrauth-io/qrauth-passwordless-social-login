<?php
/**
 * Unit tests for {@see RestController::decide_redirect()}.
 *
 * Pure Brain\Monkey — no WP harness. Stubs the WP helpers the method
 * touches (`admin_url`, `site_url`, `wp_login_url`,
 * `wp_validate_redirect`, `wp_parse_url`) plus `wc_get_page_permalink`.
 * `WP_REST_Request` is loaded from `tests/stubs/wp-rest-request.php`.
 *
 * The "no WooCommerce active" branch (where `function_exists(
 * 'wc_get_page_permalink' )` returns false) is deliberately NOT covered
 * here because PHP can't undefine a function once Brain\Monkey has
 * created it. That code path runs through every integration test in
 * `tests/Integration/` since the WP test harness runs without
 * WooCommerce loaded, so it's continuously exercised there.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Rest\RestController;

require_once dirname( __DIR__, 2 ) . '/stubs/wp-rest-request.php';

/**
 * Exercises every branch of the redirect-decision logic added in 0.1.9.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Rest\RestController::decide_redirect
 */
final class RedirectDecisionTest extends TestCase {

	/**
	 * Brain\Monkey + stubs for the small WP surface `decide_redirect` touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/' );
		Functions\when( 'site_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				if ( ! is_string( $url ) || '' === $url ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- deliberately calling native parse_url from inside the wp_parse_url shim.
				return -1 === $component ? \parse_url( $url ) : \parse_url( $url, $component );
			}
		);

		// `wp_validate_redirect`: return the input when it's same-origin
		// with site_url(), empty otherwise. Mirrors WP core semantics at
		// the precision this unit test cares about.
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( $location, $fallback = '' ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test-only stub; we're mimicking wp_validate_redirect's own URL parsing.
				$loc = is_string( $location ) ? \parse_url( $location ) : false;
				if ( ! is_array( $loc ) ) {
					return $fallback;
				}
				if ( ( $loc['host'] ?? '' ) !== 'example.test' ) {
					return $fallback;
				}
				if ( ( $loc['scheme'] ?? '' ) !== 'https' ) {
					return $fallback;
				}
				return $location;
			}
		);

		// `wc_get_page_permalink`: default stub returns '' for every slug.
		// Represents both "WC loaded but pages not configured" and (for
		// matching purposes) "no WC page matches this slug". Individual
		// tests call override_wc_pages() to supply real URLs.
		Functions\when( 'wc_get_page_permalink' )->alias(
			static function ( $slug ) {
				unset( $slug );
				return '';
			}
		);
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Replace the default `wc_get_page_permalink` stub with one that
	 * returns real URLs for `myaccount` and `checkout` slugs.
	 *
	 * @param string $myaccount My Account page URL.
	 * @param string $checkout  Checkout page URL.
	 */
	private function override_wc_pages( string $myaccount, string $checkout ): void {
		Functions\when( 'wc_get_page_permalink' )->alias(
			static function ( $slug ) use ( $myaccount, $checkout ) {
				if ( 'myaccount' === $slug ) {
					return $myaccount;
				}
				if ( 'checkout' === $slug ) {
					return $checkout;
				}
				return '';
			}
		);
	}

	/**
	 * Build a request with the given Referer.
	 *
	 * @param string $referer Value of the Referer header; pass '' to omit.
	 */
	private function request_with_referer( string $referer ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		if ( '' !== $referer ) {
			$request->set_header( 'Referer', $referer );
		}
		return $request;
	}

	// ------------------------------------------------------------------
	// Safe fallbacks
	// ------------------------------------------------------------------

	/**
	 * No Referer → admin_url fallback.
	 */
	public function test_no_referer_returns_admin_url(): void {
		$request = $this->request_with_referer( '' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	/**
	 * Referer on a different origin → admin_url fallback.
	 */
	public function test_cross_origin_referer_returns_admin_url(): void {
		$request = $this->request_with_referer( 'https://evil.example.com/catch' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	/**
	 * Malformed Referer → admin_url fallback.
	 */
	public function test_unparseable_referer_returns_admin_url(): void {
		$request = $this->request_with_referer( 'not a url' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	/**
	 * Same-origin Referer over http:// (mismatched scheme) → fallback.
	 */
	public function test_scheme_mismatch_referer_returns_admin_url(): void {
		$request = $this->request_with_referer( 'http://example.test/my-account/' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	// ------------------------------------------------------------------
	// wp-login.php
	// ------------------------------------------------------------------

	/**
	 * Wp-login.php referer → admin_url (classic flow, pre-0.1.9 behaviour).
	 */
	public function test_wp_login_referer_returns_admin_url(): void {
		$request = $this->request_with_referer( 'https://example.test/wp-login.php' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	/**
	 * Wp-login.php?action=register variant → admin_url. Query strings
	 * are ignored when matching the path.
	 */
	public function test_wp_login_register_variant_returns_admin_url(): void {
		$request = $this->request_with_referer( 'https://example.test/wp-login.php?action=register' );
		$this->assertSame( 'https://example.test/wp-admin/', RestController::decide_redirect( $request ) );
	}

	// ------------------------------------------------------------------
	// WooCommerce
	// ------------------------------------------------------------------

	/**
	 * WC My Account root → my-account permalink.
	 */
	public function test_wc_myaccount_root_returns_myaccount(): void {
		$this->override_wc_pages( 'https://example.test/my-account/', 'https://example.test/checkout/' );

		$request = $this->request_with_referer( 'https://example.test/my-account/' );
		$this->assertSame( 'https://example.test/my-account/', RestController::decide_redirect( $request ) );
	}

	/**
	 * WC My Account sub-page (e.g. Edit Account) → my-account permalink root.
	 */
	public function test_wc_myaccount_subpage_returns_myaccount(): void {
		$this->override_wc_pages( 'https://example.test/my-account/', 'https://example.test/checkout/' );

		$request = $this->request_with_referer( 'https://example.test/my-account/edit-account/' );
		$this->assertSame( 'https://example.test/my-account/', RestController::decide_redirect( $request ) );
	}

	/**
	 * WC Checkout page → checkout permalink (user resumes their purchase).
	 */
	public function test_wc_checkout_returns_checkout(): void {
		$this->override_wc_pages( 'https://example.test/my-account/', 'https://example.test/checkout/' );

		$request = $this->request_with_referer( 'https://example.test/checkout/' );
		$this->assertSame( 'https://example.test/checkout/', RestController::decide_redirect( $request ) );
	}

	/**
	 * A path that merely shares a prefix with /my-account/ (e.g.
	 * /my-accountant/) must NOT match the WC My Account permalink.
	 */
	public function test_shared_prefix_does_not_match_wc_page(): void {
		$this->override_wc_pages( 'https://example.test/my-account/', 'https://example.test/checkout/' );

		$request = $this->request_with_referer( 'https://example.test/my-accountant/' );
		$this->assertSame( 'https://example.test/my-accountant/', RestController::decide_redirect( $request ) );
	}

	/**
	 * With WC pages unconfigured (permalinks return ''), the code falls
	 * through to the "back to referer" branch for any same-origin page.
	 */
	public function test_wc_pages_unconfigured_falls_through_to_referer(): void {
		// The default stub in setUp returns '' for every slug — no override call.
		$request = $this->request_with_referer( 'https://example.test/my-account/' );
		$this->assertSame( 'https://example.test/my-account/', RestController::decide_redirect( $request ) );
	}

	// ------------------------------------------------------------------
	// Shortcode / Gutenberg / custom page
	// ------------------------------------------------------------------

	/**
	 * A same-origin referer that isn't wp-login / WC → user returns to it.
	 */
	public function test_custom_page_referer_returns_to_self(): void {
		$request = $this->request_with_referer( 'https://example.test/custom-sign-in-page/' );
		$this->assertSame( 'https://example.test/custom-sign-in-page/', RestController::decide_redirect( $request ) );
	}

	/**
	 * Same-origin referer with query string → returned verbatim (with the
	 * query string preserved), because the user's state may depend on it.
	 */
	public function test_custom_page_preserves_query_string(): void {
		$request = $this->request_with_referer( 'https://example.test/landing/?from=promo&utm_source=email' );
		$this->assertSame( 'https://example.test/landing/?from=promo&utm_source=email', RestController::decide_redirect( $request ) );
	}
}
