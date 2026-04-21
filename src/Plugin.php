<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Frontend\AssetEnqueue;
use QRAuth\PasswordlessSocialLogin\Frontend\LoginWidget;
use QRAuth\PasswordlessSocialLogin\Frontend\ProfileFields;
use QRAuth\PasswordlessSocialLogin\Rest\AuthSessionProxyController;
use QRAuth\PasswordlessSocialLogin\Rest\RestController;
use QRAuth\PasswordlessSocialLogin\Settings\Settings;
use QRAuth\PasswordlessSocialLogin\Support\Options;

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
	 * Admin settings controller.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Asset enqueuer for wp-login.php.
	 *
	 * @var AssetEnqueue|null
	 */
	private ?AssetEnqueue $assets = null;

	/**
	 * Widget renderer for wp-login.php.
	 *
	 * @var LoginWidget|null
	 */
	private ?LoginWidget $login_widget = null;

	/**
	 * REST controller for the verify route.
	 *
	 * @var RestController|null
	 */
	private ?RestController $rest = null;

	/**
	 * REST controller that proxies QRAuth auth-session endpoints so the
	 * browser widget never crosses a CORS boundary.
	 *
	 * @var AuthSessionProxyController|null
	 */
	private ?AuthSessionProxyController $proxy = null;

	/**
	 * Profile-page QRAuth section + unlink handler.
	 *
	 * @var ProfileFields|null
	 */
	private ?ProfileFields $profile = null;

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

		$this->settings = new Settings();
		$this->settings->boot();

		$this->assets = new AssetEnqueue();
		$this->assets->boot();

		$this->login_widget = new LoginWidget();
		$this->login_widget->boot();

		$this->rest = new RestController();
		$this->rest->boot();

		$this->proxy = new AuthSessionProxyController();
		$this->proxy->boot();

		$this->profile = new ProfileFields();
		$this->profile->boot();
	}

	/**
	 * Seed default settings on first activation, and migrate pre-0.2
	 * installs forward (base_url → tenant_url, backfill client_secret).
	 */
	public function on_activate(): void {
		if ( false === get_option( Options::OPTION_NAME ) ) {
			add_option( Options::OPTION_NAME, self::default_settings() );
			return;
		}

		Options::migrate();
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
	 * Delegates to {@see Options::defaults()} so there's a single source
	 * of truth for the option shape across the plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return Options::defaults();
	}
}
