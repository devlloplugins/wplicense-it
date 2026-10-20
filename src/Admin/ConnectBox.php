<?php
/**
 * The Connect box on the product edit screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Connect\Checklist;
use Devllo\WPLicenseIt\Connect\ExamplePlugin;
use Devllo\WPLicenseIt\Connect\SdkZip;
use Devllo\WPLicenseIt\Connect\Snippet;
use Devllo\WPLicenseIt\Plans\PlanRepository;
use Devllo\WPLicenseIt\Plans\ProductGateway;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use WP_Post;

/**
 * Shows what is left before a product can be sold, and the code that connects the seller's own plugin or theme
 * to this site, with the server address and product ID already filled in.
 */
final class ConnectBox {

	/**
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * @var callable
	 */
	private $gateway;

	/**
	 * @var ProductCatalog
	 */
	private $catalog;

	public const ACTION            = 'wplit_download_sdk';
	public const EXAMPLE_ACTION    = 'wplit_download_example';
	public const DOWNLOADED_OPTION = 'wplit_sdk_downloaded';

	/**
	 * Constructor.
	 *
	 * @param PlanRepository $plans   Plan storage.
	 * @param callable       $gateway Returns the WooCommerce gateway, or null when WooCommerce is not active.
	 * @param ProductCatalog $catalog Software products and their zip files.
	 */
	public function __construct( PlanRepository $plans, callable $gateway, ProductCatalog $catalog ) {
		$this->plans   = $plans;
		$this->gateway = $gateway;
		$this->catalog = $catalog;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_wplit_product', array( $this, 'add_box' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download_sdk' ) );
		add_action( 'admin_post_' . self::EXAMPLE_ACTION, array( $this, 'download_example' ) );
	}

	/**
	 * The link that downloads the SDK zip.
	 */
	public static function sdk_url(): string {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION ), admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Sends the SDK as a zip.
	 */
	public function download_sdk(): void {
		// The licensing capability, not edit_products: that one belongs to WooCommerce, so it is missing on a site without it
		// (and on every subsite of a network where WooCommerce is not active).
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		// For the setup checklist on the Overview screen.
		update_option( self::DOWNLOADED_OPTION, 1, false );

		try {
			$path = SdkZip::build( WPLICENSE_IT_DIR . 'sdk' );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html__( 'The SDK zip could not be made on this server. Copy the sdk folder from the plugin instead.', 'wplicense-it' ), '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . SdkZip::FOLDER . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a temp file we made.
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp file we made.
		exit;
	}

	/**
	 * The link that downloads the example plugin of a product.
	 *
	 * @param int $product_id License product ID.
	 */
	public static function example_url( int $product_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::EXAMPLE_ACTION,
					'product' => $product_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXAMPLE_ACTION . '_' . $product_id
		);
	}

	/**
	 * Sends a working example plugin for a product, with this server and the product filled in, and the SDK inside.
	 */
	public function download_example(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		$product_id = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;
		check_admin_referer( self::EXAMPLE_ACTION . '_' . $product_id );

		$post = get_post( $product_id );

		if ( ! $post instanceof WP_Post || 'wplit_product' !== $post->post_type ) {
			wp_die( esc_html__( 'That product does not exist.', 'wplicense-it' ), '', array( 'response' => 404 ) );
		}

		$name  = (string) get_post_meta( $post->ID, 'wplit_package_name', true );
		$files = ExamplePlugin::files( '' !== $name ? $name : $post->post_title, (string) get_post_meta( $post->ID, 'wplit_package_folder', true ), untrailingslashit( home_url() ), $post->ID );

		try {
			$path = ExamplePlugin::build( $files, WPLICENSE_IT_DIR . 'sdk' );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html__( 'The example zip could not be made on this server.', 'wplicense-it' ), '', array( 'response' => 500 ) );
		}

		update_option( self::DOWNLOADED_OPTION, 1, false );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . strtok( (string) array_key_first( $files ), '/' ) . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a temp file we made.
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp file we made.
		exit;
	}

	/**
	 * Adds the box.
	 */
	public function add_box(): void {
		add_meta_box( 'wplit_connect', __( 'Connect your plugin or theme', 'wplicense-it' ), array( $this, 'render' ), 'wplit_product', 'normal', 'default' );
	}

	/**
	 * Draws the box.
	 *
	 * @param WP_Post $post The license product.
	 */
	public function render( WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the product first. Its code and checklist appear here.', 'wplicense-it' ) . '</p>';

			return;
		}

		$server = untrailingslashit( home_url() );
		$type   = 'theme' === get_post_meta( $post->ID, 'wplit_package_type', true ) ? 'theme' : 'plugin';
		$name   = (string) get_post_meta( $post->ID, 'wplit_package_name', true );
		$name   = '' !== $name ? $name : $post->post_title;
		$slug   = (string) get_post_meta( $post->ID, 'wplit_package_folder', true );

		echo '<h3 style="margin-bottom:4px;font-size:14px">' . esc_html__( 'Before you sell', 'wplicense-it' ) . '</h3>';
		echo '<ul style="margin:0 0 12px">';
		foreach ( Checklist::items( $this->facts( $post->ID, $server ) ) as $item ) {
			printf(
				'<li style="margin:4px 0"><span aria-hidden="true" style="display:inline-block;width:1.4em;font-weight:bold;color:%1$s">%2$s</span><span class="screen-reader-text">%3$s </span>%4$s<br><span class="description" style="margin-left:1.4em">%5$s</span></li>',
				$item['done'] ? '#00a32a' : '#996800',
				$item['done'] ? '&#10003;' : '&#9675;',
				esc_html( $item['done'] ? __( 'Done:', 'wplicense-it' ) : __( 'To do:', 'wplicense-it' ) ),
				esc_html( $item['label'] ),
				esc_html( $item['hint'] )
			);
		}
		echo '</ul>';

		echo '<h3 style="margin-bottom:4px;font-size:14px">' . esc_html__( 'Put this in your plugin or theme', 'wplicense-it' ) . '</h3>';
		echo '<ol style="margin-top:0">';
		echo '<li>' . sprintf(
			/* translators: %s: link text "Download the SDK". */
			esc_html__( '%s and unzip it into your plugin or theme, so you have vendor/wplicense-it-client.', 'wplicense-it' ),
			'<a href="' . esc_url( self::sdk_url() ) . '" id="wplit-sdk-download">' . esc_html__( 'Download the SDK', 'wplicense-it' ) . '</a>'
		) . '</li>';
		echo '<li>' . esc_html__( 'Paste this code into the main file of your plugin, or the functions.php of your theme:', 'wplicense-it' ) . '</li>';
		echo '</ol>';

		printf(
			'<label class="screen-reader-text" for="wplit-connect-code">%1$s</label><textarea id="wplit-connect-code" class="large-text code" rows="14" readonly style="font-family:monospace">%2$s</textarea>',
			esc_html__( 'Code to paste into your plugin or theme', 'wplicense-it' ),
			esc_textarea( Snippet::generate( $type, $name, $slug, $server, $post->ID ) )
		);
		echo '<p><button type="button" class="button" id="wplit-connect-copy">' . esc_html__( 'Copy the code', 'wplicense-it' ) . '</button> <span id="wplit-connect-copied" role="status" class="description"></span></p>';
		echo '<p class="description">' . sprintf(
			/* translators: 1: product ID, 2: settings page name. */
			esc_html__( 'Product ID: %1$s. Customers then find the license page under Settings, %2$s.', 'wplicense-it' ),
			esc_html( (string) $post->ID ),
			esc_html( $name . ' ' . __( 'License', 'wplicense-it' ) )
		) . '</p>';

		if ( 'plugin' === $type ) {
			echo '<p>' . sprintf(
				/* translators: %s: link text "Download an example plugin". */
				esc_html__( 'Or start from a working example: %s for this product, with the SDK and this code already in it. Install it on a test site to try activation and updates, then copy what you need.', 'wplicense-it' ),
				'<a href="' . esc_url( self::example_url( $post->ID ) ) . '" id="wplit-example-download">' . esc_html__( 'Download an example plugin', 'wplicense-it' ) . '</a>'
			) . '</p>';
		}
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A few lines that only serve this box.
		?>
		<script>
		( function () {
			var button = document.getElementById( 'wplit-connect-copy' );
			var area = document.getElementById( 'wplit-connect-code' );
			var note = document.getElementById( 'wplit-connect-copied' );
			if ( ! button || ! area ) { return; }
			button.addEventListener( 'click', function () {
				area.focus();
				area.select();
				var done = function ( ok ) { note.textContent = ok ? <?php echo wp_json_encode( __( 'Copied.', 'wplicense-it' ) ); ?> : <?php echo wp_json_encode( __( 'Press Ctrl+C to copy the selected code.', 'wplicense-it' ) ); ?>; };
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( area.value ).then( function () { done( true ); }, function () { done( false ); } );
				} else {
					var ok = false;
					try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
					done( ok );
				}
			} );
		}() );
		</script>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Looks up what the checklist needs.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $server      This site's address.
	 * @return array{has_package:bool,version:string,plan_count:int,created_count:int,tested_count:int,published_count:int,server_https:bool}
	 */
	private function facts( int $software_id, string $server ): array {
		$plans   = $this->plans->all( $software_id );
		$gateway = ( $this->gateway )();
		$gateway = $gateway instanceof ProductGateway ? $gateway : null;

		$created   = 0;
		$tested    = 0;
		$published = 0;

		$package = $this->catalog->package_file( $software_id );

		foreach ( $plans as $plan ) {
			if ( Fingerprint::is_current( $plan, Fingerprint::of( $software_id, $plan, $package ) ) ) {
				++$tested;
			}
			if ( $plan->woo_product_id > 0 ) {
				++$created;
				$remote = null !== $gateway ? $gateway->find( $plan->woo_product_id ) : null;
				if ( null !== $remote && 'publish' === $remote->status ) {
					++$published;
				}
			}
		}

		return array(
			'has_package'     => '' !== (string) get_post_meta( $software_id, 'file_name', true ),
			'version'         => (string) get_post_meta( $software_id, 'wplit_product_version', true ),
			'plan_count'      => count( $plans ),
			'created_count'   => $created,
			'tested_count'    => $tested,
			'published_count' => $published,
			'server_https'    => 0 === strpos( $server, 'https://' ),
		);
	}
}
