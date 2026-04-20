<?php
/**
 * Unit tests for {@see LoginWidget}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Frontend\LoginWidget;

/**
 * Covers the two gating conditions (`client_id` + `enabled_on`) and the
 * `<qrauth-login>` markup emitted by {@see LoginWidget::emit()}.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Frontend\LoginWidget
 */
final class LoginWidgetTest extends TestCase {

	/**
	 * Brain\Monkey + stubs for the WordPress helpers LoginWidget touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `esc_*` helpers — pass-through is fine for the shape assertions below.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_x' )->alias(
			static function ( $text, $_context, $_domain = null ) {
				unset( $_context, $_domain );
				return $text;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
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
	 * Configure `get_option` to return a specific merged options payload.
	 *
	 * @param array<string,mixed> $overrides Fields to merge into the default shape.
	 */
	private function stub_options( array $overrides = array() ): void {
		$defaults = array(
			'client_id'      => 'test-client-id',
			'base_url'       => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
		);
		$merged   = array_merge( $defaults, $overrides );

		Functions\when( 'get_option' )->alias(
			static function () use ( $merged ) {
				return $merged;
			}
		);
	}

	/**
	 * No client_id means the widget never renders, even if the injection
	 * point is enabled.
	 */
	public function test_should_render_returns_false_when_client_id_empty(): void {
		$this->stub_options( array( 'client_id' => '' ) );

		$this->assertFalse( LoginWidget::should_render( 'wp-login' ) );
		$this->assertFalse( LoginWidget::should_render( 'register' ) );
	}

	/**
	 * Injection points not listed in `enabled_on` are skipped.
	 */
	public function test_should_render_respects_enabled_on_allowlist(): void {
		$this->stub_options( array( 'enabled_on' => array( 'wp-login' ) ) );

		$this->assertTrue( LoginWidget::should_render( 'wp-login' ) );
		$this->assertFalse( LoginWidget::should_render( 'register' ) );
		$this->assertFalse( LoginWidget::should_render( 'profile' ) );
	}

	/**
	 * Happy path — both gates pass.
	 */
	public function test_should_render_returns_true_when_gates_pass(): void {
		$this->stub_options();

		$this->assertTrue( LoginWidget::should_render( 'wp-login' ) );
		$this->assertTrue( LoginWidget::should_render( 'register' ) );
	}

	/**
	 * Button emission carries the expected attributes on `<qrauth-login>`.
	 */
	public function test_emit_button_markup(): void {
		$this->stub_options();

		ob_start();
		LoginWidget::emit( 'button', 'login' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<qrauth-login', $html );
		$this->assertStringContainsString( 'display="button"', $html );
		$this->assertStringContainsString( 'mode="login"', $html );
		$this->assertStringContainsString( 'tenant="test-client-id"', $html );
		$this->assertStringContainsString( 'base-url="https://qrauth.io"', $html );
		$this->assertStringContainsString( 'scope="identity email"', $html );
		$this->assertStringContainsString( 'redirect-uri="https://example.test/wp-login.php"', $html );
	}

	/**
	 * Inline emission flips `display` and `mode` but keeps everything else.
	 */
	public function test_emit_inline_markup(): void {
		$this->stub_options();

		ob_start();
		LoginWidget::emit( 'inline', 'register' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'display="inline"', $html );
		$this->assertStringContainsString( 'mode="register"', $html );
		$this->assertStringContainsString( 'qrauth-psl-widget--inline', $html );
	}

	/**
	 * Scopes are joined with a single space, matching the OAuth convention.
	 */
	public function test_emit_joins_scopes_with_space(): void {
		$this->stub_options(
			array( 'allowed_scopes' => array( 'identity', 'email', 'organization' ) )
		);

		ob_start();
		LoginWidget::emit( 'button', 'login' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'scope="identity email organization"', $html );
	}
}
