<?php
/**
 * Integration tests for the `POST /qrauth-psl/v1/verify` route.
 *
 * Runs only when the WordPress test library is available
 * (WP_TESTS_DIR set + `WP_UnitTestCase` loaded).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Integration\Rest;

use QRAuth\PasswordlessSocialLogin\Rest\RestController;
use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserMetaKeys;
use QRAuth\PasswordlessSocialLogin\Verification\QRAuthClient;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyResult;
use WP_REST_Request;
use WP_UnitTestCase;

require_once __DIR__ . '/FakeQRAuthClient.php';

/**
 * Exercises the full verify-route flow end-to-end against a real WP test
 * environment, with the upstream HTTP client faked out via
 * {@see FakeQRAuthClient}. Every row of the error-mapping table in
 * `SPECS/prompts/P3-rest-verify.md` gets a case here.
 */
final class VerifyRouteTest extends WP_UnitTestCase {

	/**
	 * Injected fake client.
	 *
	 * @var FakeQRAuthClient
	 */
	private FakeQRAuthClient $fake_client;

	/**
	 * Controller under test.
	 *
	 * @var RestController
	 */
	private RestController $controller;

	/**
	 * A valid UUID + signature that pass the format validators.
	 */
	private const VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * Valid base64url signature (exactly 24 chars — minimum the validator accepts).
	 */
	private const VALID_SIG = 'MEUCIQDabc123_-def456GHIjkl789';

	/**
	 * Install the controller with a fake QRAuthClient and a clean rate-limit slot.
	 *
	 * WP emits a `_doing_it_wrong` notice (since 5.1) if `register_rest_route`
	 * is called outside the `rest_api_init` action. We wire the controller
	 * through `boot()` so its callback hooks `rest_api_init`, then initialise
	 * Spy_REST_Server and fire the action — the same pattern WP core tests
	 * use for REST controllers.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->fake_client = new FakeQRAuthClient();
		$this->controller  = new RestController( $this->fake_client );
		$this->controller->boot();

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP's own REST server global; the test harness uses it directly.
		$wp_rest_server = new \Spy_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- driving WP's own hook from the test harness.
		do_action( 'rest_api_init', $wp_rest_server );

		update_option(
			Options::OPTION_NAME,
			array(
				'client_id'      => 'cid',
				'base_url'       => 'https://qrauth.io',
				'auto_provision' => true,
				'default_role'   => 'subscriber',
				'allowed_scopes' => array( 'identity', 'email' ),
				'enabled_on'     => array( 'wp-login', 'register' ),
			)
		);

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->reset_rate_limit();
	}

	/**
	 * Clean up $_SERVER globals and the REST server between tests.
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->reset_rate_limit();

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP's own REST server global; reset between tests.
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Missing X-WP-Nonce → 403 nonce_failed.
	 */
	public function test_missing_nonce_returns_nonce_failed(): void {
		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'nonce_failed', $response->get_data()['code'] );
	}

	/**
	 * Malformed sessionId → 400 from WP's validator before our handler runs.
	 */
	public function test_invalid_body_returns_400(): void {
		$this->authenticate();

		$response = $this->dispatch_verify( 'not-a-uuid', self::VALID_SIG );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * QRAuth returns `valid=false` → 400 signature_invalid.
	 */
	public function test_signature_invalid(): void {
		$this->authenticate();
		$this->fake_client->return_value = new VerifyResult( false, '', '', '', null, null, array(), '' );

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'signature_invalid', $response->get_data()['code'] );
	}

	/**
	 * QRAuth approves but session is PENDING → 400 session_not_approved.
	 */
	public function test_session_not_approved(): void {
		$this->authenticate();
		$this->fake_client->return_value = $this->make_verify_result( 'PENDING' );

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'session_not_approved', $response->get_data()['code'] );
	}

	/**
	 * Approved + new email + auto-provision OFF → 403 provision_disabled.
	 */
	public function test_provision_disabled_returns_403(): void {
		$this->authenticate();

		$options                   = get_option( Options::OPTION_NAME );
		$options['auto_provision'] = false;
		update_option( Options::OPTION_NAME, $options );

		$this->fake_client->return_value = $this->make_verify_result();

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'provision_disabled', $response->get_data()['code'] );
	}

	/**
	 * Approved + new email + auto-provision ON → 200, user created with default role, meta stamped.
	 */
	public function test_provision_creates_user(): void {
		$this->authenticate();
		$this->fake_client->return_value = $this->make_verify_result();

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( admin_url(), $data['redirect'] );

		$user = get_user_by( 'email', 'alice@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertSame( 'qa-user-1', get_user_meta( $user->ID, UserMetaKeys::QRAUTH_USER_ID, true ) );
		$this->assertNotEmpty( get_user_meta( $user->ID, UserMetaKeys::LINKED_AT, true ) );
	}

	/**
	 * Approved + existing email → 200, meta stamped on existing user, role unchanged.
	 */
	public function test_existing_email_link_preserves_role(): void {
		$existing_id = self::factory()->user->create(
			array(
				'user_email' => 'alice@example.com',
				'role'       => 'editor',
			)
		);

		$this->authenticate();
		$this->fake_client->return_value = $this->make_verify_result();

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'ID', $existing_id );
		$this->assertContains( 'editor', $user->roles ); // role preserved.
		$this->assertSame( 'qa-user-1', get_user_meta( $existing_id, UserMetaKeys::QRAUTH_USER_ID, true ) );
	}

	/**
	 * Approved + meta already stamped → 200, no new user created, role unchanged.
	 */
	public function test_meta_match_short_circuits(): void {
		$existing_id = self::factory()->user->create(
			array(
				'user_email' => 'alice@example.com',
				'role'       => 'contributor',
			)
		);
		update_user_meta( $existing_id, UserMetaKeys::QRAUTH_USER_ID, 'qa-user-1' );

		$count_before = count_users();
		$total_before = (int) $count_before['total_users'];

		$this->authenticate();
		$this->fake_client->return_value = $this->make_verify_result();

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 200, $response->get_status() );

		$count_after = count_users();
		$this->assertSame( $total_before, (int) $count_after['total_users'] );

		$user = get_user_by( 'ID', $existing_id );
		$this->assertContains( 'contributor', $user->roles );
	}

	/**
	 * 11th request within the window → 429 rate_limited.
	 */
	public function test_rate_limit_kicks_in_on_11th_request(): void {
		$this->authenticate();
		$this->fake_client->return_value = $this->make_verify_result();

		for ( $i = 0; $i < 10; $i++ ) {
			$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );
			$this->assertNotSame(
				429,
				$response->get_status(),
				sprintf( 'Request %d should not be rate-limited', $i + 1 )
			);
		}

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['code'] );
	}

	/**
	 * Upstream raises VerifyException → 502 verify_failed.
	 */
	public function test_upstream_failure_returns_502(): void {
		$this->authenticate();
		$this->fake_client->throw_value = new VerifyException( 'verify_failed' );

		$response = $this->dispatch_verify( self::VALID_UUID, self::VALID_SIG );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'verify_failed', $response->get_data()['code'] );
	}

	/**
	 * Set a valid X-WP-Nonce for the anonymous user.
	 */
	private function authenticate(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Dispatch a POST to the verify route with the given sessionId + signature.
	 *
	 * @param string $session_id Session UUID.
	 * @param string $signature  Signature string.
	 * @return \WP_REST_Response
	 */
	private function dispatch_verify( string $session_id, string $signature ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/qrauth-psl/v1/verify' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_param( 'sessionId', $session_id );
		$request->set_param( 'signature', $signature );

		$response = rest_do_request( $request );

		$status = $response->get_status();
		$data   = $response->get_data();

		// WP wraps error responses in `{code, message, data}`. Normalise so tests
		// can read `$response->get_data()['code']` uniformly.
		if ( $status >= 400 && is_array( $data ) && isset( $data['data']['code'] ) && ! isset( $data['code'] ) ) {
			// Already shaped like a WP_Error — pass through.
			$data['code'] = $data['data']['code'];
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Build a minimal approved VerifyResult for the fake client to return.
	 *
	 * @param string $status Session status, defaults to `APPROVED`.
	 * @return VerifyResult
	 */
	private function make_verify_result( string $status = 'APPROVED' ): VerifyResult {
		return new VerifyResult(
			true,
			$status,
			'Test App',
			'qa-user-1',
			'alice@example.com',
			'Alice',
			array( 'identity', 'email' ),
			'2026-04-21T10:00:00Z'
		);
	}

	/**
	 * Clear the rate-limit transient for the test IP.
	 */
	private function reset_rate_limit(): void {
		$key = 'qrauth_psl_rl_' . hash( 'sha256', '127.0.0.1' . wp_salt() );
		delete_transient( $key );
	}
}
