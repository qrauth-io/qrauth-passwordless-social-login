<?php
/**
 * DTO for the QRAuth `/api/v1/auth-sessions/verify-result` response.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO wrapping QRAuth's `verify-result` payload.
 *
 * The parser in {@see self::from_array()} validates defensively —
 * missing keys and wrong types raise {@see VerifyException} rather than
 * silently falling back, so a malformed response can never be confused
 * with a valid approval.
 *
 * @since 0.1.0
 */
final class VerifyResult {

	/**
	 * Build an immutable result.
	 *
	 * @param bool              $valid      Whether QRAuth considered the signature valid.
	 * @param string            $status     Session status (e.g. `APPROVED`, `PENDING`, `DENIED`).
	 * @param string            $app_name   The QRAuth app's display name, if returned.
	 * @param string            $user_id    Remote user ID (UUID string).
	 * @param string|null       $user_email User's email, if the `email` scope was granted.
	 * @param string|null       $user_name  User's display name, if returned.
	 * @param array<int,string> $scopes     Scopes actually granted on this session.
	 * @param string            $resolved_at ISO-8601 timestamp of when the session was resolved.
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly string $status,
		public readonly string $app_name,
		public readonly string $user_id,
		public readonly ?string $user_email,
		public readonly ?string $user_name,
		public readonly array $scopes,
		public readonly string $resolved_at
	) {
	}

	/**
	 * Parse QRAuth's verify-result response into a VerifyResult.
	 *
	 * Expected shape (documented in `docs/INTEGRATION-CONTRACT.md`):
	 *
	 * ```
	 * {
	 *   valid: bool,
	 *   session?: {
	 *     id: string,
	 *     status: string,
	 *     appName?: string,
	 *     scopes?: string[],
	 *     user: { id: string, email?: string, name?: string },
	 *     resolvedAt?: string
	 *   }
	 * }
	 * ```
	 *
	 * When `valid` is false we don't require `session` (signature was bad
	 * or the session doesn't exist). When `valid` is true we require the
	 * nested `user.id`; everything else is best-effort.
	 *
	 * @param array<string,mixed> $data Decoded JSON payload.
	 * @throws VerifyException If the payload shape is invalid.
	 */
	public static function from_array( array $data ): self {
		if ( ! array_key_exists( 'valid', $data ) || ! is_bool( $data['valid'] ) ) {
			throw new VerifyException( 'verify_failed', 'Missing or non-boolean `valid` key' );
		}

		if ( false === $data['valid'] ) {
			return new self(
				false,
				'',
				'',
				'',
				null,
				null,
				array(),
				''
			);
		}

		if ( ! isset( $data['session'] ) || ! is_array( $data['session'] ) ) {
			throw new VerifyException( 'verify_failed', 'Missing `session` object' );
		}
		$session = $data['session'];

		if ( ! isset( $session['user'] ) || ! is_array( $session['user'] ) ) {
			throw new VerifyException( 'verify_failed', 'Missing `session.user` object' );
		}
		$user = $session['user'];

		if ( ! isset( $user['id'] ) || ! is_string( $user['id'] ) || '' === $user['id'] ) {
			throw new VerifyException( 'verify_failed', 'Missing `session.user.id`' );
		}

		$scopes = array();
		if ( isset( $session['scopes'] ) && is_array( $session['scopes'] ) ) {
			foreach ( $session['scopes'] as $scope ) {
				if ( is_string( $scope ) && '' !== $scope ) {
					$scopes[] = $scope;
				}
			}
		}

		return new self(
			true,
			isset( $session['status'] ) && is_string( $session['status'] ) ? $session['status'] : '',
			isset( $session['appName'] ) && is_string( $session['appName'] ) ? $session['appName'] : '',
			$user['id'],
			isset( $user['email'] ) && is_string( $user['email'] ) && '' !== $user['email'] ? $user['email'] : null,
			isset( $user['name'] ) && is_string( $user['name'] ) && '' !== $user['name'] ? $user['name'] : null,
			$scopes,
			isset( $session['resolvedAt'] ) && is_string( $session['resolvedAt'] ) ? $session['resolvedAt'] : ''
		);
	}
}
