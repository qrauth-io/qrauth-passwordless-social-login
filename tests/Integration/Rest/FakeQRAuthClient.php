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

		if ( null !== $this->throw_value ) {
			throw $this->throw_value;
		}

		if ( null === $this->return_value ) {
			// Distinguish "fake was invoked but return_value wasn't set" from
			// "a real QRAuthClient ran because the fake was never reached" —
			// the second case would surface as verify_failed from a transport
			// error, this as fake_unconfigured.
			throw new VerifyException( 'fake_unconfigured', 'FakeQRAuthClient has no return_value configured' );
		}

		return $this->return_value;
	}
}
