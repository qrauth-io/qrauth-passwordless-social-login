<?php
/**
 * `[qrauth_login]` shortcode — render the widget on arbitrary front-end
 * pages (theme-managed login pages, custom landing pages, etc.).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Registers and renders the `[qrauth_login]` shortcode.
 *
 * Usage:
 *
 *   [qrauth_login]                              — inline QR, login mode
 *   [qrauth_login display="button"]             — modal-button variant
 *   [qrauth_login mode="register"]              — flips to sign-up copy
 *   [qrauth_login display="inline" mode="login"]
 *
 * Gating:
 *
 *  - Plugin must have a `client_id` configured (same as every other
 *    widget injection point).
 *  - Site admin must have `shortcode` in `enabled_on` (off by default —
 *    opt-in so activating the plugin never injects a widget on a page
 *    the admin didn't explicitly mark up).
 *  - Shortcode must actually run for its side effects to take hold
 *    (asset enqueue, global `qrauthPsl` nonce + REST URL).
 *
 * @since 0.1.6
 */
final class Shortcode {

	/**
	 * Shortcode tag — `[qrauth_login ...]`.
	 */
	public const TAG = 'qrauth_login';

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback — returns the widget markup, or an empty string
	 * when gating conditions fail.
	 *
	 * Returns (not echoes) per WP's shortcode contract; a shortcode that
	 * echoes directly puts its output at the top of `the_content()`
	 * instead of wherever the tag appeared.
	 *
	 * @param array<string,string>|string $raw_atts Raw attributes from the shortcode.
	 * @return string
	 */
	public function render( $raw_atts = array() ): string {
		$options = Options::all();

		if ( '' === $options['client_id'] ) {
			return '';
		}

		if ( ! in_array( 'shortcode', $options['enabled_on'], true ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'display' => 'inline',
				'mode'    => 'login',
			),
			is_array( $raw_atts ) ? $raw_atts : array(),
			self::TAG
		);

		$display = in_array( $atts['display'], array( 'inline', 'button' ), true )
			? (string) $atts['display']
			: 'inline';

		$mode = in_array( $atts['mode'], array( 'login', 'register' ), true )
			? (string) $atts['mode']
			: 'login';

		// Only load the ~65 KB IIFE on pages that actually render the
		// widget. `AssetEnqueue::on_frontend_page()` has already
		// registered the handles on `wp_enqueue_scripts`; this call
		// activates them so WP emits them in the footer.
		AssetEnqueue::enqueue_for_widget();

		ob_start();
		?>
		<div class="qrauth-psl-widget qrauth-psl-widget--<?php echo esc_attr( $display ); ?>">
			<?php LoginWidget::render_widget( $display, $mode ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
