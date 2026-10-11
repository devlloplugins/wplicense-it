<?php
/**
 * The release history box on the product edit screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Packages\UploadReport;
use Devllo\WPLicenseIt\Products\FileStore;
use Devllo\WPLicenseIt\Products\FileStoreException;
use Devllo\WPLicenseIt\Products\SaveReport;
use Devllo\WPLicenseIt\Releases\ReleaseException;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use WP_Post;

/**
 * Lists every release of a product, newest first, and lets the seller make an older one current (a rollback) or
 * delete one that is no longer needed. The row actions are nonce-protected links.
 */
final class ReleasesBox {

	private const ACTION = 'wplit_release_action';

	/**
	 * @var ReleaseService
	 */
	private $releases;

	/**
	 * @var FileStore
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @param ReleaseService $releases Release history.
	 * @param FileStore      $files    Where release zips are kept.
	 */
	public function __construct( ReleaseService $releases, FileStore $files ) {
		$this->releases = $releases;
		$this->files    = $files;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_wplit_product', array( $this, 'add_box' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Adds the box.
	 */
	public function add_box(): void {
		add_meta_box( 'wplit_release_history', __( 'Release history', 'wplicense-it' ), array( $this, 'render' ), 'wplit_product', 'normal', 'default' );
	}

	/**
	 * Draws the box.
	 *
	 * @param WP_Post $post The product.
	 */
	public function render( WP_Post $post ): void {
		$releases = $this->releases->all( $post->ID );
		$current  = $this->releases->current( $post->ID );

		if ( ! $releases ) {
			echo '<p>' . esc_html__( 'No release yet. Upload a zip above and every upload will be kept here.', 'wplicense-it' ) . '</p>';

			return;
		}

		$total = 0;
		foreach ( $releases as $release ) {
			$total += $release->size;
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: number of releases, 2: total size or empty. */
				_n( '%1$d release%2$s. Customers get the one marked Current.', '%1$d releases%2$s. Customers get the one marked Current.', count( $releases ), 'wplicense-it' ),
				count( $releases ),
				$total > 0 ? ', ' . self::size_text( $total ) . ' on disk' : ''
			)
		) . ' ' . esc_html__( 'Making an older release current stops new downloads of the newer one. Sites that already updated stay on it, because WordPress does not downgrade on its own.', 'wplicense-it' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Version', 'wplicense-it' ), __( 'Uploaded', 'wplicense-it' ), __( 'File', 'wplicense-it' ), __( 'Notes', 'wplicense-it' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $releases as $release ) {
			$is_current = null !== $current && $current->id === $release->id;

			echo '<tr><td><strong>' . esc_html( '' !== $release->version ? $release->version : '?' ) . '</strong>';
			if ( $is_current ) {
				echo ' <span style="background:#00a32a;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px">' . esc_html__( 'Current', 'wplicense-it' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( self::date_text( $release->uploaded_at ) ) . '</td>';
			echo '<td>' . esc_html( $release->name ) . ( $release->size > 0 ? '<br><span class="description">' . esc_html( self::size_text( $release->size ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . $this->notes_html( $release->notes ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside notes_html().
			echo '<td>';
			if ( ! $is_current ) {
				echo '<a href="' . esc_url( $this->action_url( 'current', $post->ID, $release->id ) ) . '">' . esc_html__( 'Make current', 'wplicense-it' ) . '</a> &middot; ';
				echo '<a href="' . esc_url( $this->action_url( 'delete', $post->ID, $release->id ) ) . '" style="color:#b32d2e" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Delete this release and its zip file? This cannot be undone.', 'wplicense-it' ) ) ) . ');">' . esc_html__( 'Delete', 'wplicense-it' ) . '</a>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Runs a row action and goes back to the Releases tab.
	 */
	public function handle_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below, against the specific release and action.
		$do         = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$post_id    = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$release_id = isset( $_GET['release'] ) ? sanitize_key( wp_unslash( $_GET['release'] ) ) : '';
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! wp_verify_nonce( $nonce, self::nonce_action( $do, $post_id, $release_id ) ) ) {
			wp_die( esc_html__( 'This link has expired. Go back and try again.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}
		if ( 'wplit_product' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		UploadReport::queue( $this->run( $do, $post_id, $release_id ) );
		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) . '#wplit-releases' );
		exit;
	}

	/**
	 * Does one row action.
	 *
	 * @param string $do         'current' or 'delete'.
	 * @param int    $post_id    Product ID.
	 * @param string $release_id Release ID.
	 */
	public function run( string $do, int $post_id, string $release_id ): SaveReport {
		$report = new SaveReport();

		try {
			if ( 'current' === $do ) {
				$release            = $this->releases->make_current( $post_id, $release_id );
				$report->messages[] = sprintf( 'Version %s is now the release customers get.', $release->version );
			} elseif ( 'delete' === $do ) {
				$release            = $this->releases->remove( $post_id, $release_id );
				$report->messages[] = sprintf( 'Version %s was removed from the history.', $release->version );

				if ( ! $this->releases->shares_file( $post_id, $release_id, $release->path ) ) {
					$this->files->delete_package( $release->path );
				}
			} else {
				$report->errors[] = 'Unknown action.';
			}
		} catch ( ReleaseException $e ) {
			$report->errors[] = $e->getMessage();
		} catch ( FileStoreException $e ) {
			$report->errors[] = 'The release was removed from the list, but its file stays on the server: ' . $e->getMessage();
		}

		return $report;
	}

	/**
	 * The notes of a release, escaped, with a long text folded away.
	 *
	 * @param string $notes Notes.
	 */
	private function notes_html( string $notes ): string {
		if ( '' === $notes ) {
			return '<span class="description">&mdash;</span>';
		}

		$text = esc_html( $notes );
		if ( strlen( $notes ) <= 160 ) {
			return '<div style="white-space:pre-line">' . $text . '</div>';
		}

		$short = esc_html( function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, 140 ) : substr( $notes, 0, 140 ) );

		return '<details><summary style="white-space:pre-line;cursor:pointer">' . $short . '&hellip;</summary><div style="white-space:pre-line;margin-top:6px">' . $text . '</div></details>';
	}

	/**
	 * A nonce-protected link for a row action.
	 *
	 * @param string $do         Action.
	 * @param int    $post_id    Product ID.
	 * @param string $release_id Release ID.
	 */
	private function action_url( string $do, int $post_id, string $release_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'do'      => $do,
					'post'    => $post_id,
					'release' => $release_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $do, $post_id, $release_id )
		);
	}

	/**
	 * Nonce action for one row action.
	 *
	 * @param string $do         Action.
	 * @param int    $post_id    Product ID.
	 * @param string $release_id Release ID.
	 */
	private static function nonce_action( string $do, int $post_id, string $release_id ): string {
		return 'wplit_release_' . $do . '_' . $post_id . '_' . $release_id;
	}

	/**
	 * A size such as 1.2 MB.
	 *
	 * @param int $bytes Size in bytes.
	 */
	public static function size_text( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1, '.', '' ) . ' MB';
		}

		return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}

	/**
	 * A stored UTC time as text, or a dash if unknown.
	 *
	 * @param string $utc Time in UTC, Y-m-d H:i:s.
	 */
	public static function date_text( string $utc ): string {
		$time = '' === $utc ? false : strtotime( $utc . ' UTC' );

		return false === $time ? '—' : gmdate( 'Y-m-d H:i', $time ) . ' UTC';
	}
}
