<?php
/**
 * Stateless validators for the verify route's request parameters.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Validators passed as `validate_callback` in `register_rest_route()`.
 *
 * Sanitisation is handled by WordPress's built-in `sanitize_text_field`;
 * these checks add a format gate so obviously malformed values are
 * rejected with WP's default 400 `rest_invalid_param` error before our
 * route handler runs.
 *
 * @since 0.1.0
 */
final class VerifyRequest {

	/**
	 * UUID v1-v8 (matches what QRAuth's sessions API currently emits).
	 *
	 * @param mixed $value Incoming request value.
	 * @return bool
	 */
	public static function validate_session_id( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$value
		);
	}

	/**
	 * Base64url pattern (the encoding QRAuth uses for ECDSA signatures).
	 *
	 * Accepts `[A-Za-z0-9_-]` with optional `=` padding. Enforces a
	 * minimum length of 24 chars to reject trivially-empty bodies while
	 * leaving plenty of headroom for future signature formats.
	 *
	 * @param mixed $value Incoming request value.
	 * @return bool
	 */
	public static function validate_signature( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		if ( strlen( $value ) < 24 ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_-]+=*$/', $value );
	}
}
