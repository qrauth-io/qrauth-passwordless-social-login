<?php
/**
 * Exception carrying a machine-readable code for REST error mapping.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Verification;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see QRAuthClient} and {@see \QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker}
 * when something goes wrong. The human-readable message is for logs only;
 * the REST layer reports only the machine code to the client.
 *
 * @since 0.1.0
 */
final class VerifyException extends RuntimeException {

	/**
	 * Machine-readable error code (e.g. `verify_failed`, `provision_disabled`).
	 *
	 * @var string
	 */
	private string $machine_code;

	/**
	 * Construct a new VerifyException.
	 *
	 * @param string         $machine_code Machine-readable code for REST error mapping.
	 * @param string         $message      Internal log-only message.
	 * @param Throwable|null $previous     Cause chain.
	 */
	public function __construct( string $machine_code, string $message = '', ?Throwable $previous = null ) {
		parent::__construct( '' === $message ? $machine_code : $message, 0, $previous );
		$this->machine_code = $machine_code;
	}

	/**
	 * Return the machine-readable code for safe reporting to callers.
	 */
	public function get_machine_code(): string {
		return $this->machine_code;
	}
}
