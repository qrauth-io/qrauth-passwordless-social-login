<?php
/**
 * Test helper — thrown by `AuthSessionProxyTest`'s `wp_redirect` filter
 * to short-circuit the controller before its `exit;` kills the PHPUnit
 * process.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Integration\Rest;

/**
 * The location + status the handler was about to set are exposed as
 * public properties so tests can assert on them.
 */
final class WpRedirectCapturedException extends \Exception {

	/**
	 * Construct the exception with the captured redirect details.
	 *
	 * @param string $location Location the handler was about to send the browser to.
	 * @param int    $status   HTTP status the handler requested (typically 302).
	 */
	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( $location );
	}
}
