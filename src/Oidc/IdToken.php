<?php
/**
 * ID token decoding and verification (RS256).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Oidc;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a QRAuth ID token the way OpenID Connect Core 3.1.3.7 asks:
 * RS256 signature against the issuer's JWKS, then iss, aud, azp, exp, iat
 * and nonce. Fails closed: any problem throws an OidcException.
 *
 * RS256 only. It is the OIDC baseline and the default for every QRAuth
 * client; a client switched to ES256 is refused with `id_token_alg`.
 */
final class IdToken {

	/**
	 * Seconds of clock skew tolerated on exp / iat.
	 */
	private const CLOCK_SKEW = 120;

	/**
	 * Verify a compact JWS and return its claims.
	 *
	 * @param string                         $jwt       Compact serialised ID token.
	 * @param array<int,array<string,mixed>> $jwks_keys The issuer's JWKS `keys` array.
	 * @param string                         $issuer    Expected `iss`.
	 * @param string                         $client_id Expected audience.
	 * @param string                         $nonce     Nonce sent in the authorization request.
	 * @param int|null                       $now       Current time, for tests.
	 * @return array<string,mixed> Verified claims.
	 * @throws OidcException When the token is malformed, unsigned, or any check fails.
	 */
	public static function verify( string $jwt, array $jwks_keys, string $issuer, string $client_id, string $nonce, ?int $now = null ): array {
		$now   = $now ?? time();
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			throw new OidcException( 'id_token_invalid', 'not a compact JWS' );
		}

		$header  = self::json_part( $parts[0] );
		$claims  = self::json_part( $parts[1] );
		$sig_raw = self::b64url_decode( $parts[2] );

		if ( 'RS256' !== ( $header['alg'] ?? null ) ) {
			throw new OidcException( 'id_token_alg', 'alg must be RS256' );
		}

		$jwk = self::find_key( $jwks_keys, isset( $header['kid'] ) ? (string) $header['kid'] : null );
		$pem = self::rsa_jwk_to_pem( $jwk );
		$ok  = openssl_verify( $parts[0] . '.' . $parts[1], $sig_raw, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			throw new OidcException( 'id_token_signature', 'signature does not verify' );
		}

		self::check_claims( $claims, $issuer, $client_id, $nonce, $now );
		return $claims;
	}

	/**
	 * Claim checks from OIDC Core 3.1.3.7 steps 2-4, 9, 10 and 11.
	 *
	 * @param array<string,mixed> $claims    Decoded claims.
	 * @param string              $issuer    Expected issuer.
	 * @param string              $client_id Expected audience.
	 * @param string              $nonce     Expected nonce.
	 * @param int                 $now       Current time.
	 * @throws OidcException On the first failing check.
	 */
	private static function check_claims( array $claims, string $issuer, string $client_id, string $nonce, int $now ): void {
		if ( ( $claims['iss'] ?? null ) !== $issuer ) {
			throw new OidcException( 'id_token_claims', 'iss mismatch' );
		}

		$aud = $claims['aud'] ?? null;
		$aud = is_array( $aud ) ? $aud : array( $aud );
		if ( ! in_array( $client_id, $aud, true ) ) {
			throw new OidcException( 'id_token_claims', 'aud does not include client' );
		}
		if ( count( $aud ) > 1 && ( $claims['azp'] ?? null ) !== $client_id ) {
			throw new OidcException( 'id_token_claims', 'azp mismatch' );
		}

		$exp = $claims['exp'] ?? null;
		if ( ! is_int( $exp ) || $exp + self::CLOCK_SKEW < $now ) {
			throw new OidcException( 'id_token_expired', 'exp passed' );
		}
		$iat = $claims['iat'] ?? null;
		if ( ! is_int( $iat ) || $iat - self::CLOCK_SKEW > $now ) {
			throw new OidcException( 'id_token_claims', 'iat in the future' );
		}

		$token_nonce = $claims['nonce'] ?? null;
		if ( ! is_string( $token_nonce ) || '' === $nonce || ! hash_equals( $nonce, $token_nonce ) ) {
			throw new OidcException( 'id_token_nonce', 'nonce mismatch' );
		}

		if ( ! is_string( $claims['sub'] ?? null ) || '' === $claims['sub'] ) {
			throw new OidcException( 'id_token_claims', 'sub missing' );
		}
	}

	/**
	 * Pick the signing key: by kid when the token names one, else the only RSA signing key.
	 *
	 * @param array<int,array<string,mixed>> $keys JWKS keys.
	 * @param string|null                    $kid  Key id from the token header.
	 * @return array<string,mixed>
	 * @throws OidcException When no usable key matches.
	 */
	private static function find_key( array $keys, ?string $kid ): array {
		$candidates = array_values(
			array_filter(
				$keys,
				static function ( $key ) use ( $kid ): bool {
					if ( ! is_array( $key ) || 'RSA' !== ( $key['kty'] ?? null ) ) {
						return false;
					}
					if ( isset( $key['use'] ) && 'sig' !== $key['use'] ) {
						return false;
					}
					if ( isset( $key['alg'] ) && 'RS256' !== $key['alg'] ) {
						return false;
					}
					return null === $kid || ( $key['kid'] ?? null ) === $kid;
				}
			)
		);
		if ( 1 !== count( $candidates ) ) {
			throw new OidcException( 'id_token_key', 'no single matching RSA key' );
		}
		return $candidates[0];
	}

	/**
	 * Build a PEM SubjectPublicKeyInfo from an RSA JWK's n and e.
	 *
	 * @param array<string,mixed> $jwk RSA JWK.
	 * @return string PEM public key.
	 * @throws OidcException When n or e is missing or the key is too small.
	 */
	public static function rsa_jwk_to_pem( array $jwk ): string {
		$n = self::b64url_decode( (string) ( $jwk['n'] ?? '' ) );
		$e = self::b64url_decode( (string) ( $jwk['e'] ?? '' ) );
		if ( strlen( $n ) < 256 || '' === $e ) {
			throw new OidcException( 'id_token_key', 'RSA key missing or under 2048 bits' );
		}

		$rsa_public_key = self::der_sequence( self::der_integer( $n ) . self::der_integer( $e ) );
		// AlgorithmIdentifier: rsaEncryption OID 1.2.840.113549.1.1.1 + NULL.
		$algorithm = self::der_sequence( "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" );
		$spki      = self::der_sequence( $algorithm . "\x03" . self::der_length( strlen( $rsa_public_key ) + 1 ) . "\x00" . $rsa_public_key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding of a public key, not obfuscation.
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * DER INTEGER from unsigned big-endian bytes.
	 *
	 * @param string $bytes Unsigned big-endian integer.
	 * @return string
	 */
	private static function der_integer( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * DER SEQUENCE wrapper.
	 *
	 * @param string $content Encoded members.
	 * @return string
	 */
	private static function der_sequence( string $content ): string {
		return "\x30" . self::der_length( strlen( $content ) ) . $content;
	}

	/**
	 * DER definite length.
	 *
	 * @param int $length Content length in bytes.
	 * @return string
	 */
	private static function der_length( int $length ): string {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		$bytes = ltrim( pack( 'N', $length ), "\x00" );
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Decode one JSON segment of a JWS.
	 *
	 * @param string $segment Base64url segment.
	 * @return array<string,mixed>
	 * @throws OidcException When the segment is not a JSON object.
	 */
	private static function json_part( string $segment ): array {
		$decoded = json_decode( self::b64url_decode( $segment ), true );
		if ( ! is_array( $decoded ) ) {
			throw new OidcException( 'id_token_invalid', 'segment is not a JSON object' );
		}
		return $decoded;
	}

	/**
	 * Base64url decode (no padding).
	 *
	 * @param string $value Base64url text.
	 * @return string Raw bytes.
	 * @throws OidcException When the text is not base64url.
	 */
	public static function b64url_decode( string $value ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $value ) ) {
			throw new OidcException( 'id_token_invalid', 'not base64url' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JOSE base64url decoding, not obfuscation.
		$raw = base64_decode( strtr( $value, '-_', '+/' ), true );
		if ( false === $raw ) {
			throw new OidcException( 'id_token_invalid', 'not base64url' );
		}
		return $raw;
	}

	/**
	 * Base64url encode (no padding).
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	public static function b64url_encode( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JOSE base64url encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
