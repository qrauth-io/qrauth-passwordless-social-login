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
 * @since 0.1.0
 */
final class Options {

	public const OPTION_NAME = 'qrauth_psl_settings';

	/**
	 * Return the full merged settings array.
	 *
	 * @return array{
	 *   client_id:string,
	 *   base_url:string,
	 *   auto_provision:bool,
	 *   default_role:string,
	 *   allowed_scopes:array<int,string>,
	 *   enabled_on:array<int,string>
	 * }
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$merged = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		return array(
			'client_id'      => (string) ( $merged['client_id'] ?? '' ),
			'base_url'       => (string) ( $merged['base_url'] ?? 'https://qrauth.io' ),
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
	 * @return array{
	 *   client_id:string,
	 *   base_url:string,
	 *   auto_provision:bool,
	 *   default_role:string,
	 *   allowed_scopes:array<int,string>,
	 *   enabled_on:array<int,string>
	 * }
	 */
	public static function defaults(): array {
		return array(
			'client_id'      => '',
			'base_url'       => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
		);
	}
}
