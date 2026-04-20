<?php
/**
 * Minimal `WP_Error` stub for the unit test suite.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- stub mirroring WP's own class name.

if ( ! class_exists( 'WP_Error', false ) ) {

	/**
	 * Stand-in matching the WP_Error surface our code reads.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Construct a fake error.
		 *
		 * @param string $code Error code.
		 */
		public function __construct( string $code = 'stub' ) {
			$this->code = $code;
		}

		/**
		 * Return the error code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
