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
 * path on `tenant_url` and the blank-preserve path on `client_secret`).
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

		// Default `apply_filters`: passthrough — return whatever value the
		// caller computed. The tenant_url sanitiser routes through the
		// `qrauth_psl_allow_localhost_tenant_url` filter with a
		// `WP_DEBUG`-derived default; in unit-test context `WP_DEBUG` is
		// not defined, so the default value is false → localhost rejected
		// by default. Tests that need the "accept" side call
		// `allow_localhost_filter()` to override.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				unset( $tag );
				return $value;
			}
		);
	}

	/**
	 * Override `apply_filters` so the `qrauth_psl_allow_localhost_tenant_url`
	 * filter returns true — simulates a site running with WP_DEBUG on, or
	 * one that has explicitly opted into non-HTTPS localhost tenants.
	 */
	private function allow_localhost_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'qrauth_psl_allow_localhost_tenant_url' === $tag ) {
					return true;
				}
				return $value;
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
				'client_secret'  => 'shh-very-secret',
				'tenant_url'     => 'https://qrauth.io',
				'auto_provision' => '1',
				'default_role'   => 'author',
				'allowed_scopes' => array( 'identity', 'email', 'organization' ),
				'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
			)
		);

		$this->assertSame( 'abc123', $clean['client_id'] );
		$this->assertSame( 'shh-very-secret', $clean['client_secret'] );
		$this->assertSame( 'https://qrauth.io', $clean['tenant_url'] );
		$this->assertTrue( $clean['auto_provision'] );
		$this->assertSame( 'author', $clean['default_role'] );
		$this->assertSame( array( 'identity', 'email', 'organization' ), $clean['allowed_scopes'] );
		$this->assertSame( array( 'wp-login', 'register', 'profile' ), $clean['enabled_on'] );
	}

	/**
	 * A `javascript:` scheme URL is rejected and the previous value wins.
	 */
	public function test_tenant_url_javascript_scheme_is_rejected(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'tenant_url' => 'https://previous.example' );
			}
		);

		$clean = Settings::sanitize( array( 'tenant_url' => 'javascript:alert(1)' ) );

		$this->assertSame( 'https://previous.example', $clean['tenant_url'] );
	}

	/**
	 * Plain http:// to an external host is rejected (only https or localhost allowed).
	 */
	public function test_tenant_url_non_localhost_http_is_rejected(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'tenant_url' => 'https://qrauth.io' );
			}
		);

		$clean = Settings::sanitize( array( 'tenant_url' => 'http://evil.example.com' ) );

		$this->assertSame( 'https://qrauth.io', $clean['tenant_url'] );
	}

	/**
	 * `http://localhost` and `http://127.0.0.1` are accepted for local
	 * development — but ONLY when the `qrauth_psl_allow_localhost_tenant_url`
	 * filter (defaulting to `WP_DEBUG`) returns true.
	 */
	public function test_tenant_url_localhost_and_loopback_are_accepted_when_filter_on(): void {
		$this->allow_localhost_filter();

		$clean = Settings::sanitize( array( 'tenant_url' => 'http://localhost:8888' ) );
		$this->assertSame( 'http://localhost:8888', $clean['tenant_url'] );

		$clean = Settings::sanitize( array( 'tenant_url' => 'http://127.0.0.1:3000' ) );
		$this->assertSame( 'http://127.0.0.1:3000', $clean['tenant_url'] );
	}

	/**
	 * By default (filter off → WP_DEBUG not set → production-like), a
	 * plain-http localhost tenant_url is rejected and the sanitiser falls
	 * back to the previous value.
	 *
	 * Closes the LOW-1 pentest finding from 2026-04-22: the pre-0.1.13
	 * sanitiser accepted localhost unconditionally, which let an admin
	 * point the proxy's outbound wp_remote_request at arbitrary
	 * internal-network ports.
	 */
	public function test_tenant_url_localhost_rejected_when_filter_off(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'tenant_url' => 'https://qrauth.io' );
			}
		);

		$clean = Settings::sanitize( array( 'tenant_url' => 'http://localhost:3306' ) );
		$this->assertSame( 'https://qrauth.io', $clean['tenant_url'] );

		$clean = Settings::sanitize( array( 'tenant_url' => 'http://127.0.0.1:6379' ) );
		$this->assertSame( 'https://qrauth.io', $clean['tenant_url'] );
	}

	/**
	 * Pre-0.2 installs stored the URL under `base_url`. Sanitize must read
	 * that as the previous value so the "fall back to previous" branch
	 * doesn't regress the URL to the default during the first save after
	 * upgrade.
	 */
	public function test_tenant_url_falls_back_to_previous_base_url_on_pre_migration_read(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'base_url' => 'https://legacy.example' );
			}
		);

		$clean = Settings::sanitize( array( 'tenant_url' => 'javascript:alert(1)' ) );

		$this->assertSame( 'https://legacy.example', $clean['tenant_url'] );
	}

	/**
	 * Submitting an empty `client_secret` preserves the previously stored
	 * value — the UI never echoes the stored secret back, and a blank
	 * submission means "keep what I had".
	 */
	public function test_client_secret_empty_submission_preserves_previous(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'client_secret' => 'kept-value' );
			}
		);

		$clean = Settings::sanitize(
			array(
				'client_id'     => 'cid',
				'client_secret' => '',
			)
		);

		$this->assertSame( 'kept-value', $clean['client_secret'] );
	}

	/**
	 * Submitting a non-empty `client_secret` overwrites the stored value.
	 */
	public function test_client_secret_non_empty_submission_overwrites(): void {
		Functions\when( 'get_option' )->alias(
			static function () {
				return array( 'client_secret' => 'old-value' );
			}
		);

		$clean = Settings::sanitize(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'new-value',
			)
		);

		$this->assertSame( 'new-value', $clean['client_secret'] );
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
		$this->assertSame( '', $clean['client_secret'] );
		$this->assertSame( 'https://qrauth.io', $clean['tenant_url'] );
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
