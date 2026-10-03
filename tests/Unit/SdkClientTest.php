<?php
/**
 * Tests for the client SDK: configuration, server API and license management.
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
use WPLicenseIt\Client\LicensePage;
use WPLicenseIt\Client\LicenseState;
use WPLicenseIt\Client\ServerApi;

/**
 * @covers \WPLicenseIt\Client\Config
 * @covers \WPLicenseIt\Client\ServerApi
 * @covers \WPLicenseIt\Client\ApiResult
 * @covers \WPLicenseIt\Client\LicenseManager
 * @covers \WPLicenseIt\Client\LicenseState
 * @covers \WPLicenseIt\Client\LicensePage
 */
final class SdkClientTest extends TestCase {

	private const NOW  = 1800000000;
	private const SITE = 'https://customer.example.com';

	private $transport;
	private $store;
	private $manager;

	protected function setUp(): void {
		$this->transport = new SdkFakeTransport();
		$this->store     = new SdkArrayStore();
		$config          = Config::from_array( array( 'server' => 'https://shop.example.com/', 'product_id' => 7, 'name' => 'Great Plugin', 'version' => '1.2.0', 'file' => '/x', 'basename' => 'great-plugin/great-plugin.php' ) );
		$this->manager   = new LicenseManager( new ServerApi( $config, $this->transport ), $this->store, self::SITE, fn(): int => self::NOW );
	}

	private function license_body( string $code = 'activated', array $license = array() ): array {
		return array(
			'success' => true,
			'code'    => $code,
			'license' => $license + array( 'status' => 'active', 'expires_at' => '2030-01-01T00:00:00+00:00', 'activation_limit' => 3, 'activations_used' => 1 ),
		);
	}

	private function activated(): void {
		$this->transport->reply( 200, $this->license_body() );
		$this->manager->activate( 'KEY-123' );
		$this->transport->requests = array();
	}

	// Configuration.

	public function test_a_plugin_config_gets_its_slug_and_marker_from_the_basename(): void {
		$config = Config::from_array( array( 'server' => 'https://shop.example.com/', 'product_id' => '7', 'version' => '1.0', 'file' => '/x', 'basename' => 'My Plugin/main.php' ) );

		$this->assertSame( 'https://shop.example.com', $config->server );
		$this->assertSame( 7, $config->product_id );
		$this->assertSame( 'my-plugin', $config->slug );
		$this->assertSame( 'plugin', $config->type );
		$this->assertSame( 'wplit_client_my-plugin', $config->option_name() );
		$this->assertSame( 'wplicense-it://my-plugin', $config->package_marker() );
		$this->assertSame( 'options-general.php', $config->menu_parent );
		$this->assertSame( 'my-plugin License', $config->menu_title );
	}

	public function test_a_single_file_plugin_and_a_theme(): void {
		$single = Config::from_array( array( 'server' => 'https://s.example.com', 'product_id' => 1, 'version' => '1', 'file' => '/x', 'basename' => 'solo.php' ) );
		$theme  = Config::from_array( array( 'server' => 'https://s.example.com', 'product_id' => 1, 'version' => '1', 'type' => 'theme', 'slug' => 'Pretty Theme', 'menu' => false ) );

		$this->assertSame( 'solo', $single->slug );
		$this->assertSame( 'theme', $theme->type );
		$this->assertSame( 'pretty-theme', $theme->slug );
		$this->assertSame( '', $theme->menu_parent );
	}

	public function test_bad_settings_are_rejected(): void {
		$good = array( 'server' => 'https://s.example.com', 'product_id' => 1, 'version' => '1', 'file' => '/x', 'basename' => 'p/p.php' );

		$bad = array(
			'no server'          => array( 'server' => '' ) + $good,
			'plain http'         => array( 'server' => 'http://shop.example.com' ) + $good,
			'not a url'          => array( 'server' => 'shop' ) + $good,
			'no product'         => array( 'product_id' => 0 ) + $good,
			'no version'         => array( 'version' => ' ' ) + $good,
			'no file or type'    => array( 'file' => '' ) + $good,
			'theme without slug' => array( 'file' => '', 'type' => 'theme' ) + $good,
		);

		foreach ( $bad as $label => $args ) {
			try {
				Config::from_array( $args );
				$this->fail( "Expected '{$label}' to be rejected." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'WPLicense It client', $e->getMessage() );
			}
		}
	}

	public function test_http_is_accepted_only_for_local_development(): void {
		foreach ( array( 'http://localhost:8080', 'http://127.0.0.1', 'http://shop.test', 'http://shop.local' ) as $server ) {
			$this->assertSame( $server, Config::from_array( array( 'server' => $server, 'product_id' => 1, 'version' => '1', 'file' => '/x', 'basename' => 'p/p.php' ) )->server );
		}
	}

	// Server API.

	public function test_activation_sends_only_what_the_server_needs(): void {
		$this->transport->reply( 200, $this->license_body() );

		$this->manager->activate( ' KEY-123 ' );

		$request = $this->transport->last();
		$this->assertSame( 'https://shop.example.com/wp-json/wplicense-it/v1/licenses/activate', $request['url'] );
		$this->assertSame(
			array( 'license_key' => 'KEY-123', 'product_id' => 7, 'site_url' => self::SITE, 'product_version' => '1.2.0' ),
			$request['body']
		);
	}

	public function test_it_falls_back_to_rest_route_when_pretty_urls_are_unavailable(): void {
		$this->transport->reply_raw( 404, '<html>Not found</html>' )->reply( 200, $this->license_body() );

		$result = $this->manager->activate( 'KEY-123' );

		$this->assertTrue( $result->ok );
		$this->assertSame( 'https://shop.example.com/?rest_route=/wplicense-it/v1/licenses/activate', $this->transport->last()['url'] );
	}

	public function test_a_json_404_is_a_real_answer_not_a_reason_to_retry(): void {
		$this->transport->reply( 404, array( 'success' => false, 'code' => 'not_found', 'message' => 'License not found.' ) );

		$result = $this->manager->activate( 'NOPE' );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'not_found', $result->code );
		$this->assertCount( 1, $this->transport->requests );
		$this->assertStringContainsString( 'typing mistakes', $result->message );
	}

	public function test_problems_with_the_server_are_temporary_and_definite_answers_are_not(): void {
		$this->transport->fail();
		$this->transport->reply( 503, array() );
		$this->transport->reply_raw( 200, 'not json' );
		$this->transport->reply( 429, array( 'success' => false, 'code' => 'rate_limited' ) );
		$this->transport->reply( 403, array( 'success' => false, 'code' => 'expired' ) );

		$codes = array();
		foreach ( range( 1, 5 ) as $i ) {
			$result  = $this->manager->activate( 'KEY' );
			$codes[] = array( $result->code, $result->is_temporary() );
		}

		$this->assertSame(
			array(
				array( ApiResult::NETWORK_ERROR, true ),
				array( ApiResult::SERVER_ERROR, true ),
				array( ApiResult::BAD_RESPONSE, true ),
				array( ApiResult::RATE_LIMITED, true ),
				array( 'expired', false ),
			),
			$codes
		);
	}

	// Activating.

	public function test_activation_stores_the_license(): void {
		$this->transport->reply( 200, $this->license_body() );

		$result = $this->manager->activate( 'KEY-123' );
		$state  = $this->store->load();

		$this->assertTrue( $result->ok );
		$this->assertSame( 'KEY-123', $state->key );
		$this->assertSame( LicenseState::ACTIVE, $state->status );
		$this->assertSame( strtotime( '2030-01-01T00:00:00+00:00' ), $state->expires_at );
		$this->assertSame( 3, $state->limit );
		$this->assertSame( 1, $state->used );
		$this->assertSame( self::NOW, $state->last_checked );
		$this->assertTrue( $this->manager->is_active() );
	}

	public function test_a_lifetime_license_has_no_expiry(): void {
		$this->transport->reply( 200, $this->license_body( 'activated', array( 'expires_at' => null, 'activation_limit' => 0 ) ) );

		$this->manager->activate( 'KEY-123' );

		$this->assertNull( $this->store->load()->expires_at );
		$this->assertTrue( $this->manager->is_active() );
	}

	public function test_a_rejected_key_stores_nothing_and_keeps_the_previous_license(): void {
		$this->activated();
		$this->transport->reply( 404, array( 'success' => false, 'code' => 'not_found' ) );

		$result = $this->manager->activate( 'OTHER-KEY' );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'KEY-123', $this->store->load()->key );
	}

	public function test_empty_and_overlong_keys_never_reach_the_server(): void {
		$this->assertSame( 'not_found', $this->manager->activate( '   ' )->code );
		$this->assertSame( 'not_found', $this->manager->activate( str_repeat( 'k', 65 ) )->code );
		$this->assertSame( array(), $this->transport->requests );
	}

	public function test_the_limit_reached_message_tells_the_customer_what_to_do(): void {
		$this->transport->reply( 403, array( 'success' => false, 'code' => 'limit_reached' ) );

		$this->assertStringContainsString( 'Deactivate it on another site', $this->manager->activate( 'KEY' )->message );
	}

	// Expiry and refresh.

	public function test_a_license_past_its_expiry_is_inactive_without_asking_the_server(): void {
		$this->transport->reply( 200, $this->license_body( 'activated', array( 'expires_at' => gmdate( 'c', self::NOW + 100 ) ) ) );
		$this->manager->activate( 'KEY-123' );
		$later = new LicenseManager( new ServerApi( Config::from_array( array( 'server' => 'https://s.example.com', 'product_id' => 7, 'version' => '1', 'file' => '/x', 'basename' => 'p/p.php' ) ), $this->transport ), $this->store, self::SITE, fn(): int => self::NOW + 200 );

		$this->assertTrue( $this->manager->is_active() );
		$this->assertFalse( $later->is_active() );
	}

	public function test_a_successful_refresh_updates_the_details(): void {
		$this->activated();
		$this->transport->reply( 200, $this->license_body( 'ok', array( 'expires_at' => '2031-01-01T00:00:00+00:00', 'activations_used' => 2 ) ) + array( 'site_active' => true ) );

		$result = $this->manager->refresh();

		$this->assertTrue( $result->ok );
		$this->assertSame( 'https://shop.example.com/wp-json/wplicense-it/v1/licenses/validate', $this->transport->last()['url'] );
		$this->assertSame( self::SITE, $this->transport->last()['body']['site_url'] );
		$this->assertSame( strtotime( '2031-01-01T00:00:00+00:00' ), $this->store->load()->expires_at );
		$this->assertSame( 2, $this->store->load()->used );
	}

	public function test_the_server_ending_a_license_is_believed(): void {
		foreach ( array( 'expired' => LicenseState::EXPIRED, 'revoked' => LicenseState::REVOKED, 'refunded' => LicenseState::REFUNDED, 'not_found' => LicenseState::INVALID ) as $code => $status ) {
			$this->activated();
			$this->transport->reply( 'not_found' === $code ? 404 : 403, array( 'success' => false, 'code' => $code ) );

			$this->manager->refresh();

			$this->assertSame( $status, $this->store->load()->status, $code );
			$this->assertFalse( $this->manager->is_active(), $code );
			$this->assertSame( 'KEY-123', $this->store->load()->key ); // Kept, so a renewal revives it.
		}
	}

	public function test_a_renewed_license_starts_working_again_at_the_next_check(): void {
		$this->activated();
		$this->transport->reply( 403, array( 'success' => false, 'code' => 'expired' ) );
		$this->manager->refresh();
		$this->assertFalse( $this->manager->is_active() );

		$this->transport->reply( 200, $this->license_body( 'ok' ) + array( 'site_active' => true ) );
		$this->manager->refresh();

		$this->assertTrue( $this->manager->is_active() );
	}

	public function test_a_site_deactivated_elsewhere_is_reported(): void {
		$this->activated();
		$this->transport->reply( 200, $this->license_body( 'ok' ) + array( 'site_active' => false ) );

		$this->manager->refresh();

		$this->assertSame( LicenseState::INACTIVE, $this->store->load()->status );
		$this->assertStringContainsString( 'no longer activated', $this->store->load()->message );
		$this->assertFalse( $this->manager->is_active() );
	}

	public function test_no_connection_never_downgrades_a_license(): void {
		$this->activated();
		$before = $this->store->data;

		foreach ( array( 'fail', 503, 429, 'garbage' ) as $problem ) {
			if ( 'fail' === $problem ) {
				$this->transport->fail();
			} elseif ( 'garbage' === $problem ) {
				$this->transport->reply_raw( 200, '<html>' );
			} else {
				$this->transport->reply( $problem, array( 'success' => false, 'code' => 'rate_limited' ) );
			}

			$result = $this->manager->refresh();

			$this->assertTrue( $result->is_temporary() );
			$this->assertSame( $before, $this->store->data ); // Not even the last-checked time changes.
			$this->assertTrue( $this->manager->is_active() );
		}
	}

	public function test_refresh_without_a_license_does_nothing(): void {
		$this->assertNull( $this->manager->refresh() );
		$this->assertSame( array(), $this->transport->requests );
	}

	public function test_it_checks_once_a_day(): void {
		$this->activated();

		$this->assertFalse( $this->manager->needs_refresh() );

		$state               = $this->store->load();
		$state->last_checked = self::NOW - LicenseManager::REFRESH_INTERVAL;
		$this->store->save( $state );

		$this->assertTrue( $this->manager->needs_refresh() );
	}

	// Deactivating.

	public function test_deactivating_frees_the_slot_and_forgets_the_license(): void {
		$this->activated();
		$this->store->cache['update'] = array( 'version' => '9' );
		$this->transport->reply( 200, array( 'success' => true, 'code' => 'deactivated', 'license' => array() ) );

		$result = $this->manager->deactivate();

		$this->assertTrue( $result->ok );
		$this->assertSame( 'https://shop.example.com/wp-json/wplicense-it/v1/licenses/deactivate', $this->transport->last()['url'] );
		$this->assertSame( array(), $this->store->data );
		$this->assertSame( array(), $this->store->cache );
	}

	public function test_deactivating_a_site_the_server_does_not_know_still_forgets_it(): void {
		$this->activated();
		$this->transport->reply( 404, array( 'success' => false, 'code' => 'not_active' ) );

		$this->assertTrue( $this->manager->deactivate()->ok );
		$this->assertSame( array(), $this->store->data );
	}

	public function test_deactivating_without_a_connection_keeps_the_license_unless_forced(): void {
		$this->activated();
		$this->transport->fail();

		$failed = $this->manager->deactivate();

		$this->assertFalse( $failed->ok );
		$this->assertTrue( $failed->is_temporary() );
		$this->assertSame( 'KEY-123', $this->store->load()->key );

		$this->assertTrue( $this->manager->deactivate( true )->ok );
		$this->assertSame( array(), $this->store->data );
	}

	public function test_deactivating_with_nothing_activated_is_fine(): void {
		$this->assertTrue( $this->manager->deactivate()->ok );
		$this->assertSame( array(), $this->transport->requests );
	}

	// State and display.

	public function test_state_round_trips_and_ignores_junk(): void {
		$state               = new LicenseState();
		$state->key          = 'K';
		$state->status       = LicenseState::ACTIVE;
		$state->expires_at   = 123;
		$state->limit        = 5;
		$state->used         = 2;
		$state->last_checked = 99;
		$state->message      = 'hi';

		$this->assertSame( $state->to_array(), LicenseState::from_array( $state->to_array() )->to_array() );
		$this->assertSame( LicenseState::INACTIVE, LicenseState::from_array( 'junk' )->status );
		$this->assertFalse( ( new LicenseState() )->is_active( self::NOW ) );
	}

	public function test_only_the_end_of_a_key_is_shown(): void {
		$this->assertSame( str_repeat( '•', 12 ) . 'ABCDE', LicensePage::mask( 'K7QM2-9XDHB-ABCDE' ) );
		$this->assertSame( 'ABCDE', substr( LicensePage::mask( 'K7QM2-9XDHB-ABCDE' ), -5 ) );
		$this->assertStringNotContainsString( 'K7QM2', LicensePage::mask( 'K7QM2-9XDHB-ABCDE' ) );
		$this->assertSame( '•••', LicensePage::mask( 'abc' ) );
	}
}
