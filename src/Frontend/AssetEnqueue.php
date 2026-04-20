<?php
/**
 * Enqueue the vendored web-components IIFE + the adapter on wp-login.php.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Handles wp-login.php asset loading.
 *
 * Two scripts are wired up:
 *
 *  - {@see self::HANDLE_COMPONENTS} — the vendored `@qrauth/web-components`
 *    IIFE, served from the plugin's own `assets/js/` (never from a CDN).
 *  - {@see self::HANDLE_ADAPTER} — our tiny dependency-free bridge that
 *    receives `qrauth:authenticated` events from the widget and POSTs
 *    them to the REST route.
 *
 * The `window.qrauthPsl` global is emitted inline before the adapter so
 * the adapter sees `{ nonce, restUrl }` when it runs.
 *
 * Supply-chain integrity is verified at **install/upgrade time** by
 * `bin/fetch-web-components.mjs`: the npm-tarball SHA-512 is compared
 * against `package.json` `qrauth.webComponentsIntegrity` before the
 * IIFE is extracted. A runtime `integrity` attribute on the served
 * `<script>` tag is intentionally not emitted — the asset is
 * self-hosted and same-origin, so browser SRI would compare against a
 * different hash (IIFE + our vendored-by header) and add little value
 * beyond the build-time check. See SPECS/BACKLOG.md — pentest against
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
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the components IIFE + adapter, with the `qrauthPsl` globals.
	 *
	 * Short-circuits when the plugin is not yet configured (no client_id),
	 * and when neither of the login-form injection points is enabled — in
	 * both cases, loading the IIFE would be wasted bandwidth.
	 */
	public function enqueue(): void {
		$options = Options::all();

		if ( '' === $options['client_id'] ) {
			return;
		}

		$has_login_widget = in_array( 'wp-login', $options['enabled_on'], true )
			|| in_array( 'register', $options['enabled_on'], true );

		if ( ! $has_login_widget ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE_STYLE,
			plugins_url( 'assets/css/qrauth-login.css', QRAUTH_PSL_FILE ),
			array(),
			QRAUTH_PSL_VERSION
		);

		wp_enqueue_script(
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
		wp_enqueue_script( self::HANDLE_ADAPTER );
	}
}
