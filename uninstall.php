<?php
/**
 * Uninstall cleanup for QRAuth – Passwordless & Social Login.
 *
 * Removes plugin-owned options + transients only. Does NOT touch
 * `wp_users` or `wp_usermeta` — users keep their accounts (role,
 * posts, comments) and their QRAuth link metadata so a reinstall
 * re-attaches without forcing them to re-link. Per-user unlinking
 * is a separate flow on the profile page (see `ProfileFields`).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// Fail closed if called outside the uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\QRAuth\PasswordlessSocialLogin\Uninstall\Uninstaller::run();
