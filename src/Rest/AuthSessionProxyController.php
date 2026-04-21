<?php
/**
 * Same-origin proxy for QRAuth auth-session endpoints.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Rest;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Routes the `<qrauth-login>` web component at its own WP REST namespace so
 * the browser never talks to qrauth.io directly.
 *
 * Registers three routes under `qrauth-psl/v1`:
 *
 *   POST /api/v1/auth-sessions       → proxies {tenant_url}/api/v1/auth-sessions
 *   GET  /api/v1/auth-sessions/<id>  → proxies {tenant_url}/api/v1/auth-sessions/<id> (query string forwarded)
 *   GET  /a/<token>                  → 302 redirect to {tenant_url}/a/<token>
 *
 * Path suffixes mirror what the `@qrauth/web-components` widget appends to
 * its `base-url` attribute verbatim — the widget does
 * `fetch("${baseUrl}/api/v1/auth-sessions")` and
 * `window.open("${baseUrl}/a/<token>")`, so the route paths must match
 * byte-for-byte once concatenated. (Yes, this means the browser-facing URL
 * contains `/wp-json/qrauth-psl/v1/api/v1/auth-sessions` with a double `v1`.
 * Ugly but the alternative — renaming attributes upstream in the vqr
 * monolith — is out of scope here.)
 *
 * The first two routes make server-to-server calls with
 * `wp_remote_request` and return the upstream status code + JSON body
 * verbatim. `Authorization: Basic {client_id}:{client_secret}` and
 * `X-Client-Id: {client_id}` are injected from the stored credentials;
 * no tenant-identifying header from the browser is trusted.
 *
 * The third route exists for the mobile same-device fallback — the web
 * component does `window.open("{base-url}/a/<token>")` to land the user on
 * the hosted approval page, and the approval page must live on qrauth.io
 * (WebAuthn RP-ID constraint). A bare 302 keeps that flow intact without
 * serialising the HTML page through the proxy.
 *
 * Permission callbacks are `__return_true` — these routes front endpoints
 * that QRAuth already exposes publicly. CSRF is a non-concern: the POST
 * body carries no site-bound state; PKCE on the widget side handles replay.
 *
 * @since 0.2.0
 */
final class AuthSessionProxyController {

	/**
	 * REST namespace shared with the rest of the plugin (`qrauth-psl/v1`).
	 */
	public const ROUTE_NAMESPACE = 'qrauth-psl/v1';

	/**
	 * Path segments under the namespace. These mirror the exact suffixes
	 * the web component appends to its `base-url`, not an arbitrary choice.
	 */
	public const ROUTE_CREATE   = '/api/v1/auth-sessions';
	public const ROUTE_POLL     = '/api/v1/auth-sessions/(?P<id>[A-Za-z0-9_-]+)';
	public const ROUTE_REDIRECT = '/a/(?P<token>[A-Za-z0-9_-]+)';

	/**
	 * Upstream timeout, seconds. Matches QRAuthClient for consistency.
	 */
	private const UPSTREAM_TIMEOUT = 10;

	/**
	 * Cookie set by `handle_create` after a successful upstream response,
	 * carrying the newly-minted sessionId. The landing-page adapter reads
	 * it (from JS) to decide whether URL-param auto-complete should run:
	 * present + matching → yes (the browser initiated this session);
	 * absent or mismatched → no (cross-device QR-scan scenario, the
	 * browser is someone approving a session that belongs to a different
	 * device).
	 */
	public const INITIATOR_COOKIE = 'qrauth_psl_initiator';

	/**
	 * Initiator-cookie lifetime in seconds. Matches the upstream
	 * auth-session TTL (5 minutes) so expired cookies auto-clean without
	 * a separate sweep.
	 */
	private const INITIATOR_COOKIE_TTL = 300;

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the three routes with the REST server.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_CREATE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_POLL,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_poll' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_REDIRECT,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_redirect' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * `POST /auth-sessions` — forward the JSON body upstream and return the
	 * upstream response verbatim.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_create( WP_REST_Request $request ): WP_REST_Response {
		$config = self::load_credentials();
		if ( null === $config ) {
			return self::not_configured();
		}

		$upstream = $this->upstream_request(
			'POST',
			self::join_url( $config['tenant_url'], '/api/v1/auth-sessions' ),
			$config,
			$request->get_body()
		);

		self::maybe_set_initiator_cookie( $upstream );

		return $upstream;
	}

	/**
	 * After a successful session-create response, stamp a short-lived
	 * cookie recording the sessionId on the initiating browser. The
	 * landing-page adapter gates URL-param auto-complete on this cookie
	 * so a cross-device QR scan (user on phone approves a desktop-
	 * initiated session) doesn't inadvertently sign the phone in too.
	 *
	 * Readable from JS (no `HttpOnly`) and scoped to `Path=/` so the
	 * adapter on `/wp-login.php` can read a cookie set from
	 * `/wp-json/…`. Short lifetime (5 minutes) matches the upstream
	 * auth-session TTL — expired cookies auto-clean.
	 *
	 * No-op on non-success status codes; we only mark "this browser
	 * asked qrauth.io to start a session", not "this browser made any
	 * old call to the proxy".
	 *
	 * @param \WP_REST_Response $upstream Proxy response about to be returned.
	 */
	private static function maybe_set_initiator_cookie( \WP_REST_Response $upstream ): void {
		$status = $upstream->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return;
		}

		$data = $upstream->get_data();
		if ( ! is_array( $data ) ) {
			return;
		}

		$session_id = isset( $data['sessionId'] ) && is_string( $data['sessionId'] )
			? $data['sessionId']
			: '';
		if ( '' === $session_id ) {
			return;
		}

		$expires = time() + self::INITIATOR_COOKIE_TTL;

		/**
		 * Fires just before the initiator cookie is stamped.
		 *
		 * Integration tests hook this to observe the sessionId + expiry
		 * without needing to parse a real `Set-Cookie` header (which
		 * isn't reliably available under the PHPUnit SAPI).
		 *
		 * @param string $session_id Session ID being stamped.
		 * @param int    $expires    Unix timestamp the cookie expires at.
		 */
		do_action( 'qrauth_psl_initiator_cookie', $session_id, $expires );

		// Suppress the `setcookie` call when headers have already been
		// sent — under PHPUnit this is typically the case (test runner
		// output has already flushed), and `setcookie` would emit a
		// PHP notice. The action hook above has already captured what
		// tests need; production web requests still get the real cookie.
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::INITIATOR_COOKIE,
			$session_id,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * `GET /auth-sessions/<id>` — forward with query string preserved.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_poll( WP_REST_Request $request ): WP_REST_Response {
		$config = self::load_credentials();
		if ( null === $config ) {
			return self::not_configured();
		}

		$id = (string) $request->get_param( 'id' );

		$url = self::join_url( $config['tenant_url'], '/api/v1/auth-sessions/' ) . rawurlencode( $id );

		// Forward only the widget's known query parameters (currently just
		// `code_verifier`). Anything else is dropped — WP strips the
		// `_wpnonce`/`rest_route` cruft for us, but we also don't want to
		// silently propagate arbitrary query keys upstream.
		$qs       = array();
		$verifier = $request->get_param( 'code_verifier' );
		if ( is_string( $verifier ) && '' !== $verifier ) {
			$qs['code_verifier'] = $verifier;
		}
		if ( array() !== $qs ) {
			$url .= '?' . http_build_query( $qs );
		}

		return $this->upstream_request( 'GET', $url, $config, null );
	}

	/**
	 * `GET /a/<token>` — 302 to the hosted approval page on the tenant origin.
	 *
	 * The token is opaque to us; upstream validates it. Regex at the route
	 * level constrains the character set so a malformed capture never
	 * reaches `wp_redirect`.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return void
	 */
	public function handle_redirect( WP_REST_Request $request ): void {
		$options    = Options::all();
		$tenant_url = $options['tenant_url'];
		$token      = (string) $request->get_param( 'token' );

		$destination = self::join_url( $tenant_url, '/a/' ) . rawurlencode( $token );

		wp_redirect( $destination, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional off-site redirect to the configured QRAuth tenant origin.
		exit;
	}

	/**
	 * Build + dispatch an upstream request and marshal the response into a
	 * WP_REST_Response that mirrors the upstream verbatim.
	 *
	 * @param string                                                         $method HTTP method (`GET` or `POST`).
	 * @param string                                                         $url    Absolute upstream URL.
	 * @param array{tenant_url:string,client_id:string,client_secret:string} $config Credentials.
	 * @param string|null                                                    $body   Raw request body (POST) or null (GET).
	 * @return WP_REST_Response
	 */
	private function upstream_request( string $method, string $url, array $config, ?string $body ): WP_REST_Response {
		$args = array(
			'method'      => $method,
			'timeout'     => self::UPSTREAM_TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- standard HTTP Basic auth encoding.
				'X-Client-Id'   => $config['client_id'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => self::user_agent(),
			),
		);

		if ( null !== $body && '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array( 'error' => 'upstream_unavailable' ),
				502
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$body_text   = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_text, true );
		$payload     = null === $decoded && '' !== $body_text ? array( 'error' => 'upstream_invalid_json' ) : $decoded;
		$upstream_ct = (string) wp_remote_retrieve_header( $response, 'content-type' );

		$rest_response = new WP_REST_Response( $payload, $status > 0 ? $status : 502 );

		// Preserve content-type where safe — ensures downstream consumers
		// see JSON exactly as upstream labelled it. `Set-Cookie` is
		// deliberately not forwarded.
		if ( '' !== $upstream_ct ) {
			$rest_response->header( 'Content-Type', $upstream_ct );
		}

		return $rest_response;
	}

	/**
	 * Pull credentials from the Options store, or null if incomplete.
	 *
	 * @return array{tenant_url:string,client_id:string,client_secret:string}|null
	 */
	private static function load_credentials(): ?array {
		$options = Options::all();

		if ( '' === $options['client_id'] || '' === $options['client_secret'] || '' === $options['tenant_url'] ) {
			return null;
		}

		return array(
			'tenant_url'    => rtrim( $options['tenant_url'], '/' ),
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
		);
	}

	/**
	 * Standard 500 response when the plugin has no credentials to proxy with.
	 */
	private static function not_configured(): WP_REST_Response {
		return new WP_REST_Response(
			array( 'error' => 'plugin_not_configured' ),
			500
		);
	}

	/**
	 * Join a base URL and a leading-slash path without doubling the slash.
	 *
	 * @param string $base Origin (e.g. `https://qrauth.io`), trailing slash tolerated.
	 * @param string $path Leading-slash path (e.g. `/api/v1/auth-sessions`).
	 */
	private static function join_url( string $base, string $path ): string {
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Build the outbound User-Agent. Mirrors the shape used by QRAuthClient
	 * so both plugin request paths are attributable in upstream logs.
	 */
	private static function user_agent(): string {
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown';
		return sprintf(
			'qrauth-psl/%s; WordPress/%s',
			defined( 'QRAUTH_PSL_VERSION' ) ? QRAUTH_PSL_VERSION : '0.0.0',
			$wp_version
		);
	}
}
