<?php
/**
 * Failure in the OIDC sign-in flow.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Oidc;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine code (shown to the user as a translated message and
 * logged) and an internal detail (logged only, never rendered).
 */
final class OidcException extends \RuntimeException {

	/**
	 * Machine-readable error code.
	 *
	 * @var string
	 */
	private string $machine_code;

	/**
	 * Constructor.
	 *
	 * @param string $machine_code Machine code, e.g. `state_invalid`.
	 * @param string $detail       Internal detail for logs.
	 */
	public function __construct( string $machine_code, string $detail = '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- detail is for logs, never rendered.
		parent::__construct( '' !== $detail ? $detail : $machine_code );
		$this->machine_code = $machine_code;
	}

	/**
	 * Machine code accessor.
	 *
	 * @return string
	 */
	public function get_machine_code(): string {
		return $this->machine_code;
	}
}
