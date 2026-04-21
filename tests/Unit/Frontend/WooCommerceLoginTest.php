<?php
/**
 * Unit tests for {@see WooCommerceLogin}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Frontend\WooCommerceLogin;

/**
 * Covers the three gating conditions (`class_exists('WooCommerce')`,
 * `client_id` empty, `enabled_on` missing the relevant entry) plus the
 * happy-path render.
 *
 * The real WooCommerce plugin isn't loaded in the unit harness, so the
 * `class_exists('WooCommerce')` branch is simulated by conditionally
 * defining a stub `WooCommerce` class in process memory for the tests
 * that need it. Once defined, the class stays registered for the rest
 * of the PHP process — that's fine, we only need the two states "is
 * defined" and "is not defined", and we never take the "not defined"
 * path after the corresponding test has run.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Frontend\WooCommerceLogin
 */
final class WooCommerceLoginTest extends TestCase {

	/**
	 * Brain\Monkey + stubs for the WordPress helpers the class touches.
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
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
			}
		);
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
	 * Configure `get_option` for the plugin's settings row.
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
			'enabled_on'     => array( 'wp-login', 'woocommerce-account', 'woocommerce-register' ),
		);
		$merged   = array_merge( $defaults, $overrides );

		Functions\when( 'get_option' )->alias(
			static function () use ( $merged ) {
				return $merged;
			}
		);
	}

	/**
	 * Declare a lightweight `WooCommerce` class so the `class_exists`
	 * gate in `WooCommerceLogin::should_render` returns true. Idempotent
	 * — calling multiple times is safe because PHP silently skips
	 * redeclaration when the class already exists.
	 */
	private function stub_woocommerce_active(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test helper only; declares a fake WooCommerce class so `class_exists` gates return true. Never runs in production code.
			eval( 'class WooCommerce {}' );
		}
	}

	/**
	 * Without WooCommerce active, `should_render` returns false even
	 * when `client_id` is set and the enabled_on entry is present.
	 *
	 * Runs first in source order so the `class_exists('WooCommerce')`
	 * check is guaranteed to see the un-stubbed state.
	 */
	public function test_should_not_render_when_woocommerce_inactive(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce class already declared by an earlier test in this process — cannot exercise the inactive path here.' );
		}

		$this->stub_options();

		$this->assertFalse( WooCommerceLogin::should_render( 'woocommerce-account' ) );
	}

	/**
	 * With WC active and everything configured, `should_render` returns
	 * true for the matching `enabled_on` entry.
	 */
	public function test_should_render_for_enabled_surface(): void {
		$this->stub_woocommerce_active();
		$this->stub_options();

		$this->assertTrue( WooCommerceLogin::should_render( 'woocommerce-account' ) );
		$this->assertTrue( WooCommerceLogin::should_render( 'woocommerce-register' ) );
	}

	/**
	 * Missing client_id blocks render even with WC active + surface enabled.
	 */
	public function test_empty_client_id_returns_false(): void {
		$this->stub_woocommerce_active();
		$this->stub_options( array( 'client_id' => '' ) );

		$this->assertFalse( WooCommerceLogin::should_render( 'woocommerce-account' ) );
	}

	/**
	 * Admin opt-in is per-surface — toggling off one WC surface doesn't
	 * affect the other.
	 */
	public function test_surface_not_in_enabled_on_returns_false(): void {
		$this->stub_woocommerce_active();
		$this->stub_options( array( 'enabled_on' => array( 'woocommerce-register' ) ) );

		$this->assertFalse( WooCommerceLogin::should_render( 'woocommerce-account' ) );
		$this->assertTrue( WooCommerceLogin::should_render( 'woocommerce-register' ) );
	}

	/**
	 * `render_login` emits the widget markup with the right attrs. Also
	 * confirms the WooCommerce-specific wrapper class is applied so
	 * themes can style the WC instance distinctly.
	 */
	public function test_render_login_emits_widget_markup(): void {
		$this->stub_woocommerce_active();
		$this->stub_options();

		$login = new WooCommerceLogin();

		ob_start();
		$login->render_login();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<qrauth-login', $html );
		$this->assertStringContainsString( 'display="inline"', $html );
		$this->assertStringContainsString( 'mode="login"', $html );
		$this->assertStringContainsString( 'qrauth-psl-widget--woocommerce', $html );
		$this->assertStringNotContainsString( 'qrauth-psl-widget__divider', $html );
	}

	/**
	 * `render_register` flips `mode` to `register` and keeps everything else.
	 */
	public function test_render_register_emits_widget_markup(): void {
		$this->stub_woocommerce_active();
		$this->stub_options();

		$login = new WooCommerceLogin();

		ob_start();
		$login->render_register();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'mode="register"', $html );
		$this->assertStringContainsString( 'qrauth-psl-widget--woocommerce', $html );
	}

	/**
	 * Gating is enforced at render time — a disabled surface produces no output.
	 */
	public function test_render_login_emits_nothing_when_not_enabled(): void {
		$this->stub_woocommerce_active();
		$this->stub_options( array( 'enabled_on' => array( 'wp-login' ) ) );

		$login = new WooCommerceLogin();

		ob_start();
		$login->render_login();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}
}
