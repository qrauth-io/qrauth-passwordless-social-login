<?php
/**
 * Unit tests for {@see AssetEnqueue::filter_script_tag()}.
 *
 * @package QRAuth\PasswordlessSocialLogin
 */

declare(strict_types=1);

namespace QRAuth\PasswordlessSocialLogin\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use QRAuth\PasswordlessSocialLogin\Frontend\AssetEnqueue;

/**
 * Covers the `script_loader_tag` filter that splices `integrity` +
 * `crossorigin` onto the components `<script>` tag. Asserts untouched
 * pass-through for unrelated handles and for the case where WP hasn't
 * stored an integrity value.
 *
 * @covers \QRAuth\PasswordlessSocialLogin\Frontend\AssetEnqueue::filter_script_tag
 */
final class AssetEnqueueTest extends TestCase {

	/**
	 * Set up Brain\Monkey and a default esc_attr pass-through.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
	}

	/**
	 * Tear down Brain\Monkey between tests.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub `wp_scripts()` with an object whose `get_data( $handle, $key )`
	 * returns values from the supplied map.
	 *
	 * @param array<string,array<string,mixed>> $map Nested [handle][key] => value.
	 */
	private function stub_wp_scripts( array $map ): void {
		$scripts = new class( $map ) {
			/**
			 * Map of handle → key → value.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $map;

			/**
			 * Hold the stub's data map.
			 *
			 * @param array<string,array<string,mixed>> $map Nested handle/key map.
			 */
			public function __construct( array $map ) {
				$this->map = $map;
			}

			/**
			 * Mirror of WP_Scripts::get_data.
			 *
			 * @param string $handle Script handle.
			 * @param string $key    Data key.
			 * @return mixed
			 */
			public function get_data( string $handle, string $key ) {
				return $this->map[ $handle ][ $key ] ?? '';
			}
		};

		Functions\when( 'wp_scripts' )->alias(
			static function () use ( $scripts ) {
				return $scripts;
			}
		);
	}

	/**
	 * Components handle with a stored integrity → attributes injected.
	 */
	public function test_injects_integrity_and_crossorigin_for_components_handle(): void {
		$this->stub_wp_scripts(
			array(
				AssetEnqueue::HANDLE_COMPONENTS => array(
					'integrity'   => 'sha512-abc123',
					'crossorigin' => 'anonymous',
				),
			)
		);

		$enqueue = new AssetEnqueue();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixture string, not a real enqueue.
		$tag = '<script src="/plugins/qrauth-psl/assets/js/qrauth-components.js" id="qrauth-psl-components-js"></script>';

		$result = $enqueue->filter_script_tag( $tag, AssetEnqueue::HANDLE_COMPONENTS );

		$this->assertStringContainsString( 'integrity="sha512-abc123"', $result );
		$this->assertStringContainsString( 'crossorigin="anonymous"', $result );
		$this->assertStringContainsString( 'src="/plugins/qrauth-psl/assets/js/qrauth-components.js"', $result );
	}

	/**
	 * Other handles are returned untouched (e.g. the adapter itself, or
	 * any of WordPress core's own scripts).
	 */
	public function test_passes_through_other_handles_unchanged(): void {
		$this->stub_wp_scripts(
			array(
				AssetEnqueue::HANDLE_COMPONENTS => array(
					'integrity'   => 'sha512-abc123',
					'crossorigin' => 'anonymous',
				),
			)
		);

		$enqueue = new AssetEnqueue();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixture string, not a real enqueue.
		$tag = '<script src="/wp-admin/js/common.js" id="common-js"></script>';

		$this->assertSame( $tag, $enqueue->filter_script_tag( $tag, 'common' ) );
		$this->assertSame( $tag, $enqueue->filter_script_tag( $tag, AssetEnqueue::HANDLE_ADAPTER ) );
	}

	/**
	 * When WP has no integrity stored for the handle, the tag is untouched
	 * (fail-open — we'd rather ship no SRI than a broken SRI attribute).
	 */
	public function test_passes_through_when_integrity_missing(): void {
		$this->stub_wp_scripts( array() );

		$enqueue = new AssetEnqueue();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixture string, not a real enqueue.
		$tag = '<script src="/plugins/qrauth-psl/assets/js/qrauth-components.js" id="qrauth-psl-components-js"></script>';

		$this->assertSame( $tag, $enqueue->filter_script_tag( $tag, AssetEnqueue::HANDLE_COMPONENTS ) );
	}
}
