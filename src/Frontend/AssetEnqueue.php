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
 *    Attached via Subresource Integrity so a future tarball corruption
 *    would fail-closed instead of executing unverified code.
 *  - {@see self::HANDLE_ADAPTER} — our tiny dependency-free bridge that
 *    receives `qrauth:authenticated` events from the widget and POSTs
 *    them to the REST route (landed in P3).
 *
 * The `window.qrauthPsl` global is emitted inline before the adapter so
 * the adapter sees `{ nonce, restUrl }` when it runs.
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
	 * Vendored bundle version.
	 *
	 * KEEP IN SYNC with `package.json` `qrauth.webComponentsVersion` — bump
	 * both together when upgrading `@qrauth/web-components`. The value is
	 * public (ships in the plugin) and safe to hard-code in PHP.
	 */
	public const BUNDLE_VERSION = '0.4.0';

	/**
	 * Vendored bundle sha512 SRI.
	 *
	 * KEEP IN SYNC with `package.json` `qrauth.webComponentsIntegrity`.
	 */
	public const BUNDLE_SRI = 'sha512-D3DAarUL7Vx1FB95pyNsLbpeXJsxJP9HnGI+enMCPgpkFBQHiR/iyOphEEzAc1RN5w2eYDxBMHIln/U6SDLv4A==';

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 2 );
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

		wp_enqueue_script(
			self::HANDLE_COMPONENTS,
			plugins_url( 'assets/js/qrauth-components.js', QRAUTH_PSL_FILE ),
			array(),
			self::BUNDLE_VERSION,
			true
		);
		wp_script_add_data( self::HANDLE_COMPONENTS, 'integrity', self::BUNDLE_SRI );
		wp_script_add_data( self::HANDLE_COMPONENTS, 'crossorigin', 'anonymous' );

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

	/**
	 * Inject `integrity` + `crossorigin` attributes on the components IIFE tag.
	 *
	 * WordPress core's script_loader doesn't emit these from
	 * `wp_script_add_data()` by default (the keys are stored but only some
	 * are rendered), so we splice them in via this filter.
	 *
	 * @param string $tag    The `<script>` tag for the script being enqueued.
	 * @param string $handle The script handle.
	 * @return string
	 */
	public function filter_script_tag( string $tag, string $handle ): string {
		if ( self::HANDLE_COMPONENTS !== $handle ) {
			return $tag;
		}

		$scripts     = wp_scripts();
		$integrity   = $scripts ? $scripts->get_data( $handle, 'integrity' ) : '';
		$crossorigin = $scripts ? $scripts->get_data( $handle, 'crossorigin' ) : '';

		if ( ! is_string( $integrity ) || '' === $integrity ) {
			return $tag;
		}

		$attrs = sprintf( ' integrity="%s"', esc_attr( $integrity ) );
		if ( is_string( $crossorigin ) && '' !== $crossorigin ) {
			$attrs .= sprintf( ' crossorigin="%s"', esc_attr( $crossorigin ) );
		}

		$patched = preg_replace( '/(<script\b)/', '$1' . $attrs, $tag, 1 );
		return is_string( $patched ) ? $patched : $tag;
	}
}
