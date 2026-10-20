<?php
/**
 * License Products > Import and export.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Database\Schema;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Transfer\LicenseCsv;
use Devllo\WPLicenseIt\Transfer\LicenseImport;

/**
 * Exports licenses as a CSV file (all of them, or the ones the Licenses list shows) and imports licenses from one: the file
 * is checked first and the screen shows what would be imported, skipped (a key already in use; existing licenses are never
 * changed) and refused (with the line and why). Only after the site owner confirms are licenses written.
 *
 * The checked plan is kept for an hour in a transient that belongs to the user who made it.
 */
final class TransferScreen {

	public const EXPORT_ACTION = 'wplit_export_licenses';
	public const CHECK_ACTION  = 'wplit_import_check';
	public const RUN_ACTION    = 'wplit_import_run';

	private const MAX_BYTES = 2 * 1024 * 1024;
	private const BATCH     = 500;
	private const SHOWN     = 50;

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Registers the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'check' ) );
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'run' ) );
	}

	/**
	 * The address that downloads an export, for these Licenses list filters.
	 *
	 * @param array<string, string> $filters status, product_id and s, as the list uses them.
	 */
	public static function export_url( array $filters = array() ): string {
		$args = array( 'action' => self::EXPORT_ACTION );

		foreach ( array( 'status', 'product_id', 's' ) as $name ) {
			if ( isset( $filters[ $name ] ) && '' !== $filters[ $name ] ) {
				$args[ $name ] = $filters[ $name ];
			}
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
	}

	/**
	 * Sends the CSV file.
	 */
	public function export(): void {
		Capabilities::require_manage();
		check_admin_referer( self::EXPORT_ACTION );

		global $wpdb;

		$query           = LicenseQuery::from_input(
			array(
				'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'product_id' => isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0,
				'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			)
		);
		$query->orderby  = 'id';
		$query->order    = 'ASC';
		$query->per_page = self::BATCH;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="licenses-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out   = fopen( 'php://output', 'w' );
		$names = array();

		// A byte order mark, so spreadsheet programs read the file as UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" . LicenseCsv::line( LicenseCsv::COLUMNS ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the response.

		for ( $query->page = 1; ; $query->page++ ) {
			$page = $this->licenses->search( $query );

			if ( array() === $page->items ) {
				break;
			}

			$ids    = array_map( static fn( $license ): int => $license->id, $page->items );
			$orders = array_filter( array_map( static fn( $license ): int => (int) $license->order_id, $page->items ) );
			$sites  = $this->counts( $wpdb, $ids );
			$number = $this->order_numbers( $wpdb, $orders );

			foreach ( $page->items as $license ) {
				if ( ! isset( $names[ $license->product_id ] ) ) {
					$names[ $license->product_id ] = (string) get_the_title( $license->product_id );
				}

				fwrite( $out, LicenseCsv::line( LicenseCsv::row( $license, $names[ $license->product_id ], $sites[ $license->id ] ?? 0, $number[ (int) $license->order_id ] ?? '' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the response.
			}

			if ( count( $page->items ) < self::BATCH ) {
				break;
			}
		}//end for

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The response stream.
		exit;
	}

	/**
	 * Reads the uploaded file and keeps the plan, then shows it.
	 */
	public function check(): void {
		Capabilities::require_manage();
		check_admin_referer( self::CHECK_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A file upload, checked below.
		$file = $_FILES['wplit_csv'] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$this->back( array( 'import_error' => 'nofile' ) );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			$this->back( array( 'import_error' => 'size' ) );
		}

		$text = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local uploaded file.

		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$this->back( array( 'import_error' => 'encoding' ) );
		}

		$plan         = $this->importer()->check( LicenseCsv::parse( $text ) );
		$plan['file'] = sanitize_file_name( (string) ( $file['name'] ?? '' ) );

		$token = wp_generate_password( 20, false );
		set_transient( $this->key( $token ), $plan, HOUR_IN_SECONDS );

		$this->back( array( 'plan' => $token ) );
	}

	/**
	 * Imports a checked plan.
	 */
	public function run(): void {
		Capabilities::require_manage();
		check_admin_referer( self::RUN_ACTION );

		$token = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$plan  = '' !== $token ? get_transient( $this->key( $token ) ) : false;

		if ( ! is_array( $plan ) || ! isset( $plan['import'] ) ) {
			$this->back( array( 'import_error' => 'expired' ) );
		}

		delete_transient( $this->key( $token ) );

		$utc    = new DateTimeZone( 'UTC' );
		$done   = 0;
		$failed = array();

		foreach ( $plan['import'] as $row ) {
			try {
				$this->licenses->import_license(
					$row['product_id'],
					$row['email'],
					$row['license_key'],
					$row['status'],
					$row['activation_limit'],
					null === $row['expires_at'] ? null : new DateTimeImmutable( $row['expires_at'], $utc ),
					$row['user_id'],
					null === $row['created_at'] ? null : new DateTimeImmutable( $row['created_at'], $utc )
				);
				++$done;
			} catch ( LicenseException $e ) {
				// Someone added the same key between the check and now, for example.
				$failed[] = array(
					'line'   => $row['line'],
					'reason' => $e->getMessage(),
				);
			}
		}//end foreach

		$result = array(
			'imported' => $done,
			'skipped'  => $plan['skipped'],
			'errors'   => array_merge( $plan['errors'], $failed ),
			'file'     => $plan['file'] ?? '',
		);

		$token = wp_generate_password( 20, false );
		set_transient( $this->key( $token ), $result, HOUR_IN_SECONDS );

		$this->back( array( 'result' => $token ) );
	}

	/**
	 * Draws the screen.
	 */
	public function render(): void {
		Capabilities::require_manage();

		echo '<div class="wrap"><h1>' . esc_html__( 'Import and export licenses', 'wplicense-it' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only chooses what to show.
		$error  = isset( $_GET['import_error'] ) ? sanitize_key( wp_unslash( $_GET['import_error'] ) ) : '';
		$plan   = isset( $_GET['plan'] ) ? get_transient( $this->key( sanitize_key( wp_unslash( $_GET['plan'] ) ) ) ) : false;
		$result = isset( $_GET['result'] ) ? get_transient( $this->key( sanitize_key( wp_unslash( $_GET['result'] ) ) ) ) : false;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $this->error_text( $error ) ) . '</p></div>';
		}

		if ( is_array( $result ) ) {
			$this->render_result( $result );
		} elseif ( is_array( $plan ) ) {
			$this->render_plan( $plan, sanitize_key( wp_unslash( $_GET['plan'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to show.
		}

		$this->render_export();
		$this->render_import();

		echo '</div>';
	}

	/**
	 * The export section.
	 */
	private function render_export(): void {
		echo '<h2>' . esc_html__( 'Export', 'wplicense-it' ) . '</h2>';
		echo '<p>' . esc_html__( 'A CSV file with one line per license: key, product, email, status, sites allowed and in use, expiry and creation dates (UTC), order and user. It can be opened in a spreadsheet and imported again. It holds customers\' email addresses, so keep it safe.', 'wplicense-it' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
		wp_nonce_field( self::EXPORT_ACTION, '_wpnonce', false );

		echo '<p><label for="wplit-export-status">' . esc_html__( 'Status', 'wplicense-it' ) . '</label> <select name="status" id="wplit-export-status"><option value="">' . esc_html__( 'All', 'wplicense-it' ) . '</option>';
		foreach ( Status::all() as $status ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</option>';
		}
		echo '</select> <label for="wplit-export-product">' . esc_html__( 'Product', 'wplicense-it' ) . '</label> <select name="product_id" id="wplit-export-product"><option value="0">' . esc_html__( 'All products', 'wplicense-it' ) . '</option>';
		foreach ( $this->products() as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $title ) . '</option>';
		}
		echo '</select></p>';
		submit_button( __( 'Download CSV', 'wplicense-it' ), 'secondary', 'wplit-export', false );
		echo '</form>';
	}

	/**
	 * The import section.
	 */
	private function render_import(): void {
		echo '<h2>' . esc_html__( 'Import', 'wplicense-it' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add licenses from a CSV file, for example from another licensing system or from an export. The first line names the columns:', 'wplicense-it' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . esc_html__( 'Required: license_key, email, and product_id (or product, the product\'s exact name).', 'wplicense-it' ) . '</li>';
		echo '<li>' . esc_html__( 'Optional: status (active, expired, revoked or refunded; active if empty), activation_limit (sites, 0 for unlimited; 1 if empty), expires_at (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS in UTC; empty for never), created_at, user_id. Other columns are ignored.', 'wplicense-it' ) . '</li>';
		echo '<li>' . esc_html__( 'A license whose key is already in use is skipped and left as it is. Activated sites are not imported: customers activate again.', 'wplicense-it' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'Nothing is imported yet when you upload: you first see what would happen.', 'wplicense-it' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CHECK_ACTION ) . '">';
		wp_nonce_field( self::CHECK_ACTION );
		echo '<p><label for="wplit-csv">' . esc_html__( 'CSV file (at most 2 MB)', 'wplicense-it' ) . '</label><br><input type="file" name="wplit_csv" id="wplit-csv" accept=".csv,text/csv" required></p>';
		submit_button( __( 'Check the file', 'wplicense-it' ), 'primary', 'wplit-import-check', false );
		echo '</form>';
	}

	/**
	 * What a checked file would do, with the button that imports it.
	 *
	 * @param array<string, mixed> $plan  Plan.
	 * @param string               $token Its transient token.
	 */
	private function render_plan( array $plan, string $token ): void {
		echo '<div class="notice notice-info" id="wplit-import-plan">';

		if ( '' !== (string) $plan['fatal'] ) {
			echo '<p><strong>' . esc_html__( 'This file cannot be imported.', 'wplicense-it' ) . '</strong> ' . esc_html( (string) $plan['fatal'] ) . '</p></div>';

			return;
		}

		$count = count( $plan['import'] );
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: file name, 2: licenses to import, 3: skipped, 4: with mistakes. */
				__( '%1$s: %2$d licenses can be imported, %3$d are skipped and %4$d have a mistake.', 'wplicense-it' ),
				(string) $plan['file'],
				$count,
				count( $plan['skipped'] ),
				count( $plan['errors'] )
			)
		) . '</strong></p>';

		$this->render_lines( __( 'Skipped', 'wplicense-it' ), $plan['skipped'] );
		$this->render_lines( __( 'Not imported because of a mistake (fix the file and check it again, or import the rest)', 'wplicense-it' ), $plan['errors'] );

		if ( $count > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::RUN_ACTION ) . '"><input type="hidden" name="plan" value="' . esc_attr( $token ) . '">';
			wp_nonce_field( self::RUN_ACTION );
			echo '<p>';
			submit_button(
				sprintf(
					/* translators: %d: number of licenses. */
					_n( 'Import %d license', 'Import %d licenses', $count, 'wplicense-it' ),
					$count
				),
				'primary',
				'wplit-import-run',
				false
			);
			echo '</p></form>';
		}

		echo '</div>';
	}

	/**
	 * What an import did.
	 *
	 * @param array<string, mixed> $result Result.
	 */
	private function render_result( array $result ): void {
		echo '<div class="notice notice-success" id="wplit-import-result"><p><strong>' . esc_html(
			sprintf(
				/* translators: 1: file name, 2: licenses imported, 3: skipped, 4: not imported. */
				__( '%1$s: %2$d licenses imported, %3$d skipped, %4$d not imported.', 'wplicense-it' ),
				(string) $result['file'],
				(int) $result['imported'],
				count( $result['skipped'] ),
				count( $result['errors'] )
			)
		) . '</strong> <a href="' . esc_url( Urls::list() ) . '">' . esc_html__( 'See the licenses', 'wplicense-it' ) . '</a></p>';

		$this->render_lines( __( 'Skipped', 'wplicense-it' ), $result['skipped'] );
		$this->render_lines( __( 'Not imported', 'wplicense-it' ), $result['errors'] );
		echo '</div>';
	}

	/**
	 * A list of lines with why, the first 50.
	 *
	 * @param string                                 $title Heading.
	 * @param list<array{line: int, reason: string}> $lines Lines.
	 */
	private function render_lines( string $title, array $lines ): void {
		if ( array() === $lines ) {
			return;
		}

		echo '<p>' . esc_html( $title ) . ':</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( array_slice( $lines, 0, self::SHOWN ) as $line ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: line number in the file, 2: why. */
					__( 'Line %1$d: %2$s', 'wplicense-it' ),
					(int) $line['line'],
					(string) $line['reason']
				)
			) . '</li>';
		}
		if ( count( $lines ) > self::SHOWN ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %d: number of lines not listed. */
					__( 'And %d more.', 'wplicense-it' ),
					count( $lines ) - self::SHOWN
				)
			) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The checker, with WordPress lookups.
	 */
	private function importer(): LicenseImport {
		$products = $this->products();

		return new LicenseImport(
			static fn( int $id ): bool => isset( $products[ $id ] ),
			static fn( string $name ): array => array_keys( $products, $name, true ),
			fn( string $key ): bool => null !== $this->licenses->find_by_key( $key ),
			static fn( int $id ): bool => false !== get_userdata( $id )
		);
	}

	/**
	 * License products, ID => title.
	 *
	 * @return array<int, string>
	 */
	private function products(): array {
		$titles = array();

		foreach ( get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $post ) {
			$titles[ (int) $post->ID ] = (string) $post->post_title;
		}

		return $titles;
	}

	/**
	 * Active sites per license, for a batch.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int[] $ids  License IDs.
	 * @return array<int, int>
	 */
	private function counts( $wpdb, array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$table = Schema::table( $wpdb->prefix, 'activations' );
		$rows  = $wpdb->get_results( "SELECT license_id, COUNT(*) AS n FROM {$table} WHERE status = 'active' AND is_local = 0 AND license_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') GROUP BY license_id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name from the prefix, IDs cast to integers; an export.

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['license_id'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Order numbers for a batch of order record IDs.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int[] $ids  Rows in the license orders table.
	 * @return array<int, string>
	 */
	private function order_numbers( $wpdb, array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$table = Schema::table( $wpdb->prefix, 'license_orders' );
		$rows  = $wpdb->get_results( "SELECT id, order_number FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', array_unique( $ids ) ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name from the prefix, IDs cast to integers; an export.

		$numbers = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$numbers[ (int) $row['id'] ] = (string) $row['order_number'];
		}

		return $numbers;
	}

	/**
	 * The transient that holds a plan or a result, for this user only.
	 *
	 * @param string $token Token.
	 */
	private function key( string $token ): string {
		return 'wplit_import_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * Back to the screen, and stop.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	private function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, Urls::transfer() ) );
		exit;
	}

	/**
	 * The text for an error code.
	 *
	 * @param string $code Code.
	 */
	private function error_text( string $code ): string {
		$texts = array(
			'nofile'   => __( 'No file was uploaded. Choose a CSV file.', 'wplicense-it' ),
			'size'     => __( 'The file is bigger than 2 MB. Split it into smaller files.', 'wplicense-it' ),
			'encoding' => __( 'The file is not UTF-8 text. Save it as "CSV UTF-8" and upload it again.', 'wplicense-it' ),
			'expired'  => __( 'That check is no longer available (they are kept for an hour, and each can be imported once). Upload the file again.', 'wplicense-it' ),
		);

		return $texts[ $code ] ?? __( 'Something went wrong. Try again.', 'wplicense-it' );
	}
}
