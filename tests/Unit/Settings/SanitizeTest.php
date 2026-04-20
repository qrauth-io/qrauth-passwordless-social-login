<?php
/**
 * Unit tests for {@see Settings::sanitize()}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Settings\Settings;

/**
 * Exercises every sanitizer rule documented in `SPECS/ARCHITECTURE.md` §3.
 *
 * Uses Brain\Monkey to stub the small surface of WordPress functions that
 * `Settings::sanitize()` touches: `sanitize_text_field`, `esc_url_raw`,
 * `wp_parse_url`, and `get_option` (for the "fall back to previous value"
 * path on `base_url`).
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Settings\Settings::sanitize
 */
final class SanitizeTest extends TestCase {

	/**
	 * Initialise Brain\Monkey and the WordPress-function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( (string) $value );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		// Default: no previous option stored. Individual tests may override.
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				return $default_value;
			}
		);
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A well-formed payload passes through with values preserved.
	 */
	public function test_valid_input_passes_through(): void {
		$clean = Settings::sanitize(
			array(
				'client_id'      => 'abc123',
				'base_url'       => 'https://qrauth.io',
				'auto_provision' => '1',
				'default_role'   => 'author',
				'allowed_scopes' => array( 'identity', 'email', 'organization' ),
				'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
			)
		);

		$this->assertSame( 'abc123', $clean['client_id'] );
		$this->assertSame( 'https://qrauth.io', $clean['base_url'] );
		$this->assertTrue( $clean['auto_provision'] );
		$this->assertSame( 'author', $clean['default_role'] );
		$this->assertSame( array( 'identity', 'email', 'organization' ), $clean['allowed_scopes'] );
		$this->assertSame( array( 'wp-login', 'register', 'profile' ), $clean['enabled_on'] );
	}

	/**
	 * A `javascript:` scheme URL is rejected and the previous value wins.
	 */
	public function test_base_url_javascript_scheme_is_rejected(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'base_url' => 'https://previous.example' );
			}
		);

		$clean = Settings::sanitize( array( 'base_url' => 'javascript:alert(1)' ) );

		$this->assertSame( 'https://previous.example', $clean['base_url'] );
	}

	/**
	 * Plain http:// to an external host is rejected (only https or localhost allowed).
	 */
	public function test_base_url_non_localhost_http_is_rejected(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'base_url' => 'https://qrauth.io' );
			}
		);

		$clean = Settings::sanitize( array( 'base_url' => 'http://evil.example.com' ) );

		$this->assertSame( 'https://qrauth.io', $clean['base_url'] );
	}

	/**
	 * `http://localhost` and `http://127.0.0.1` are accepted for local dev.
	 */
	public function test_base_url_localhost_and_loopback_are_accepted(): void {
		$clean = Settings::sanitize( array( 'base_url' => 'http://localhost:8888' ) );
		$this->assertSame( 'http://localhost:8888', $clean['base_url'] );

		$clean = Settings::sanitize( array( 'base_url' => 'http://127.0.0.1:3000' ) );
		$this->assertSame( 'http://127.0.0.1:3000', $clean['base_url'] );
	}

	/**
	 * `default_role = administrator` is clamped to `subscriber`.
	 */
	public function test_default_role_administrator_is_clamped(): void {
		$clean = Settings::sanitize( array( 'default_role' => 'administrator' ) );
		$this->assertSame( 'subscriber', $clean['default_role'] );
	}

	/**
	 * `default_role = editor` is also clamped (explicit exclusion per architecture §3).
	 */
	public function test_default_role_editor_is_clamped(): void {
		$clean = Settings::sanitize( array( 'default_role' => 'editor' ) );
		$this->assertSame( 'subscriber', $clean['default_role'] );
	}

	/**
	 * `identity` scope is mandatory and gets added when missing.
	 */
	public function test_allowed_scopes_missing_identity_gets_it_added(): void {
		$clean = Settings::sanitize( array( 'allowed_scopes' => array( 'email' ) ) );

		$this->assertContains( 'identity', $clean['allowed_scopes'] );
		$this->assertContains( 'email', $clean['allowed_scopes'] );
	}

	/**
	 * Unknown scopes (like `calendar`) are stripped by array_intersect with the allowlist.
	 */
	public function test_allowed_scopes_unknown_scope_is_stripped(): void {
		$clean = Settings::sanitize(
			array( 'allowed_scopes' => array( 'identity', 'email', 'calendar' ) )
		);

		$this->assertSame( array( 'identity', 'email' ), $clean['allowed_scopes'] );
		$this->assertNotContains( 'calendar', $clean['allowed_scopes'] );
	}

	/**
	 * Unknown top-level keys are silently stripped (we only carry defaults forward).
	 */
	public function test_unknown_top_level_key_is_stripped(): void {
		$clean = Settings::sanitize(
			array(
				'client_id'       => 'valid',
				'malicious_field' => 'evil',
			)
		);

		$this->assertArrayNotHasKey( 'malicious_field', $clean );
		$this->assertSame( 'valid', $clean['client_id'] );
	}

	/**
	 * Non-array input is coerced to defaults (defensive path if WP hands us garbage).
	 */
	public function test_non_array_input_yields_defaults(): void {
		$clean = Settings::sanitize( 'not an array' );

		$this->assertSame( '', $clean['client_id'] );
		$this->assertSame( 'https://qrauth.io', $clean['base_url'] );
		$this->assertFalse( $clean['auto_provision'] );
	}

	/**
	 * Unchecked / missing auto_provision becomes `false` (checkbox semantics).
	 */
	public function test_auto_provision_absent_becomes_false(): void {
		$clean = Settings::sanitize( array() );
		$this->assertFalse( $clean['auto_provision'] );

		$clean = Settings::sanitize( array( 'auto_provision' => '' ) );
		$this->assertFalse( $clean['auto_provision'] );
	}

	/**
	 * `enabled_on` values outside the allowlist are stripped.
	 */
	public function test_enabled_on_unknown_value_is_stripped(): void {
		$clean = Settings::sanitize(
			array( 'enabled_on' => array( 'wp-login', 'comments', 'register' ) )
		);

		$this->assertSame( array( 'wp-login', 'register' ), $clean['enabled_on'] );
	}
}
