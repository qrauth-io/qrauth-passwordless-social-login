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
	 * Opaque session-ID allowlist.
	 *
	 * QRAuth's `AuthSession.id` is currently a cuid (e.g.
	 * `ckpxsx1zo0000qh3h9q3jx7e2`, ~24 lowercase alphanumeric chars, no
	 * dashes), but older deployments may still return UUIDs and a future
	 * change could switch to nanoid. Rather than pin a specific format
	 * (and break again on the next schema change), accept any
	 * reasonably-sized base64url-alphabet string. Semantic validation
	 * happens server-side at `/verify-result` — this gate just rejects
	 * obviously-garbage input before burning an upstream call.
	 *
	 * Min 8 / max 128 chars, `[A-Za-z0-9_-]` only.
	 *
	 * @param mixed $value Incoming request value.
	 * @return bool
	 */
	public static function validate_session_id( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $value );
	}

	/**
	 * Signature format — envelope + either alphabet QRAuth could emit.
	 *
	 * Real signatures on the wire are a two-part envelope:
	 * `<keyId>:<base64sig>` (see `packages/api/src/services/auth-session.ts`
	 * in the monolith — the approve path stores
	 * `${signingKey.keyId}:${base64sig}` so the verifier can look up the
	 * right key at verify-result time). The base64 part may use either
	 * standard (`+`, `/`, `=`) or URL-safe (`-`, `_`) alphabet. The keyId
	 * is an opaque string (currently cuid-shaped).
	 *
	 * Accepted character set: `[A-Za-z0-9+/=_:.-]`. The `:` covers the
	 * envelope separator; `.` is included for forward-compatibility with
	 * JWT-style compact envelopes should upstream ever switch.
	 *
	 * Semantic validation (key lookup + cryptographic check) happens
	 * server-side at `/verify-result`; this gate just rejects obviously
	 * malformed input. Min 24 chars rules out trivially-empty bodies.
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
		return (bool) preg_match( '/^[A-Za-z0-9+\/=_:.-]+$/', $value );
	}
}
