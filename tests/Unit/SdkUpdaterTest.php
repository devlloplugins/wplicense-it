<?php
/**
 * Tests for the client SDK's update handling.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Tests\Support\SdkArrayStore;
use Devllo\WPLicenseIt\Tests\Support\SdkFakeTransport;
use PHPUnit\Framework\TestCase;
use WPLicenseIt\Client\ApiResult;
use WPLicenseIt\Client\Config;
use WPLicenseIt\Client\LicenseManager;
use WPLicenseIt\Client\LicenseState;
use WPLicenseIt\Client\ServerApi;
use WPLicenseIt\Client\UpdateInfo;
use WPLicenseIt\Client\UpdateService;
use WPLicenseIt\Client\Updater;

require_once dirname( __DIR__ ) . '/Support/sdk-wp-stubs.php';

/**
 * @covers \WPLicenseIt\Client\Updater
 * @covers \WPLicenseIt\Client\UpdateService
 * @covers \WPLicenseIt\Client\UpdateInfo
 */
final class SdkUpdaterTest extends TestCase {

	private const NOW = 1800000000;

	private $transport;
	private $store;
	private $config;
	private $service;
	private $updater;

	protected function setUp(): void {
		$GLOBALS['wplit_test_downloaded'] = array();

		$this->transport = new SdkFakeTransport();
		$this->store     = new SdkArrayStore();
		$this->config    = Config::from_array( array( 'server' => 'https://shop.example.com', 'product_id' => 7, 'name' => 'Great Plugin', 'version' => '1.2.0', 'file' => '/x', 'basename' => 'great-plugin/great-plugin.php' ) );
		$api             = new ServerApi( $this->config, $this->transport );
		$this->service   = new UpdateService( $api, $this->store, 'https://shop.example.com' );
		$manager         = new LicenseManager( $api, $this->store, 'https://customer.example.com', fn(): int => time() );
		$this->updater   = new Updater( $this->config, $this->service, $manager );

		// An active license that expires far in the future.
		$state             = new LicenseState();
		$state->key        = 'KEY-123';
		$state->status     = LicenseState::ACTIVE;
		$state->expires_at = null;
		$this->store->save( $state );
	}

	private function update_response( string $version = '2.0.0' ): array {
		return array(
			'success'          => true,
			'product'          => array( 'id' => 7, 'name' => 'Great Plugin', 'version' => $version, 'requires' => '6.2', 'tested' => '6.6', 'description' => 'Does great things.', 'banners' => array( 'low' => 'https://x/low.png', 'high' => 'https://x/high.png' ) ),
			'update_available' => true,
			'download_url'     => 'https://shop.example.com/wp-json/wplicense-it/v1/download?token=abc',
			'expires_in'       => 900,
		);
	}

	// Looking up updates.

	public function test_the_latest_version_is_cached_without_the_download_link(): void {
		$this->transport->reply( 200, $this->update_response() );

		$info = $this->service->latest( $this->store->load(), self::NOW );

		$this->assertSame( '2.0.0', $info->version );
		$this->assertSame( 21600, $this->store->ttl['update'] );
		$this->assertStringNotContainsString( 'token=', json_encode( $this->store->cache['update'] ) );

		// The second lookup is served from the cache.
		$this->service->latest( $this->store->load(), self::NOW );
		$this->assertCount( 1, $this->transport->requests );
	}

	public function test_a_force_lookup_ignores_the_cache(): void {
		$this->transport->reply( 200, $this->update_response( '2.0.0' ) )->reply( 200, $this->update_response( '2.1.0' ) );

		$this->service->latest( $this->store->load(), self::NOW );
		$info = $this->service->latest( $this->store->load(), self::NOW, true );

		$this->assertSame( '2.1.0', $info->version );
	}

	public function test_no_lookup_is_made_without_an_active_license(): void {
		$this->assertNull( $this->service->latest( new LicenseState(), self::NOW ) );
		$this->assertSame( array(), $this->transport->requests );
	}

	public function test_a_definite_no_is_remembered_for_an_hour_but_a_hiccup_is_not(): void {
		$this->transport->reply( 403, array( 'success' => false, 'code' => 'expired' ) );
		$this->assertNull( $this->service->latest( $this->store->load(), self::NOW ) );
		$this->assertSame( 'none', $this->store->cache['update'] );
		$this->assertSame( 3600, $this->store->ttl['update'] );

		$this->assertNull( $this->service->latest( $this->store->load(), self::NOW ) ); // From the cache.
		$this->assertCount( 1, $this->transport->requests );

		$this->store->cache = array();
		$this->transport->fail();
		$this->assertNull( $this->service->latest( $this->store->load(), self::NOW ) );
		$this->assertArrayNotHasKey( 'update', $this->store->cache );
	}

	public function test_a_response_without_a_version_is_ignored(): void {
		$this->transport->reply( 200, array( 'success' => true, 'product' => array() ) );

		$this->assertNull( $this->service->latest( $this->store->load(), self::NOW ) );
	}

	public function test_the_download_link_is_always_fresh(): void {
		$this->transport->reply( 200, $this->update_response() )->reply( 200, $this->update_response() );

		$this->service->download_url( $this->store->load() );
		$url = $this->service->download_url( $this->store->load() );

		$this->assertSame( 'https://shop.example.com/wp-json/wplicense-it/v1/download?token=abc', $url );
		$this->assertCount( 2, $this->transport->requests );
	}

	public function test_a_download_link_for_another_host_is_never_used(): void {
		$response                 = $this->update_response();
		$response['download_url'] = 'https://evil.example.net/steal.zip';
		$this->transport->reply( 200, $response );

		$result = $this->service->download_url( $this->store->load() );

		$this->assertInstanceOf( ApiResult::class, $result );
		$this->assertStringContainsString( 'another site', $result->message );

		// The same host on another port or with other capitals is still the configured server.
		$response['download_url'] = 'https://SHOP.example.com:8443/wp-json/wplicense-it/v1/download?token=abc';
		$this->transport->reply( 200, $response );
		$this->assertSame( 'https://SHOP.example.com:8443/wp-json/wplicense-it/v1/download?token=abc', $this->service->download_url( $this->store->load() ) );
	}

	public function test_a_missing_package_is_reported(): void {
		$this->transport->reply( 404, array( 'success' => false, 'code' => 'no_package' ) );

		$result = $this->service->download_url( $this->store->load() );

		$this->assertInstanceOf( ApiResult::class, $result );
		$this->assertSame( 'no_package', $result->code );
	}

	// WordPress update data.

	public function test_an_update_is_offered_when_the_version_is_newer(): void {
		$this->transport->reply( 200, $this->update_response( '2.0.0' ) );
		$transient = (object) array( 'response' => array(), 'no_update' => array( 'great-plugin/great-plugin.php' => (object) array() ) );

		$result = $this->updater->add_plugin_update( $transient );
		$entry  = $result->response['great-plugin/great-plugin.php'];

		$this->assertSame( '2.0.0', $entry->new_version );
		$this->assertSame( 'great-plugin', $entry->slug );
		$this->assertSame( 'great-plugin/great-plugin.php', $entry->plugin );
		$this->assertSame( 'wplicense-it://great-plugin', $entry->package );
		$this->assertSame( '6.6', $entry->tested );
		$this->assertArrayNotHasKey( 'great-plugin/great-plugin.php', $result->no_update );
	}

	public function test_no_update_is_offered_when_up_to_date(): void {
		$this->transport->reply( 200, $this->update_response( '1.2.0' ) );

		$result = $this->updater->add_plugin_update( (object) array( 'response' => array() ) );

		$this->assertSame( array(), $result->response );
	}

	public function test_a_stale_entry_is_removed_when_the_license_can_no_longer_update(): void {
		$this->store->clear(); // License gone.
		$stale     = (object) array( 'package' => 'wplicense-it://great-plugin', 'new_version' => '2.0.0' );
		$other     = (object) array( 'package' => 'https://elsewhere/x.zip' );
		$transient = (object) array( 'response' => array( 'great-plugin/great-plugin.php' => $stale, 'other/other.php' => $other ) );

		$result = $this->updater->add_plugin_update( $transient );

		$this->assertArrayNotHasKey( 'great-plugin/great-plugin.php', $result->response );
		$this->assertArrayHasKey( 'other/other.php', $result->response ); // Other plugins are never touched.
	}

	public function test_non_object_transients_pass_through(): void {
		$this->assertFalse( $this->updater->add_plugin_update( false ) );
	}

	public function test_a_theme_entry_has_the_shape_wordpress_expects(): void {
		$theme = Config::from_array( array( 'server' => 'https://shop.example.com', 'product_id' => 7, 'version' => '1.0.0', 'type' => 'theme', 'slug' => 'pretty-theme' ) );
		$info  = UpdateInfo::from_response( $this->update_response( '1.1.0' ) );

		$entry = Updater::theme_entry( $theme, $info );

		$this->assertSame( 'pretty-theme', $entry['theme'] );
		$this->assertSame( '1.1.0', $entry['new_version'] );
		$this->assertSame( 'wplicense-it://pretty-theme', $entry['package'] );
	}

	public function test_the_details_popup_is_filled_for_our_plugin_only(): void {
		$this->transport->reply( 200, $this->update_response( '2.0.0' ) );

		$details = $this->updater->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'great-plugin' ) );

		$this->assertSame( 'Great Plugin', $details->name );
		$this->assertSame( '2.0.0', $details->version );
		$this->assertSame( 'wplicense-it://great-plugin', $details->download_link );
		$this->assertStringContainsString( 'Does great things.', $details->sections['description'] );

		$this->assertFalse( $this->updater->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'someone-else' ) ) );
		$this->assertFalse( $this->updater->plugin_information( false, 'query_plugins', (object) array( 'slug' => 'great-plugin' ) ) );
	}

	// Installing.

	public function test_the_marker_is_replaced_by_a_fresh_download_at_install_time(): void {
		$this->transport->reply( 200, $this->update_response() );

		$path = $this->updater->download_package( false, 'wplicense-it://great-plugin' );

		$this->assertSame( '/tmp/downloaded.zip', $path );
		$this->assertSame( array( 'https://shop.example.com/wp-json/wplicense-it/v1/download?token=abc' ), $GLOBALS['wplit_test_downloaded'] );
	}

	public function test_other_packages_are_left_to_wordpress(): void {
		$this->assertFalse( $this->updater->download_package( false, 'https://downloads.wordpress.org/plugin/x.zip' ) );
		$this->assertFalse( $this->updater->download_package( false, 'wplicense-it://another-plugin' ) );
		$this->assertSame( array(), $this->transport->requests );
	}

	public function test_a_failed_download_becomes_a_clear_error(): void {
		$this->transport->reply( 403, array( 'success' => false, 'code' => 'expired' ) );

		$error = $this->updater->download_package( false, 'wplicense-it://great-plugin' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertStringContainsString( 'expired', $error->get_error_message() );
	}

	public function test_the_unpacked_folder_is_renamed_to_the_slug(): void {
		$GLOBALS['wp_filesystem'] = new class() {
			public $moves = array();

			public function move( $from, $to, $overwrite ) {
				$this->moves[] = array( $from, $to );

				return true;
			}
		};

		$dir = sys_get_temp_dir() . '/wplit-sdk-test-' . uniqid();
		mkdir( $dir . '/great-plugin-2.0.0', 0777, true );

		$result = $this->updater->fix_folder_name( $dir . '/great-plugin-2.0.0/', $dir, null, array( 'plugin' => 'great-plugin/great-plugin.php' ) );

		$this->assertSame( $dir . '/great-plugin/', $result );
		$this->assertSame( array( array( $dir . '/great-plugin-2.0.0', $dir . '/great-plugin' ) ), $GLOBALS['wp_filesystem']->moves );

		// Updates of other plugins are not touched.
		$GLOBALS['wp_filesystem']->moves = array();
		$this->assertSame( $dir . '/x/', $this->updater->fix_folder_name( $dir . '/x/', $dir, null, array( 'plugin' => 'other/other.php' ) ) );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );

		rmdir( $dir . '/great-plugin-2.0.0' );
		rmdir( $dir );
		unset( $GLOBALS['wp_filesystem'] );
	}

	public function test_versions_are_compared_the_way_wordpress_does(): void {
		$info = UpdateInfo::from_response( $this->update_response( '1.10.0' ) );

		$this->assertTrue( $info->is_newer_than( '1.9.0' ) );
		$this->assertFalse( $info->is_newer_than( '1.10.0' ) );
		$this->assertFalse( $info->is_newer_than( '2.0' ) );
		$this->assertSame( $info->to_array(), UpdateInfo::from_array( $info->to_array() )->to_array() );
		$this->assertNull( UpdateInfo::from_array( 'junk' ) );
	}
}
