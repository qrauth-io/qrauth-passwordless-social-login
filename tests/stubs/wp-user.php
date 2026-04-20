<?php
/**
 * Minimal `WP_User` stub for the unit test suite.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- stub mirroring WP's own class name.

if ( ! class_exists( 'WP_User', false ) ) {

	/**
	 * Stand-in matching the WP_User properties our code reads.
	 */
	class WP_User {

		/**
		 * User ID.
		 *
		 * @var int
		 */
		public int $ID = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- mirrors WP_User's public ID property.

		/**
		 * Construct from an ID.
		 *
		 * @param int $user_id WP user ID.
		 */
		public function __construct( int $user_id = 0 ) {
			$this->ID = $user_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mirrors WP_User's public ID property.
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
