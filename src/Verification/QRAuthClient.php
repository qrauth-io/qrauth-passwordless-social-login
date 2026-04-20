<?php
/**
 * HTTP client for the QRAuth `verify-result` endpoint.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Server-to-server caller for QRAuth's cryptographic session verification.
 *
 * All outbound requests go through `wp_remote_post` with TLS enforced,
 * a 10-second timeout, and a `User-Agent` string identifying both our
 * plugin version and the host WordPress version (a convention we can
 * expose in QRAuth's analytics without leaking PII).
 *
 * On any deviation from the expected response shape we raise
 * {@see VerifyException} rather than silently defaulting, per the hard
 * stop in `SPECS/prompts/P3-rest-verify.md`.
 *
 * @since 0.1.0
 */
class QRAuthClient {

	/**
	 * Base URL for QRAuth, without a trailing slash (e.g. `https://qrauth.io`).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * User-Agent string sent with every request.
	 *
	 * @var string
	 */
	private string $user_agent;

	/**
	 * Construct a new client.
	 *
	 * @param string $base_url   Pre-sanitised base URL. Trailing slash is tolerated.
	 * @param string $wp_version The host WordPress version, for the User-Agent header.
	 */
	public function __construct( string $base_url, string $wp_version ) {
		$this->base_url   = rtrim( $base_url, '/' );
		$this->user_agent = sprintf(
			'qrauth-psl/%s; WordPress/%s',
			defined( 'QRAUTH_PSL_VERSION' ) ? QRAUTH_PSL_VERSION : '0.0.0',
			$wp_version
		);
	}

	/**
	 * Call `POST {base}/api/v1/auth-sessions/verify-result` and parse the response.
	 *
	 * @param string $session_id The session UUID emitted by the widget.
	 * @param string $signature  The base64url ECDSA-P256 signature from QRAuth.
	 * @return VerifyResult
	 * @throws VerifyException On transport error, non-200 status, or invalid JSON / shape.
	 */
	public function verify_result( string $session_id, string $signature ): VerifyResult {
		$response = wp_remote_post(
			$this->base_url . '/api/v1/auth-sessions/verify-result',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => $this->user_agent,
				),
				'body'        => wp_json_encode(
					array(
						'sessionId' => $session_id,
						'signature' => $signature,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is for logs, never rendered to users.
			throw new VerifyException(
				'verify_failed',
				'Transport error: ' . $response->get_error_code()
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is for logs, never rendered to users.
			throw new VerifyException(
				'verify_failed',
				sprintf( 'Non-200 response from QRAuth: %d', $status )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new VerifyException( 'verify_failed', 'Non-array JSON response' );
		}

		return VerifyResult::from_array( $data );
	}
}
