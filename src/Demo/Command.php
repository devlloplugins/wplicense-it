<?php
/**
 * WP-CLI commands for the demo store.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Demo;

use Devllo\WPLicenseIt\Plugin;
use RuntimeException;
use WP_CLI;

/**
 * Fills a test site with a demo store, and removes it again.
 *
 * The demo makes two products with releases, four plans published in WooCommerce, eight customers
 * (demo-<name>@example.com) and nine real WooCommerce orders, with licenses in every state. Use it on a test or demo
 * site, not on a live store: the orders show in WooCommerce's reports until the demo is removed.
 *
 * ## EXAMPLES
 *
 *     wp wplit demo create
 *     wp wplit demo status
 *     wp wplit demo remove
 */
final class Command {

	/**
	 * Makes the demo store.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function create( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'This adds demo products, customers and WooCommerce orders to ' . home_url() . '. Use a test site. Continue?', $assoc_args );

		$demo = $this->demo();

		try {
			$notes = $demo->create();
		} catch ( RuntimeException $e ) {
			$made = DemoStore::recorded();
			WP_CLI::error( $e->getMessage() . ( null !== $made ? ' What was made so far is recorded: run wp wplit demo remove to clean it up.' : '' ) );
		}

		foreach ( $notes as $note ) {
			WP_CLI::warning( $note );
		}

		WP_CLI::success( 'The demo store is ready. Open License Products > Overview. Remove it with: wp wplit demo remove' );
	}

	/**
	 * Says whether there is a demo store, and what it holds.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$made = DemoStore::recorded();
		if ( null === $made ) {
			WP_CLI::log( 'There is no demo store on this site.' );

			return;
		}

		WP_CLI::log( 'Demo store made on ' . (string) ( $made['created_at'] ?? '?' ) . ' (UTC):' );
		foreach ( array( 'products', 'woo_products', 'users', 'orders', 'licenses' ) as $kind ) {
			WP_CLI::log( sprintf( '  %-13s %d', $kind, count( (array) ( $made[ $kind ] ?? array() ) ) ) );
		}
	}

	/**
	 * Deletes everything the demo store made, and nothing else.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function remove( array $args, array $assoc_args ): void {
		if ( null === DemoStore::recorded() ) {
			WP_CLI::log( 'There is no demo store on this site.' );

			return;
		}

		WP_CLI::confirm( 'This deletes the demo products, customers, orders and licenses. Continue?', $assoc_args );

		$count = $this->demo()->remove();
		$parts = array();
		foreach ( $count as $kind => $n ) {
			$parts[] = $n . ' ' . str_replace( '_', ' ', $kind );
		}

		WP_CLI::success( 'Removed the demo store: ' . implode( ', ', $parts ) . '.' );
	}

	/**
	 * The demo store, printing its progress.
	 */
	private function demo(): DemoStore {
		return new DemoStore( Plugin::instance()->licenses(), $GLOBALS['wpdb'], static fn( string $line ) => WP_CLI::log( $line ) );
	}
}
