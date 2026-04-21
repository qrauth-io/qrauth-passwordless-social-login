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
	 * The current QRAuth monolith emits cuid-format session IDs
	 * (~24 lowercase alphanumeric chars, no dashes).
	 */
	public function test_cuid_session_ids_pass(): void {
		$this->assertTrue( VerifyRequest::validate_session_id( 'ckpxsx1zo0000qh3h9q3jx7e2' ) );
		$this->assertTrue( VerifyRequest::validate_session_id( 'clq9r8t0u0000xyz1234abcde' ) );
	}

	/**
	 * Historical UUID-format session IDs still pass (older API versions).
	 */
	public function test_uuid_session_ids_pass(): void {
		$this->assertTrue(
			VerifyRequest::validate_session_id( '550e8400-e29b-41d4-a716-446655440000' )
		);
		$this->assertTrue(
			VerifyRequest::validate_session_id( 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' )
		);
	}

	/**
	 * Non-strings, too-short strings, and strings containing unsafe
	 * characters are rejected.
	 */
	public function test_invalid_session_ids_fail(): void {
		$this->assertFalse( VerifyRequest::validate_session_id( '' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( 'short' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( 'has spaces in it' ) );
		$this->assertFalse( VerifyRequest::validate_session_id( "inject'\"<script>" ) );
		$this->assertFalse( VerifyRequest::validate_session_id( str_repeat( 'A', 129 ) ) );
		$this->assertFalse( VerifyRequest::validate_session_id( 123 ) );
		$this->assertFalse( VerifyRequest::validate_session_id( null ) );
	}

	/**
	 * Standard base64 signatures (the monolith's current DER-encoded
	 * ECDSA output) pass — including `+`, `/`, and `=` padding.
	 */
	public function test_standard_base64_signatures_pass(): void {
		$this->assertTrue(
			VerifyRequest::validate_signature( 'MEUCIQDabc+123/def456GHIjkl789' )
		);
		$this->assertTrue(
			VerifyRequest::validate_signature( str_repeat( 'A', 86 ) . '==' )
		);
	}

	/**
	 * Base64url signatures also pass (used by some clients and older
	 * QRAuth deployments).
	 */
	public function test_base64url_signatures_pass(): void {
		$this->assertTrue( VerifyRequest::validate_signature( 'MEUCIQDabc123_-def456GHIjkl789' ) );
		$this->assertTrue( VerifyRequest::validate_signature( str_repeat( 'A', 24 ) ) );
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
