<?php
/**
 * Uninstall cleanup for QRAuth – Passwordless & Social Login.
 *
 * Removes plugin-owned options only. Does NOT remove user meta created
 * when users linked their QRAuth identity — that is handled per-user
 * via the profile "Unlink" control.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// Fail closed if called outside the uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'qrauth_psl_settings' );
