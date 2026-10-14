<?php
/**
 * A verified QRAuth identity from the OIDC flow.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Oidc;

defined( 'ABSPATH' ) || exit;

/**
 * Built only from a verified ID token plus userinfo for the same subject.
 */
final class Identity {

	/**
	 * Constructor.
	 *
	 * @param string      $subject        Pairwise `sub` for this site's client.
	 * @param string|null $email          Email claim, when the email scope was granted.
	 * @param bool        $email_verified QRAuth's `email_verified` claim.
	 * @param string|null $name           Display name, when the profile scope was granted.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly ?string $email,
		public readonly bool $email_verified,
		public readonly ?string $name,
	) {}

	/**
	 * Combine verified ID token claims with userinfo.
	 *
	 * @param array<string,mixed> $id_claims Verified ID token claims.
	 * @param array<string,mixed> $userinfo  Userinfo response.
	 * @return self
	 * @throws OidcException When userinfo describes a different subject.
	 */
	public static function from_claims( array $id_claims, array $userinfo ): self {
		$subject = (string) $id_claims['sub'];
		// OIDC Core 5.3.2: userinfo `sub` must equal the ID token's.
		if ( ( $userinfo['sub'] ?? null ) !== $subject ) {
			throw new OidcException( 'userinfo_subject', 'userinfo sub differs from id_token sub' );
		}

		$email = $userinfo['email'] ?? null;
		$email = is_string( $email ) && '' !== $email ? sanitize_email( $email ) : '';
		$name  = $userinfo['name'] ?? null;

		return new self(
			$subject,
			'' !== $email ? $email : null,
			true === ( $userinfo['email_verified'] ?? false ),
			is_string( $name ) && '' !== $name ? sanitize_text_field( $name ) : null,
		);
	}
}
