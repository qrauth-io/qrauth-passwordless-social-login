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

$qrauth_psl_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $qrauth_psl_wp_tests_dir && file_exists( $qrauth_psl_wp_tests_dir . '/includes/functions.php' ) ) {
	// Integration suite — WordPress defines ABSPATH to the real core dir
	// inside `wp-tests-config.php`. Pre-defining it here would freeze the
	// value at the project root and break files that do
	// `require ABSPATH . 'wp-includes/…'` (e.g. WP's own mock-mailer).
	require_once $qrauth_psl_wp_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/qrauth-passwordless-social-login.php';
		}
	);

	require $qrauth_psl_wp_tests_dir . '/includes/bootstrap.php';
} else {
	// Unit-suite-only. Brain\Monkey doesn't load WordPress, so we fake
	// the pieces our code type-hints against. Defining ABSPATH keeps the
	// `defined( 'ABSPATH' ) || exit;` guards at the top of src/ files
	// (required by WP.org plugin-check) from aborting the process during
	// class autoload.
	if ( ! defined( 'ABSPATH' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WP's constant; defined here only for the unit suite.
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}
	require __DIR__ . '/stubs/wp-error.php';
	require __DIR__ . '/stubs/wp-user.php';
}
