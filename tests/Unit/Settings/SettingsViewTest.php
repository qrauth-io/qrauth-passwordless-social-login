<?php
/**
 * Unit tests for {@see SettingsView}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Settings\SettingsView;

/**
 * Covers the status banner, the OIDC setup guide and the folded legacy
 * section of the settings page.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Settings\SettingsView
 */
final class SettingsViewTest extends TestCase {

	/**
	 * Brain\Monkey + pass-through stubs for the WordPress helpers the view uses.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
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
	 * Render the page with the given option overrides.
	 *
	 * @param array<string,mixed> $overrides Fields to merge into the default shape.
	 */
	private function render( array $overrides ): string {
		$options = array_merge(
			array(
				'client_id'          => '',
				'client_secret'      => '',
				'tenant_url'         => 'https://qrauth.io',
				'auto_provision'     => false,
				'default_role'       => 'subscriber',
				'allowed_scopes'     => array( 'identity', 'email' ),
				'enabled_on'         => array( 'wp-login' ),
				'oidc_client_id'     => '',
				'oidc_client_secret' => '',
			),
			$overrides
		);
		Functions\when( 'get_option' )->justReturn( $options );

		ob_start();
		SettingsView::render();
		return (string) ob_get_clean();
	}

	/**
	 * A fresh install says it is not set up and shows the return address to copy.
	 */
	public function test_unconfigured_site_shows_setup_guide(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( 'data-status="unconfigured"', $html );
		$this->assertStringContainsString( 'value="https://example.test/wp-login.php?action=qrauth_psl_oidc"', $html );
		$this->assertStringNotContainsString( '<details class="qrauth-psl-legacy" open>', $html );
	}

	/**
	 * A site with an OIDC client says Sign in with QRAuth is on.
	 */
	public function test_oidc_site_shows_success_banner(): void {
		$html = $this->render(
			array(
				'oidc_client_id'     => 'qrc_abc',
				'oidc_client_secret' => 'secret',
			)
		);

		$this->assertStringContainsString( 'data-status="oidc"', $html );
		$this->assertStringNotContainsString( '<details class="qrauth-psl-legacy" open>', $html );
	}

	/**
	 * A widget-only site is told to switch, and its legacy settings stay open.
	 */
	public function test_legacy_site_is_nudged_to_switch_and_keeps_widget_settings_open(): void {
		$html = $this->render(
			array(
				'client_id'     => 'legacy-client',
				'client_secret' => 'legacy-secret',
			)
		);

		$this->assertStringContainsString( 'data-status="legacy"', $html );
		$this->assertStringContainsString( '<details class="qrauth-psl-legacy" open>', $html );
		$this->assertStringContainsString( 'value="legacy-client"', $html );
	}

	/**
	 * The OIDC fields come before the legacy widget fields in the form.
	 */
	public function test_oidc_fields_come_before_legacy_fields(): void {
		$html = $this->render( array() );

		$this->assertLessThan(
			strpos( $html, 'name="qrauth_psl_settings[client_id]"' ),
			strpos( $html, 'name="qrauth_psl_settings[oidc_client_id]"' )
		);
	}
}
