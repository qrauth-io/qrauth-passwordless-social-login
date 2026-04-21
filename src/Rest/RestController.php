<?php
/**
 * POST /wp-json/qrauth-psl/v1/verify handler.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Rest;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker;
use QRAuth\PasswordlessSocialLogin\Verification\QRAuthClient;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and serves the plugin's single REST route.
 *
 * The route is intentionally permissionless — it creates sessions, so the
 * caller won't yet be authenticated. Three defences take the place of the
 * usual `current_user_can` check:
 *
 *   - WP's REST cookie nonce (`X-WP-Nonce`).
 *   - A per-hashed-IP rate-limit (10 reqs / 5 min, per Q4 and
 *     `SPECS/ARCHITECTURE.md` §5). The IP is hashed with `wp_salt()` so
 *     a DB dump can't recover the client addresses.
 *   - Cryptographic verification against QRAuth's `/verify-result`
 *     endpoint. Only `valid=true` + `status=APPROVED` lets us set the
 *     auth cookie.
 *
 * The `QRAuthClient` and `UserLinker` are injected so integration tests
 * can swap in fakes without standing up a real HTTP server.
 *
 * @since 0.1.0
 */
final class RestController {

	/**
	 * REST namespace (`/wp-json/qrauth-psl/v1/...`).
	 */
	public const ROUTE_NAMESPACE = 'qrauth-psl/v1';

	/**
	 * Route path under the namespace.
	 */
	public const ROUTE_PATH = '/verify';

	/**
	 * Maximum verify attempts per window, per hashed IP.
	 */
	private const RATE_LIMIT_MAX = 10;

	/**
	 * Rate-limit window, in seconds (5 minutes).
	 */
	private const RATE_LIMIT_WINDOW = 300;

	/**
	 * Injected QRAuth HTTP client — null means "build from Options at request time".
	 *
	 * @var QRAuthClient|null
	 */
	private ?QRAuthClient $client;

	/**
	 * Injected user linker — allows tests to swap in a fake.
	 *
	 * @var UserLinker
	 */
	private UserLinker $linker;

	/**
	 * Construct the controller.
	 *
	 * @param QRAuthClient|null $client Optional injected HTTP client.
	 * @param UserLinker|null   $linker Optional injected user linker.
	 */
	public function __construct( ?QRAuthClient $client = null, ?UserLinker $linker = null ) {
		$this->client = $client;
		$this->linker = $linker ?? new UserLinker();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the `/verify` route with the REST server.
	 *
	 * Callable directly by the `rest_api_init` action — WP passes the
	 * active WP_REST_Server as a positional argument, which PHP silently
	 * ignores here (parameterless signature).
	 */
	public function register_route(): void {
		$this->do_register_route( false );
	}

	/**
	 * Register the route with the override flag set.
	 *
	 * Integration tests call this to replace the route that the
	 * globally-booted plugin instance already registered during
	 * `muplugins_loaded` — otherwise WP's endpoint merging keeps the
	 * default (fake-less) handler in front and the test's handler
	 * never runs.
	 */
	public function register_route_override(): void {
		$this->do_register_route( true );
	}

	/**
	 * Private implementation shared by the public (re)register methods.
	 *
	 * @param bool $override Forwarded to `register_rest_route()`.
	 */
	private function do_register_route( bool $override ): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'sessionId' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( VerifyRequest::class, 'validate_session_id' ),
					),
					'signature' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( VerifyRequest::class, 'validate_signature' ),
					),
				),
			),
			$override
		);
	}

	/**
	 * Handle a verify request.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// 1. Nonce (REST cookie auth). Read from the X-WP-Nonce header —
		// `check_ajax_referer` only looks at $_REQUEST, which WP's REST
		// dispatcher doesn't populate from request headers. Our adapter
		// always sends the nonce via the X-WP-Nonce header.
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return self::error( 'nonce_failed', 403 );
		}

		// 2. Rate limit.
		if ( $this->rate_limit_exceeded() ) {
			return self::error( 'rate_limited', 429 );
		}

		// 3. Upstream verification.
		try {
			$client = $this->client ?? self::default_client();
			$result = $client->verify_result(
				(string) $request->get_param( 'sessionId' ),
				(string) $request->get_param( 'signature' )
			);
		} catch ( VerifyException $e ) {
			return self::error( $e->get_machine_code(), 502 );
		}

		if ( true !== $result->valid ) {
			return self::error( 'signature_invalid', 400 );
		}
		if ( 'APPROVED' !== $result->status ) {
			return self::error( 'session_not_approved', 400 );
		}

		// 4. Link or provision.
		try {
			$user_id = $this->linker->link_or_provision( $result );
		} catch ( VerifyException $e ) {
			$code = $e->get_machine_code();
			$http = 'provision_disabled' === $code ? 403 : 500;
			return self::error( $code, $http );
		}

		// 5. Log the user in. The `redirect` destination is decided
		// server-side based on where the sign-in originated (Referer
		// header, validated same-origin via `wp_validate_redirect`):
		// WC surfaces → WC equivalents; everything else → admin_url().
		// We deliberately ignore any `redirect` field QRAuth may have
		// supplied to prevent open-redirect abuse.
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		wp_set_current_user( $user_id );

		do_action( 'qrauth_psl_user_linked', $user_id, $result );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'redirect' => self::decide_redirect( $request ),
			),
			200
		);
	}

	/**
	 * Decide where to send the browser after a successful sign-in.
	 *
	 * Reads the request's Referer header (the page that rendered the
	 * widget) and routes:
	 *
	 *   - `wp-login.php` (and its `?action=register` variant) → `admin_url()`.
	 *     Classic "sign in to wp-admin" flow; matches pre-0.1.9 behaviour.
	 *   - WooCommerce My Account (`/my-account/` or any sub-page) → the
	 *     My Account permalink. Customer sees their dashboard, not wp-admin.
	 *   - WooCommerce Checkout (`/checkout/`) → the Checkout permalink.
	 *     Returning-customer sign-in on the checkout page completes
	 *     right where the user left off.
	 *   - Any other same-origin referer (shortcode on a custom landing
	 *     page, future Gutenberg block) → back to that page.
	 *   - No referer, cross-origin referer, or unparseable value →
	 *     `admin_url()` as the safe fallback.
	 *
	 * Referer is browser-provided (and thus forgeable), but this only
	 * affects where *the signed-in user* is sent — not authorisation.
	 * `wp_validate_redirect()` blocks cross-origin destinations, so an
	 * attacker forging Referer can't turn this into an open redirect.
	 *
	 * @param WP_REST_Request $request Incoming verify request.
	 * @return string Absolute URL to redirect to.
	 */
	public static function decide_redirect( WP_REST_Request $request ): string {
		$default = admin_url();
		$referer = (string) $request->get_header( 'Referer' );
		if ( '' === $referer ) {
			return $default;
		}

		// Same-origin gate. Returns empty when the URL fails the check,
		// which we treat as "fall back to admin_url()".
		$validated = wp_validate_redirect( $referer, '' );
		if ( '' === $validated ) {
			return $default;
		}

		// WooCommerce-aware routing — only when WC is loaded, and only
		// when the pages are actually configured (a fresh WC install
		// creates them, but customised setups may not).
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$myaccount = (string) wc_get_page_permalink( 'myaccount' );
			if ( '' !== $myaccount && self::referer_matches( $validated, $myaccount ) ) {
				return $myaccount;
			}

			$checkout = (string) wc_get_page_permalink( 'checkout' );
			if ( '' !== $checkout && self::referer_matches( $validated, $checkout ) ) {
				return $checkout;
			}
		}

		// wp-login.php (and its action-variant pages) → admin. Matches
		// the paths site_url('/wp-login.php') can produce so we behave
		// the same whether Referer is `wp-login.php` or
		// `wp-login.php?action=register`.
		if ( self::referer_matches( $validated, site_url( '/wp-login.php' ) ) ) {
			return $default;
		}

		// Shortcode on a custom page, Gutenberg block, etc. — send the
		// user back to where they started. The same-origin check above
		// has already filtered unsafe values.
		return $validated;
	}

	/**
	 * Path-prefix match between two absolute URLs, ignoring query and
	 * fragment.
	 *
	 * Used by `decide_redirect` to test whether a Referer falls under a
	 * canonical page permalink. Matches exact paths (`/my-account/` ===
	 * `/my-account/`) and sub-paths (`/my-account/edit-account/` falls
	 * under `/my-account/`) but not paths that merely share a prefix
	 * (`/my-accountant/` does NOT fall under `/my-account/`). Trailing
	 * slashes on the target are normalised away before comparison.
	 *
	 * @param string $candidate Absolute URL to test.
	 * @param string $target    Absolute URL the candidate should fall under.
	 */
	private static function referer_matches( string $candidate, string $target ): bool {
		$candidate_path = (string) ( wp_parse_url( $candidate, PHP_URL_PATH ) ?? '' );
		$target_path    = rtrim( (string) ( wp_parse_url( $target, PHP_URL_PATH ) ?? '' ), '/' );

		if ( '' === $candidate_path || '' === $target_path ) {
			return false;
		}

		if ( $candidate_path === $target_path ) {
			return true;
		}

		return 0 === strpos( $candidate_path, $target_path . '/' );
	}

	/**
	 * Record an attempt for the caller's IP and report whether the limit is hit.
	 */
	private function rate_limit_exceeded(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$key = 'qrauth_psl_rl_' . hash( 'sha256', $ip . wp_salt() );

		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT_MAX ) {
			return true;
		}
		set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );
		return false;
	}

	/**
	 * Build a default QRAuthClient from the current plugin options.
	 */
	private static function default_client(): QRAuthClient {
		$options = Options::all();
		return new QRAuthClient(
			$options['tenant_url'],
			function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown'
		);
	}

	/**
	 * Build a consistent error response — `{ ok: false, code: '<machine-code>' }`.
	 *
	 * @param string $code Machine code identifying the failure.
	 * @param int    $http HTTP status.
	 * @return WP_REST_Response
	 */
	private static function error( string $code, int $http ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'   => false,
				'code' => $code,
			),
			$http
		);
	}
}
