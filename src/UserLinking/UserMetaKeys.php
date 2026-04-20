<?php
/**
 * User-meta key constants for QRAuth-linked accounts.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\UserLinking;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises the `qrauth_psl_*` user_meta keys so rename-refactors stay
 * local to one file.
 *
 * @since 0.1.0
 */
final class UserMetaKeys {

	/**
	 * Remote QRAuth user ID (opaque string — UUID in the current API).
	 */
	public const QRAUTH_USER_ID = 'qrauth_psl_user_id';

	/**
	 * ISO-8601 UTC timestamp of the first successful link.
	 */
	public const LINKED_AT = 'qrauth_psl_linked_at';
}
