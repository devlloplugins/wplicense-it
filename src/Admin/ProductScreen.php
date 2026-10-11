<?php
/**
 * The product edit screen: tabs and the boxes behind them.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\WooCommerce\ProductFields;
use WP_Post;

/**
 * Turns the product edit screen into four tabs: Software, Plans, Releases and Connect.
 *
 * The product is still a WordPress post, so saving, revisions and the file upload keep working. This class draws
 * the boxes for the software details, the release (version and zip) and the old API key, and a tab bar that shows
 * one tab's boxes at a time. The plans and connect boxes come from PlansBox and ConnectBox. Without JavaScript
 * every box is shown and the tab bar stays hidden.
 *
 * The field names below are the ones ProductSaver reads. A test keeps the two in step.
 */
final class ProductScreen {

	/**
	 * Posted field names the save code reads, and that a box here must provide.
	 */
	public const FIELDS = array(
		'wplit_product_name',
		'wplit_product_description',
		'wplit_tested_wp_version',
		'wplit_required_wp_version',
		'wplit_product_version',
		'wplit_product_api_key',
		'wplit_default_activation_limit',
		'wplit_period',
	);

	/**
	 * Uploaded file field names the save code reads.
	 */
	public const FILE_FIELDS = array(
		'wplit_product_file_upload',
		'wplit_product_logo',
		'wplit_product_banner',
	);

	/**
	 * Which boxes belong to which tab, in tab order.
	 */
	public const TABS = array(
		'software' => array( 'wplit_software', 'wplit_defaults' ),
		'plans'    => array( 'wplit_plans' ),
		'releases' => array( 'wplit_release' ),
		'connect'  => array( 'wplit_connect', 'wplit_legacy_key' ),
	);

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_wplit_product', array( $this, 'add_boxes' ) );
		add_action( 'edit_form_after_editor', array( $this, 'render_tabs' ) );
	}

	/**
	 * Tab names, for the tab bar.
	 *
	 * @return array<string, string>
	 */
	public static function tab_labels(): array {
		return array(
			'software' => __( 'Software', 'wplicense-it' ),
			'plans'    => __( 'Plans', 'wplicense-it' ),
			'releases' => __( 'Releases', 'wplicense-it' ),
			'connect'  => __( 'Connect', 'wplicense-it' ),
		);
	}

	/**
	 * Adds the boxes.
	 */
	public function add_boxes(): void {
		add_meta_box( 'wplit_software', __( 'About the software', 'wplicense-it' ), array( $this, 'render_software' ), 'wplit_product', 'normal', 'high' );
		add_meta_box( 'wplit_defaults', __( 'Defaults for licenses issued by hand', 'wplicense-it' ), array( $this, 'render_defaults' ), 'wplit_product', 'normal', 'default' );
		add_meta_box( 'wplit_release', __( 'Current release', 'wplicense-it' ), array( $this, 'render_release' ), 'wplit_product', 'normal', 'high' );
		add_meta_box( 'wplit_legacy_key', __( 'Old API key (1.x clients)', 'wplicense-it' ), array( $this, 'render_legacy_key' ), 'wplit_product', 'normal', 'low' );
	}

	/**
	 * Prints the tab bar. It stays hidden until the script that switches tabs has started.
	 *
	 * @param WP_Post $post The product being edited.
	 */
	public function render_tabs( $post ): void {
		if ( ! $post instanceof WP_Post || 'wplit_product' !== $post->post_type ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper" id="wplit-tabs" style="display:none;margin:12px 0" aria-label="' . esc_attr__( 'Product sections', 'wplicense-it' ) . '" data-product="' . esc_attr( (string) $post->ID ) . '" data-tabs="' . esc_attr( (string) wp_json_encode( self::TABS ) ) . '">';
		foreach ( self::tab_labels() as $id => $label ) {
			echo '<a href="#wplit-' . esc_attr( $id ) . '" class="nav-tab" role="tab" aria-selected="false" data-tab="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A few lines that only serve this screen.
		?>
		<script>
		( function () {
			// The boxes are printed after this script, so wait until the page is in place.
			function start() {
				var nav = document.getElementById( 'wplit-tabs' );
				if ( ! nav ) { return; }
				var tabs;
				try { tabs = JSON.parse( nav.getAttribute( 'data-tabs' ) ); } catch ( e ) { return; }
				var links = nav.querySelectorAll( '[data-tab]' );
				var key = 'wplit-tab-' + nav.getAttribute( 'data-product' );

				function known( name ) { return Object.prototype.hasOwnProperty.call( tabs, name ); }

				function show( name ) {
					Object.keys( tabs ).forEach( function ( tab ) {
						tabs[ tab ].forEach( function ( id ) {
							var box = document.getElementById( id );
							if ( box ) { box.style.display = tab === name ? '' : 'none'; }
						} );
					} );
					Array.prototype.forEach.call( links, function ( link ) {
						var on = link.getAttribute( 'data-tab' ) === name;
						link.setAttribute( 'aria-selected', on ? 'true' : 'false' );
						link.className = 'nav-tab' + ( on ? ' nav-tab-active' : '' );
					} );
					try { window.sessionStorage.setItem( key, name ); } catch ( e ) { /* Storage may be blocked. */ }
				}

				var wanted = ( window.location.hash || '' ).replace( '#wplit-', '' );
				if ( ! known( wanted ) ) {
					try { wanted = window.sessionStorage.getItem( key ) || ''; } catch ( e ) { wanted = ''; }
				}
				if ( ! known( wanted ) ) { wanted = 'software'; }

				nav.style.display = '';
				show( wanted );

				Array.prototype.forEach.call( links, function ( link ) {
					link.addEventListener( 'click', function ( event ) {
						event.preventDefault();
						show( link.getAttribute( 'data-tab' ) );
					} );
				} );
			}

			if ( 'loading' === document.readyState ) {
				document.addEventListener( 'DOMContentLoaded', start );
			} else {
				start();
			}
		}() );
		</script>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * The Software tab: name, description, requirements, logo and banner.
	 *
	 * @param WP_Post $post The product.
	 */
	public function render_software( WP_Post $post ): void {
		wp_nonce_field( 'wplit_inner_custom_box', 'wplit_inner_custom_box_nonce' );

		$logo   = (string) get_post_meta( $post->ID, 'wplit_product_logo_url', true );
		$banner = (string) get_post_meta( $post->ID, 'wplit_product_banner_url', true );

		$this->text_field( 'wplit_product_name', __( 'Name shown to customers', 'wplicense-it' ), (string) get_post_meta( $post->ID, 'wplit_product_name', true ), __( 'Read from the zip when you upload a release. Empty uses the title above.', 'wplicense-it' ) );
		$this->text_field( 'wplit_product_description', __( 'Short description', 'wplicense-it' ), (string) get_post_meta( $post->ID, 'wplit_product_description', true ), __( 'Shown in the "View details" popup on customers\' sites.', 'wplicense-it' ) );
		$this->text_field( 'wplit_required_wp_version', __( 'Needs WordPress', 'wplicense-it' ), (string) get_post_meta( $post->ID, 'wplit_required_wp_version', true ), __( 'Filled in from the zip when you upload a release.', 'wplicense-it' ) );
		$this->text_field( 'wplit_tested_wp_version', __( 'Tested up to WordPress', 'wplicense-it' ), (string) get_post_meta( $post->ID, 'wplit_tested_wp_version', true ), __( 'Filled in from the zip\'s readme.txt when you upload a release.', 'wplicense-it' ) );

		echo '<p><label for="wplit_product_logo"><strong>' . esc_html__( 'Logo', 'wplicense-it' ) . '</strong></label><br>';
		echo '<input type="file" id="wplit_product_logo" name="wplit_product_logo">';
		if ( '' !== $logo ) {
			echo '<br><img src="' . esc_url( $logo ) . '" alt="" style="width:50px;margin-top:6px">';
		}
		echo '</p>';

		echo '<p><label for="wplit_product_banner"><strong>' . esc_html__( 'Banner', 'wplicense-it' ) . '</strong></label><br>';
		echo '<input type="file" id="wplit_product_banner" name="wplit_product_banner">';
		if ( '' !== $banner ) {
			echo '<br><img src="' . esc_url( $banner ) . '" alt="" style="width:200px;margin-top:6px">';
		}
		echo '</p>';
	}

	/**
	 * The defaults for licenses issued by hand, and for WooCommerce products that carry no setting of their own.
	 *
	 * @param WP_Post $post The product.
	 */
	public function render_defaults( WP_Post $post ): void {
		$limit  = (string) get_post_meta( $post->ID, 'wplit_default_activation_limit', true );
		$period = (string) get_post_meta( $post->ID, 'wplit_period', true );

		echo '<p class="description">' . esc_html__( 'Plans carry their own sites and period. These defaults apply to licenses you issue by hand, and to a WooCommerce product linked to this software that has no setting of its own.', 'wplicense-it' ) . '</p>';
		echo '<p><label for="wplit_default_activation_limit"><strong>' . esc_html__( 'Sites per license', 'wplicense-it' ) . '</strong></label><br>';
		echo '<input type="number" min="0" step="1" id="wplit_default_activation_limit" name="wplit_default_activation_limit" value="' . esc_attr( $limit ) . '" class="small-text" placeholder="1"><br>';
		echo '<span class="description">' . esc_html__( 'Empty means 1. 0 is unlimited.', 'wplicense-it' ) . '</span></p>';

		echo '<p><label for="wplit_period"><strong>' . esc_html__( 'License period', 'wplicense-it' ) . '</strong></label><br>';
		echo '<select id="wplit_period" name="wplit_period">';
		foreach ( ProductFields::periods() as $value => $label ) {
			if ( 'lifetime' === $value ) {
				continue; // Empty already means lifetime here.
			}
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $period, $value, false ) . '>' . esc_html( '' === $value ? __( 'Lifetime (never expires)', 'wplicense-it' ) : $label ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * The Releases tab: the current version and zip.
	 *
	 * @param WP_Post $post The product.
	 */
	public function render_release( WP_Post $post ): void {
		$file    = (string) get_post_meta( $post->ID, 'file_name', true );
		$type    = (string) get_post_meta( $post->ID, 'wplit_package_type', true );
		$name    = (string) get_post_meta( $post->ID, 'wplit_package_name', true );
		$version = (string) get_post_meta( $post->ID, 'wplit_product_version', true );
		$php     = (string) get_post_meta( $post->ID, 'wplit_required_php_version', true );

		echo '<p><label for="wplit_product_file_upload"><strong>' . esc_html__( 'Upload a new release (zip)', 'wplicense-it' ) . '</strong></label><br>';
		echo '<input type="file" id="wplit_product_file_upload" name="wplit_product_file_upload" accept=".zip,application/zip"><br>';
		echo '<span class="description">' . esc_html__( 'When you save, the name, version and requirements are read from the file, and a zip that is not a plugin or theme is refused. The folder inside the zip should match your plugin\'s folder name.', 'wplicense-it' ) . '</span></p>';

		$this->text_field( 'wplit_product_version', __( 'Version', 'wplicense-it' ), $version, __( 'Set from the zip when you upload. Change it only if you have to.', 'wplicense-it' ) );

		echo '<p><strong>' . esc_html__( 'Stored file', 'wplicense-it' ) . ':</strong> ';
		if ( '' === $file ) {
			echo esc_html__( 'none yet', 'wplicense-it' ) . '</p>';

			return;
		}

		$details = array_filter(
			array(
				'' !== $name ? $name : null,
				'' !== $type ? $type : null,
				'' !== $php ? 'PHP ' . $php : null,
			)
		);
		echo esc_html( $file ) . ( $details ? ' <span class="description">(' . esc_html( implode( ', ', $details ) ) . ')</span>' : '' ) . '</p>';
	}

	/**
	 * The old API key, only needed by 1.x clients.
	 *
	 * @param WP_Post $post The product.
	 */
	public function render_legacy_key( WP_Post $post ): void {
		echo '<p class="description">' . esc_html__( 'Plugins and themes still using the 1.x client send this key. The new client code does not need it. Leave it empty to keep the current key. A new product gets one automatically.', 'wplicense-it' ) . '</p>';
		$this->text_field( 'wplit_product_api_key', __( 'API key', 'wplicense-it' ), (string) get_post_meta( $post->ID, 'wplit_product_api_key', true ), '' );
	}

	/**
	 * A labelled text input.
	 *
	 * @param string $name  Field name, also its ID.
	 * @param string $label Label text.
	 * @param string $value Current value.
	 * @param string $help  Help text, or ''.
	 */
	private function text_field( string $name, string $label, string $value, string $help ): void {
		echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		if ( '' !== $help ) {
			echo '<br><span class="description">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}
}
