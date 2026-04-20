<?php
/**
 * Unit tests for {@see VerifyRequest}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Rest\VerifyRequest;

/**
 * Covers the format validators passed to `register_rest_route()` as
 * `validate_callback`s. Pure static functions — no WP mocks required.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Rest\VerifyRequest
 */
final class VerifyRequestTest extends TestCase {

	/**
	 * Well-formed UUIDs pass the session_id validator.
	 */
	public function test_valid_uuid_session_ids_pass(): void {
		$this->assertTrue(
			VerifyRequest::validate_session_id( '550e8400-e29b-41d4-a716-446655440000' )
		);
		$this->assertTrue(
			VerifyRequest::validate_session_id( 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' )
		);
	}

	/**
	 * Garbage inputs are rejected by the session_id validator.
	 */
	public function test_invalid_session_ids_fail(): void {
		$this->assertFalse( VerifyRequest::validate_session_id( '' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( 'not a uuid' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( '550e8400-e29b-41d4-a716' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( 123 ) );
		$this->assertFalse( VerifyRequest::validate_session_id( null ) );
	}

	/**
	 * Plausible base64url signatures pass the signature validator.
	 */
	public function test_valid_base64url_signatures_pass(): void {
		$this->assertTrue( VerifyRequest::validate_signature( 'MEUCIQDabc123_-def456GHIjkl789' ) );
		$this->assertTrue( VerifyRequest::validate_signature( str_repeat( 'A', 24 ) ) );
		$this->assertTrue( VerifyRequest::validate_signature( str_repeat( 'A', 88 ) . '==' ) );
	}

	/**
	 * Too-short, wrong-alphabet, or non-string signatures are rejected.
	 */
	public function test_invalid_signatures_fail(): void {
		$this->assertFalse( VerifyRequest::validate_signature( '' ) );
		$this->assertFalse( VerifyRequest::validate_signature( 'too-short' ) );
		$this->assertFalse( VerifyRequest::validate_signature( str_repeat( 'A', 24 ) . '!' ) );
		$this->assertFalse( VerifyRequest::validate_signature( 'contains spaces and stuff ' ) );
		$this->assertFalse( VerifyRequest::validate_signature( 1234567890 ) );
	}
}
