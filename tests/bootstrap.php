<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit suite uses Brain\Monkey; integration suite loads the WP test library
 * from WP_TESTS_DIR (set by bin/install-wp-tests.sh in CI).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The integration harness defines ABSPATH when it boots WordPress. Unit tests
// run under Brain\Monkey without a WP runtime, so define a harmless value
// here — src/ files guard with `defined( 'ABSPATH' ) || exit;` (required by
// WP.org plugin-check) and would otherwise exit during class autoload.
if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WP's constant; defined here only for the unit suite.
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$qrauth_psl_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $qrauth_psl_wp_tests_dir && file_exists( $qrauth_psl_wp_tests_dir . '/includes/functions.php' ) ) {
	require_once $qrauth_psl_wp_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/qrauth-passwordless-social-login.php';
		}
	);

	require $qrauth_psl_wp_tests_dir . '/includes/bootstrap.php';
}
