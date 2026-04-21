<?php
/**
 * Integration tests for `AuthSessionProxyController` — the same-origin
 * proxy routes added in 0.1.1 under `qrauth-psl/v1/api/v1/...` and
 * `qrauth-psl/v1/a/<token>`.
 *
 * Runs only when the WordPress test library is available
 * (`WP_TESTS_DIR` set + `WP_UnitTestCase` loaded).
 *
 * The controller has no injected dependencies — it reads credentials
 * from `Options::all()` and calls `wp_remote_request` directly — so the
 * test approach is:
 *   1. Seed the plugin's option row with known credentials.
 *   2. Intercept outbound HTTP via `pre_http_request` to capture the
 *      args the controller sent and return a canned upstream response.
 *   3. Intercept `wp_redirect` (for the /a/<token> route) to capture the
 *      target URL and throw `WpRedirectCapturedException` so the `exit;`
 *      in the handler never runs and the test process stays alive.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Integration\Rest;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use WP_REST_Request;
use WP_UnitTestCase;

require_once __DIR__ . '/WpRedirectCapturedException.php';

/**
 * Integration coverage for the three proxy routes.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Rest\AuthSessionProxyController
 */
final class AuthSessionProxyTest extends WP_UnitTestCase {

	/**
	 * Upstream response the `pre_http_request` filter will return. Each
	 * test can mutate this before dispatching to simulate different
	 * upstream outcomes (status codes, bodies, WP_Error, etc.).
	 *
	 * @var array|\WP_Error
	 */
	private $upstream_response;

	/**
	 * Most-recent args captured by the `pre_http_request` filter.
	 *
	 * @var array{url:string,args:array}|null
	 */
	private ?array $captured = null;

	/**
	 * Cookies recorded via the `qrauth_psl_initiator_cookie` action hook.
	 *
	 * @var array<string,array{value:string,expires:int}>
	 */
	private array $captured_cookies = array();

	/**
	 * Install a fresh REST server, seed credentials, and register the
	 * `pre_http_request` + `wp_redirect` filters that the tests rely on
	 * to observe the controller's behaviour.
	 */
	public function set_up(): void {
		parent::set_up();

		// Fresh REST server per test so route registrations from a prior
		// test's `rest_api_init` don't leak in.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP's own REST server global; the test harness uses it directly.
		$wp_rest_server = new \Spy_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- driving WP's own hook from the test harness.
		do_action( 'rest_api_init', $wp_rest_server );

		// Seed credentials so `load_credentials()` in the controller
		// returns non-null. Individual tests override to simulate the
		// missing-credentials branch.
		update_option(
			Options::OPTION_NAME,
			array(
				'client_id'      => 'test-client-id',
				'client_secret'  => 'test-client-secret',
				'tenant_url'     => 'https://qrauth.io',
				'auto_provision' => false,
				'default_role'   => 'subscriber',
				'allowed_scopes' => array( 'identity', 'email' ),
				'enabled_on'     => array( 'wp-login', 'register' ),
			)
		);

		// Default canned upstream response — 200 OK with a plausible
		// PENDING session body. Tests override `$this->upstream_response`
		// to simulate 201 / 401 / 5xx / WP_Error.
		$this->captured          = null;
		$this->upstream_response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'sessionId' => 'clq9r8t0u0000abc123def456',
					'status'    => 'PENDING',
				)
			),
		);

		$this->captured_cookies = array();

		add_filter( 'pre_http_request', array( $this, 'capture_upstream' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		add_action( 'qrauth_psl_initiator_cookie', array( $this, 'capture_cookie' ), 10, 2 );
	}

	/**
	 * Remove the filters installed in `set_up` and reset the REST server
	 * global so the next test starts with a clean slate.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_upstream' ), 10 );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		remove_action( 'qrauth_psl_initiator_cookie', array( $this, 'capture_cookie' ), 10 );

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP's own REST server global; reset between tests.
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Filter callback — records the outbound args and returns the canned
	 * response. Registered in set_up, removed in tear_down.
	 *
	 * @param false|array|\WP_Error $preempt Preempt value (we always preempt).
	 * @param array                 $args    HTTP request args.
	 * @param string                $url     Absolute upstream URL.
	 * @return array|\WP_Error
	 */
	public function capture_upstream( $preempt, array $args, string $url ) {
		unset( $preempt );
		$this->captured = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->upstream_response;
	}

	/**
	 * Filter callback — captures the redirect target and throws so the
	 * handler's `exit;` never runs.
	 *
	 * @param string $location Location the handler is about to set.
	 * @param int    $status   HTTP status.
	 * @throws WpRedirectCapturedException Always, so the handler's `exit;` never runs.
	 */
	public function capture_redirect( string $location, int $status ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test helper; the thrown values are caught by the test, never rendered to users.
		throw new WpRedirectCapturedException( $location, $status );
	}

	/**
	 * Action callback — records the sessionId + expiry the controller
	 * was about to stamp into `qrauth_psl_initiator`.
	 *
	 * Tests assert against `$this->captured_cookies` rather than trying
	 * to read a real `Set-Cookie` header because the PHPUnit SAPI has
	 * usually already sent headers by the time the controller runs, so
	 * `setcookie` is suppressed in-process.
	 *
	 * @param string $session_id Session ID being stamped.
	 * @param int    $expires    Unix timestamp the cookie expires at.
	 */
	public function capture_cookie( string $session_id, int $expires ): void {
		$this->captured_cookies['qrauth_psl_initiator'] = array(
			'value'   => $session_id,
			'expires' => $expires,
		);
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	/**
	 * All three proxy routes should be registered under the plugin's REST
	 * namespace after `rest_api_init` fires.
	 */
	public function test_all_three_proxy_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'qrauth-psl/v1' );

		$this->assertArrayHasKey( '/qrauth-psl/v1/api/v1/auth-sessions', $routes );
		$this->assertArrayHasKey( '/qrauth-psl/v1/api/v1/auth-sessions/(?P<id>[A-Za-z0-9_-]+)', $routes );
		$this->assertArrayHasKey( '/qrauth-psl/v1/a/(?P<token>[A-Za-z0-9_-]+)', $routes );
	}

	// ------------------------------------------------------------------
	// POST /api/v1/auth-sessions
	// ------------------------------------------------------------------

	/**
	 * POST body is forwarded verbatim to `{tenant_url}/api/v1/auth-sessions`.
	 */
	public function test_create_forwards_upstream_to_tenant_url(): void {
		$this->dispatch_post( '{"scopes":["identity"]}' );

		$this->assertNotNull( $this->captured );
		$this->assertSame( 'https://qrauth.io/api/v1/auth-sessions', $this->captured['url'] );
		$this->assertSame( 'POST', $this->captured['args']['method'] );
		$this->assertSame( '{"scopes":["identity"]}', $this->captured['args']['body'] );
	}

	/**
	 * HTTP Basic auth is injected server-side from the stored credentials.
	 */
	public function test_create_injects_basic_auth_from_stored_credentials(): void {
		$this->dispatch_post( '{}' );

		$headers = $this->captured['args']['headers'];
		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- standard HTTP Basic auth encoding; mirrors the controller's production code.
			'Basic ' . base64_encode( 'test-client-id:test-client-secret' ),
			$headers['Authorization']
		);
	}

	/**
	 * `X-Client-Id` is injected from the stored `client_id`.
	 */
	public function test_create_injects_x_client_id_header(): void {
		$this->dispatch_post( '{}' );

		$headers = $this->captured['args']['headers'];
		$this->assertSame( 'test-client-id', $headers['X-Client-Id'] );
	}

	/**
	 * Browser-sent `Cookie` and `Authorization` headers are not forwarded
	 * to qrauth.io — the proxy always injects its own Basic auth.
	 */
	public function test_create_strips_browser_sent_cookie_and_authorization(): void {
		$request = new WP_REST_Request( 'POST', '/qrauth-psl/v1/api/v1/auth-sessions' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Cookie', 'wordpress_logged_in_abc=attacker-session' );
		$request->set_header( 'Authorization', 'Bearer attacker-token' );
		$request->set_body( '{}' );

		rest_do_request( $request );

		$headers = $this->captured['args']['headers'];

		// The browser-supplied Authorization MUST be replaced with our
		// server-injected Basic auth, not the Bearer the attacker sent.
		$this->assertStringStartsWith( 'Basic ', $headers['Authorization'] );
		$this->assertStringNotContainsString( 'Bearer', $headers['Authorization'] );

		// Cookie must not reach the upstream.
		$this->assertArrayNotHasKey( 'Cookie', $headers );
		$this->assertArrayNotHasKey( 'cookie', $headers );
	}

	/**
	 * Upstream status code and JSON body are returned to the browser verbatim.
	 */
	public function test_create_returns_upstream_status_verbatim(): void {
		$this->upstream_response = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'statusCode' => 401,
					'error'      => 'Unauthorized',
				)
			),
		);

		$response = $this->dispatch_post( '{}' );

		$this->assertSame( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 401, $data['statusCode'] );
		$this->assertSame( 'Unauthorized', $data['error'] );
	}

	/**
	 * Missing credentials short-circuit before any upstream call.
	 */
	public function test_create_without_credentials_returns_500_plugin_not_configured(): void {
		$options                  = get_option( Options::OPTION_NAME );
		$options['client_secret'] = '';
		update_option( Options::OPTION_NAME, $options );

		$response = $this->dispatch_post( '{}' );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'plugin_not_configured', $response->get_data()['error'] );
		$this->assertNull( $this->captured, 'No upstream request should have been made' );
	}

	/**
	 * A WP_Error from `wp_remote_request` maps to 502 `upstream_unavailable`.
	 */
	public function test_create_upstream_wp_error_returns_502_upstream_unavailable(): void {
		$this->upstream_response = new \WP_Error( 'http_request_failed', 'Connection refused' );

		$response = $this->dispatch_post( '{}' );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'upstream_unavailable', $response->get_data()['error'] );
	}

	/**
	 * On a successful upstream response, the proxy stamps a
	 * short-lived cookie with the new sessionId so the landing-page
	 * adapter can later distinguish same-device flow (cookie matches)
	 * from cross-device (no cookie, scrub URL params without auto-auth).
	 */
	public function test_create_sets_initiator_cookie_on_success(): void {
		$this->upstream_response = array(
			'response' => array( 'code' => 201 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'sessionId' => 'clq9r8t0u0000abc123def456',
					'status'    => 'PENDING',
				)
			),
		);

		$this->dispatch_post( '{}' );

		$this->assertArrayHasKey( 'qrauth_psl_initiator', $this->captured_cookies );
		$this->assertSame( 'clq9r8t0u0000abc123def456', $this->captured_cookies['qrauth_psl_initiator']['value'] );
	}

	/**
	 * Non-2xx upstream responses must NOT set the cookie. Marking
	 * "this browser initiated a session" on a failed create would gate
	 * future auto-auth in the wrong direction.
	 */
	public function test_create_does_not_set_cookie_on_upstream_error(): void {
		$this->upstream_response = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'error' => 'Unauthorized' ) ),
		);

		$this->dispatch_post( '{}' );

		$this->assertArrayNotHasKey( 'qrauth_psl_initiator', $this->captured_cookies );
	}

	/**
	 * The decoded upstream JSON body is returned to the browser unchanged.
	 */
	public function test_create_response_body_forwarded_verbatim(): void {
		$payload                 = array(
			'sessionId' => 'clq9r8t0u0000abc123def456',
			'token'     => '01HXABC123DEF456',
			'qrUrl'     => 'https://qrauth.io/a/01HXABC123DEF456',
			'status'    => 'PENDING',
		);
		$this->upstream_response = array(
			'response' => array( 'code' => 201 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		);

		$response = $this->dispatch_post( '{"scopes":["identity"]}' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $payload, $response->get_data() );
	}

	// ------------------------------------------------------------------
	// GET /api/v1/auth-sessions/<id>
	// ------------------------------------------------------------------

	/**
	 * Session ID from the WP route path is forwarded as the upstream path segment.
	 */
	public function test_poll_forwards_session_id_as_path_segment(): void {
		$request = new WP_REST_Request( 'GET', '/qrauth-psl/v1/api/v1/auth-sessions/clq9r8t0u0000abc123def456' );
		rest_do_request( $request );

		$this->assertSame(
			'https://qrauth.io/api/v1/auth-sessions/clq9r8t0u0000abc123def456',
			$this->captured['url']
		);
		$this->assertSame( 'GET', $this->captured['args']['method'] );
	}

	/**
	 * `code_verifier` query param is forwarded to the upstream URL.
	 */
	public function test_poll_forwards_code_verifier_query_string(): void {
		$request = new WP_REST_Request( 'GET', '/qrauth-psl/v1/api/v1/auth-sessions/clq9r8t0u0000abc123def456' );
		$request->set_param( 'code_verifier', 'abcDEF123_-xyzABC456' );
		rest_do_request( $request );

		$this->assertStringContainsString(
			'code_verifier=abcDEF123_-xyzABC456',
			$this->captured['url']
		);
	}

	/**
	 * Unknown query params are dropped, not silently forwarded upstream.
	 */
	public function test_poll_does_not_forward_unknown_query_params(): void {
		$request = new WP_REST_Request( 'GET', '/qrauth-psl/v1/api/v1/auth-sessions/clq9r8t0u0000abc123def456' );
		$request->set_param( 'code_verifier', 'abc' );
		$request->set_param( 'injected_junk', 'should-not-be-forwarded' );
		rest_do_request( $request );

		$this->assertStringNotContainsString( 'injected_junk', $this->captured['url'] );
		$this->assertStringNotContainsString( 'should-not-be-forwarded', $this->captured['url'] );
	}

	// ------------------------------------------------------------------
	// GET /a/<token> — 302 to tenant origin
	// ------------------------------------------------------------------

	/**
	 * /a/<token> issues a 302 to the tenant origin with the token preserved.
	 */
	public function test_redirect_302s_to_tenant_origin_with_token(): void {
		$request = new WP_REST_Request( 'GET', '/qrauth-psl/v1/a/01HXABC123DEF456' );

		try {
			rest_do_request( $request );
			$this->fail( 'wp_redirect was not called' );
		} catch ( WpRedirectCapturedException $e ) {
			$this->assertSame( 'https://qrauth.io/a/01HXABC123DEF456', $e->location );
			$this->assertSame( 302, $e->status );
		}
	}

	/**
	 * The redirect respects the configured `tenant_url` (self-hosted QRAuth).
	 */
	public function test_redirect_uses_configured_tenant_url(): void {
		$options               = get_option( Options::OPTION_NAME );
		$options['tenant_url'] = 'https://self-hosted-qrauth.example.com';
		update_option( Options::OPTION_NAME, $options );

		$request = new WP_REST_Request( 'GET', '/qrauth-psl/v1/a/tok999' );

		try {
			rest_do_request( $request );
			$this->fail( 'wp_redirect was not called' );
		} catch ( WpRedirectCapturedException $e ) {
			$this->assertSame( 'https://self-hosted-qrauth.example.com/a/tok999', $e->location );
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build and dispatch a POST to the proxy's create route with the
	 * given raw JSON body. Content-Type defaulted to `application/json`.
	 *
	 * @param string $body Raw JSON request body.
	 * @return \WP_REST_Response
	 */
	private function dispatch_post( string $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/qrauth-psl/v1/api/v1/auth-sessions' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		return rest_do_request( $request );
	}
}
