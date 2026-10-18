<?php
/**
 * Overview screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Files\ProtectedStorage;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Migration\MigrationState;
use Devllo\WPLicenseIt\Migration\OptionStateStore;
use Devllo\WPLicenseIt\Overview\OverviewReport;
use Devllo\WPLicenseIt\Reminders\ReminderSettings;
use Devllo\WPLicenseIt\Renewals\RenewalSettings;
use Devllo\WPLicenseIt\WooCommerce\IssueProblems;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;

/**
 * The first screen of the plugin: what needs attention, the setup steps that are left, how many licenses there are in each
 * state, the ones about to expire and the newest ones.
 */
final class OverviewScreen {

	private const EXPIRING_DAYS  = 30;
	private const EXPIRING_LIMIT = 10;
	private const RECENT_LIMIT   = 8;

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		Capabilities::require_manage();

		$facts  = $this->facts();
		$counts = $this->licenses->status_counts();
		$names  = array();

		echo '<div class="wrap wplit-overview"><h1>' . esc_html__( 'Overview', 'wplicense-it' ) . '</h1>';
		echo '<style>'
			. '.wplit-overview .wplit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px}'
			. '.wplit-overview .wplit-panel{background:#fff;border:1px solid #c3c4c7;padding:4px 16px 16px}'
			. '.wplit-overview .wplit-panel h2{font-size:15px;margin:12px 0}'
			. '.wplit-overview .wplit-steps{list-style:none;margin:0;padding:0}'
			. '.wplit-overview .wplit-steps li{padding:6px 0;border-bottom:1px solid #f0f0f1}'
			. '.wplit-overview .wplit-steps li:last-child{border-bottom:0}'
			. '.wplit-overview .wplit-done{color:#0a3622}'
			. '.wplit-overview .wplit-numbers{display:flex;flex-wrap:wrap;gap:12px}'
			. '.wplit-overview .wplit-numbers a{display:block;min-width:90px;padding:8px 12px;border:1px solid #dcdcde;text-decoration:none;text-align:center}'
			. '.wplit-overview .wplit-numbers strong{display:block;font-size:22px;line-height:1.3}'
			. '.wplit-overview .wplit-wide{grid-column:1/-1}'
			. '.wplit-overview code{background:transparent;padding:0;white-space:nowrap}'
			. '</style>';

		$this->render_problems( OverviewReport::problems( $facts ), $facts['order_problem_list'] ?? array() );

		echo '<div class="wplit-grid">';
		$this->render_checklist( OverviewReport::checklist( $facts ) );
		$this->render_numbers( $counts );
		$this->render_licenses(
			sprintf(
				/* translators: %d: number of days. */
				_n( 'Expiring in the next %d day', 'Expiring in the next %d days', self::EXPIRING_DAYS, 'wplicense-it' ),
				self::EXPIRING_DAYS
			),
			$this->licenses->expiring_soon( self::EXPIRING_DAYS, self::EXPIRING_LIMIT ),
			'expires',
			__( 'Nothing expires soon.', 'wplicense-it' ),
			$names
		);
		$this->render_licenses(
			__( 'Newest licenses', 'wplicense-it' ),
			$this->licenses->search( LicenseQuery::from_input( array( 'per_page' => self::RECENT_LIMIT ) ) )->items,
			'created',
			__( 'No licenses yet.', 'wplicense-it' ),
			$names
		);
		echo '</div></div>';
	}

	/**
	 * The facts the report is built from.
	 *
	 * @return array<string, mixed>
	 */
	public function facts(): array {
		$products   = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Products are few; this only bounds the check.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$no_release = 0;

		foreach ( $products as $id ) {
			if ( '' === (string) get_post_meta( (int) $id, 'file_dir_path', true ) ) {
				++$no_release;
			}
		}

		$woo     = class_exists( 'WooCommerce' );
		$selling = false;

		if ( $woo ) {
			$selling = array() !== get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => OrderReader::META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, on an admin screen.
					'meta_compare'   => 'EXISTS',
				)
			);
		}

		$orders = $woo ? IssueProblems::recent( 5 ) : array(
			'total'  => 0,
			'orders' => array(),
		);

		$uploads = wp_upload_dir();
		$folder  = ProtectedStorage::directory();
		$state   = ( new OptionStateStore() )->load();
		$next    = wp_next_scheduled( Maintenance::HOOK );

		return array(
			'now'                      => time(),
			'products'                 => count( $products ),
			'products_without_release' => $no_release,
			'licenses_total'           => array_sum( $this->licenses->status_counts() ),
			'woo_active'               => $woo,
			'selling'                  => $selling,
			'sdk_downloaded'           => (bool) get_option( ConnectBox::DOWNLOADED_OPTION, false ),
			'reminders_on'             => ReminderSettings::enabled(),
			'invoices_on'              => RenewalSettings::enabled(),
			'cron_disabled'            => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'last_maintenance'         => (int) get_option( Maintenance::LAST_RUN_OPTION, 0 ),
			'next_maintenance'         => false === $next ? 0 : (int) $next,
			'order_problems'           => $orders['total'],
			'order_problem_list'       => $orders['orders'],
			'migration_attention'      => MigrationState::NEEDS_ATTENTION === $state->status,
			'storage_writable'         => is_dir( $folder ) ? wp_is_writable( $folder ) : wp_is_writable( (string) $uploads['basedir'] ),
		);
	}

	/**
	 * Problems, as notices.
	 *
	 * @param list<array{level:string,key:string,count:int}>              $problems    From OverviewReport::problems().
	 * @param list<array{id:int,number:string,problem:string,url:string}> $order_list The orders that could not get a license.
	 */
	private function render_problems( array $problems, array $order_list ): void {
		foreach ( $problems as $problem ) {
			$class = 'error' === $problem['level'] ? 'notice-error' : 'notice-warning';
			$link  = '';

			if ( 'migration' === $problem['key'] ) {
				$link = ' <a href="' . esc_url( Urls::settings() ) . '">' . esc_html__( 'See the details', 'wplicense-it' ) . '</a>';
			} elseif ( 'no_release' === $problem['key'] ) {
				$link = ' <a href="' . esc_url( admin_url( 'edit.php?post_type=wplit_product' ) ) . '">' . esc_html__( 'Open the products', 'wplicense-it' ) . '</a>';
			}

			$list = '';

			if ( 'orders' === $problem['key'] ) {
				foreach ( $order_list as $order ) {
					$list .= '<li><a href="' . esc_url( $order['url'] ) . '">' . esc_html(
						sprintf(
							/* translators: %s: order number. */
							__( 'Order %s', 'wplicense-it' ),
							$order['number']
						)
					) . '</a>: ' . esc_html( strtok( $order['problem'], "\n" ) ) . '</li>';
				}

				$list = '' === $list ? '' : '<ul style="list-style:disc;margin-left:20px">' . $list . '</ul>';
			}

			echo '<div class="notice ' . esc_attr( $class ) . ' inline" role="' . ( 'error' === $problem['level'] ? 'alert' : 'status' ) . '"><p>' . esc_html( $this->problem_text( $problem ) ) . $link . '</p>' . $list . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link and $list are built from escaped parts.
		}//end foreach
	}

	/**
	 * The words for a problem.
	 *
	 * @param array{level:string,key:string,count:int} $problem From OverviewReport::problems().
	 */
	private function problem_text( array $problem ): string {
		switch ( $problem['key'] ) {
			case 'orders':
				/* translators: %d: number of orders. */
				return sprintf( _n( '%d paid order could not be given its license, so the customer is waiting. Open it, fix the cause and press Try again.', '%d paid orders could not be given their licenses, so the customers are waiting. Open each one, fix the cause and press Try again.', $problem['count'], 'wplicense-it' ), $problem['count'] );

			case 'migration':
				return __( 'The move of your 1.x data needs attention. Some licenses or orders were not moved.', 'wplicense-it' );

			case 'storage':
				return __( 'The folder for product packages cannot be written to. Uploading a release will fail until its permissions are fixed.', 'wplicense-it' );

			case 'no_release':
				/* translators: %d: number of products. */
				return sprintf( _n( '%d published product has no release uploaded, so customers cannot download or update it.', '%d published products have no release uploaded, so customers cannot download or update them.', $problem['count'], 'wplicense-it' ), $problem['count'] );

			case 'cron_disabled':
				return __( 'WP-Cron is switched off on this site (DISABLE_WP_CRON). Marking licenses as expired, expiry reminders and renewal invoices only happen if a real cron job calls wp-cron.php every few minutes.', 'wplicense-it' );

			case 'job_stale':
				return __( 'The daily job (expiry, reminders, renewal invoices) has not run for more than two days. Check that WP-Cron works on this site.', 'wplicense-it' );

			default:
				return __( 'The daily job (expiry, reminders, renewal invoices) was due more than a day ago and has not run yet. Check that WP-Cron works on this site.', 'wplicense-it' );
		}//end switch
	}

	/**
	 * The setup checklist.
	 *
	 * @param array{items: list<array{key:string,done:bool,required:bool}>, complete: bool} $checklist From OverviewReport::checklist().
	 */
	private function render_checklist( array $checklist ): void {
		$open = array_filter( $checklist['items'], static fn( array $item ): bool => ! $item['done'] );

		echo '<section class="wplit-panel" aria-labelledby="wplit-steps-title"><h2 id="wplit-steps-title">' . esc_html( $checklist['complete'] ? __( 'Setup', 'wplicense-it' ) : __( 'Getting started', 'wplicense-it' ) ) . '</h2>';

		if ( $checklist['complete'] ) {
			echo '<p class="wplit-done">' . esc_html__( 'The basics are set up.', 'wplicense-it' ) . ( array() === $open ? '' : ' ' . esc_html__( 'These could help too:', 'wplicense-it' ) ) . '</p>';
		}

		echo '<ol class="wplit-steps">';

		foreach ( $checklist['items'] as $item ) {
			if ( $checklist['complete'] && $item['done'] ) {
				continue;
			}

			$step = $this->step( $item['key'] );
			$mark = $item['done'] ? '<span class="wplit-done" aria-hidden="true">&#10003;</span> <span class="screen-reader-text">' . esc_html__( 'Done:', 'wplicense-it' ) . '</span>' : '<span aria-hidden="true">&#9675;</span> <span class="screen-reader-text">' . esc_html__( 'To do:', 'wplicense-it' ) . '</span>';
			$text = '' === $step['url'] || $item['done'] ? esc_html( $step['label'] ) : '<a href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['label'] ) . '</a>';
			$tag  = $item['required'] ? '' : ' <span class="description">' . esc_html__( '(optional)', 'wplicense-it' ) . '</span>';

			echo '<li>' . $mark . $text . $tag . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
		}

		echo '</ol></section>';
	}

	/**
	 * The words and link of a checklist step.
	 *
	 * @param string $key Step.
	 * @return array{label:string,url:string}
	 */
	private function step( string $key ): array {
		$product = admin_url( 'post-new.php?post_type=wplit_product' );

		$steps = array(
			'product'     => array( __( 'Create your first product', 'wplicense-it' ), $product ),
			'release'     => array( __( 'Upload a release (a zip file) to a product', 'wplicense-it' ), admin_url( 'edit.php?post_type=wplit_product' ) ),
			'license'     => array( __( 'Issue a license, to try it yourself', 'wplicense-it' ), Urls::add() ),
			'sell'        => array( __( 'Add a plan with a price to a product, so customers can buy it', 'wplicense-it' ), admin_url( 'edit.php?post_type=wplit_product' ) ),
			'install_woo' => array( __( 'Install WooCommerce to sell licenses from your store, or issue them by hand', 'wplicense-it' ), admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ),
			'connect'     => array( __( 'Download the client SDK and add it to your plugin or theme', 'wplicense-it' ), ConnectBox::sdk_url() ),
			'reminders'   => array( __( 'Turn on expiry reminder emails', 'wplicense-it' ), Urls::settings() ),
			'invoices'    => array( __( 'Turn on renewal invoices', 'wplicense-it' ), Urls::settings() ),
		);

		$step = $steps[ $key ] ?? array( $key, '' );

		return array(
			'label' => $step[0],
			'url'   => $step[1],
		);
	}

	/**
	 * The number of licenses in each state, each a link to the filtered list.
	 *
	 * @param array<string, int> $counts Counts by status.
	 */
	private function render_numbers( array $counts ): void {
		$labels = array(
			Status::ACTIVE   => __( 'Active', 'wplicense-it' ),
			Status::EXPIRED  => __( 'Expired', 'wplicense-it' ),
			Status::REVOKED  => __( 'Revoked', 'wplicense-it' ),
			Status::REFUNDED => __( 'Refunded', 'wplicense-it' ),
		);

		echo '<section class="wplit-panel" aria-labelledby="wplit-numbers-title"><h2 id="wplit-numbers-title">' . esc_html__( 'Licenses', 'wplicense-it' ) . '</h2><div class="wplit-numbers">';

		foreach ( $labels as $status => $label ) {
			echo '<a href="' . esc_url( Urls::list( array( 'status' => $status ) ) ) . '"><strong>' . esc_html( number_format_i18n( (int) ( $counts[ $status ] ?? 0 ) ) ) . '</strong>' . esc_html( $label ) . '</a>';
		}

		echo '</div><p><a href="' . esc_url( Urls::list() ) . '">' . esc_html__( 'All licenses', 'wplicense-it' ) . '</a></p></section>';
	}

	/**
	 * A short table of licenses.
	 *
	 * @param string             $title  Panel title.
	 * @param License[]          $list   Licenses.
	 * @param string             $column 'expires' or 'created': which date to show.
	 * @param string             $empty  Text when there are none.
	 * @param array<int, string> $names  Product names already looked up (filled as needed).
	 */
	private function render_licenses( string $title, array $list, string $column, string $empty, array &$names ): void {
		$id = 'wplit-' . sanitize_key( $column ) . '-title';

		echo '<section class="wplit-panel wplit-wide" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';

		if ( array() === $list ) {
			echo '<p>' . esc_html( $empty ) . '</p></section>';

			return;
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'License', 'wplicense-it' ) . '</th><th scope="col">' . esc_html__( 'Customer', 'wplicense-it' ) . '</th><th scope="col">' . esc_html( 'expires' === $column ? __( 'Expires', 'wplicense-it' ) : __( 'Created', 'wplicense-it' ) ) . '</th></tr></thead><tbody>';

		foreach ( $list as $license ) {
			if ( ! isset( $names[ $license->product_id ] ) ) {
				$names[ $license->product_id ] = (string) get_the_title( $license->product_id );
			}

			$date = 'expires' === $column ? $license->expires_at : $license->created_at;
			$when = null === $date ? '' : wp_date( (string) get_option( 'date_format' ), $date->getTimestamp() );

			if ( 'expires' === $column && null !== $date ) {
				$days  = (int) ceil( ( $date->getTimestamp() - $now->getTimestamp() ) / 86400 );
				$when .= ' (' . sprintf(
					/* translators: %d: number of days. */
					_n( '%d day', '%d days', max( 1, $days ), 'wplicense-it' ),
					max( 1, $days )
				) . ')';
			}

			echo '<tr><td><a href="' . esc_url( Urls::view( $license->id ) ) . '"><code>' . esc_html( $license->license_key ) . '</code></a><br><span class="description">' . esc_html( $names[ $license->product_id ] ) . '</span></td><td>' . esc_html( $license->email ) . '</td><td>' . esc_html( $when ) . '</td></tr>';
		}

		echo '</tbody></table></section>';
	}
}
