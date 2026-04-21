<?php
/**
 * Settings registration, admin menu, and sanitize callback.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Settings;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Wires the admin-side settings surface for the plugin.
 *
 * Responsibilities are split with {@see SettingsView}: this class handles
 * registration and WP hook wiring; `SettingsView` owns the HTML for the
 * settings page. The `sanitize()` callback is a pure static function so
 * it can be unit tested without the WordPress runtime.
 *
 * @since 0.1.0
 */
final class Settings {

	/**
	 * Settings API option group (the argument to `settings_fields()`).
	 */
	public const OPTION_GROUP = 'qrauth_psl';

	/**
	 * Admin page slug under Settings → QRAuth.
	 */
	public const PAGE_SLUG = 'qrauth-psl';

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( QRAUTH_PSL_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
	 * Register the single settings option with the Settings API.
	 */
	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			Options::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Register the admin menu entry under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'QRAuth — Passwordless & Social Login', 'qrauth-passwordless-social-login' ),
			__( 'QRAuth', 'qrauth-passwordless-social-login' ),
			'manage_options',
			self::PAGE_SLUG,
			array( SettingsView::class, 'render' )
		);
	}

	/**
	 * Render an admin notice when the plugin is missing required credentials.
	 *
	 * Two distinct conditions produce a notice:
	 *  - `client_id` is empty → "not yet configured".
	 *  - `client_id` is set but `client_secret` is empty → the proxy can't
	 *    reach qrauth.io (it can't forge Basic auth without the secret).
	 *
	 * Both are suppressed on the plugin's own settings page, where an
	 * inline callout already carries the message.
	 */
	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}

		$client_id     = (string) Options::get( 'client_id' );
		$client_secret = (string) Options::get( 'client_secret' );
		$settings_url  = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		if ( '' === $client_id ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'QRAuth is not yet configured.', 'qrauth-passwordless-social-login' ),
				esc_url( $settings_url ),
				esc_html__( 'Set your Client ID in Settings → QRAuth.', 'qrauth-passwordless-social-login' )
			);
			return;
		}

		if ( '' === $client_secret ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'QRAuth plugin requires a Client Secret.', 'qrauth-passwordless-social-login' ),
				esc_url( $settings_url ),
				esc_html__( 'Configure it in Settings → QRAuth.', 'qrauth-passwordless-social-login' )
			);
		}
	}

	/**
	 * Add a Settings link to the plugin row on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'qrauth-passwordless-social-login' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Sanitize the submitted settings payload.
	 *
	 * Pure function — depends only on its argument and a read of the
	 * existing option (for "fall back to previous" behaviour). Designed
	 * to be unit tested with Brain\Monkey stubs.
	 *
	 * Rules, in order:
	 *  - `client_id`       : sanitize_text_field + trim. Empty permitted.
	 *  - `client_secret`   : preserve previous if submitted empty (the UI
	 *                        never echoes the stored value back), otherwise
	 *                        trim. Stored plaintext — same trust boundary
	 *                        as every other WP-stored API credential.
	 *  - `tenant_url`      : https:// or http://localhost|127.0.0.1 only; otherwise falls back to previous.
	 *  - `auto_provision`  : cast to bool.
	 *  - `default_role`    : allowlist [subscriber, contributor, author]; else → subscriber.
	 *  - `allowed_scopes`  : array_intersect with [identity, email, organization]; identity is mandatory.
	 *  - `enabled_on`      : array_intersect with [wp-login, register, profile].
	 *  - Unknown top-level keys are dropped (we start from defaults).
	 *
	 * @param mixed $input Raw $_POST payload under Options::OPTION_NAME.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = Options::defaults();
		$previous = get_option( Options::OPTION_NAME, $defaults );
		if ( ! is_array( $previous ) ) {
			$previous = $defaults;
		}
		// Bridge any pre-0.2 install where `base_url` is still the stored
		// key — treat it as the previous `tenant_url` so the "fall back to
		// previous" branch below doesn't regress to the default origin on
		// the first save after upgrade.
		if ( ! isset( $previous['tenant_url'] ) && isset( $previous['base_url'] ) ) {
			$previous['tenant_url'] = (string) $previous['base_url'];
		}
		$previous = array_merge( $defaults, $previous );

		$clean = $defaults;

		// client_id.
		$clean['client_id'] = isset( $input['client_id'] )
			? trim( sanitize_text_field( (string) $input['client_id'] ) )
			: (string) $previous['client_id'];

		// client_secret — blank means "keep existing" because the UI never
		// emits the stored value into the form. Only a non-empty submission
		// overwrites.
		$submitted_secret       = isset( $input['client_secret'] ) ? (string) $input['client_secret'] : '';
		$clean['client_secret'] = '' === trim( $submitted_secret )
			? (string) $previous['client_secret']
			: trim( $submitted_secret );

		// tenant_url.
		$url      = isset( $input['tenant_url'] ) ? trim( (string) $input['tenant_url'] ) : '';
		$parsed   = '' !== $url ? wp_parse_url( $url ) : false;
		$scheme   = is_array( $parsed ) && isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '';
		$host     = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$is_https = 'https' === $scheme;
		$is_local = 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true );

		if ( $is_https || $is_local ) {
			$clean['tenant_url'] = esc_url_raw( $url );
		} else {
			$clean['tenant_url'] = (string) $previous['tenant_url'];
		}

		// auto_provision.
		$clean['auto_provision'] = ! empty( $input['auto_provision'] );

		// default_role.
		$role                  = isset( $input['default_role'] ) ? (string) $input['default_role'] : '';
		$allowed_roles         = array( 'subscriber', 'contributor', 'author' );
		$clean['default_role'] = in_array( $role, $allowed_roles, true ) ? $role : 'subscriber';

		// allowed_scopes — identity is mandatory.
		$scopes        = isset( $input['allowed_scopes'] ) && is_array( $input['allowed_scopes'] ) ? $input['allowed_scopes'] : array();
		$allowed_scope = array( 'identity', 'email', 'organization' );
		$scopes_clean  = array_values( array_intersect( $scopes, $allowed_scope ) );
		if ( ! in_array( 'identity', $scopes_clean, true ) ) {
			array_unshift( $scopes_clean, 'identity' );
		}
		$clean['allowed_scopes'] = $scopes_clean;

		// enabled_on.
		$enabled             = isset( $input['enabled_on'] ) && is_array( $input['enabled_on'] ) ? $input['enabled_on'] : array();
		$allowed_points      = array( 'wp-login', 'register', 'profile' );
		$clean['enabled_on'] = array_values( array_intersect( $enabled, $allowed_points ) );

		return $clean;
	}
}
