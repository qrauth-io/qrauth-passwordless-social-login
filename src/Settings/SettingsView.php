<?php
/**
 * Admin settings page markup.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Settings;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Oidc\LoginFlow;
use QRAuth\PasswordlessSocialLogin\Support\Options;

/**
 * Renders the Settings → QRAuth admin page.
 *
 * Markup-only sibling of {@see Settings}. No side effects beyond emitting
 * HTML; all reads go through {@see Options} so defaults stay centralised.
 *
 * Layout, top to bottom: a status banner, the Sign in with QRAuth (OIDC)
 * setup guide and fields, the settings shared by both sign-in methods, and
 * the legacy embedded-widget fields folded inside a <details> element. Every
 * field stays inside the one form, so the option row and the sanitiser are
 * unchanged.
 *
 * @since 0.1.0
 */
final class SettingsView {

	/**
	 * Where site owners create a QRAuth account.
	 */
	private const SIGNUP_URL = 'https://qrauth.io/signup';

	/**
	 * Dashboard page where websites (OIDC clients) are added.
	 */
	private const WEBSITE_SIGN_IN_URL = 'https://qrauth.io/dashboard/sign-in-clients';

	/**
	 * Legacy dashboard page for the embedded widget's app credentials.
	 */
	private const LEGACY_APPS_URL = 'https://qrauth.io/dashboard/apps';

	/**
	 * Emit the admin settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = Options::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QRAuth — Passwordless & Social Login', 'qrauth-passwordless-social-login' ); ?></h1>

			<?php self::render_status_notice( $options ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>

				<?php self::render_oidc_section( $options ); ?>
				<?php self::render_access_section( $options ); ?>
				<?php self::render_surfaces_section( $options ); ?>
				<?php self::render_legacy_section( $options ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Banner that says which sign-in method is active and what to do next.
	 *
	 * @param array<string,mixed> $options Current plugin options.
	 */
	private static function render_status_notice( array $options ): void {
		if ( Options::uses_oidc() ) {
			?>
			<div class="notice notice-success inline qrauth-psl-status" data-status="oidc">
				<p>
					<strong><?php esc_html_e( 'Sign in with QRAuth is on.', 'qrauth-passwordless-social-login' ); ?></strong>
					<?php esc_html_e( 'Open your login page in a private window to try it.', 'qrauth-passwordless-social-login' ); ?>
					<a href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open login page', 'qrauth-passwordless-social-login' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		if ( '' !== $options['client_id'] ) {
			?>
			<div class="notice notice-warning inline qrauth-psl-status" data-status="legacy">
				<p>
					<strong><?php esc_html_e( 'You are using the older embedded widget.', 'qrauth-passwordless-social-login' ); ?></strong>
					<?php esc_html_e( 'It keeps working, but Sign in with QRAuth is simpler and more reliable on phones. Follow the steps below to switch; your users and their linked accounts stay as they are.', 'qrauth-passwordless-social-login' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-info inline qrauth-psl-status" data-status="unconfigured">
			<p>
				<strong><?php esc_html_e( 'QRAuth is not set up yet.', 'qrauth-passwordless-social-login' ); ?></strong>
				<?php esc_html_e( 'Follow the steps below. It takes about five minutes.', 'qrauth-passwordless-social-login' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sign in with QRAuth (OpenID Connect): numbered setup guide + the two fields.
	 *
	 * @param array<string,mixed> $options Current plugin options.
	 */
	private static function render_oidc_section( array $options ): void {
		?>
		<h2><?php esc_html_e( 'Sign in with QRAuth', 'qrauth-passwordless-social-login' ); ?></h2>
		<p>
			<?php esc_html_e( 'Adds a "Sign in with QRAuth" button to your login page. Visitors go to QRAuth, confirm it is them, and come straight back signed in. On a computer they scan a code with their phone; on a phone they confirm on the phone itself.', 'qrauth-passwordless-social-login' ); ?>
		</p>

		<ol class="qrauth-psl-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to the QRAuth sign-up page. */
					esc_html__( 'Create a free QRAuth account at %s (skip this if you have one).', 'qrauth-passwordless-social-login' ),
					'<a href="' . esc_url( self::SIGNUP_URL ) . '" target="_blank" rel="noopener noreferrer">qrauth.io</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: link to the Website Sign-in page of the QRAuth dashboard, 2: the "Add website" button label. */
					esc_html__( 'In the QRAuth dashboard, open %1$s and press %2$s.', 'qrauth-passwordless-social-login' ),
					'<a href="' . esc_url( self::WEBSITE_SIGN_IN_URL ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Website Sign-in', 'qrauth-passwordless-social-login' ) . '</strong></a>',
					'<strong>' . esc_html__( 'Add website', 'qrauth-passwordless-social-login' ) . '</strong>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Fill in the form:', 'qrauth-passwordless-social-login' ); ?>
				<ul class="qrauth-psl-substeps" style="list-style: disc; margin: 0.5em 0 0 1.5em;">
					<li><?php esc_html_e( 'Name: your site name. Visitors see it on the QRAuth sign-in page.', 'qrauth-passwordless-social-login' ); ?></li>
					<li>
						<?php esc_html_e( 'Sign-in return address: copy this exact address.', 'qrauth-passwordless-social-login' ); ?>
						<br />
						<input type="text" class="large-text code qrauth-psl-copy" readonly value="<?php echo esc_attr( LoginFlow::redirect_uri() ); ?>" aria-label="<?php esc_attr_e( 'Sign-in return address to copy', 'qrauth-passwordless-social-login' ); ?>" />
					</li>
					<li><?php esc_html_e( 'Kind of site: Website with a server.', 'qrauth-passwordless-social-login' ); ?></li>
					<li><?php esc_html_e( 'What your site can see: keep Name and Email address ticked. The plugin asks for both, and sign-in fails without them.', 'qrauth-passwordless-social-login' ); ?></li>
				</ul>
			</li>
			<li><?php esc_html_e( 'QRAuth then shows your Client ID and Client Secret. Copy both into the boxes below and save. The secret is shown only once; if you lose it, create a new one from the same page.', 'qrauth-passwordless-social-login' ); ?></li>
			<li><?php esc_html_e( 'Open your login page in a private window and try "Sign in with QRAuth".', 'qrauth-passwordless-social-login' ); ?></li>
		</ol>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="qrauth_psl_oidc_client_id"><?php esc_html_e( 'Client ID', 'qrauth-passwordless-social-login' ); ?></label>
				</th>
				<td>
					<input type="text" id="qrauth_psl_oidc_client_id" name="qrauth_psl_settings[oidc_client_id]" value="<?php echo esc_attr( $options['oidc_client_id'] ); ?>" class="regular-text code" autocomplete="off" spellcheck="false" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="qrauth_psl_oidc_client_secret"><?php esc_html_e( 'Client Secret', 'qrauth-passwordless-social-login' ); ?></label>
				</th>
				<td>
					<input type="password" id="qrauth_psl_oidc_client_secret" name="qrauth_psl_settings[oidc_client_secret]" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />
					<p class="description">
						<?php
						if ( '' !== $options['oidc_client_secret'] ) {
							esc_html_e( 'Saved. Leave blank to keep it, or paste a new one to replace it.', 'qrauth-passwordless-social-login' );
						} else {
							esc_html_e( 'Kept on your server only; never sent to visitors\' browsers.', 'qrauth-passwordless-social-login' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>

		<details class="qrauth-psl-help">
			<summary><?php esc_html_e( 'Sign-in not working?', 'qrauth-passwordless-social-login' ); ?></summary>
			<ul>
				<li><?php esc_html_e( 'QRAuth says "This sign-in link looks wrong": the return address saved in the QRAuth dashboard does not match the one above exactly (check https, www, and the part after the question mark), or the Client ID has a typo.', 'qrauth-passwordless-social-login' ); ?></li>
				<li><?php esc_html_e( 'Your login page says "QRAuth sign-in failed": usually Name or Email address is not ticked for your website in the QRAuth dashboard, or the Client Secret was replaced there. Tick both, or paste the current secret above.', 'qrauth-passwordless-social-login' ); ?></li>
				<li><?php esc_html_e( '"There is no account on this site for your QRAuth identity": no user here has that email address and creating new accounts is off (see "Who can sign in" below).', 'qrauth-passwordless-social-login' ); ?></li>
				<li><?php esc_html_e( '"This account has elevated permissions": Administrators and Editors link their account once from their own profile page, while signed in with their password.', 'qrauth-passwordless-social-login' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: the WP_DEBUG_LOG constant. */
						esc_html__( 'Still stuck? Turn on %s; each failed sign-in then writes its exact reason to the debug log, in lines starting with [qrauth-psl].', 'qrauth-passwordless-social-login' ),
						'<code>WP_DEBUG_LOG</code>'
					);
					?>
				</li>
			</ul>
		</details>
		<?php
	}

	/**
	 * Settings that apply to both sign-in methods: account creation and role.
	 *
	 * @param array<string,mixed> $options Current plugin options.
	 */
	private static function render_access_section( array $options ): void {
		?>
		<h2><?php esc_html_e( 'Who can sign in', 'qrauth-passwordless-social-login' ); ?></h2>
		<p><?php esc_html_e( 'Existing users sign in to their own account when QRAuth confirms the same email address. Administrators and Editors are never matched by email: they link their account once from their profile page.', 'qrauth-passwordless-social-login' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'New visitors', 'qrauth-passwordless-social-login' ); ?>
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
							<?php esc_html_e( 'Create a WordPress account for people who sign in with QRAuth and do not have one yet.', 'qrauth-passwordless-social-login' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default, so only people who already have an account can sign in. Turn it on for open registration, for example a shop or a members area.', 'qrauth-passwordless-social-login' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="qrauth_psl_default_role">
						<?php esc_html_e( 'Role for new accounts', 'qrauth-passwordless-social-login' ); ?>
					</label>
				</th>
				<td>
					<select id="qrauth_psl_default_role" name="qrauth_psl_settings[default_role]">
						<option value="subscriber" selected>
							<?php esc_html_e( 'Subscriber', 'qrauth-passwordless-social-login' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'New accounts are always created as Subscriber. To give someone more access, change their role under Users → All Users after their first sign-in.', 'qrauth-passwordless-social-login' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Where the sign-in button is shown (applies to both sign-in methods).
	 *
	 * @param array<string,mixed> $options Current plugin options.
	 */
	private static function render_surfaces_section( array $options ): void {
		?>
		<h2><?php esc_html_e( 'Where the button appears', 'qrauth-passwordless-social-login' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Show the button on', 'qrauth-passwordless-social-login' ); ?>
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
							<?php esc_html_e( 'WordPress login page (wp-login.php)', 'qrauth-passwordless-social-login' ); ?>
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
							<?php esc_html_e( 'User profile (link or unlink QRAuth)', 'qrauth-passwordless-social-login' ); ?>
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
								esc_html__( 'Any page or post, with the shortcode %s', 'qrauth-passwordless-social-login' ),
								'<code>[qrauth_login]</code>'
							);
							?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: 1, 2, 3: example shortcode invocations wrapped in <code>. */
								esc_html__( 'Place %1$s in any page, post, or widget area. Optional settings for the older widget: %2$s, %3$s.', 'qrauth-passwordless-social-login' ),
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
								<?php esc_html_e( 'WooCommerce My Account and checkout login', 'qrauth-passwordless-social-login' ); ?>
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
								<?php esc_html_e( 'The button appears inside WooCommerce\'s own login and register forms on /my-account/ (and on the checkout page when guest checkout is disabled).', 'qrauth-passwordless-social-login' ); ?>
							</p>
						<?php endif; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Legacy embedded-widget settings, folded away. Open by default only for
	 * sites that still run the widget, so its settings stay one click away.
	 *
	 * @param array<string,mixed> $options Current plugin options.
	 */
	private static function render_legacy_section( array $options ): void {
		$is_legacy_active = ! Options::uses_oidc() && '' !== $options['client_id'];
		// wp_login_url() is filterable, so security plugins (WPS Hide Login,
		// etc.) that move the default login URL keep working. Multilingual
		// plugins (Polylang, WPML, Weglot) language-prefix home_url but not the
		// site_url base, so one registered URL works across all languages.
		$login_url = wp_login_url();
		?>
		<details class="qrauth-psl-legacy"<?php echo $is_legacy_active ? ' open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'Older embedded widget (legacy)', 'qrauth-passwordless-social-login' ); ?></strong></summary>
			<p><?php esc_html_e( 'Only for sites set up before Sign in with QRAuth existed. It is used only while the Client ID and Client Secret above are empty. New sites do not need anything here.', 'qrauth-passwordless-social-login' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: the login URL to register, 2: link to the legacy apps page of the QRAuth dashboard. */
					esc_html__( 'For sign-in on phones, the widget needs this address in your QRAuth app\'s redirect list: %1$s (%2$s).', 'qrauth-passwordless-social-login' ),
					'<code>' . esc_html( $login_url ) . '</code>',
					'<a href="' . esc_url( self::LEGACY_APPS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'QRAuth apps', 'qrauth-passwordless-social-login' ) . '</a>'
				);
				?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="qrauth_psl_client_id">
							<?php esc_html_e( 'Widget Client ID', 'qrauth-passwordless-social-login' ); ?>
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
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qrauth_psl_client_secret">
							<?php esc_html_e( 'Widget Client Secret', 'qrauth-passwordless-social-login' ); ?>
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
								esc_html_e( 'Saved. Leave blank to keep it, or paste a new one to replace it.', 'qrauth-passwordless-social-login' );
							} else {
								esc_html_e( 'Kept on your server only; never sent to visitors\' browsers.', 'qrauth-passwordless-social-login' );
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
						<?php esc_html_e( 'Widget scopes', 'qrauth-passwordless-social-login' ); ?>
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
								<?php esc_html_e( 'QRAuth asks the user to consent to each scope on first approval. Sign in with QRAuth ignores this setting.', 'qrauth-passwordless-social-login' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</table>
		</details>
		<?php
	}
}
