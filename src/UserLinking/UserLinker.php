<?php
/**
 * Map a QRAuth identity to a WordPress user — meta, then email, then provision.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\UserLinking;

defined( 'ABSPATH' ) || exit;

use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyResult;

/**
 * Idempotent WordPress-user resolver.
 *
 * Walks a three-step ladder:
 *
 *   1. Look up a WP user by the `qrauth_psl_user_id` meta value.
 *   2. Look up by email (only if the `email` scope was granted).
 *   3. If auto-provision is enabled, create a fresh user with role
 *      'subscriber'.
 *
 * Every successful resolution stamps the link meta so subsequent calls
 * short-circuit at step 1. The auto-provisioned role is hardcoded to
 * 'subscriber' — there is no programmatic path to elevate.
 *
 * @since 0.1.0
 */
final class UserLinker {

	/**
	 * Resolve or create a WordPress user for the given verified QRAuth result.
	 *
	 * @param VerifyResult $result Verified session payload.
	 * @return int Resolved WP user ID.
	 * @throws VerifyException If the user can't be linked or provisioned.
	 */
	public function link_or_provision( VerifyResult $result ): int {
		// 1. Meta match — the fast path after the first successful link.
		$by_meta = get_users(
			array(
				'meta_key'   => UserMetaKeys::QRAUTH_USER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed meta_key lookup, single row.
				'meta_value' => $result->user_id,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- matched against indexed meta_key above.
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( ! empty( $by_meta ) ) {
			$user_id = (int) $by_meta[0];
			$this->stamp_meta( $user_id, $result );
			return $user_id;
		}

		// 2. Email match — only if QRAuth returned one.
		if ( null !== $result->user_email ) {
			$by_email = get_user_by( 'email', $result->user_email );
			if ( $by_email instanceof \WP_User ) {
				$this->stamp_meta( (int) $by_email->ID, $result );
				return (int) $by_email->ID;
			}
		}

		// 3. Provision (only if allowed).
		$options = Options::all();
		if ( true !== $options['auto_provision'] ) {
			throw new VerifyException( 'provision_disabled' );
		}

		if ( null === $result->user_email ) {
			throw new VerifyException( 'provision_disabled', 'Cannot provision without email' );
		}

		// Auto-provisioned accounts are hardcoded to 'subscriber' at every
		// layer (settings UI, sanitiser, runtime) — there is no programmatic
		// path to elevate. Operators who need a different role per user must
		// change it manually via Users → All Users after first sign-in.
		$role = 'subscriber';

		$user_id = wp_insert_user(
			array(
				'user_login'   => $this->derive_user_login( $result->user_email ),
				'user_email'   => $result->user_email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => null !== $result->user_name ? $result->user_name : '',
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// Surface only a machine code to the client; log the wp_error code for ops.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is for logs, never rendered to users.
			throw new VerifyException(
				'internal_error',
				'wp_insert_user failed: ' . $user_id->get_error_code()
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$user_id = (int) $user_id;
		$this->stamp_meta( $user_id, $result );

		do_action( 'qrauth_psl_user_provisioned', $user_id, $result );

		return $user_id;
	}

	/**
	 * Detach QRAuth from a WP user account.
	 *
	 * Removes the link metadata only — the WP account itself (role, posts,
	 * comments, sessions, etc.) is preserved. Re-linking remains possible
	 * via the next successful sign-in.
	 *
	 * Callers must be `edit_user`-capable on the target user (admins can
	 * unlink anyone; regular users can only unlink themselves).
	 *
	 * @param int $user_id WP user ID to unlink.
	 * @return bool True on success, false if the caller lacks capability or
	 *              the user had no link metadata to remove.
	 */
	public function unlink( int $user_id ): bool {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		$had_qrauth_id = (bool) get_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, true );
		$had_linked_at = (bool) get_user_meta( $user_id, UserMetaKeys::LINKED_AT, true );

		if ( ! $had_qrauth_id && ! $had_linked_at ) {
			return false;
		}

		delete_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID );
		delete_user_meta( $user_id, UserMetaKeys::LINKED_AT );

		do_action( 'qrauth_psl_user_unlinked', $user_id );

		return true;
	}

	/**
	 * Write the `qrauth_psl_user_id` + `qrauth_psl_linked_at` meta for a user.
	 *
	 * @param int          $user_id WP user ID.
	 * @param VerifyResult $result  Verified payload.
	 */
	private function stamp_meta( int $user_id, VerifyResult $result ): void {
		update_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, $result->user_id );
		update_user_meta( $user_id, UserMetaKeys::LINKED_AT, gmdate( 'c' ) );
	}

	/**
	 * Derive a unique WP user_login from the email local-part.
	 *
	 * Lowercased, stripped of anything outside `[a-z0-9._-]`. If the
	 * result is empty or collides with an existing account, append a
	 * numeric suffix until unique (bounded at 100 attempts).
	 *
	 * @param string $email User email.
	 * @return string
	 * @throws VerifyException If we can't find a free slot within 100 tries.
	 */
	private function derive_user_login( string $email ): string {
		$local = strstr( $email, '@', true );
		if ( ! is_string( $local ) || '' === $local ) {
			$local = 'qrauth-user';
		}
		$base = strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $local ) );
		if ( '' === $base ) {
			$base = 'qrauth-user';
		}

		$candidate = $base;
		$suffix    = 0;
		while ( username_exists( $candidate ) ) {
			++$suffix;
			if ( $suffix > 100 ) {
				throw new VerifyException( 'internal_error', 'Could not derive unique username' );
			}
			$candidate = $base . '-' . $suffix;
		}

		return $candidate;
	}
}
