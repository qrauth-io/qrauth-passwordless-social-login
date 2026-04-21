<?php
/**
 * Unit tests for {@see Uninstaller}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Uninstall;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Support\Options;
use QRAuth\PasswordlessSocialLogin\Uninstall\Uninstaller;

/**
 * Covers the small surface of `Uninstaller::run()`: delete the plugin
 * option and sweep `qrauth_psl_*` transients. Asserts defensively that
 * we never touch `wp_users` / `wp_usermeta`.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Uninstall\Uninstaller
 */
final class UninstallerTest extends TestCase {

	/**
	 * Stub `$wpdb` captured `query` + `prepare` calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $wpdb_queries = array();

	/**
	 * `delete_option()` call log.
	 *
	 * @var array<int,string>
	 */
	private array $deleted_options = array();

	/**
	 * Set up Brain\Monkey + WP-function + $wpdb stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_queries    = array();
		$this->deleted_options = array();

		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$this->deleted_options[] = $name;
				return true;
			}
		);

		$this->install_wpdb_stub();
	}

	/**
	 * Tear down Brain\Monkey + the $wpdb global between tests.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Install an anonymous-class stand-in for the global $wpdb with the
	 * two methods our Uninstaller uses (`prepare`, `query`). Queries are
	 * recorded on `$this->wpdb_queries` for later assertions.
	 */
	private function install_wpdb_stub(): void {
		$wpdb_queries = &$this->wpdb_queries;

		$wpdb = new class( $wpdb_queries ) {
			/**
			 * Mirror of $wpdb->options.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * Back-reference to the outer test's query log.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $log;

			/**
			 * Hold a reference to the outer test's log.
			 *
			 * @param array<int,array<string,mixed>> $log Reference to outer log array.
			 */
			public function __construct( array &$log ) {
				$this->log = &$log;
			}

			/**
			 * Mirror of wpdb::esc_like.
			 *
			 * @param string $text Raw string to escape for LIKE.
			 * @return string
			 */
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Mirror of wpdb::prepare — returns the query with placeholders
			 * expanded naively (enough for assertion matching).
			 *
			 * @param string       $query   SQL with %s placeholders.
			 * @param array<mixed> ...$args Replacement values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
				}
				return $query;
			}

			/**
			 * Mirror of wpdb::query — logs the SQL.
			 *
			 * @param string $sql SQL to "execute".
			 * @return int
			 */
			public function query( string $sql ): int {
				$this->log[] = array( 'sql' => $sql );
				return 0;
			}
		};

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- installing a stub for the unit suite; tear_down() removes it.
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * `run()` deletes the `qrauth_psl_settings` option.
	 */
	public function test_run_deletes_settings_option(): void {
		Uninstaller::run();

		$this->assertContains( Options::OPTION_NAME, $this->deleted_options );
	}

	/**
	 * `run()` issues a DELETE against `wp_options` targeting only
	 * `qrauth_psl_*` transients — never `wp_users` or `wp_usermeta`.
	 */
	public function test_run_sweeps_transients_without_touching_user_tables(): void {
		Uninstaller::run();

		$this->assertGreaterThan( 0, count( $this->wpdb_queries ) );

		$sql = $this->wpdb_queries[0]['sql'];
		$this->assertStringContainsString( 'DELETE FROM wp_options', $sql );
		// esc_like escapes underscores (\_), so assert on the logical substring
		// rather than the raw bytes.
		$this->assertMatchesRegularExpression( '/_transient(\\\\_|_)qrauth/', $sql );
		$this->assertMatchesRegularExpression( '/_transient(\\\\_|_)timeout(\\\\_|_)qrauth/', $sql );

		foreach ( $this->wpdb_queries as $query ) {
			$this->assertStringNotContainsString( 'wp_users', $query['sql'] );
			$this->assertStringNotContainsString( 'wp_usermeta', $query['sql'] );
		}
	}

	/**
	 * When `$wpdb` isn't available (e.g. a fresh install with no DB),
	 * `run()` still removes the option and doesn't fatal.
	 */
	public function test_run_is_safe_without_wpdb(): void {
		unset( $GLOBALS['wpdb'] );

		Uninstaller::run();

		$this->assertContains( Options::OPTION_NAME, $this->deleted_options );
	}
}
