<?php
/**
 * Test signal for redirects.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Oidc;

/**
 * Thrown by the injected redirect so a test sees where the flow went.
 */
final class Redirected extends \RuntimeException {}
