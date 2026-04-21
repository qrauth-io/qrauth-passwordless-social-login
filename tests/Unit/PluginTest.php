<?php
/**
 * Unit tests for the Plugin bootstrap singleton.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Plugin;

/**
 * Exercises the Plugin singleton and its default settings payload.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * Default settings include the expected keys and safe defaults.
	 */
	public function test_default_settings_shape(): void {
		$defaults = Plugin::default_settings();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'client_id', $defaults );
		$this->assertArrayHasKey( 'client_secret', $defaults );
		$this->assertSame( '', $defaults['client_secret'] );
		$this->assertArrayHasKey( 'tenant_url', $defaults );
		$this->assertSame( 'https://qrauth.io', $defaults['tenant_url'] );
		$this->assertArrayNotHasKey( 'base_url', $defaults );
		$this->assertArrayNotHasKey( 'api_base_url', $defaults );
		$this->assertSame( 'subscriber', $defaults['default_role'] );
		$this->assertFalse( $defaults['auto_provision'] );
		$this->assertSame( array( 'identity', 'email' ), $defaults['allowed_scopes'] );
	}

	/**
	 * The instance() accessor returns the same object on repeated calls.
	 */
	public function test_singleton_returns_same_instance(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}
}
