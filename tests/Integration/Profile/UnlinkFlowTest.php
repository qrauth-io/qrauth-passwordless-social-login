<?php
/**
 * Integration tests for the profile-page unlink flow and the uninstaller.
 *
 * Runs only when the WordPress test library is available
 * (WP_TESTS_DIR set + `WP_UnitTestCase` loaded).
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Integration\Profile;

use QRAuth\PasswordlessSocialLogin\Frontend\ProfileFields;
use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\Uninstall\Uninstaller;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserMetaKeys;
use WP_UnitTestCase;

/**
 * Covers the two user-facing flows that P4 introduces — unlinking QRAuth
 * from a WP account via the profile page, and uninstalling the plugin
 * without deleting user accounts. The plugin's ProfileFields is already
 * booted by the test bootstrap, so these tests drive WP's profile-update
 * action directly.
 */
final class UnlinkFlowTest extends WP_UnitTestCase {

	/**
	 * Seed a linked user on each test run.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option(
			Options::OPTION_NAME,
			array(
				'client_id'      => 'cid',
				'base_url'       => 'https://qrauth.io',
				'auto_provision' => true,
				'default_role'   => 'subscriber',
				'allowed_scopes' => array( 'identity', 'email' ),
				'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
			)
		);
	}

	/**
	 * Reset $_POST between tests so state doesn't leak.
	 */
	public function tear_down(): void {
		unset( $_POST[ ProfileFields::UNLINK_FIELD ] );
		unset( $_POST[ ProfileFields::UNLINK_NONCE_FIELD ] );
		parent::tear_down();
	}

	/**
	 * Linked user + valid nonce → meta deleted, user account preserved.
	 */
	public function test_unlink_with_valid_nonce_clears_meta(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, 'qa-user-1' );
		update_user_meta( $user_id, UserMetaKeys::LINKED_AT, '2026-04-21T10:00:00+00:00' );

		wp_set_current_user( $user_id );

		$_POST[ ProfileFields::UNLINK_NONCE_FIELD ] = wp_create_nonce( 'qrauth_psl_unlink_' . $user_id );
		$_POST[ ProfileFields::UNLINK_FIELD ]       = '1';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- driving WP's own hook from the test harness.
		do_action( 'personal_options_update', $user_id );

		$this->assertSame( '', get_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, true ) );
		$this->assertSame( '', get_user_meta( $user_id, UserMetaKeys::LINKED_AT, true ) );

		$user = get_user_by( 'ID', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'author', $user->roles );
	}

	/**
	 * Tampered nonce → meta preserved.
	 */
	public function test_unlink_with_tampered_nonce_keeps_meta(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, 'qa-user-1' );

		wp_set_current_user( $user_id );

		$_POST[ ProfileFields::UNLINK_NONCE_FIELD ] = 'not-a-real-nonce';
		$_POST[ ProfileFields::UNLINK_FIELD ]       = '1';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- driving WP's own hook from the test harness.
		do_action( 'personal_options_update', $user_id );

		$this->assertSame( 'qa-user-1', get_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, true ) );
	}

	/**
	 * Editor attempting to unlink another user fails the capability check
	 * and leaves the target's meta intact.
	 */
	public function test_editor_cannot_unlink_another_user(): void {
		$target = self::factory()->user->create();
		update_user_meta( $target, UserMetaKeys::QRAUTH_USER_ID, 'qa-user-2' );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$_POST[ ProfileFields::UNLINK_NONCE_FIELD ] = wp_create_nonce( 'qrauth_psl_unlink_' . $target );
		$_POST[ ProfileFields::UNLINK_FIELD ]       = '1';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- driving WP's own hook from the test harness.
		do_action( 'edit_user_profile_update', $target );

		$this->assertSame( 'qa-user-2', get_user_meta( $target, UserMetaKeys::QRAUTH_USER_ID, true ) );
	}

	/**
	 * Uninstaller removes the plugin option and rate-limit transients but
	 * never touches user accounts or their QRAuth link meta.
	 */
	public function test_uninstall_preserves_user_data(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, 'qa-user-3' );
		update_user_meta( $user_id, UserMetaKeys::LINKED_AT, '2026-04-21T10:00:00+00:00' );

		set_transient( 'qrauth_psl_rl_fakehash', 5, 300 );

		$users_before = (int) count_users()['total_users'];

		Uninstaller::run();

		$this->assertFalse( get_option( Options::OPTION_NAME, false ) );
		$this->assertFalse( get_transient( 'qrauth_psl_rl_fakehash' ) );

		$this->assertSame( $users_before, (int) count_users()['total_users'] );
		$this->assertSame( 'qa-user-3', get_user_meta( $user_id, UserMetaKeys::QRAUTH_USER_ID, true ) );
		$this->assertSame( '2026-04-21T10:00:00+00:00', get_user_meta( $user_id, UserMetaKeys::LINKED_AT, true ) );
	}
}
