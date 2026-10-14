<?php
/**
 * "Sign in with QRAuth" over OpenID Connect.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Oidc;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use WP_Error;
use WP_User;

/**
 * Authorization code flow with PKCE (S256), state and nonce, served from
 * `wp-login.php?action=qrauth_psl_oidc`. That one URL starts the flow and is
 * the registered redirect URI, so it works with or without pretty permalinks.
 *
 * Every step is a full-page navigation in one browser, so the sign-in lands
 * in the tab that started it on phones as well as desktops.
 *
 * Security properties:
 *  - `state` is single-use and bound to this browser: the start sets an
 *    HttpOnly binding cookie and stores only its hash with the state, so a
 *    callback URL replayed in another browser is refused (login CSRF).
 *  - The ID token is verified (RS256 signature, iss, aud, exp, nonce) and
 *    userinfo must name the same subject.
 *  - The post-login destination is chosen at the start, validated with
 *    `wp_validate_redirect`, and validated again before use.
 */
final class LoginFlow {

	/**
	 * The wp-login.php action this flow answers.
	 */
	public const ACTION = 'qrauth_psl_oidc';

	/**
	 * Query arg carrying an error code back to wp-login.php.
	 */
	public const ERROR_ARG = 'qrauth_psl_error';

	/**
	 * Browser-binding cookie name on plain http (local development).
	 */
	public const BINDING_COOKIE = 'qrauth_psl_oidc_binding';

	/**
	 * Browser-binding cookie name on https. The `__Host-` prefix makes the
	 * browser refuse it from a sibling subdomain or a plain-http response,
	 * so it cannot be planted (cookie tossing) to fix someone's login.
	 */
	public const HOST_BINDING_COOKIE = '__Host-qrauth_psl_oidc';

	/**
	 * Nonce action for linking a signed-in user from the profile page.
	 */
	public const LINK_NONCE_ACTION = 'qrauth_psl_oidc_link';

	/**
	 * Query arg set on profile.php after a successful link.
	 */
	public const LINKED_ARG = 'qrauth_psl_linked';

	/**
	 * Flow starts allowed per IP per FLOW_TTL (each start stores a transient).
	 */
	private const START_LIMIT = 20;

	/**
	 * Lifetime of a started flow (and its binding cookie) in seconds.
	 */
	private const FLOW_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Scopes requested. `email` is needed to match existing accounts.
	 */
	private const SCOPES = 'openid profile email';

	/**
	 * Constructor.
	 *
	 * @param UserLinker                    $linker   Account linker.
	 * @param Provider|null                 $provider Provider override for tests.
	 * @param (callable(string):never)|null $redirect Redirect-and-exit override for tests.
	 */
	public function __construct(
		private readonly UserLinker $linker = new UserLinker(),
		private readonly ?Provider $provider = null,
		private $redirect = null,
	) {}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'login_form_' . self::ACTION, array( $this, 'handle' ) );
		add_filter( 'wp_login_errors', array( $this, 'show_error' ) );
		add_action( 'admin_notices', array( $this, 'show_linked_notice' ) );
	}

	/**
	 * True when OIDC credentials are configured.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Options::uses_oidc();
	}

	/**
	 * The redirect URI to register on the QRAuth OIDC client.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return add_query_arg( 'action', self::ACTION, wp_login_url() );
	}

	/**
	 * URL that starts the flow and returns to `$redirect_to` afterwards.
	 *
	 * @param string $redirect_to Same-site destination after sign-in.
	 * @return string
	 */
	public static function start_url( string $redirect_to = '' ): string {
		$url = self::redirect_uri();
		return '' !== $redirect_to ? add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url ) : $url;
	}

	/**
	 * URL a signed-in user follows from their profile to link QRAuth.
	 *
	 * @return string
	 */
	public static function link_url(): string {
		return add_query_arg(
			array(
				'intent'   => 'link',
				'_wpnonce' => wp_create_nonce( self::LINK_NONCE_ACTION ),
			),
			self::redirect_uri()
		);
	}

	/**
	 * Entry point for wp-login.php?action=qrauth_psl_oidc.
	 */
	public function handle(): void {
		if ( ! self::is_enabled() ) {
			$this->fail( 'not_configured' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback: CSRF is covered by the single-use, browser-bound state.
		$has_code  = isset( $_GET['code'] );
		$has_error = isset( $_GET['error'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $has_code || $has_error ) {
			$this->callback();
		}
		$this->start();
	}

	/**
	 * Start: store state, nonce and PKCE verifier, then go to /authorize.
	 */
	private function start(): void {
		try {
			$provider  = $this->provider();
			$discovery = $provider->discovery();
		} catch ( OidcException $e ) {
			$this->fail( $e->get_machine_code(), $e->getMessage() );
		}

		if ( $this->start_limit_exceeded() ) {
			$this->fail( 'rate_limited' );
		}

		// Linking from the profile page: only for the signed-in user, with a nonce.
		$link_user_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified right here.
		if ( isset( $_GET['intent'] ) && 'link' === $_GET['intent'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on this line.
			$link_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
			if ( ! is_user_logged_in() || ! wp_verify_nonce( $link_nonce, self::LINK_NONCE_ACTION ) ) {
				$this->fail( 'link_session' );
			}
			$link_user_id = get_current_user_id();
		}

		$state    = IdToken::b64url_encode( random_bytes( 32 ) );
		$nonce    = IdToken::b64url_encode( random_bytes( 32 ) );
		$verifier = IdToken::b64url_encode( random_bytes( 32 ) );
		$binding  = $this->binding_token();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only destination, validated below.
		$requested = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';
		if ( $link_user_id > 0 ) {
			$requested = admin_url( 'profile.php' );
		}

		set_transient(
			self::state_key( $state ),
			array(
				'nonce'        => $nonce,
				'verifier'     => $verifier,
				'binding_hash' => hash( 'sha256', $binding ),
				'redirect_to'  => wp_validate_redirect( $requested, '' ),
				'link_user_id' => $link_user_id,
			),
			self::FLOW_TTL
		);

		$authorize = add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $provider->client_id() ),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'scope'                 => rawurlencode( self::SCOPES ),
				'state'                 => $state,
				'nonce'                 => $nonce,
				'code_challenge'        => IdToken::b64url_encode( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			(string) $discovery['authorization_endpoint']
		);

		$this->go( $authorize, true );
	}

	/**
	 * Callback: verify state and binding, exchange the code, sign the user in.
	 */
	private function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback: CSRF is covered by the single-use, browser-bound state.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$flow = $this->take_flow( $state );
		if ( null === $flow ) {
			$this->fail( 'state_invalid' );
		}
		if ( '' !== $error ) {
			$this->fail( 'access_denied' === $error ? 'cancelled' : 'provider_error', 'provider error: ' . $error );
		}

		try {
			$identity = $this->verify( $code, $flow );
		} catch ( OidcException $e ) {
			$this->report_failed_login( $e->get_machine_code() );
			$this->fail( $e->get_machine_code(), $e->getMessage() );
		}

		$link_user_id = (int) ( $flow['link_user_id'] ?? 0 );
		if ( $link_user_id > 0 ) {
			$this->complete_link( $link_user_id, $identity );
		}

		try {
			$user_id = $this->linker->link_or_provision_identity( $identity );
		} catch ( VerifyException $e ) {
			$this->fail( $e->get_machine_code(), $e->getMessage() );
		}

		$this->sign_in( $user_id, $identity );
		$this->clear_binding();
		$destination = wp_validate_redirect( (string) $flow['redirect_to'], admin_url() );
		$this->go( '' !== $destination ? $destination : admin_url(), false );
	}

	/**
	 * Link the verified identity to the user who started the link, if that
	 * user is still the one signed in on this browser. Never returns.
	 *
	 * @param int      $user_id  User who started the link.
	 * @param Identity $identity Verified identity.
	 * @return never
	 */
	private function complete_link( int $user_id, Identity $identity ): never {
		if ( get_current_user_id() !== $user_id ) {
			$this->fail( 'link_session' );
		}
		try {
			$this->linker->link_subject_to_user( $user_id, $identity );
		} catch ( VerifyException $e ) {
			$this->fail( $e->get_machine_code(), $e->getMessage() );
		}
		$this->clear_binding();
		$this->go( add_query_arg( self::LINKED_ARG, '1', admin_url( 'profile.php' ) ), false );
	}

	/**
	 * Exchange the code and verify the result.
	 *
	 * @param string              $code Authorization code.
	 * @param array<string,mixed> $flow Stored flow data.
	 * @return Identity
	 * @throws OidcException On any failure.
	 */
	private function verify( string $code, array $flow ): Identity {
		if ( '' === $code ) {
			throw new OidcException( 'token_exchange', 'callback without code' );
		}
		$provider = $this->provider();
		$tokens   = $provider->exchange_code( $code, (string) $flow['verifier'], self::redirect_uri() );

		$kid    = self::token_kid( $tokens['id_token'] );
		$claims = IdToken::verify(
			$tokens['id_token'],
			$provider->jwks_keys( $kid ),
			$provider->issuer(),
			$provider->client_id(),
			(string) $flow['nonce']
		);

		return Identity::from_claims( $claims, $provider->userinfo( $tokens['access_token'] ) );
	}

	/**
	 * Fetch and delete the stored flow for this state, if it belongs to this browser.
	 *
	 * @param string $state State from the callback.
	 * @return array<string,mixed>|null
	 */
	private function take_flow( string $state ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $state ) ) {
			return null;
		}
		$key  = self::state_key( $state );
		$flow = get_transient( $key );
		if ( ! is_array( $flow ) ) {
			return null;
		}
		// Single use, whatever happens next.
		delete_transient( $key );

		$binding = self::read_binding_cookie();
		if ( '' === $binding || ! hash_equals( (string) $flow['binding_hash'], hash( 'sha256', $binding ) ) ) {
			return null;
		}
		return $flow;
	}

	/**
	 * This browser's binding token: reused across concurrent flows, so two
	 * tabs signing in at once do not invalidate each other.
	 *
	 * @return string
	 */
	private function binding_token(): string {
		$existing = self::read_binding_cookie();
		$token    = 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $existing ) ? $existing : IdToken::b64url_encode( random_bytes( 32 ) );
		self::write_binding_cookie( $token, time() + self::FLOW_TTL );
		return $token;
	}

	/**
	 * Drop the binding cookie once a flow has completed.
	 */
	private function clear_binding(): void {
		self::write_binding_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * The binding cookie's name: `__Host-` prefixed on https.
	 *
	 * @return string
	 */
	public static function binding_cookie_name(): string {
		return is_ssl() ? self::HOST_BINDING_COOKIE : self::BINDING_COOKIE;
	}

	/**
	 * Read this browser's binding cookie.
	 *
	 * @return string
	 */
	private static function read_binding_cookie(): string {
		$name = self::binding_cookie_name();
		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Set or expire the binding cookie.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 */
	private static function write_binding_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		$is_host_cookie = is_ssl();
		setcookie(
			self::binding_cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				// `__Host-` requires path "/" and no domain.
				'path'     => $is_host_cookie ? '/' : SITECOOKIEPATH,
				'domain'   => $is_host_cookie ? '' : COOKIE_DOMAIN,
				'secure'   => $is_host_cookie,
				'httponly' => true,
				// Lax: the callback is a top-level GET navigation from the provider.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Count a flow start for this IP; true once the limit is passed.
	 *
	 * @return bool
	 */
	private function start_limit_exceeded(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'qrauth_psl_rl_oidc_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::START_LIMIT ) {
			return true;
		}
		set_transient( $key, $count + 1, self::FLOW_TTL );
		return false;
	}

	/**
	 * Set the auth cookie and fire core's login action.
	 *
	 * @param int      $user_id  WordPress user id.
	 * @param Identity $identity Verified identity.
	 */
	private function sign_in( int $user_id, Identity $identity ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			$this->fail( 'internal_error', 'linked user missing' );
		}

		// Let account-state plugins (disabled, pending approval, lockouts)
		// refuse the sign-in as they would a password login. The password is
		// empty: this sign-in has none.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, so account-state plugins apply.
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( is_wp_error( $checked ) || ! $checked instanceof WP_User ) {
			$this->fail( 'account_blocked', is_wp_error( $checked ) ? $checked->get_error_code() : 'filter returned no user' );
		}
		if ( is_multisite() && ( is_user_spammy( $user ) || ! is_user_member_of_blog( $user_id ) ) ) {
			$this->fail( 'account_blocked', 'multisite: spam or not a member' );
		}

		// Not "remember me": a browser session, as when the box is unticked.
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		wp_set_current_user( $user_id );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's login action, so security plugins and audit logs see this sign-in.
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'qrauth_psl_oidc_signed_in', $user_id, $identity );
	}

	/**
	 * Count a failed verification with security plugins, like a bad password.
	 *
	 * @param string $code Machine code.
	 */
	private function report_failed_login( string $code ): void {
		if ( ! str_starts_with( $code, 'id_token' ) && 'userinfo_subject' !== $code ) {
			return;
		}
		$error = new WP_Error( 'qrauth_oidc_' . $code, __( 'QRAuth sign-in could not be verified.', 'qrauth-passwordless-social-login' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's failed-login action, so throttling plugins count this attempt.
		do_action( 'wp_login_failed', '', $error );
	}

	/**
	 * Back to wp-login.php with an error code. Never returns.
	 *
	 * @param string $code   Machine code.
	 * @param string $detail Internal detail for the debug log.
	 * @return never
	 */
	private function fail( string $code, string $detail = '' ): never {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only, machine codes and no personal data.
			error_log( sprintf( '[qrauth-psl] oidc %s: %s', $code, $detail ) );
		}
		$this->go( add_query_arg( self::ERROR_ARG, rawurlencode( $code ), wp_login_url() ), false );
	}

	/**
	 * Redirect and exit.
	 *
	 * @param string $url      Destination.
	 * @param bool   $external True for the provider (not a same-site URL).
	 * @return never
	 */
	private function go( string $url, bool $external ): never {
		if ( null !== $this->redirect ) {
			( $this->redirect )( $url );
		}
		if ( $external ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the configured issuer's authorization endpoint, validated in Provider::discovery().
			wp_redirect( $url );
		} else {
			wp_safe_redirect( $url );
		}
		exit;
	}

	/**
	 * Show the error code from a failed attempt on wp-login.php.
	 *
	 * @param WP_Error $errors Login page errors.
	 * @return WP_Error
	 */
	public function show_error( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET[ self::ERROR_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::ERROR_ARG ] ) ) : '';
		if ( '' === $code || ! $errors instanceof WP_Error ) {
			return $errors;
		}
		$errors->add( 'qrauth_psl_' . $code, esc_html( self::message( $code ) ) );
		return $errors;
	}

	/**
	 * Success notice on the profile page after linking.
	 */
	public function show_linked_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET[ self::LINKED_ARG ] ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || 'profile' !== $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Your QRAuth account is linked. You can now sign in with QRAuth.', 'qrauth-passwordless-social-login' )
		);
	}

	/**
	 * Customer-facing message for a machine code.
	 *
	 * @param string $code Machine code.
	 * @return string
	 */
	public static function message( string $code ): string {
		return match ( $code ) {
			'cancelled'           => __( 'QRAuth sign-in was cancelled.', 'qrauth-passwordless-social-login' ),
			'state_invalid'       => __( 'That QRAuth sign-in expired or was started in another browser. Please try again.', 'qrauth-passwordless-social-login' ),
			'provision_disabled'  => __( 'There is no account on this site for your QRAuth identity.', 'qrauth-passwordless-social-login' ),
			'email_unverified'    => __( 'Your QRAuth email address is not verified, so it cannot be matched to an account here.', 'qrauth-passwordless-social-login' ),
			'account_conflict'    => __( 'This site account is already linked to a different QRAuth identity.', 'qrauth-passwordless-social-login' ),
			'link_required'       => __( 'This account has elevated permissions, so it must be linked to QRAuth from its own profile first. Sign in with your password, then choose "Link your QRAuth account" on your profile.', 'qrauth-passwordless-social-login' ),
			'link_session'        => __( 'Linking needs you to be signed in on this browser. Sign in, then start again from your profile.', 'qrauth-passwordless-social-login' ),
			'account_blocked'     => __( 'This account cannot sign in right now.', 'qrauth-passwordless-social-login' ),
			'rate_limited'        => __( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'qrauth-passwordless-social-login' ),
			'not_configured'      => __( 'QRAuth sign-in is not configured on this site.', 'qrauth-passwordless-social-login' ),
			default               => __( 'QRAuth sign-in failed. Please try again.', 'qrauth-passwordless-social-login' ),
		};
	}

	/**
	 * The provider, built from settings unless injected.
	 *
	 * @return Provider
	 */
	private function provider(): Provider {
		if ( null !== $this->provider ) {
			return $this->provider;
		}
		$options = Options::all();
		return new Provider( Options::oidc_issuer(), $options['oidc_client_id'], $options['oidc_client_secret'] );
	}

	/**
	 * Transient key for a state value. Only a hash of the state is used.
	 *
	 * @param string $state State value.
	 * @return string
	 */
	private static function state_key( string $state ): string {
		return 'qrauth_psl_oidc_' . substr( hash( 'sha256', $state ), 0, 40 );
	}

	/**
	 * The `kid` from an ID token header, if any.
	 *
	 * @param string $jwt Compact JWS.
	 * @return string|null
	 */
	private static function token_kid( string $jwt ): ?string {
		$header_segment = strstr( $jwt, '.', true );
		if ( false === $header_segment ) {
			return null;
		}
		try {
			$header = json_decode( IdToken::b64url_decode( $header_segment ), true );
		} catch ( OidcException $e ) {
			return null;
		}
		return is_array( $header ) && is_string( $header['kid'] ?? null ) ? $header['kid'] : null;
	}
}
