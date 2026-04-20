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
	require_once $qrauth_psl_wp_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/qrauth-passwordless-social-login.php';
		}
	);

	require $qrauth_psl_wp_tests_dir . '/includes/bootstrap.php';
}
