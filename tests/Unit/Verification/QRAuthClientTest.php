<?php
/**
 * Unit tests for {@see QRAuthClient}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Verification;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Verification\QRAuthClient;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use WP_Error;

/**
 * Covers the HTTP client's error-handling. The wp_remote_* helpers are
 * stubbed via Brain\Monkey so we can simulate transport errors, non-200
 * responses, and valid approvals without a live network.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Verification\QRAuthClient
 */
final class QRAuthClientTest extends TestCase {

	/**
	 * Brain\Monkey setup + WordPress-function stubs shared by all tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'QRAUTH_PSL_VERSION' ) ) {
			define( 'QRAUTH_PSL_VERSION', '0.1.0-test' );
		}

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ) {
				return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- we're IN the wp_json_encode stub.
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ) {
				return $value instanceof WP_Error;
			}
		);
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Transport-level failures (DNS, TLS, timeout) raise `verify_failed`.
	 */
	public function test_transport_error_raises_verify_failed(): void {
		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				return new WP_Error( 'http_request_failed' );
			}
		);

		$client = new QRAuthClient( 'https://qrauth.io', '6.4' );

		try {
			$client->verify_result( 'sess-1', 'sig-1' );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'verify_failed', $e->get_machine_code() );
		}
	}

	/**
	 * Non-200 HTTP responses raise `verify_failed`.
	 */
	public function test_non_200_status_raises_verify_failed(): void {
		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => 'internal server error',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);

		$client = new QRAuthClient( 'https://qrauth.io', '6.4' );

		try {
			$client->verify_result( 'sess-1', 'sig-1' );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'verify_failed', $e->get_machine_code() );
		}
	}

	/**
	 * A 200 with non-JSON body raises `verify_failed`.
	 */
	public function test_invalid_json_raises_verify_failed(): void {
		$this->stub_200_body( 'not-json-at-all' );

		$client = new QRAuthClient( 'https://qrauth.io', '6.4' );

		try {
			$client->verify_result( 'sess-1', 'sig-1' );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'verify_failed', $e->get_machine_code() );
		}
	}

	/**
	 * A well-shaped approved response is parsed into a VerifyResult.
	 */
	public function test_approved_response_returns_verify_result(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fixture string for the stub; WP helpers aren't available here.
		$body = json_encode(
			array(
				'valid'   => true,
				'session' => array(
					'id'     => 'sess-1',
					'status' => 'APPROVED',
					'user'   => array(
						'id'    => 'user-1',
						'email' => 'bob@example.com',
					),
				),
			)
		);
		$this->stub_200_body( $body );

		$client = new QRAuthClient( 'https://qrauth.io/', '6.4' );
		$result = $client->verify_result( 'sess-1', 'sig-1' );

		$this->assertTrue( $result->valid );
		$this->assertSame( 'APPROVED', $result->status );
		$this->assertSame( 'user-1', $result->user_id );
		$this->assertSame( 'bob@example.com', $result->user_email );
	}

	/**
	 * A `valid=false` response is accepted without throwing.
	 */
	public function test_signature_rejection_returns_invalid_result(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fixture string for the stub; WP helpers aren't available here.
		$this->stub_200_body( json_encode( array( 'valid' => false ) ) );

		$client = new QRAuthClient( 'https://qrauth.io', '6.4' );
		$result = $client->verify_result( 'sess-1', 'sig-1' );

		$this->assertFalse( $result->valid );
	}

	/**
	 * With the app's credentials configured, verify-result is called with
	 * them, so QRAuth answers `valid` only for this site's own sessions.
	 */
	public function test_sends_app_credentials_when_configured(): void {
		$sent = $this->capture_request_headers();

		$client = new QRAuthClient( 'https://qrauth.io', '6.4', 'qrauth_app_abc', 's3cret' );
		$client->verify_result( 'sess-1', 'sig-1' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
		$this->assertSame( 'Basic ' . base64_encode( 'qrauth_app_abc:s3cret' ), $sent->headers['Authorization'] ?? null );
		$this->assertSame( 'application/json', $sent->headers['Content-Type'] );
	}

	/**
	 * Without both halves of the credentials, no Authorization header is
	 * sent: a client id alone proves nothing and a half-built header would
	 * be rejected upstream.
	 *
	 * @dataProvider incomplete_credentials
	 *
	 * @param string $client_id     Configured client id.
	 * @param string $client_secret Configured client secret.
	 */
	public function test_sends_no_credentials_when_incomplete( string $client_id, string $client_secret ): void {
		$sent = $this->capture_request_headers();

		$client = new QRAuthClient( 'https://qrauth.io', '6.4', $client_id, $client_secret );
		$client->verify_result( 'sess-1', 'sig-1' );

		$this->assertArrayNotHasKey( 'Authorization', $sent->headers );
	}

	/**
	 * Credential pairs that must not produce an Authorization header.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function incomplete_credentials(): array {
		return array(
			'neither'     => array( '', '' ),
			'id only'     => array( 'qrauth_app_abc', '' ),
			'secret only' => array( '', 's3cret' ),
		);
	}

	/**
	 * The two-argument constructor still works and sends no credentials.
	 */
	public function test_two_argument_constructor_sends_no_credentials(): void {
		$sent = $this->capture_request_headers();

		$client = new QRAuthClient( 'https://qrauth.io', '6.4' );
		$client->verify_result( 'sess-1', 'sig-1' );

		$this->assertArrayNotHasKey( 'Authorization', $sent->headers );
	}

	/**
	 * Stub a valid 200 response and record the headers of the request.
	 *
	 * @return \stdClass Object whose `headers` property holds the sent headers.
	 */
	private function capture_request_headers(): \stdClass {
		$sent          = new \stdClass();
		$sent->headers = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fixture string for the stub.
		$body = json_encode(
			array(
				'valid'   => false,
				'session' => array(
					'id'     => 'sess-1',
					'status' => 'PENDING',
					'user'   => null,
				),
			)
		);
		$this->stub_200_body( $body );
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( $sent, $body ) {
				$sent->headers = $args['headers'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
				);
			}
		);
		return $sent;
	}

	/**
	 * Stub `wp_remote_post` returning HTTP 200 with the given body.
	 *
	 * @param string $body Response body.
	 */
	private function stub_200_body( string $body ): void {
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( $response ) {
				return $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
	}
}
