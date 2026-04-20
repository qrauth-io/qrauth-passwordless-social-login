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
	 */
	public function register_route(): void {
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
			)
		);
	}

	/**
	 * Handle a verify request.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// 1. Nonce (REST cookie auth).
		if ( ! check_ajax_referer( 'wp_rest', '_wpnonce', false ) ) {
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

		// 5. Log the user in. The `redirect` is always `admin_url()` —
		// we deliberately ignore any field QRAuth may have supplied to
		// prevent open-redirect abuse.
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		wp_set_current_user( $user_id );

		do_action( 'qrauth_psl_user_linked', $user_id, $result );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'redirect' => admin_url(),
			),
			200
		);
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
			$options['base_url'],
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
