<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin;

/**
 * Wires WordPress hooks and holds references to service objects.
 *
 * Do not put business logic here — delegate to classes under
 * QRAuth\PasswordlessSocialLogin\<Module>\.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Return the shared plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use Plugin::instance().
	 */
	private function __construct() {}

	/**
	 * Register all WordPress hooks this plugin needs.
	 *
	 * Called once, from the root bootstrap file.
	 */
	public function boot(): void {
		register_activation_hook( QRAUTH_PSL_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( QRAUTH_PSL_FILE, array( $this, 'on_deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Seed default settings on first activation.
	 */
	public function on_activate(): void {
		if ( false === get_option( 'qrauth_psl_settings' ) ) {
			add_option( 'qrauth_psl_settings', self::default_settings() );
		}
	}

	/**
	 * Deactivation is a no-op — settings persist so reactivation restores state.
	 */
	public function on_deactivate(): void {
		// Intentional no-op.
	}

	/**
	 * Load the plugin text domain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'qrauth-passwordless-social-login',
			false,
			dirname( plugin_basename( QRAUTH_PSL_FILE ) ) . '/languages'
		);
	}

	/**
	 * Default settings seeded on first activation.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'client_id'      => '',
			'base_url'       => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register' ),
		);
	}
}
