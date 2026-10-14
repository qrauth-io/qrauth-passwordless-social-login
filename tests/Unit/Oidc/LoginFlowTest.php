<?php
/**
 * Unit tests for {@see LoginFlow}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- unit suite runs without WordPress.

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Oidc;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Oidc\IdToken;
use QRAuth\PasswordlessSocialLogin\Oidc\LoginFlow;
use QRAuth\PasswordlessSocialLogin\Oidc\OidcException;
use QRAuth\PasswordlessSocialLogin\Oidc\Provider;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker;
use WP_User;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constants, defined only for the unit suite.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'SITECOOKIEPATH' ) || define( 'SITECOOKIEPATH', '/' );
defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', '' );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 31536000 );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

/**
 * State binding, single use, redirect validation and failure reporting.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Oidc\LoginFlow
 */
final class LoginFlowTest extends TestCase {

	private const BINDING = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * Fake transient store.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients = array();

	/**
	 * Fired actions.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $actions = array();

	/**
	 * User id passed to wp_set_auth_cookie, if any.
	 *
	 * @var int|null
	 */
	private ?int $signed_in = null;

	/**
	 * Stub WordPress functions and reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_GET             = array();
		$_COOKIE          = array();
		$this->transients = array();
		$this->actions    = array();
		$this->signed_in  = null;

		Functions\when( 'get_option' )->justReturn(
			array(
				'oidc_client_id'     => 'wp-client',
				'oidc_client_secret' => 'secret',
			)
		);
		Functions\when( 'rest_url' )->justReturn( 'https://site.test/wp-json/qrauth-psl/v1' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://site.test/wp-login.php' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias( array( self::class, 'add_query_arg' ) );
		// Same-site paths and https://site.test only, like core.
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( $location, $fallback = '' ) {
				$location = (string) $location;
				return ( str_starts_with( $location, '/' ) && ! str_starts_with( $location, '//' ) )
					|| str_starts_with( $location, 'https://site.test/' ) ? $location : $fallback;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( ...$args ) {
				$this->actions[] = $args;
			}
		);
		Functions\when( 'wp_set_auth_cookie' )->alias(
			function ( $user_id ) {
				$this->signed_in = (int) $user_id;
			}
		);
		Functions\when( 'wp_set_current_user' )->justReturn( null );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'user_can' )->justReturn( false );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				$user             = new WP_User();
				$user->ID         = (int) $id;
				$user->user_login = 'alice';
				return $user;
			}
		);
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Minimal add_query_arg: ( array $args, string $url ) or ( key, value, url ).
	 *
	 * @param mixed ...$args Arguments.
	 * @return string
	 */
	public static function add_query_arg( ...$args ): string {
		if ( is_array( $args[0] ) ) {
			list( $params, $url ) = $args;
		} else {
			list( $key, $value, $url ) = $args;
			$params                    = array( $key => $value );
		}
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
	}

	/**
	 * The one stored flow (ignores the per-IP start counter).
	 *
	 * @return array<string,mixed>
	 */
	private function stored_flow(): array {
		foreach ( $this->transients as $key => $value ) {
			if ( str_starts_with( $key, 'qrauth_psl_oidc_' ) ) {
				return $value;
			}
		}
		$this->fail( 'no stored flow' );
	}

	/**
	 * A provider stub with discovery.
	 *
	 * @return Provider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function provider() {
		$provider = $this->createMock( Provider::class );
		$provider->method( 'discovery' )->willReturn( array( 'authorization_endpoint' => 'https://id.qrauth.io/authorize' ) );
		$provider->method( 'client_id' )->willReturn( 'wp-client' );
		$provider->method( 'issuer' )->willReturn( 'https://id.qrauth.io' );
		return $provider;
	}

	/**
	 * Run the flow and return where it redirected.
	 *
	 * @param LoginFlow $flow Flow under test.
	 * @return string
	 */
	private function run_flow( LoginFlow $flow ): string {
		try {
			$flow->handle();
		} catch ( Redirected $r ) {
			return $r->getMessage();
		}
		$this->fail( 'flow did not redirect' );
	}

	/**
	 * Build a flow with a throwing redirect.
	 *
	 * @param Provider        $provider Provider.
	 * @param UserLinker|null $linker   Linker.
	 * @return LoginFlow
	 */
	private function flow( Provider $provider, ?UserLinker $linker = null ): LoginFlow {
		return new LoginFlow(
			$linker ?? new UserLinker(),
			$provider,
			static function ( string $url ): never {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test signal, never rendered.
				throw new Redirected( $url );
			}
		);
	}

	/**
	 * Start a flow in this browser and return its state.
	 *
	 * @param Provider $provider Provider.
	 * @param string   $redirect_to Requested destination.
	 * @return string
	 */
	private function start( Provider $provider, string $redirect_to = '' ): string {
		$_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] = self::BINDING;
		$_GET                                      = '' !== $redirect_to ? array( 'redirect_to' => $redirect_to ) : array();
		$location                                  = $this->run_flow( $this->flow( $provider ) );
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $query );
		return (string) $query['state'];
	}

	/**
	 * Start sends pkce s256 state and nonce and stores only a binding hash.
	 */
	public function test_start_sends_pkce_s256_state_and_nonce_and_stores_only_a_binding_hash(): void {
		$_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] = self::BINDING;

		$location = $this->run_flow( $this->flow( $this->provider() ) );

		$this->assertStringStartsWith( 'https://id.qrauth.io/authorize?', $location );
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'openid profile email', $query['scope'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $query['state'] );

		$stored = $this->stored_flow();
		$this->assertSame( hash( 'sha256', self::BINDING ), $stored['binding_hash'] );
		$this->assertStringNotContainsString( self::BINDING, (string) json_encode( $this->transients ) );
		$this->assertSame( 0, $stored['link_user_id'] );
		$this->assertSame( $query['code_challenge'], IdToken::b64url_encode( hash( 'sha256', $stored['verifier'], true ) ) );
		$this->assertSame( $query['nonce'], $stored['nonce'] );
	}

	/**
	 * Start drops an off site redirect to.
	 */
	public function test_start_drops_an_off_site_redirect_to(): void {
		$this->start( $this->provider(), 'https://evil.example/' );

		$this->assertSame( '', $this->stored_flow()['redirect_to'] );
	}

	/**
	 * Callback from another browser is refused and burns the state.
	 */
	public function test_callback_from_another_browser_is_refused_and_burns_the_state(): void {
		$state                                     = $this->start( $this->provider() );
		$_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] = str_repeat( 'x', 43 );
		$_GET                                      = array(
			'code'  => 'c',
			'state' => $state,
		);

		$location = $this->run_flow( $this->flow( $this->provider() ) );

		$this->assertStringContainsString( 'qrauth_psl_error=state_invalid', $location );
		$this->assertSame( array(), array_filter( $this->transients, static fn( $k ) => str_starts_with( $k, 'qrauth_psl_oidc_' ), ARRAY_FILTER_USE_KEY ) );
		$this->assertNull( $this->signed_in );
	}

	/**
	 * Callback without the binding cookie is refused.
	 */
	public function test_callback_without_the_binding_cookie_is_refused(): void {
		$state = $this->start( $this->provider() );
		unset( $_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] );
		$_GET = array(
			'code'  => 'c',
			'state' => $state,
		);

		$this->assertStringContainsString( 'state_invalid', $this->run_flow( $this->flow( $this->provider() ) ) );
	}

	/**
	 * A bad token reports a failed login and burns the state.
	 */
	public function test_a_bad_token_reports_a_failed_login_and_burns_the_state(): void {
		$state    = $this->start( $this->provider(), '/my-account/' );
		$provider = $this->provider();
		$provider->method( 'exchange_code' )->willReturn(
			array(
				'id_token'     => 'x.y.z',
				'access_token' => 'at',
			)
		);
		$provider->method( 'jwks_keys' )->willThrowException( new OidcException( 'id_token_key' ) );
		$_GET = array(
			'code'  => 'c',
			'state' => $state,
		);

		// A failing token check reports a failed login and signs nobody in.
		$location = $this->run_flow( $this->flow( $provider ) );
		$this->assertStringContainsString( 'qrauth_psl_error=id_token_key', $location );
		$this->assertContains( 'wp_login_failed', array_column( $this->actions, 0 ) );
		$this->assertNull( $this->signed_in );

		// The state was used up: the same callback again is refused.
		$this->assertStringContainsString( 'state_invalid', $this->run_flow( $this->flow( $provider ) ) );
	}

	/**
	 * Provider error maps cancel.
	 */
	public function test_provider_error_maps_cancel(): void {
		$state = $this->start( $this->provider() );
		$_GET  = array(
			'error' => 'access_denied',
			'state' => $state,
		);

		$this->assertStringContainsString( 'qrauth_psl_error=cancelled', $this->run_flow( $this->flow( $this->provider() ) ) );
	}

	/**
	 * Malformed state never reaches the transient store.
	 */
	public function test_malformed_state_never_reaches_the_transient_store(): void {
		$_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] = self::BINDING;
		$_GET                                      = array(
			'code'  => 'c',
			'state' => '../../x',
		);

		$this->assertStringContainsString( 'state_invalid', $this->run_flow( $this->flow( $this->provider() ) ) );
	}

	/**
	 * Run a callback that carries a valid, signed ID token for a linked user.
	 *
	 * @return string Where the flow redirected.
	 */
	private function sign_in_with_valid_token(): string {
		$state = $this->start( $this->provider(), '/my-account/' );
		$nonce = $this->stored_flow()['nonce'];

		$key     = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details = openssl_pkey_get_details( $key );
		$jwk     = array(
			'kty' => 'RSA',
			'kid' => 'k1',
			'n'   => IdToken::b64url_encode( $details['rsa']['n'] ),
			'e'   => IdToken::b64url_encode( $details['rsa']['e'] ),
		);
		$head    = IdToken::b64url_encode(
			(string) json_encode(
				array(
					'alg' => 'RS256',
					'kid' => 'k1',
				)
			)
		);
		$body    = IdToken::b64url_encode(
			(string) json_encode(
				array(
					'iss'   => 'https://id.qrauth.io',
					'aud'   => 'wp-client',
					'sub'   => 'pairwise-sub-1',
					'exp'   => time() + 300,
					'iat'   => time(),
					'nonce' => $nonce,
				)
			)
		);
		openssl_sign( $head . '.' . $body, $sig, $key, OPENSSL_ALGO_SHA256 );

		$provider = $this->provider();
		$provider->method( 'exchange_code' )->willReturn(
			array(
				'id_token'     => $head . '.' . $body . '.' . IdToken::b64url_encode( $sig ),
				'access_token' => 'at',
			)
		);
		$provider->method( 'jwks_keys' )->with( 'k1' )->willReturn( array( $jwk ) );
		$provider->method( 'userinfo' )->with( 'at' )->willReturn(
			array(
				'sub'            => 'pairwise-sub-1',
				'email'          => 'alice@example.com',
				'email_verified' => true,
			)
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'get_users' )->justReturn( array( 42 ) );
		Functions\when( 'update_user_meta' )->justReturn( true );
		$_GET = array(
			'code'  => 'c',
			'state' => $state,
		);

		return $this->run_flow( $this->flow( $provider ) );
	}

	/**
	 * A valid callback signs in the linked user and returns to the stored page.
	 */
	public function test_success_signs_in_the_linked_user_and_returns_to_the_stored_page(): void {
		$location = $this->sign_in_with_valid_token();

		$this->assertSame( '/my-account/', $location );
		$this->assertSame( 42, $this->signed_in );
		$this->assertContains( 'wp_login', array_column( $this->actions, 0 ) );
	}

	/**
	 * Starts beyond the per-IP limit are refused before anything is stored.
	 */
	public function test_start_is_rate_limited_per_ip(): void {
		$this->transients[ 'qrauth_psl_rl_oidc_' . md5( '203.0.113.7' ) ] = 20;

		$this->assertStringContainsString( 'qrauth_psl_error=rate_limited', $this->run_flow( $this->flow( $this->provider() ) ) );
	}

	/**
	 * A link start needs a signed-in user and a valid nonce.
	 */
	public function test_link_intent_requires_a_signed_in_user_and_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_GET = array(
			'intent'   => 'link',
			'_wpnonce' => 'forged',
		);

		$this->assertStringContainsString( 'qrauth_psl_error=link_session', $this->run_flow( $this->flow( $this->provider() ) ) );
	}

	/**
	 * A link start stores the signed-in user so the callback links that user only.
	 */
	public function test_link_intent_records_the_signed_in_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$_COOKIE[ LoginFlow::HOST_BINDING_COOKIE ] = self::BINDING;
		$_GET                                      = array(
			'intent'   => 'link',
			'_wpnonce' => 'ok',
		);

		$this->run_flow( $this->flow( $this->provider() ) );

		$this->assertSame( 5, $this->stored_flow()['link_user_id'] );
		$this->assertSame( 'https://site.test/wp-admin/profile.php', $this->stored_flow()['redirect_to'] );
	}

	/**
	 * An account-state plugin refusing the user stops the sign-in.
	 */
	public function test_account_state_filter_can_refuse_the_sign_in(): void {
		Monkey\Filters\expectApplied( 'qrauth_psl_oidc_authenticate_user' )->andReturn( new \WP_Error( 'pending_approval', 'x' ) );
		$this->assertStringContainsString( 'account_blocked', $this->sign_in_with_valid_token() );
		$this->assertNull( $this->signed_in );
	}

	/**
	 * Password-form checks (reCAPTCHA, 2FA) on core's wp_authenticate_user never run.
	 */
	public function test_password_form_checks_do_not_block_a_qrauth_sign_in(): void {
		Monkey\Filters\expectApplied( 'wp_authenticate_user' )->never();

		$this->assertSame( '/my-account/', $this->sign_in_with_valid_token() );
		$this->assertSame( 42, $this->signed_in );
	}

	/**
	 * An inactive account (user_status set) is refused.
	 */
	public function test_an_inactive_account_is_refused(): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				$user              = new WP_User();
				$user->ID          = (int) $id;
				$user->user_login  = 'alice';
				$user->user_status = 1;
				return $user;
			}
		);

		$this->assertStringContainsString( 'account_blocked', $this->sign_in_with_valid_token() );
		$this->assertNull( $this->signed_in );
	}
}
