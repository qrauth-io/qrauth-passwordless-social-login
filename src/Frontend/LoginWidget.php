<?php
/**
 * Renders the `<qrauth-login>` widget on wp-login.php.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Oidc\LoginFlow;
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

		if ( ! Options::is_configured() ) {
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

		if ( Options::uses_oidc() ) {
			self::render_oidc_button();
			return;
		}

		?>
		<qrauth-login
			tenant="<?php echo esc_attr( $options['client_id'] ); ?>"
			base-url="<?php echo esc_attr( $options['api_base_url'] ); ?>"
			display="<?php echo esc_attr( $display ); ?>"
			mode="<?php echo esc_attr( $mode ); ?>"
			scopes="<?php echo esc_attr( implode( ' ', $options['allowed_scopes'] ) ); ?>"
			redirect-uri="<?php echo esc_url( wp_login_url() ); ?>"
		></qrauth-login>
		<?php
	}

	/**
	 * The OIDC "Sign in with QRAuth" link. After sign-in the visitor returns
	 * to where they started: the login page's `redirect_to`, or the page the
	 * button sits on (shortcode, WooCommerce). LoginFlow validates both.
	 */
	public static function render_oidc_button(): void {
		$on_login_page = isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only destination, validated by LoginFlow.
		$redirect_to = $on_login_page
			? ( isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '' )
			: ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<a class="qrauth-psl-oidc-button" href="<?php echo esc_url( LoginFlow::start_url( $redirect_to ) ); ?>">
			<svg class="qrauth-psl-oidc-button__logo" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
				<path d="M48 24 H152 Q166 24 166 38 V108 Q166 156 100 182 Q34 156 34 108 V38 Q34 24 48 24 Z" fill="none" stroke="#fff" stroke-width="5"/>
				<g fill="#fff" opacity="0.2">
					<rect x="52" y="40" width="18" height="18" rx="3"/>
					<rect x="78" y="40" width="18" height="18" rx="3"/>
					<rect x="130" y="40" width="18" height="18" rx="3"/>
					<rect x="52" y="66" width="18" height="18" rx="3"/>
					<rect x="104" y="66" width="18" height="18" rx="3"/>
					<rect x="78" y="92" width="18" height="18" rx="3"/>
					<rect x="130" y="92" width="18" height="18" rx="3"/>
					<rect x="52" y="118" width="18" height="18" rx="3"/>
					<rect x="104" y="118" width="18" height="18" rx="3"/>
				</g>
				<path d="M62 104 L88 134 L144 64" fill="none" stroke="#fff" stroke-width="15" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<span><?php esc_html_e( 'Sign in with QRAuth', 'qrauth-passwordless-social-login' ); ?></span>
		</a>
		<?php
	}
}
