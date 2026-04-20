<?php
/**
 * Test-only subclass of QRAuthClient that lets tests swap the response.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Integration\Rest;

use QRAuth\PasswordlessSocialLogin\Verification\QRAuthClient;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyResult;

/**
 * Deterministic replacement for the upstream HTTP client.
 *
 * Injected into {@see \QRAuth\PasswordlessSocialLogin\Rest\RestController}
 * via its constructor so integration tests can simulate every branch of
 * the verify-result response without standing up a fake HTTP server.
 */
final class FakeQRAuthClient extends QRAuthClient {

	/**
	 * Per-invocation diagnostic trace — tests can read it to check that
	 * the fake actually ran with a configured return_value.
	 *
	 * Each entry: `['instance' => int, 'return_set' => bool, 'throw_set' => bool]`.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $invocations = array();

	/**
	 * Configurable return value — tests set this before dispatching.
	 *
	 * @var VerifyResult|null
	 */
	public ?VerifyResult $return_value = null;

	/**
	 * Optional exception to throw instead of returning a result.
	 *
	 * @var VerifyException|null
	 */
	public ?VerifyException $throw_value = null;

	/**
	 * Bypass the parent constructor's real HTTP setup.
	 */
	public function __construct() {
		// Intentional — we don't need the base URL or UA string for the fake.
	}

	/**
	 * Return the configured result or throw the configured exception.
	 *
	 * @param string $session_id Ignored.
	 * @param string $signature  Ignored.
	 * @return VerifyResult
	 * @throws VerifyException If $throw_value was set.
	 */
	public function verify_result( string $session_id, string $signature ): VerifyResult {
		unset( $session_id, $signature );

		self::$invocations[] = array(
			'instance'   => spl_object_id( $this ),
			'return_set' => null !== $this->return_value,
			'throw_set'  => null !== $this->throw_value,
		);

		if ( null !== $this->throw_value ) {
			throw $this->throw_value;
		}

		if ( null === $this->return_value ) {
			throw new VerifyException( 'fake_unconfigured', 'FakeQRAuthClient has no return_value configured' );
		}

		return $this->return_value;
	}
}
