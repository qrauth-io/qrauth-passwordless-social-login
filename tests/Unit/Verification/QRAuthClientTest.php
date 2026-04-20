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
