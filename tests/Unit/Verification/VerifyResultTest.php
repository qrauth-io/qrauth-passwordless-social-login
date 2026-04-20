<?php
/**
 * Unit tests for {@see VerifyResult::from_array()}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Verification;

use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyResult;

/**
 * Covers the defensive JSON-to-DTO parser. Missing keys and wrong types
 * must raise `VerifyException` rather than silently defaulting.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Verification\VerifyResult
 */
final class VerifyResultTest extends TestCase {

	/**
	 * A well-shaped approved response parses cleanly.
	 */
	public function test_approved_response_parses(): void {
		$result = VerifyResult::from_array(
			array(
				'valid'   => true,
				'session' => array(
					'id'         => 'sess-1',
					'status'     => 'APPROVED',
					'appName'    => 'Test App',
					'scopes'     => array( 'identity', 'email' ),
					'user'       => array(
						'id'    => 'user-uuid-1',
						'email' => 'alice@example.com',
						'name'  => 'Alice',
					),
					'resolvedAt' => '2026-04-21T10:00:00Z',
				),
			)
		);

		$this->assertTrue( $result->valid );
		$this->assertSame( 'APPROVED', $result->status );
		$this->assertSame( 'Test App', $result->app_name );
		$this->assertSame( 'user-uuid-1', $result->user_id );
		$this->assertSame( 'alice@example.com', $result->user_email );
		$this->assertSame( 'Alice', $result->user_name );
		$this->assertSame( array( 'identity', 'email' ), $result->scopes );
		$this->assertSame( '2026-04-21T10:00:00Z', $result->resolved_at );
	}

	/**
	 * `valid=false` returns a bare result without requiring `session`.
	 */
	public function test_invalid_response_parses_without_session(): void {
		$result = VerifyResult::from_array( array( 'valid' => false ) );

		$this->assertFalse( $result->valid );
		$this->assertSame( '', $result->status );
		$this->assertSame( '', $result->user_id );
		$this->assertNull( $result->user_email );
	}

	/**
	 * Missing `valid` key → VerifyException.
	 */
	public function test_missing_valid_key_throws(): void {
		$this->expectException( VerifyException::class );
		VerifyResult::from_array( array( 'session' => array() ) );
	}

	/**
	 * Non-boolean `valid` → VerifyException.
	 */
	public function test_non_boolean_valid_throws(): void {
		$this->expectException( VerifyException::class );
		VerifyResult::from_array( array( 'valid' => 'yes' ) );
	}

	/**
	 * `valid=true` without `session` → VerifyException.
	 */
	public function test_valid_without_session_throws(): void {
		$this->expectException( VerifyException::class );
		VerifyResult::from_array( array( 'valid' => true ) );
	}

	/**
	 * `valid=true` with `session` but no `user.id` → VerifyException.
	 */
	public function test_valid_without_user_id_throws(): void {
		$this->expectException( VerifyException::class );
		VerifyResult::from_array(
			array(
				'valid'   => true,
				'session' => array(
					'status' => 'APPROVED',
					'user'   => array( 'email' => 'x@y.z' ),
				),
			)
		);
	}

	/**
	 * Optional fields may be absent without throwing.
	 */
	public function test_optional_fields_may_be_absent(): void {
		$result = VerifyResult::from_array(
			array(
				'valid'   => true,
				'session' => array(
					'status' => 'APPROVED',
					'user'   => array( 'id' => 'u-1' ),
				),
			)
		);

		$this->assertTrue( $result->valid );
		$this->assertNull( $result->user_email );
		$this->assertNull( $result->user_name );
		$this->assertSame( array(), $result->scopes );
		$this->assertSame( '', $result->app_name );
	}

	/**
	 * Non-string entries inside `scopes` are silently dropped.
	 */
	public function test_non_string_scopes_are_filtered_out(): void {
		$result = VerifyResult::from_array(
			array(
				'valid'   => true,
				'session' => array(
					'status' => 'APPROVED',
					'scopes' => array( 'identity', 42, null, 'email' ),
					'user'   => array( 'id' => 'u-1' ),
				),
			)
		);

		$this->assertSame( array( 'identity', 'email' ), $result->scopes );
	}
}
