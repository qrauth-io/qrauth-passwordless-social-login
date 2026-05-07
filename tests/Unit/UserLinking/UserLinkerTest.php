<?php
/**
 * Unit tests for {@see UserLinker}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\UserLinking;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker;
use QRAuth\PasswordlessSocialLogin\UserLinking\UserMetaKeys;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyException;
use QRAuth\PasswordlessSocialLogin\Verification\VerifyResult;
use WP_User;

/**
 * Exercises the three-step resolution ladder: meta → email → provision,
 * plus defensive role clamping and the unique-username derivation.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\UserLinking\UserLinker
 */
final class UserLinkerTest extends TestCase {

	/**
	 * Track `update_user_meta` calls for assertion.
	 *
	 * @var array<int,array{user_id:int,key:string,value:mixed}>
	 */
	private array $meta_updates = array();

	/**
	 * Track `wp_insert_user` calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $inserts = array();

	/**
	 * Brain\Monkey + the shared set of WordPress-function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta_updates = array();
		$this->inserts      = array();

		$this->stub_options();

		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta_updates[] = array(
					'user_id' => $user_id,
					'key'     => $key,
					'value'   => $value,
				);
				return true;
			}
		);

		// Default stubs — individual tests override as needed.
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'random-pass' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $v ) {
				return $v instanceof \WP_Error;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
			}
		);
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Configure `get_option('qrauth_psl_settings', ...)` for Options::all().
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function stub_options( array $overrides = array() ): void {
		$defaults = array(
			'client_id'      => 'cid',
			'client_secret'  => 'csec',
			'tenant_url'     => 'https://qrauth.io',
			'auto_provision' => false,
			'default_role'   => 'subscriber',
			'allowed_scopes' => array( 'identity', 'email' ),
			'enabled_on'     => array( 'wp-login', 'register', 'profile' ),
		);
		$merged   = array_merge( $defaults, $overrides );

		Functions\when( 'get_option' )->alias(
			static function () use ( $merged ) {
				return $merged;
			}
		);
	}

	/**
	 * Build a minimal valid VerifyResult for tests.
	 *
	 * @param string      $user_id Remote QRAuth user ID.
	 * @param string|null $email   Optional user email.
	 * @return VerifyResult
	 */
	private function approved_result(
		string $user_id = 'qa-user-1',
		?string $email = 'alice@example.com'
	): VerifyResult {
		return new VerifyResult(
			true,
			'APPROVED',
			'Test App',
			$user_id,
			$email,
			'Alice',
			array( 'identity', 'email' ),
			'2026-04-21T10:00:00Z'
		);
	}

	/**
	 * Meta match short-circuits and stamps the link meta.
	 */
	public function test_meta_match_returns_existing_user(): void {
		Functions\when( 'get_users' )->justReturn( array( 42 ) );

		$linker = new UserLinker();
		$uid    = $linker->link_or_provision( $this->approved_result() );

		$this->assertSame( 42, $uid );
		$this->assertGreaterThan( 0, count( $this->meta_updates ) );
		$this->assertSame( UserMetaKeys::QRAUTH_USER_ID, $this->meta_updates[0]['key'] );
	}

	/**
	 * Email match (when no meta match) returns the existing user and stamps meta.
	 */
	public function test_email_match_returns_existing_user_and_stamps_meta(): void {
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $value ) {
				if ( 'email' === $field && 'alice@example.com' === $value ) {
					return new WP_User( 77 );
				}
				return false;
			}
		);

		$linker = new UserLinker();
		$uid    = $linker->link_or_provision( $this->approved_result() );

		$this->assertSame( 77, $uid );
		$saw_meta_key = false;
		foreach ( $this->meta_updates as $update ) {
			if ( 77 === $update['user_id'] && UserMetaKeys::QRAUTH_USER_ID === $update['key'] ) {
				$saw_meta_key = true;
			}
		}
		$this->assertTrue( $saw_meta_key, 'Expected qrauth_psl_user_id meta stamped on matched user' );
	}

	/**
	 * No match + auto_provision off → `provision_disabled`.
	 */
	public function test_provision_off_throws_provision_disabled(): void {
		$this->stub_options( array( 'auto_provision' => false ) );

		$linker = new UserLinker();

		try {
			$linker->link_or_provision( $this->approved_result() );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'provision_disabled', $e->get_machine_code() );
		}
	}

	/**
	 * Auto-provision on, but no email on the result → still `provision_disabled`
	 * (we need an email to create a WP user).
	 */
	public function test_provision_on_without_email_throws_provision_disabled(): void {
		$this->stub_options( array( 'auto_provision' => true ) );

		$linker = new UserLinker();

		try {
			$linker->link_or_provision( $this->approved_result( 'qa-user-1', null ) );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'provision_disabled', $e->get_machine_code() );
		}
	}

	/**
	 * Happy provision path: creates a user with the hardcoded 'subscriber'
	 * role, stamps meta, fires `qrauth_psl_user_provisioned`.
	 */
	public function test_provision_creates_user_with_subscriber_role(): void {
		$this->stub_options(
			array(
				'auto_provision' => true,
				'default_role'   => 'subscriber',
			)
		);

		Functions\when( 'wp_insert_user' )->alias(
			function ( $userdata ) {
				$this->inserts[] = $userdata;
				return 101;
			}
		);

		$linker = new UserLinker();
		$uid    = $linker->link_or_provision( $this->approved_result() );

		$this->assertSame( 101, $uid );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'subscriber', $this->inserts[0]['role'] );
		$this->assertSame( 'alice@example.com', $this->inserts[0]['user_email'] );
		$this->assertSame( 'alice', $this->inserts[0]['user_login'] );
	}

	/**
	 * Even if the stored option somehow drifted to a higher role
	 * (`administrator`, `editor`, `author`, `contributor`), the provisioner
	 * hardcodes `subscriber` — there is no programmatic way to elevate.
	 */
	public function test_provision_ignores_drifted_option_and_uses_subscriber(): void {
		$this->stub_options(
			array(
				'auto_provision' => true,
				'default_role'   => 'administrator',
			)
		);

		Functions\when( 'wp_insert_user' )->alias(
			function ( $userdata ) {
				$this->inserts[] = $userdata;
				return 202;
			}
		);

		$linker = new UserLinker();
		$linker->link_or_provision( $this->approved_result() );

		$this->assertSame( 'subscriber', $this->inserts[0]['role'] );
	}

	/**
	 * Colliding usernames get a numeric suffix (-1, -2, …).
	 */
	public function test_provision_suffixes_colliding_user_login(): void {
		$this->stub_options( array( 'auto_provision' => true ) );

		$calls = 0;
		Functions\when( 'username_exists' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $candidate receives the value from the loop but the test doesn't care which.
			static function ( $candidate ) use ( &$calls ) {
				++$calls;
				// First two candidates collide, third is free.
				return $calls <= 2;
			}
		);

		Functions\when( 'wp_insert_user' )->alias(
			function ( $userdata ) {
				$this->inserts[] = $userdata;
				return 303;
			}
		);

		$linker = new UserLinker();
		$linker->link_or_provision( $this->approved_result() );

		$this->assertSame( 'alice-2', $this->inserts[0]['user_login'] );
	}

	/**
	 * `wp_insert_user` returning a WP_Error maps to `internal_error` (not
	 * leaked to the caller as a raw error message).
	 */
	public function test_insert_user_error_maps_to_internal_error(): void {
		$this->stub_options( array( 'auto_provision' => true ) );

		Functions\when( 'wp_insert_user' )->alias(
			static function () {
				return new \WP_Error( 'existing_user_email' );
			}
		);

		$linker = new UserLinker();

		try {
			$linker->link_or_provision( $this->approved_result() );
			$this->fail( 'Expected VerifyException' );
		} catch ( VerifyException $e ) {
			$this->assertSame( 'internal_error', $e->get_machine_code() );
		}
	}

	/**
	 * Caller without `edit_user` capability → unlink returns false and
	 * doesn't touch user_meta.
	 */
	public function test_unlink_without_capability_returns_false(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$deletes = 0;
		Functions\when( 'delete_user_meta' )->alias(
			static function () use ( &$deletes ) {
				++$deletes;
				return true;
			}
		);

		$linker = new UserLinker();

		$this->assertFalse( $linker->unlink( 42 ) );
		$this->assertSame( 0, $deletes );
	}

	/**
	 * Unlinking an already-unlinked user returns false (nothing to remove).
	 */
	public function test_unlink_unlinked_user_returns_false(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		$linker = new UserLinker();

		$this->assertFalse( $linker->unlink( 42 ) );
	}

	/**
	 * Happy path: meta is deleted and the unlink action fires exactly once.
	 */
	public function test_unlink_linked_user_deletes_meta_and_fires_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key ) {
				if ( UserMetaKeys::QRAUTH_USER_ID === $key ) {
					return 'qa-user-1';
				}
				if ( UserMetaKeys::LINKED_AT === $key ) {
					return '2026-04-21T10:00:00+00:00';
				}
				return '';
			}
		);

		$deletes = array();
		Functions\when( 'delete_user_meta' )->alias(
			static function ( $user_id, $key ) use ( &$deletes ) {
				$deletes[] = array(
					'user_id' => $user_id,
					'key'     => $key,
				);
				return true;
			}
		);

		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		$linker = new UserLinker();

		$this->assertTrue( $linker->unlink( 7 ) );
		$this->assertCount( 2, $deletes );
		$this->assertSame( UserMetaKeys::QRAUTH_USER_ID, $deletes[0]['key'] );
		$this->assertSame( UserMetaKeys::LINKED_AT, $deletes[1]['key'] );

		$action_names = array_column( $actions, 0 );
		$this->assertContains( 'qrauth_psl_user_unlinked', $action_names );
	}
}
