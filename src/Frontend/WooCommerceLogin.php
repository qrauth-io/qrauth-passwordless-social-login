<?php
/**
 * WooCommerce sign-in integration — hooks the widget into WC's My Account
 * login form (which also fronts the checkout sign-in step) and WC's
 * registration form.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Renders `<qrauth-login>` on WooCommerce's login + register forms.
 *
 * WC exposes four template hooks around its core forms:
 *
 *   - `woocommerce_login_form`      — inside the form, before username field
 *   - `woocommerce_login_form_end`  — inside the form, after fields + remember-me, before submit
 *   - `woocommerce_register_form`   — inside register, before email field
 *   - `woocommerce_register_form_end` — inside register, after fields, before submit
 *
 * We bind to the `_end` variants so the widget appears after WC's fields
 * but still inside the form container — matches where "Sign in with
 * Google / Facebook / …" buttons conventionally go in WC themes.
 *
 * Gating:
 *
 *  - WooCommerce must be active (`class_exists('WooCommerce')`). The
 *    plugin must boot cleanly on non-WC sites, so the check is runtime,
 *    not require'd at file load.
 *  - `client_id` must be configured (same gate as every widget surface).
 *  - `enabled_on` must contain the relevant value
 *    (`woocommerce-account` for the login form; `woocommerce-register`
 *    for the register form). Admins opt in per-surface.
 *
 * The WC login template is also what renders on the checkout page when
 * guest checkout is disabled and a returning customer needs to sign in,
 * so hooking `woocommerce_login_form_end` covers both My Account and
 * checkout with a single binding.
 *
 * @since 0.1.7
 */
final class WooCommerceLogin {

	/**
	 * Register WordPress hooks.
	 *
	 * The WC presence check runs at render time (inside the hook
	 * callbacks), not here, so the plugin stays bootable even when WC
	 * isn't installed. `add_action` itself is free — the hook just
	 * never fires on a non-WC site.
	 */
	public function boot(): void {
		add_action( 'woocommerce_login_form_end', array( $this, 'render_login' ) );
		add_action( 'woocommerce_register_form_end', array( $this, 'render_register' ) );
	}

	/**
	 * Render the widget inside WC's My Account / checkout login form.
	 */
	public function render_login(): void {
		if ( ! self::should_render( 'woocommerce-account' ) ) {
			return;
		}

		self::emit_widget( 'inline', 'login' );
	}

	/**
	 * Render the widget inside WC's My Account register form.
	 */
	public function render_register(): void {
		if ( ! self::should_render( 'woocommerce-register' ) ) {
			return;
		}

		self::emit_widget( 'inline', 'register' );
	}

	/**
	 * Shared gating check — WC present, plugin configured, surface enabled.
	 *
	 * @param string $enabled_on_value Which `enabled_on` allowlist entry gates this surface.
	 */
	public static function should_render( string $enabled_on_value ): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		$options = Options::all();

		if ( '' === $options['client_id'] ) {
			return false;
		}

		return in_array( $enabled_on_value, $options['enabled_on'], true );
	}

	/**
	 * Emit the widget with the same wrapper + asset-activation sequence
	 * the shortcode uses. No "— or —" divider; WC's form provides its
	 * own visual separation.
	 *
	 * @param string $display `inline` or `button`.
	 * @param string $mode    `login` or `register`.
	 */
	private static function emit_widget( string $display, string $mode ): void {
		AssetEnqueue::enqueue_for_widget();

		?>
		<div class="qrauth-psl-widget qrauth-psl-widget--<?php echo esc_attr( $display ); ?> qrauth-psl-widget--woocommerce">
			<?php LoginWidget::render_widget( $display, $mode ); ?>
		</div>
		<?php
	}
}
