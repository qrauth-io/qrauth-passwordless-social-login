<?php
/**
 * Typed accessor for the plugin's single stored option.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and merges the plugin's `qrauth_psl_settings` option with defaults.
 *
 * The plugin stores all configuration in a single `wp_options` row to keep
 * the installation footprint small. Callers go through this class instead
 * of calling `get_option()` directly so the return shape stays consistent
 * even when the stored array is missing keys (e.g. after an upgrade).
 *
 * Two URL-shaped values are exposed:
 *  - `tenant_url`   — the origin of the hosted QRAuth service this plugin
 *                     proxies to (default `https://qrauth.io`). User-editable.
 *  - `api_base_url` — the REST-root URL this site exposes to the browser
 *                     widget. Computed from `rest_url()`; never stored.
 *
 * @since 0.1.0
 */
final class Options {

	public const OPTION_NAME = 'qrauth_psl_settings';

	/**
	 * REST namespace the plugin's browser-facing API lives under. Kept here
	 * (instead of referencing `Rest\RestController::ROUTE_NAMESPACE`) so
	 * `Options` stays free of a cross-package dependency.
	 */
	public const REST_NAMESPACE = 'qrauth-psl/v1';

	/**
	 * Return the full merged settings array.
	 *
	 * @return array{
	 *   client_id:string,
	 *   client_secret:string,
	 *   tenant_url:string,
	 *   api_base_url:string,
	 *   auto_provision:bool,
	 *   default_role:string,
	 *   allowed_scopes:array<int,string>,
	 *   enabled_on:array<int,string>
	 * }
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( self::defaults(), $stored );

		// Backwards-compat read: if the stored option still carries the
		// pre-0.2 `base_url` key (persisted from an older install that
		// hasn't hit `migrate()` yet) and `tenant_url` is missing, treat
		// the old value as the new one so the widget doesn't break between
		// upgrade and the first activation hook firing.
		if ( ! isset( $stored['tenant_url'] ) && isset( $stored['base_url'] ) ) {
			$merged['tenant_url'] = (string) $stored['base_url'];
		}

		return array(
			'client_id'      => (string) ( $merged['client_id'] ?? '' ),
			'client_secret'  => (string) ( $merged['client_secret'] ?? '' ),
			'tenant_url'     => (string) ( $merged['tenant_url'] ?? 'https://qrauth.io' ),
			'api_base_url'   => function_exists( 'rest_url' ) ? (string) rest_url( self::REST_NAMESPACE ) : '',
			'auto_provision' => (bool) ( $merged['auto_provision'] ?? false ),
			'default_role'   => (string) ( $merged['default_role'] ?? 'subscriber' ),
			'allowed_scopes' => is_array( $merged['allowed_scopes'] ?? null ) ? array_values( $merged['allowed_scopes'] ) : array( 'identity', 'email' ),
			'enabled_on'     => is_array( $merged['enabled_on'] ?? null ) ? array_values( $merged['enabled_on'] ) : array( 'wp-login', 'register', 'profile' ),
		);
	}

	/**
	 * Return a single setting value, or null if the key is unknown.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Default values seeded on activation and used to backfill missing keys.
	 *
	 * Note: `api_base_url` is intentionally omitted — it is always computed
	 * at read time via `rest_url()` and never stored.
	 *
	 * @return array{
	 *   client_id:string,
	 *   client_secret:string,
	 *   tenant_url:string,
	 *   auto_provision:bool,
	 *   default_role:string,
	 *   allowed_scopes:array<int,string>,
	 *   enabled_on:array<int,string>
	 * }
	 */
	public static function defaults(): array {
		return array(
			'client_id'      => '',
			'client_secret'  => '',
			'tenant_url'     => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
		);
	}

	/**
	 * One-shot migration: rename stored `base_url` → `tenant_url`.
	 *
	 * Called from the activation hook; safe to call repeatedly. Also
	 * ensures `client_secret` is present (empty string) so downstream
	 * consumers can rely on the key existing.
	 */
	public static function migrate(): void {
		$stored = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $stored ) ) {
			return;
		}

		$dirty = false;

		if ( isset( $stored['base_url'] ) ) {
			if ( ! isset( $stored['tenant_url'] ) ) {
				$stored['tenant_url'] = (string) $stored['base_url'];
			}
			unset( $stored['base_url'] );
			$dirty = true;
		}

		if ( ! array_key_exists( 'client_secret', $stored ) ) {
			$stored['client_secret'] = '';
			$dirty                   = true;
		}

		if ( $dirty ) {
			update_option( self::OPTION_NAME, $stored );
		}
	}
}
