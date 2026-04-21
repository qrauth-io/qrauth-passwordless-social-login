<?php
/**
 * Enqueue the vendored web-components IIFE + the adapter on wp-login.php
 * and on any front-end page that actually renders the widget.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Handles script + style registration and enqueuing.
 *
 * Two hook entry points, same registered handles:
 *
 *  - {@see self::on_login_page()} (hooked to `login_enqueue_scripts`) —
 *    registers **and** enqueues the assets on wp-login.php, because the
 *    widget is always rendered there when the plugin is configured.
 *  - {@see self::on_frontend_page()} (hooked to `wp_enqueue_scripts`) —
 *    registers the assets on every front-end page but only enqueues them
 *    when a caller (shortcode, WooCommerce hook, Gutenberg block) flips
 *    the activation flag via {@see self::enqueue_for_widget()}. This
 *    keeps the ~65 KB IIFE off pages that don't actually render the
 *    widget.
 *
 * Supply-chain integrity for the vendored IIFE is verified at
 * **install/upgrade time** by `bin/fetch-web-components.mjs` (tarball
 * SHA-512 pinned in `package.json`). A runtime `integrity` attribute on
 * the served `<script>` tag is intentionally not emitted — the asset is
 * self-hosted and same-origin, so browser SRI would compare against a
 * different hash (IIFE + our vendored-by header) and add little value
 * beyond the build-time check. See `SPECS/BACKLOG.md`: pentest against
 * SRI-less load is scheduled before v0.2.0.
 *
 * @since 0.1.0
 */
final class AssetEnqueue {

	/**
	 * Script handle for the vendored web-components IIFE.
	 */
	public const HANDLE_COMPONENTS = 'qrauth-psl-components';

	/**
	 * Script handle for the adapter that bridges the widget to our REST route.
	 */
	public const HANDLE_ADAPTER = 'qrauth-psl-adapter';

	/**
	 * Style handle for the login-form widget wrapper.
	 */
	public const HANDLE_STYLE = 'qrauth-psl-login';

	/**
	 * Vendored bundle version (used as the `ver=` cache-buster).
	 *
	 * KEEP IN SYNC with `package.json` `qrauth.webComponentsVersion` — bump
	 * both together when upgrading `@qrauth/web-components`.
	 */
	public const BUNDLE_VERSION = '0.4.0';

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'on_login_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'on_frontend_page' ) );
	}

	/**
	 * `login_enqueue_scripts` handler — register + enqueue on wp-login.php.
	 *
	 * Short-circuits when the plugin is not yet configured (no client_id),
	 * and when neither of the login-form injection points is enabled —
	 * in both cases, loading the IIFE would be wasted bandwidth.
	 */
	public function on_login_page(): void {
		$options = Options::all();

		if ( '' === $options['client_id'] ) {
			return;
		}

		$has_login_widget = in_array( 'wp-login', $options['enabled_on'], true )
			|| in_array( 'register', $options['enabled_on'], true );

		if ( ! $has_login_widget ) {
			return;
		}

		self::register_assets();

		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_COMPONENTS );
		wp_enqueue_script( self::HANDLE_ADAPTER );
	}

	/**
	 * `wp_enqueue_scripts` handler — register only on front-end pages.
	 *
	 * Callers that actually render the widget (the shortcode today; the
	 * Gutenberg block + WooCommerce hooks to follow) activate the
	 * registered handles via {@see self::enqueue_for_widget()}. Without
	 * that call the handles stay registered-but-idle and WP doesn't
	 * output any `<script>` / `<link>` tags — zero bandwidth impact on
	 * pages that don't use the widget.
	 */
	public function on_frontend_page(): void {
		if ( '' === (string) Options::get( 'client_id' ) ) {
			return;
		}

		self::register_assets();
	}

	/**
	 * Activate the registered assets so WP emits them on the current page.
	 *
	 * Idempotent — safe to call multiple times in a single request (e.g.
	 * one post with two shortcodes, or a shortcode on a page that also
	 * has a WooCommerce login form). `wp_enqueue_*` deduplicates by
	 * handle.
	 *
	 * Callers must have run through `on_frontend_page()` first (i.e.
	 * they must be on `wp_enqueue_scripts` or later); otherwise the
	 * handles won't be registered yet. In practice this is always true
	 * for shortcode callbacks and WC hook callbacks.
	 */
	public static function enqueue_for_widget(): void {
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_COMPONENTS );
		wp_enqueue_script( self::HANDLE_ADAPTER );
	}

	/**
	 * Register the three handles + the inline `qrauthPsl` globals.
	 *
	 * Split out so both hook entry points can share the registration
	 * logic without duplicating the URLs, versions, and nonce payload.
	 * Safe to call repeatedly — `wp_register_*` is tolerant of re-calls
	 * with the same handle.
	 */
	private static function register_assets(): void {
		wp_register_style(
			self::HANDLE_STYLE,
			plugins_url( 'assets/css/qrauth-login.css', QRAUTH_PSL_FILE ),
			array(),
			QRAUTH_PSL_VERSION
		);

		wp_register_script(
			self::HANDLE_COMPONENTS,
			plugins_url( 'assets/js/qrauth-components.js', QRAUTH_PSL_FILE ),
			array(),
			self::BUNDLE_VERSION,
			true
		);

		wp_register_script(
			self::HANDLE_ADAPTER,
			plugins_url( 'assets/js/qrauth-adapter.js', QRAUTH_PSL_FILE ),
			array( self::HANDLE_COMPONENTS ),
			QRAUTH_PSL_VERSION,
			true
		);

		wp_add_inline_script(
			self::HANDLE_ADAPTER,
			'window.qrauthPsl=' . wp_json_encode(
				array(
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'restUrl' => esc_url_raw( rest_url() ),
				)
			) . ';',
			'before'
		);
	}
}
