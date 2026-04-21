<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Uninstall;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Removes plugin-owned rows from wp_options — nothing else.
 *
 * Specifically DOES NOT touch `wp_users` or `wp_usermeta`: the user
 * accounts created during linking are still the site owner's accounts
 * (with roles, posts, comments, etc.), and deleting them on plugin
 * removal would be a destructive surprise. Users keep their
 * `qrauth_psl_user_id` meta too — if the plugin is reinstalled and a
 * user signs in again via QRAuth, the meta match short-circuits and
 * re-links the account without a detour through email matching.
 *
 * Multisite: for v0.1.0 this runs only against the site invoking
 * uninstall.php. Network-wide cleanup across sub-sites is tracked in
 * `SPECS/BACKLOG.md` under v0.2.0+.
 *
 * @since 0.1.0
 */
final class Uninstaller {

	/**
	 * Run the uninstall cleanup.
	 *
	 * Called from the top-level `uninstall.php` by WordPress after the
	 * user confirms plugin deletion. Safe to call multiple times.
	 */
	public static function run(): void {
		// 1. Remove the single plugin option.
		delete_option( Options::OPTION_NAME );

		// 2. Remove rate-limit transients. The keys use a
		// `qrauth_psl_rl_<hash>` prefix and we can't enumerate them
		// individually, so we sweep by prefix.
		self::delete_rate_limit_transients();
	}

	/**
	 * Remove all `qrauth_psl_*` transients in one sweep.
	 *
	 * Transient API doesn't expose a delete-by-prefix helper, so we
	 * target `wp_options` directly with a prepared LIKE. Both the value
	 * row (`_transient_<key>`) and the expiry row
	 * (`_transient_timeout_<key>`) need to go.
	 */
	private static function delete_rate_limit_transients(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot cleanup at uninstall time; no cache to invalidate because the options are about to be dropped.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_qrauth_psl_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_qrauth_psl_' ) . '%'
			)
		);
	}
}
