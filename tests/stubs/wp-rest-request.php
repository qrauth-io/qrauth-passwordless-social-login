<?php
/**
 * Minimal `WP_REST_Request` stub for the unit test suite.
 *
 * Declared in the global namespace (matching WP core) so unit tests can
 * `new \WP_REST_Request()` without pulling in the full WP test harness.
 * Exposes only the tiny surface the code under test actually touches —
 * header get/set. If future code reads body / params / URL from the
 * request, extend this stub rather than loading all of WP.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- stub mirroring WP's own class name.

if ( ! class_exists( 'WP_REST_Request', false ) ) {

	/**
	 * Stand-in matching the tiny WP_REST_Request surface we exercise.
	 */
	class WP_REST_Request {

		/**
		 * Case-insensitive header store.
		 *
		 * @var array<string,string>
		 */
		private array $headers = array();

		/**
		 * Record a header on the request.
		 *
		 * @param string $name  Header name.
		 * @param string $value Header value.
		 */
		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		/**
		 * Fetch a header value, or empty string when unset.
		 *
		 * @param string $name Header name.
		 */
		public function get_header( string $name ): string {
			return $this->headers[ strtolower( $name ) ] ?? '';
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
