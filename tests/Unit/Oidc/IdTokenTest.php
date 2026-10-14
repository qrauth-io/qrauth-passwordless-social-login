<?php
/**
 * Unit tests for {@see IdToken}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- unit suite runs without WordPress.

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Oidc\IdToken;
use QRAuth\PasswordlessSocialLogin\Oidc\OidcException;

/**
 * RS256 signature and OIDC Core 3.1.3.7 claim checks, against a key pair
 * generated per test run.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Oidc\IdToken
 */
final class IdTokenTest extends TestCase {

	private const ISSUER = 'https://id.qrauth.io';
	private const CLIENT = 'wp-client';
	private const NONCE  = 'nonce-123';
	private const NOW    = 1_800_000_000;

	/**
	 * Private key used to sign test tokens.
	 *
	 * @var \OpenSSLAsymmetricKey
	 */
	private static $private_key;

	/**
	 * Matching public JWK.
	 *
	 * @var array<string,string>
	 */
	private static array $jwk;

	/**
	 * Generate one RSA key pair for the class.
	 */
	public static function setUpBeforeClass(): void {
		self::$private_key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details           = openssl_pkey_get_details( self::$private_key );
		self::$jwk         = array(
			'kty' => 'RSA',
			'kid' => 'kid-1',
			'use' => 'sig',
			'alg' => 'RS256',
			'n'   => IdToken::b64url_encode( $details['rsa']['n'] ),
			'e'   => IdToken::b64url_encode( $details['rsa']['e'] ),
		);
	}

	/**
	 * Sign a token with the test key.
	 *
	 * @param array<string,mixed> $overrides Claim overrides (null removes a claim).
	 * @param array<string,mixed> $header    Header overrides.
	 * @return string
	 */
	private function token( array $overrides = array(), array $header = array() ): string {
		$claims = array_filter(
			array_merge(
				array(
					'iss'   => self::ISSUER,
					'aud'   => self::CLIENT,
					'sub'   => 'pairwise-sub',
					'exp'   => self::NOW + 300,
					'iat'   => self::NOW,
					'nonce' => self::NONCE,
				),
				$overrides
			),
			static fn( $v ) => null !== $v
		);
		$head   = IdToken::b64url_encode(
			(string) json_encode(
				array_merge(
					array(
						'alg' => 'RS256',
						'kid' => 'kid-1',
					),
					$header
				)
			)
		);
		$body   = IdToken::b64url_encode( (string) json_encode( $claims ) );
		openssl_sign( $head . '.' . $body, $signature, self::$private_key, OPENSSL_ALGO_SHA256 );
		return $head . '.' . $body . '.' . IdToken::b64url_encode( $signature );
	}

	/**
	 * Verify with the defaults.
	 *
	 * @param string $jwt Token.
	 * @return array<string,mixed>
	 */
	private function verify( string $jwt ): array {
		return IdToken::verify( $jwt, array( self::$jwk ), self::ISSUER, self::CLIENT, self::NONCE, self::NOW );
	}

	/**
	 * Expect a machine code.
	 *
	 * @param string   $code Expected machine code.
	 * @param callable $callback Code under test.
	 */
	private function expect_code( string $code, callable $callback ): void {
		try {
			$callback();
		} catch ( OidcException $e ) {
			$this->assertSame( $code, $e->get_machine_code() );
			return;
		}
		$this->fail( 'Expected OidcException ' . $code );
	}

	/**
	 * Accepts a valid token.
	 */
	public function test_accepts_a_valid_token(): void {
		$claims = $this->verify( $this->token() );

		$this->assertSame( 'pairwise-sub', $claims['sub'] );
	}

	/**
	 * Jwk to pem matches openssl export.
	 */
	public function test_jwk_to_pem_matches_openssl_export(): void {
		$details = openssl_pkey_get_details( self::$private_key );

		$this->assertSame( trim( $details['key'] ), trim( IdToken::rsa_jwk_to_pem( self::$jwk ) ) );
	}

	/**
	 * Rejects a tampered payload.
	 */
	public function test_rejects_a_tampered_payload(): void {
		$parts    = explode( '.', $this->token() );
		$parts[1] = IdToken::b64url_encode( (string) json_encode( array( 'sub' => 'someone-else' ) ) );

		$this->expect_code( 'id_token_signature', fn() => $this->verify( implode( '.', $parts ) ) );
	}

	/**
	 * Rejects unsigned and hmac tokens.
	 */
	public function test_rejects_unsigned_and_hmac_tokens(): void {
		$this->expect_code( 'id_token_alg', fn() => $this->verify( $this->token( array(), array( 'alg' => 'none' ) ) ) );
		$this->expect_code( 'id_token_alg', fn() => $this->verify( $this->token( array(), array( 'alg' => 'HS256' ) ) ) );
	}

	/**
	 * Rejects a token signed by another key.
	 */
	public function test_rejects_a_token_signed_by_another_key(): void {
		$other   = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details = openssl_pkey_get_details( $other );
		$jwk     = array_merge( self::$jwk, array( 'n' => IdToken::b64url_encode( $details['rsa']['n'] ) ) );

		$this->expect_code(
			'id_token_signature',
			fn() => IdToken::verify( $this->token(), array( $jwk ), self::ISSUER, self::CLIENT, self::NONCE, self::NOW )
		);
	}

	/**
	 * Rejects an unknown kid.
	 */
	public function test_rejects_an_unknown_kid(): void {
		$this->expect_code( 'id_token_key', fn() => $this->verify( $this->token( array(), array( 'kid' => 'other' ) ) ) );
	}

	/**
	 * Cases for the data-driven test below.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function bad_claims(): array {
		return array(
			'other issuer'      => array( array( 'iss' => 'https://evil.example' ), 'id_token_claims' ),
			'other audience'    => array( array( 'aud' => 'other-client' ), 'id_token_claims' ),
			'multi-aud, no azp' => array( array( 'aud' => array( self::CLIENT, 'other' ) ), 'id_token_claims' ),
			'expired'           => array( array( 'exp' => self::NOW - 600 ), 'id_token_expired' ),
			'issued in future'  => array( array( 'iat' => self::NOW + 600 ), 'id_token_claims' ),
			'wrong nonce'       => array( array( 'nonce' => 'other' ), 'id_token_nonce' ),
			'missing nonce'     => array( array( 'nonce' => null ), 'id_token_nonce' ),
			'missing sub'       => array( array( 'sub' => null ), 'id_token_claims' ),
		);
	}

	/**
	 * Runs once per case from bad_claims().
	 *
	 * @dataProvider bad_claims
	 *
	 * @param array<string,mixed> $overrides Claim overrides.
	 * @param string              $code      Expected machine code.
	 */
	public function test_rejects_bad_claims( array $overrides, string $code ): void {
		$this->expect_code( $code, fn() => $this->verify( $this->token( $overrides ) ) );
	}

	/**
	 * Accepts multi audience with matching azp.
	 */
	public function test_accepts_multi_audience_with_matching_azp(): void {
		$claims = $this->verify(
			$this->token(
				array(
					'aud' => array( self::CLIENT, 'other' ),
					'azp' => self::CLIENT,
				)
			)
		);

		$this->assertSame( 'pairwise-sub', $claims['sub'] );
	}

	/**
	 * Rejects keys under 2048 bits.
	 */
	public function test_rejects_keys_under_2048_bits(): void {
		$this->expect_code(
			'id_token_key',
			fn() => IdToken::rsa_jwk_to_pem(
				array(
					'n' => IdToken::b64url_encode( str_repeat( "\x01", 128 ) ),
					'e' => 'AQAB',
				)
			)
		);
	}

	/**
	 * Rejects malformed tokens.
	 */
	public function test_rejects_malformed_tokens(): void {
		$this->expect_code( 'id_token_invalid', fn() => $this->verify( 'not.a.jwt!' ) );
		$this->expect_code( 'id_token_invalid', fn() => $this->verify( 'onlyone' ) );
	}
}
