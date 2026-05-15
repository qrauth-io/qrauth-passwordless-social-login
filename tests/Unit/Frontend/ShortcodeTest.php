<?php
/**
 * Unit tests for {@see Shortcode}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Frontend\Shortcode;

/**
 * Covers the gating (`client_id` empty, `shortcode` absent from
 * `enabled_on`), attribute parsing + allowlisting, and markup shape.
 *
 * The shortcode's `AssetEnqueue::enqueue_for_widget()` side effect calls
 * `wp_enqueue_style` / `wp_enqueue_script` — both are stubbed as no-ops
 * since they are verified end-to-end in the integration suite rather
 * than here.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Frontend\Shortcode
 */
final class ShortcodeTest extends TestCase {

	/**
	 * Brain\Monkey + stubs for the WordPress helpers the shortcode touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'site_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'shortcode_atts' )->alias(
			static function ( $pairs, $atts ) {
				return array_merge( $pairs, is_array( $atts ) ? $atts : array() );
			}
		);

		// Asset enqueue helper hits these — stub as no-ops.
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Configure `get_option` to return a specific merged options payload.
	 *
	 * @param array<string,mixed> $overrides Fields to merge into the default shape.
	 */
	private function stub_options( array $overrides = array() ): void {
		$defaults = array(
			'client_id'      => 'test-client-id',
			'client_secret'  => 'test-client-secret',
			'tenant_url'     => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register', 'profile', 'shortcode' ),
		);
		$merged   = array_merge( $defaults, $overrides );

		Functions\when( 'get_option' )->alias(
			static function () use ( $merged ) {
				return $merged;
			}
		);
	}

	/**
	 * With no client_id configured, the shortcode renders nothing — same
	 * gate as the login-page widget.
	 */
	public function test_empty_client_id_returns_empty_string(): void {
		$this->stub_options( array( 'client_id' => '' ) );

		$shortcode = new Shortcode();
		$this->assertSame( '', $shortcode->render() );
	}

	/**
	 * With `shortcode` absent from `enabled_on`, the shortcode renders
	 * nothing even if `client_id` is set. Admins opt in per-surface.
	 */
	public function test_shortcode_not_enabled_returns_empty_string(): void {
		$this->stub_options( array( 'enabled_on' => array( 'wp-login', 'register' ) ) );

		$shortcode = new Shortcode();
		$this->assertSame( '', $shortcode->render() );
	}

	/**
	 * Default invocation → inline display + login mode.
	 */
	public function test_default_attrs_render_inline_login(): void {
		$this->stub_options();

		$shortcode = new Shortcode();
		$html      = $shortcode->render();

		$this->assertStringContainsString( '<qrauth-login', $html );
		$this->assertStringContainsString( 'display="inline"', $html );
		$this->assertStringContainsString( 'mode="login"', $html );
		$this->assertStringContainsString( 'tenant="test-client-id"', $html );
		$this->assertStringContainsString( 'base-url="https://example.test/wp-json/qrauth-psl/v1"', $html );
		$this->assertStringContainsString( 'scopes="identity email"', $html );
	}

	/**
	 * Explicit attributes flow through to the rendered element.
	 */
	public function test_explicit_button_register_attrs(): void {
		$this->stub_options();

		$shortcode = new Shortcode();
		$html      = $shortcode->render(
			array(
				'display' => 'button',
				'mode'    => 'register',
			)
		);

		$this->assertStringContainsString( 'display="button"', $html );
		$this->assertStringContainsString( 'mode="register"', $html );
		$this->assertStringContainsString( 'qrauth-psl-widget--button', $html );
	}

	/**
	 * Unknown values for display/mode are clamped to the safe defaults.
	 */
	public function test_unknown_attr_values_fall_back_to_defaults(): void {
		$this->stub_options();

		$shortcode = new Shortcode();
		$html      = $shortcode->render(
			array(
				'display' => 'rogue-value',
				'mode'    => 'admin',
			)
		);

		$this->assertStringContainsString( 'display="inline"', $html );
		$this->assertStringContainsString( 'mode="login"', $html );
	}

	/**
	 * Shortcode output does NOT include the "— or —" divider — that is
	 * login-form-specific markup and would read awkwardly on a custom
	 * page. The divider only appears via `LoginWidget::emit()`.
	 */
	public function test_shortcode_output_has_no_login_divider(): void {
		$this->stub_options();

		$shortcode = new Shortcode();
		$html      = $shortcode->render();

		$this->assertStringNotContainsString( 'qrauth-psl-widget__divider', $html );
	}

	/**
	 * WP passes an empty string (not an array) when the shortcode is
	 * invoked with no attributes. Callback must handle that gracefully.
	 */
	public function test_empty_string_attrs_are_tolerated(): void {
		$this->stub_options();

		$shortcode = new Shortcode();
		$html      = $shortcode->render( '' );

		$this->assertStringContainsString( '<qrauth-login', $html );
		$this->assertStringContainsString( 'display="inline"', $html );
	}
}
