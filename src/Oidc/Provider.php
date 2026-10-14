<?php
/**
 * Server-to-server calls to the QRAuth OpenID Provider.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Oidc;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery, JWKS, the token exchange and userinfo for one issuer.
 *
 * Every endpoint is read from the issuer's discovery document, which must
 * name the configured issuer exactly and serve every endpoint over https on
 * the issuer's host. Discovery and JWKS are cached in transients; a key the
 * cache does not know triggers one JWKS refresh (key rotation).
 */
class Provider {

	/**
	 * Discovery cache lifetime in seconds.
	 */
	private const DISCOVERY_TTL = DAY_IN_SECONDS;

	/**
	 * JWKS cache lifetime in seconds.
	 */
	private const JWKS_TTL = HOUR_IN_SECONDS;

	/**
	 * HTTP timeout in seconds.
	 */
	private const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param string $issuer        Issuer URL, https, no trailing slash.
	 * @param string $client_id     OIDC client id.
	 * @param string $client_secret OIDC client secret.
	 */
	public function __construct(
		private readonly string $issuer,
		private readonly string $client_id,
		private readonly string $client_secret,
	) {}

	/**
	 * Issuer accessor.
	 *
	 * @return string
	 */
	public function issuer(): string {
		return $this->issuer;
	}

	/**
	 * Client id accessor.
	 *
	 * @return string
	 */
	public function client_id(): string {
		return $this->client_id;
	}

	/**
	 * The issuer's discovery document, validated.
	 *
	 * @return array<string,mixed>
	 * @throws OidcException When discovery is unreachable or inconsistent.
	 */
	public function discovery(): array {
		$cache_key = 'qrauth_psl_oidc_disc_' . md5( $this->issuer );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$doc = $this->get_json( $this->issuer . '/.well-known/openid-configuration' );
		if ( ( $doc['issuer'] ?? null ) !== $this->issuer ) {
			throw new OidcException( 'discovery_invalid', 'issuer mismatch' );
		}
		foreach ( array( 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri' ) as $field ) {
			if ( ! $this->is_issuer_url( $doc[ $field ] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- log detail, never rendered.
				throw new OidcException( 'discovery_invalid', $field . ' is not an https URL on the issuer host' );
			}
		}

		set_transient( $cache_key, $doc, self::DISCOVERY_TTL );
		return $doc;
	}

	/**
	 * JWKS keys, refreshed once when the token names a kid the cache lacks.
	 *
	 * @param string|null $kid Key id from the ID token header.
	 * @return array<int,array<string,mixed>>
	 * @throws OidcException When the JWKS cannot be fetched.
	 */
	public function jwks_keys( ?string $kid ): array {
		$cache_key = 'qrauth_psl_oidc_jwks_' . md5( $this->issuer );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ( null === $kid || self::has_kid( $cached, $kid ) ) ) {
			return $cached;
		}

		$jwks = $this->get_json( (string) $this->discovery()['jwks_uri'] );
		$keys = is_array( $jwks['keys'] ?? null ) ? array_values( $jwks['keys'] ) : array();
		set_transient( $cache_key, $keys, self::JWKS_TTL );
		return $keys;
	}

	/**
	 * Exchange an authorization code (with its PKCE verifier) at the token endpoint.
	 *
	 * @param string $code          Authorization code from the callback.
	 * @param string $code_verifier PKCE verifier stored at the start of the flow.
	 * @param string $redirect_uri  The redirect URI sent to /authorize.
	 * @return array{id_token:string,access_token:string}
	 * @throws OidcException On transport errors or an unusable response.
	 */
	public function exchange_code( string $code, string $code_verifier, string $redirect_uri ): array {
		$response = wp_safe_remote_post(
			(string) $this->discovery()['token_endpoint'],
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					// RFC 6749 2.3.1: form-encode both parts before Basic encoding.
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Authorization' => 'Basic ' . base64_encode( rawurlencode( $this->client_id ) . ':' . rawurlencode( $this->client_secret ) ),
					'Accept'        => 'application/json',
					'User-Agent'    => self::user_agent(),
				),
				'body'        => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
					'code_verifier' => $code_verifier,
				),
			)
		);
		$body     = $this->decode_response( $response, 'token_exchange' );

		if ( ! is_string( $body['id_token'] ?? null ) || ! is_string( $body['access_token'] ?? null ) ) {
			throw new OidcException( 'token_exchange', 'id_token or access_token missing' );
		}
		if ( 0 !== strcasecmp( (string) ( $body['token_type'] ?? '' ), 'Bearer' ) ) {
			throw new OidcException( 'token_exchange', 'token_type is not Bearer' );
		}
		return array(
			'id_token'     => $body['id_token'],
			'access_token' => $body['access_token'],
		);
	}

	/**
	 * Userinfo claims for an access token.
	 *
	 * @param string $access_token Bearer token from the exchange.
	 * @return array<string,mixed>
	 * @throws OidcException On transport errors or an unusable response.
	 */
	public function userinfo( string $access_token ): array {
		$response = wp_safe_remote_get(
			(string) $this->discovery()['userinfo_endpoint'],
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
					'User-Agent'    => self::user_agent(),
				),
			)
		);
		return $this->decode_response( $response, 'userinfo' );
	}

	/**
	 * GET a JSON document.
	 *
	 * @param string $url URL to fetch.
	 * @return array<string,mixed>
	 * @throws OidcException On transport errors or non-JSON.
	 */
	private function get_json( string $url ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => self::user_agent(),
				),
			)
		);
		return $this->decode_response( $response, 'discovery_unreachable' );
	}

	/**
	 * Require a 200 JSON object.
	 *
	 * @param array|\WP_Error $response Result of a wp_remote_* call.
	 * @param string          $code     Machine code to throw.
	 * @return array<string,mixed>
	 * @throws OidcException When the response is not a 200 JSON object.
	 */
	private function decode_response( $response, string $code ): array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are for logs, never rendered.
		if ( is_wp_error( $response ) ) {
			throw new OidcException( $code, 'transport: ' . $response->get_error_code() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			$error = is_array( $body ) && is_string( $body['error'] ?? null ) ? $body['error'] : 'none';
			throw new OidcException( $code, sprintf( 'HTTP %d, error %s', $status, $error ) );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		return $body;
	}

	/**
	 * True for an https URL on the issuer's host.
	 *
	 * @param mixed $url Candidate URL.
	 * @return bool
	 */
	private function is_issuer_url( $url ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$parts  = wp_parse_url( $url );
		$issuer = wp_parse_url( $this->issuer );
		return is_array( $parts ) && is_array( $issuer )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& ( $parts['host'] ?? '' ) === ( $issuer['host'] ?? null );
	}

	/**
	 * True when a cached key set contains this kid.
	 *
	 * @param array<int,mixed> $keys Cached keys.
	 * @param string           $kid  Key id.
	 * @return bool
	 */
	private static function has_kid( array $keys, string $kid ): bool {
		foreach ( $keys as $key ) {
			if ( is_array( $key ) && ( $key['kid'] ?? null ) === $kid ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * User-Agent for outbound calls.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		return sprintf(
			'qrauth-psl/%s; WordPress/%s',
			defined( 'QRAUTH_PSL_VERSION' ) ? QRAUTH_PSL_VERSION : '0.0.0',
			(string) get_bloginfo( 'version' )
		);
	}
}
