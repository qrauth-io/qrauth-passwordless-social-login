<?php
/**
 * QRAuth section + unlink handler on the user-profile admin page.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Frontend;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserMetaKeys;
use WP_User;

/**
 * Renders the QRAuth section on `wp-admin/profile.php` (own profile) and
 * `wp-admin/user-edit.php` (admin editing another user), plus handles the
 * unlink form submission.
 *
 * The section has two visual states:
 *
 *  - **Linked** — shows the remote QRAuth user id, the link timestamp,
 *    and an "Unlink" submit button gated by a per-user nonce. Always
 *    renders for linked users so they keep an escape hatch.
 *  - **Not linked** — short helper text pointing the user back to the
 *    login form. Only rendered when `enabled_on` includes `profile`
 *    (so admins can hide the section for unlinked users).
 *
 * @since 0.1.0
 */
final class ProfileFields {

	/**
	 * Form field name of the unlink trigger.
	 */
	public const UNLINK_FIELD = 'qrauth_psl_unlink';

	/**
	 * Form field name of the unlink nonce.
	 */
	public const UNLINK_NONCE_FIELD = '_qrauth_psl_unlink_nonce';

	/**
	 * Transient key prefix for the post-unlink success notice.
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'qrauth_psl_unlink_notice_';

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'maybe_unlink' ) );
		add_action( 'edit_user_profile_update', array( $this, 'maybe_unlink' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	/**
	 * Emit the QRAuth section on the profile page.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$linked_id = (string) get_user_meta( $user->ID, UserMetaKeys::QRAUTH_USER_ID, true );
		$linked_at = (string) get_user_meta( $user->ID, UserMetaKeys::LINKED_AT, true );
		$is_linked = '' !== $linked_id;

		$options = Options::all();
		// Hide the section for unlinked users unless the admin has enabled
		// the profile injection point. Linked users always see it so they
		// keep the unlink control.
		if ( ! $is_linked && ! in_array( 'profile', $options['enabled_on'], true ) ) {
			return;
		}

		?>
		<h2 id="qrauth-psl-profile">
			<?php esc_html_e( 'QRAuth', 'qrauth-passwordless-social-login' ); ?>
		</h2>
		<table class="form-table" role="presentation">
			<?php if ( $is_linked ) : ?>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Status', 'qrauth-passwordless-social-login' ); ?>
					</th>
					<td>
						<p>
							<strong><?php esc_html_e( 'Linked', 'qrauth-passwordless-social-login' ); ?></strong>
						</p>
						<p class="description">
							<?php esc_html_e( 'QRAuth user ID:', 'qrauth-passwordless-social-login' ); ?>
							<code><?php echo esc_html( $linked_id ); ?></code>
						</p>
						<?php if ( '' !== $linked_at ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s is a localised date/time string. */
									esc_html__( 'Linked since %s.', 'qrauth-passwordless-social-login' ),
									esc_html(
										date_i18n(
											(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
											(int) strtotime( $linked_at )
										)
									)
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Unlink', 'qrauth-passwordless-social-login' ); ?>
					</th>
					<td>
						<?php wp_nonce_field( self::nonce_action( (int) $user->ID ), self::UNLINK_NONCE_FIELD ); ?>
						<button
							type="submit"
							name="<?php echo esc_attr( self::UNLINK_FIELD ); ?>"
							value="1"
							class="button button-secondary"
						>
							<?php esc_html_e( 'Unlink QRAuth', 'qrauth-passwordless-social-login' ); ?>
						</button>
						<p class="description">
							<?php
							esc_html_e(
								'Removes the QRAuth link only. The WordPress account (role, posts, comments) is preserved and can be re-linked later by signing in via QRAuth again.',
								'qrauth-passwordless-social-login'
							);
							?>
						</p>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Status', 'qrauth-passwordless-social-login' ); ?>
					</th>
					<td>
						<p>
							<strong><?php esc_html_e( 'Not linked', 'qrauth-passwordless-social-login' ); ?></strong>
						</p>
						<p class="description">
							<?php
							esc_html_e(
								'Sign in via the QRAuth widget on wp-login.php to link this account.',
								'qrauth-passwordless-social-login'
							);
							?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * If the profile form submitted our unlink request, verify the nonce
	 * and hand off to {@see UserLinker::unlink()}.
	 *
	 * @param int $user_id WP user ID whose profile is being saved.
	 */
	public function maybe_unlink( int $user_id ): void {
		// Nonce verification gates every $_POST access below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check ahead of explicit wp_verify_nonce() on the next line.
		if ( ! isset( $_POST[ self::UNLINK_NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::UNLINK_NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $user_id ) ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::UNLINK_FIELD ] ) ) {
			return;
		}

		$linker = new UserLinker();
		if ( $linker->unlink( $user_id ) ) {
			set_transient(
				self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				1,
				30
			);
		}
	}

	/**
	 * Show a one-shot admin notice after a successful unlink.
	 */
	public function maybe_show_notice(): void {
		$key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'QRAuth has been unlinked from this account.', 'qrauth-passwordless-social-login' )
		);
	}

	/**
	 * Build the per-user nonce action name.
	 *
	 * @param int $user_id WP user ID.
	 */
	private static function nonce_action( int $user_id ): string {
		return 'qrauth_psl_unlink_' . $user_id;
	}
}
