<?php
/**
 * Admin settings page markup.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Settings;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Renders the Settings → QRAuth admin page.
 *
 * Markup-only sibling of {@see Settings}. No side effects beyond emitting
 * HTML; all reads go through {@see Options} so defaults stay centralised.
 *
 * @since 0.1.0
 */
final class SettingsView {

	/**
	 * Emit the admin settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options       = Options::all();
		$get_client_id = 'https://qrauth.io/dashboard/apps/create';
		// site_url (not home_url) — multilingual plugins (Polylang, WPML, Weglot)
		// language-prefix home_url but not site_url, which is the canonical
		// WP admin-infrastructure URL. Using site_url here means the admin
		// registers one URL in the QRAuth dashboard that works across all
		// languages, not one per language.
		$login_url = site_url( '/wp-login.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QRAuth — Passwordless & Social Login', 'qrauth-passwordless-social-login' ); ?></h1>

			<?php if ( '' === $options['client_id'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'QRAuth is not yet configured.', 'qrauth-passwordless-social-login' ); ?></strong>
						<?php esc_html_e( 'Paste your Client ID below to enable the login widget.', 'qrauth-passwordless-social-login' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<a href="<?php echo esc_url( $get_client_id ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get your Client ID', 'qrauth-passwordless-social-login' ); ?>
				</a>
			</p>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Enable sign-in on phones', 'qrauth-passwordless-social-login' ); ?></strong>
				</p>
				<p>
					<?php
					printf(
						/* translators: 1: the login URL admins should register, 2: the path in the QRAuth dashboard where redirect URLs are managed. */
						esc_html__( 'Add this URL to your QRAuth app\'s redirect allowlist so visitors who approve on their phone are sent back here: %1$s. Set it in the dashboard under %2$s.', 'qrauth-passwordless-social-login' ),
						'<code>' . esc_html( $login_url ) . '</code>',
						'<strong>' . esc_html__( 'Apps → your app → Redirect URLs', 'qrauth-passwordless-social-login' ) . '</strong>'
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Desktop sign-in works without this. Only phones (same-device approval) need the redirect URL to be registered.', 'qrauth-passwordless-social-login' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="qrauth_psl_client_id">
								<?php esc_html_e( 'Client ID', 'qrauth-passwordless-social-login' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="qrauth_psl_client_id"
								name="qrauth_psl_settings[client_id]"
								value="<?php echo esc_attr( $options['client_id'] ); ?>"
								class="regular-text code"
								autocomplete="off"
								spellcheck="false"
							/>
							<p class="description">
								<?php esc_html_e( 'The public Client ID from your QRAuth app. Safe to expose to the browser.', 'qrauth-passwordless-social-login' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="qrauth_psl_client_secret">
								<?php esc_html_e( 'Client Secret', 'qrauth-passwordless-social-login' ); ?>
							</label>
						</th>
						<td>
							<input
								type="password"
								id="qrauth_psl_client_secret"
								name="qrauth_psl_settings[client_secret]"
								value=""
								class="regular-text code"
								autocomplete="new-password"
								spellcheck="false"
							/>
							<p class="description">
								<?php
								if ( '' !== $options['client_secret'] ) {
									esc_html_e( 'Configured — leave blank to keep the current value. Enter a new value to overwrite.', 'qrauth-passwordless-social-login' );
								} else {
									esc_html_e( 'The private Client Secret from your QRAuth app. Used server-side only; never sent to the browser.', 'qrauth-passwordless-social-login' );
								}
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="qrauth_psl_tenant_url">
								<?php esc_html_e( 'Tenant URL', 'qrauth-passwordless-social-login' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="qrauth_psl_tenant_url"
								name="qrauth_psl_settings[tenant_url]"
								value="<?php echo esc_attr( $options['tenant_url'] ); ?>"
								class="regular-text code"
							/>
							<p class="description">
								<?php
								printf(
									/* translators: 1: the default tenant URL, 2: the localhost URL allowed for local development. */
									esc_html__( 'Leave this set to %1$s unless your organisation runs a self-hosted QRAuth instance. Only https:// URLs are accepted (%2$s is allowed for local development).', 'qrauth-passwordless-social-login' ),
									'<code>https://qrauth.io</code>',
									'<code>http://localhost</code>'
								);
								?>
							</p>
							<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: the WordPress REST URL this site exposes to the browser widget. Only shown when WP_DEBUG is on. */
										esc_html__( 'Debug — browser widget talks to: %s', 'qrauth-passwordless-social-login' ),
										'<code>' . esc_html( $options['api_base_url'] ) . '</code>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto-provision new users', 'qrauth-passwordless-social-login' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[auto_provision]"
										value="1"
										<?php checked( $options['auto_provision'], true ); ?>
									/>
									<?php esc_html_e( 'Create a WordPress account on first successful QRAuth login.', 'qrauth-passwordless-social-login' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Off by default. Enable only for sites where you want open registration through QRAuth — otherwise only users who already have a WordPress account can sign in.', 'qrauth-passwordless-social-login' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="qrauth_psl_default_role">
								<?php esc_html_e( 'Default role for new users', 'qrauth-passwordless-social-login' ); ?>
							</label>
						</th>
						<td>
							<select id="qrauth_psl_default_role" name="qrauth_psl_settings[default_role]">
								<option value="subscriber" <?php selected( $options['default_role'], 'subscriber' ); ?>>
									<?php esc_html_e( 'Subscriber', 'qrauth-passwordless-social-login' ); ?>
								</option>
								<option value="contributor" <?php selected( $options['default_role'], 'contributor' ); ?>>
									<?php esc_html_e( 'Contributor', 'qrauth-passwordless-social-login' ); ?>
								</option>
								<option value="author" <?php selected( $options['default_role'], 'author' ); ?>>
									<?php esc_html_e( 'Author', 'qrauth-passwordless-social-login' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Applied only when Auto-provision is on. Editor and Administrator are deliberately unavailable.', 'qrauth-passwordless-social-login' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Allowed scopes', 'qrauth-passwordless-social-login' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" checked disabled />
									<code>identity</code>
									<span class="description"><?php esc_html_e( '(always required)', 'qrauth-passwordless-social-login' ); ?></span>
								</label>
								<input type="hidden" name="qrauth_psl_settings[allowed_scopes][]" value="identity" />
								<br />
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[allowed_scopes][]"
										value="email"
										<?php checked( in_array( 'email', $options['allowed_scopes'], true ), true ); ?>
									/>
									<code>email</code>
								</label>
								<br />
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[allowed_scopes][]"
										value="organization"
										<?php checked( in_array( 'organization', $options['allowed_scopes'], true ), true ); ?>
									/>
									<code>organization</code>
								</label>
								<p class="description">
									<?php esc_html_e( 'QRAuth asks the user to consent to each scope on first approval.', 'qrauth-passwordless-social-login' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Show widget on', 'qrauth-passwordless-social-login' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[enabled_on][]"
										value="wp-login"
										<?php checked( in_array( 'wp-login', $options['enabled_on'], true ), true ); ?>
									/>
									<?php esc_html_e( 'WordPress login form (wp-login.php)', 'qrauth-passwordless-social-login' ); ?>
								</label>
								<br />
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[enabled_on][]"
										value="register"
										<?php checked( in_array( 'register', $options['enabled_on'], true ), true ); ?>
									/>
									<?php esc_html_e( 'Registration form', 'qrauth-passwordless-social-login' ); ?>
								</label>
								<br />
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[enabled_on][]"
										value="profile"
										<?php checked( in_array( 'profile', $options['enabled_on'], true ), true ); ?>
									/>
									<?php esc_html_e( 'User profile (link / unlink account)', 'qrauth-passwordless-social-login' ); ?>
								</label>
								<br />
								<label>
									<input
										type="checkbox"
										name="qrauth_psl_settings[enabled_on][]"
										value="shortcode"
										<?php checked( in_array( 'shortcode', $options['enabled_on'], true ), true ); ?>
									/>
									<?php
									printf(
										/* translators: %s: the shortcode tag, wrapped in <code>. */
										esc_html__( 'Anywhere via shortcode — %s', 'qrauth-passwordless-social-login' ),
										'<code>[qrauth_login]</code>'
									);
									?>
								</label>
								<p class="description">
									<?php
									printf(
										/* translators: 1, 2, 3: example shortcode invocations wrapped in <code>. */
										esc_html__( 'Place %1$s in any page, post, or widget area. Attributes: %2$s, %3$s.', 'qrauth-passwordless-social-login' ),
										'<code>[qrauth_login]</code>',
										'<code>display="inline|button"</code>',
										'<code>mode="login|register"</code>'
									);
									?>
								</p>

								<?php if ( class_exists( 'WooCommerce' ) ) : ?>
									<br />
									<label>
										<input
											type="checkbox"
											name="qrauth_psl_settings[enabled_on][]"
											value="woocommerce-account"
											<?php checked( in_array( 'woocommerce-account', $options['enabled_on'], true ), true ); ?>
										/>
										<?php esc_html_e( 'WooCommerce My Account + checkout login', 'qrauth-passwordless-social-login' ); ?>
									</label>
									<br />
									<label>
										<input
											type="checkbox"
											name="qrauth_psl_settings[enabled_on][]"
											value="woocommerce-register"
											<?php checked( in_array( 'woocommerce-register', $options['enabled_on'], true ), true ); ?>
										/>
										<?php esc_html_e( 'WooCommerce registration form', 'qrauth-passwordless-social-login' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Widget appears inside WooCommerce\'s own login and register forms on /my-account/ (and on the checkout page when guest checkout is disabled).', 'qrauth-passwordless-social-login' ); ?>
									</p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
