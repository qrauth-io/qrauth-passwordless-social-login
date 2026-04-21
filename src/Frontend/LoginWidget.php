<?php
/**
 * Renders the `<qrauth-login>` widget on wp-login.php.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Injects the web-components login widget on wp-login.php forms.
 *
 * Rendering is gated on two conditions, both of which must be true:
 *
 *  1. `client_id` is configured — otherwise the widget has nothing to
 *     authenticate against and we stay silent.
 *  2. The injection point (`wp-login` or `register`) is listed in the
 *     `enabled_on` setting.
 *
 * The widget dispatches `qrauth:authenticated` on successful approval;
 * {@see AssetEnqueue} wires the adapter that forwards the event to our
 * REST route.
 *
 * @since 0.1.0
 */
final class LoginWidget {

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'login_form', array( $this, 'render_button' ) );
		add_action( 'register_form', array( $this, 'render_inline' ) );
	}

	/**
	 * Emit the button-mode widget below the default login form fields.
	 */
	public function render_button(): void {
		if ( ! self::should_render( 'wp-login' ) ) {
			return;
		}
		self::emit( 'button', 'login' );
	}

	/**
	 * Emit the inline-mode widget at the top of the registration form.
	 */
	public function render_inline(): void {
		if ( ! self::should_render( 'register' ) ) {
			return;
		}
		self::emit( 'inline', 'register' );
	}

	/**
	 * Decide whether the widget should render at the given injection point.
	 *
	 * @param string $injection_point One of `wp-login`, `register`, `profile`.
	 * @return bool
	 */
	public static function should_render( string $injection_point ): bool {
		$options = Options::all();

		if ( '' === $options['client_id'] ) {
			return false;
		}

		return in_array( $injection_point, $options['enabled_on'], true );
	}

	/**
	 * Print the `<qrauth-login>` element plus the "— or —" divider above it.
	 *
	 * Used by the wp-login.php / registration-form injection sites where
	 * the widget sits directly below (or beside) the default WP form.
	 *
	 * Callers that render the widget outside of a login form context
	 * (shortcode, future Gutenberg block, WooCommerce flows) should use
	 * {@see self::render_widget()} instead — the divider makes no sense
	 * when there is nothing visually "above" it to separate from.
	 *
	 * @param string $display `button` or `inline`.
	 * @param string $mode    `login` or `register` — passed through to the widget.
	 */
	public static function emit( string $display, string $mode ): void {
		?>
		<div class="qrauth-psl-widget qrauth-psl-widget--<?php echo esc_attr( $display ); ?>">
			<p class="qrauth-psl-widget__divider" aria-hidden="true">
				<span>
					<?php
					echo esc_html_x(
						'— or —',
						'Separator between the WordPress login form and the QRAuth widget',
						'qrauth-passwordless-social-login'
					);
					?>
				</span>
			</p>
			<?php self::render_widget( $display, $mode ); ?>
		</div>
		<?php
	}

	/**
	 * Print just the `<qrauth-login>` custom element, with no divider or
	 * wrapper beyond the standard widget class.
	 *
	 * Shared rendering path for every non-login surface the widget
	 * can appear on (shortcode today, Gutenberg block and WooCommerce
	 * hooks to follow). All of them need the same element + attributes;
	 * only {@see self::emit()} adds the "— or —" divider above.
	 *
	 * @param string $display `button` or `inline`.
	 * @param string $mode    `login` or `register`.
	 */
	public static function render_widget( string $display, string $mode ): void {
		$options = Options::all();

		?>
		<qrauth-login
			tenant="<?php echo esc_attr( $options['client_id'] ); ?>"
			base-url="<?php echo esc_attr( $options['api_base_url'] ); ?>"
			display="<?php echo esc_attr( $display ); ?>"
			mode="<?php echo esc_attr( $mode ); ?>"
			scopes="<?php echo esc_attr( implode( ' ', $options['allowed_scopes'] ) ); ?>"
			redirect-uri="<?php echo esc_url( site_url( '/wp-login.php' ) ); ?>"
		></qrauth-login>
		<?php
	}
}
