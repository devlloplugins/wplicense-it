<?php

defined( 'ABSPATH' ) || exit;

class WP_License_It_Product_Admin {
    private static $_instance = null;
    
    public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
    }

    public function __construct() {
        add_action( 'save_post', array( $this, 'save_metabox' ), 1, 2 );
        add_action ('add_meta_boxes', array ($this, 'wplit_add_metabox' ));
        add_action('post_edit_form_tag', array ($this, 'update_edit_form'));

    }


      // Add Meta Boxes
	public function wplit_add_metabox() {  

        add_meta_box(
            'wp_license_it_product_info',
            __( 'Product License Information', 'wplicense-it' ),
            array( $this, 'wplit_render_metabox' ),
            'wplit_product',
            'advanced',
            'high'
        );

        add_meta_box(
            'wp_license_it_product_details',
            __( 'License Details', 'wplicense-it' ),
            array( $this, 'wplit_render_details_metabox' ),
            'wplit_product',
            'side',
            'low'
        );

        add_meta_box(
            'wp_license_it_product_shortcode',
            __( 'Selling and the client SDK', 'wplicense-it' ),
            array( $this, 'wplit_render_license_shortcode' ),
            'wplit_product',
            'side',
            'low'
        );
        
    }

    public function wplit_render_license_shortcode( $post, $args ) {
        wp_nonce_field( 'wplit_inner_custom_box', 'wplit_inner_custom_box_nonce' );
        ?>
        <p>
            <strong><?php esc_html_e( 'Product ID', 'wplicense-it' ); ?>:</strong>
            <code><?php echo esc_html( (string) $post->ID ); ?></code><br>
            <span class="description"><?php esc_html_e( 'Use this number as "product_id" in the client SDK.', 'wplicense-it' ); ?></span>
        </p>
        <p>
            <?php esc_html_e( 'To sell licenses, create a WooCommerce product and choose this product under "WPLicense It" on its General tab.', 'wplicense-it' ); ?>
        </p>
        <?php
    }


    public function wplit_render_details_metabox( $post, $args ) {

        wp_nonce_field( 'wplit_inner_custom_box', 'wplit_inner_custom_box_nonce' );

        $limit  = (string) get_post_meta( $post->ID, 'wplit_default_activation_limit', true );
        $period = (string) get_post_meta( $post->ID, 'wplit_period', true );

        ?>
        <p>
            <label for="wplit_default_activation_limit"><strong><?php esc_html_e( 'Sites per license', 'wplicense-it' ); ?></strong></label><br>
            <input type="number" min="0" step="1" id="wplit_default_activation_limit" name="wplit_default_activation_limit" value="<?php echo esc_attr( $limit ); ?>" class="small-text" placeholder="1"><br>
            <span class="description"><?php esc_html_e( 'The default. Empty means 1. 0 is unlimited. A WooCommerce product can override it.', 'wplicense-it' ); ?></span>
        </p>
        <p>
            <label for="wplit_period"><strong><?php esc_html_e( 'License period', 'wplicense-it' ); ?></strong></label><br>
            <select id="wplit_period" name="wplit_period">
                <?php foreach ( \Devllo\WPLicenseIt\WooCommerce\ProductFields::periods() as $value => $label ) : ?>
                    <?php if ( 'lifetime' === $value ) { continue; } // Empty already means lifetime here. ?>
                    <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $period, $value ); ?>><?php echo esc_html( '' === $value ? __( 'Lifetime (never expires)', 'wplicense-it' ) : $label ); ?></option>
                <?php endforeach; ?>
            </select><br>
            <span class="description"><?php esc_html_e( 'The default. A WooCommerce product can override it.', 'wplicense-it' ); ?></span>
        </p>
        <?php
    }


    public function wplit_render_metabox( $post, $args ) {

        // Add an nonce field so we can check for it later.
		wp_nonce_field( 'wplit_inner_custom_box', 'wplit_inner_custom_box_nonce' );
        $wplit_product_name = get_post_meta( $post->ID, 'wplit_product_name', true );
        $wplit_product_version = get_post_meta( $post->ID, 'wplit_product_version', true );

        $wplit_product_file = get_post_meta( $post->ID, 'wplit_product_file_upload', true );
        
        $wplit_product_api_key = get_post_meta( $post->ID, 'wplit_product_api_key', true );

        $wplit_tested_wp_version = get_post_meta( $post->ID, 'wplit_tested_wp_version', true );

        $wplit_required_wp_version = get_post_meta( $post->ID, 'wplit_required_wp_version', true );

        $wplit_product_description = get_post_meta( $post->ID, 'wplit_product_description', true );

        $file_dir_location = get_post_meta( $post->ID, 'file_dir_location', true );

        $file_dir_path = get_post_meta( $post->ID, 'file_dir_path', true );

        $file_name = get_post_meta( $post->ID, 'file_name', true );

        $wplit_product_banner_url = get_post_meta( $post->ID, 'wplit_product_banner_url', true );


        $wplit_product_logo_url = get_post_meta( $post->ID, 'wplit_product_logo_url', true );


        ?>
        <p>
            <label for="wplit_product_api_key">
            <h4> <?php _e( 'Plugin/Theme API Key', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_product_api_key" name="wplit_product_api_key" value="<?php echo esc_attr( $wplit_product_api_key ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_product_name">
            <h4> <?php _e( 'Plugin/Theme Name', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_product_name" name="wplit_product_name" value="<?php echo esc_attr( $wplit_product_name ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_tested_wp_version">
            <h4> <?php _e( 'Tested with WP Version', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_tested_wp_version" name="wplit_tested_wp_version" value="<?php echo esc_attr( $wplit_tested_wp_version ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_required_wp_version">
            <h4> <?php _e( 'Required WP Version', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_required_wp_version" name="wplit_required_wp_version" value="<?php echo esc_attr( $wplit_required_wp_version ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_product_description">
            <h4> <?php _e( 'Plugin/Theme Description', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_product_description" name="wplit_product_description" value="<?php echo esc_attr( $wplit_product_description ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_product_version">
            <h4> <?php _e( 'Plugin/Theme Version', 'wplicense-it' ); ?></h4>
            <input type="text" id="wplit_product_version" name="wplit_product_version" value="<?php echo esc_attr( $wplit_product_version ); ?>" size="25" />
            </label> 
        </p>

        <p>
            <label for="wplit_product_file_upload">
                <h4>Upload Plugin/Theme File</h4>
                <input type="file" id="wplit_product_file_upload" name="wplit_product_file_upload" value="" size="25" /> <br />
                <?php if ($file_name) { echo 'File Name: ' . esc_attr($file_name); }?>
            </label> 
        </p>

        <p>
            <label for="wplit_product_logo">
                <h4>Plugin/Theme Logo</h4>
                <input type="file" id="wplit_product_logo" name="wplit_product_logo" value="" size="25" /> <br />
                <?php if ($wplit_product_logo_url) { echo '<img src="'. esc_url($wplit_product_logo_url) . '" style="width: 50px;">';} ?>
            </label>
        </p>

        <p>
            <label for="wplit_product_banner">
                <h4>Plugin/Theme Banner</h4>
                <input type="file" id="wplit_product_banner" name="wplit_product_banner" value="" size="25" /> <br />
                <?php if ($wplit_product_banner_url) { echo '<img src="'. esc_url($wplit_product_banner_url) . '" style="width: 200px;">';} ?>
            </label>
        </p>
        <?php

    }

    function update_edit_form() {
        echo ' enctype="multipart/form-data"'; 
    } // end update_edit_form
         

    public function save_metabox( $post_id, $post ) {
        if ( ! isset( $_POST['wplit_inner_custom_box_nonce'] ) ) {
            return $post_id;
        }
        
        $nonce = $_POST['wplit_inner_custom_box_nonce'];

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $nonce, 'wplit_inner_custom_box' ) ) {
            return $post_id;
        }

        // Only handle our product post type, and only for users allowed to edit it.
        if ( 'wplit_product' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
            return $post_id;
        }
        
        /*
        * If this is an autosave, our form has not been submitted,
        * so we don't want to do anything.
        */
        
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $post_id;
        }

        // Defaults for new licenses of this product (a WooCommerce product can override them).
        $default_limit = isset( $_POST['wplit_default_activation_limit'] ) ? \Devllo\WPLicenseIt\WooCommerce\ProductFields::clean_limit( sanitize_text_field( wp_unslash( $_POST['wplit_default_activation_limit'] ) ) ) : null;
        if ( null !== $default_limit ) {
            '' === $default_limit ? delete_post_meta( $post_id, 'wplit_default_activation_limit' ) : update_post_meta( $post_id, 'wplit_default_activation_limit', $default_limit );
        }

        $default_period = isset( $_POST['wplit_period'] ) ? \Devllo\WPLicenseIt\WooCommerce\ProductFields::clean_period( sanitize_text_field( wp_unslash( $_POST['wplit_period'] ) ) ) : null;
        if ( null !== $default_period ) {
            '' === $default_period ? delete_post_meta( $post_id, 'wplit_period' ) : update_post_meta( $post_id, 'wplit_period', $default_period );
        }

        if (isset($_POST['wplit_product_name'])){
            $wplit_product_name = sanitize_text_field( $_POST['wplit_product_name'] );
        }

        if (isset($_POST['wplit_product_name'])){
            update_post_meta( $post_id, 'wplit_product_name', $wplit_product_name );
        }

        if (isset($_POST['wplit_tested_wp_version'])){
            $wplit_tested_wp_version = sanitize_text_field( $_POST['wplit_tested_wp_version'] );
        }

        if (isset($_POST['wplit_tested_wp_version'])){
            update_post_meta( $post_id, 'wplit_tested_wp_version', $wplit_tested_wp_version );
        }

        if (isset($_POST['wplit_required_wp_version'])){
            $wplit_required_wp_version = sanitize_text_field( $_POST['wplit_required_wp_version'] );
        }

        if (isset($_POST['wplit_required_wp_version'])){
            update_post_meta( $post_id, 'wplit_required_wp_version', $wplit_required_wp_version );
        }

        if (isset($_POST['wplit_product_description'])){
            $wplit_product_description = sanitize_text_field( $_POST['wplit_product_description'] );
        }

        if (isset($_POST['wplit_product_description'])){
            update_post_meta( $post_id, 'wplit_product_description', $wplit_product_description );
        }

        if (isset($_POST['wplit_product_version'])){
            // The version is used in a folder name, so only allow safe characters
            $wplit_product_version = preg_replace( '/[^A-Za-z0-9._-]/', '', sanitize_text_field( wp_unslash( $_POST['wplit_product_version'] ) ) );
            $wplit_product_version = trim( str_replace( '..', '', $wplit_product_version ), '.' );
        } else {
            $wplit_product_version = (string) get_post_meta( $post_id, 'wplit_product_version', true );
        }

        if (isset($_POST['wplit_product_version'])){
            update_post_meta( $post_id, 'wplit_product_version', $wplit_product_version );
        }

        if (isset($_POST['wplit_product_api_key'])){
            $wplit_product_api_key = sanitize_text_field( $_POST['wplit_product_api_key'] );
        }

        if (empty($_POST['wplit_product_api_key'])){
            $wplit_product_api_key = wp_generate_password(24, false, false);
        }

        if (isset($_POST['wplit_product_name'])){
            update_post_meta( $post_id, 'wplit_product_api_key', $wplit_product_api_key );
        }

        // Move Uploaded Files to WPLit Files Folder
        global $wp_filesystem;
        WP_Filesystem();
                            
        // Create File Path
        $content_directory = $wp_filesystem->wp_content_dir() . 'uploads/';
        $uploads_content_url = content_url() . '/uploads/';

        $wp_filesystem->mkdir( $content_directory . 'wplit-files' );
        $wp_files_directory = $content_directory . 'wplit-files/';

        // Create File Path for product folder
        $product_slug = $post->post_name;
        $wp_files_directory_slug = $content_directory . 'wplit-files/' . $product_slug . '/';
        $wplit_files_directory_url = $uploads_content_url . 'wplit-files/' . $product_slug . '/';

        if (! is_dir($wp_files_directory_slug)){

        mkdir( $wp_files_directory_slug, 0755 );
        }

        // Create File For for product version
        $wp_files_directory_path = $wp_files_directory_slug  . 'v' . $wplit_product_version . '/';
        $wplit_files_version_url = $wplit_files_directory_url . 'v' . $wplit_product_version . '/';

        if(! is_dir($wp_files_directory_path)) {
        mkdir( $wp_files_directory_path, 0755 );
        }

        // Create Product Logo Folders and move files after upload
        if(!empty($_FILES['wplit_product_logo']['name'])){
            $supported_file_type = array('image/png');

            $file_type = wp_check_filetype(basename($_FILES['wplit_product_logo']['name']));
            $upload_file_type = $file_type['type'];

            if(in_array($upload_file_type, $supported_file_type)) {
                $thefile = sanitize_file_name(basename($_FILES['wplit_product_logo']['name']));
                if (validate_file($thefile) !== 0) {
                    wp_die('Invalid file name');
                }

                $tmp_name = $_FILES['wplit_product_logo']['tmp_name'];
                if (! is_uploaded_file($tmp_name)) {
                    wp_die('Invalid upload');
                }

                if( $wp_files_directory_slug ) {

                    $wplit_files_logo_directory = $wp_files_directory_slug . 'logo/';
                }

                if( $wplit_files_directory_url ) {

                    $wplit_files_logo_url = $wplit_files_directory_url . 'logo/';
                }


                if (! is_dir($wplit_files_logo_directory)){
    
                mkdir( $wplit_files_logo_directory, 0755 );
                }

                if( $wplit_files_logo_directory ) {
                    move_uploaded_file($tmp_name, $wplit_files_logo_directory . $thefile);
                } else {
                    wp_die('There was an error uploading the product to the directory.');
                }

                $wplit_product_logo_url = $wplit_files_logo_url . $thefile;
                update_post_meta( $post_id, 'wplit_product_logo_url', $wplit_product_logo_url ); 

            } else {
                wp_die('Incorrect File Format');
            }

        }


        // Create Product Banner Folders and move files after upload
        if(!empty($_FILES['wplit_product_banner']['name'])){
            $supported_file_type = array('image/png');

            $file_type = wp_check_filetype(basename($_FILES['wplit_product_banner']['name']));
            $upload_file_type = $file_type['type'];

            if(in_array($upload_file_type, $supported_file_type)) {
                $thefile = sanitize_file_name(basename($_FILES['wplit_product_banner']['name']));
                if (validate_file($thefile) !== 0) {
                    wp_die('Invalid file name');
                }

                $tmp_name = $_FILES['wplit_product_banner']['tmp_name'];
                if (! is_uploaded_file($tmp_name)) {
                    wp_die('Invalid upload');
                }
                

                if( $wp_files_directory_slug ) {

                $wplit_files_banner_directory = $wp_files_directory_slug . 'banner/';
                }

                if( $wplit_files_directory_url ) {

                    $wplit_files_banner_url = $wplit_files_directory_url . 'banner/';
                }

                if (! is_dir($wplit_files_banner_directory)){
    
                mkdir( $wplit_files_banner_directory, 0755 );
                }

                if( $wplit_files_banner_directory ) {
                    move_uploaded_file($tmp_name, $wplit_files_banner_directory . $thefile);
                } else {
                    wp_die('There was an error uploading the product to the directory.');
                }

                $wplit_product_banner_url = $wplit_files_banner_url . $thefile;
                update_post_meta( $post_id, 'wplit_product_banner_url', $wplit_product_banner_url );                   


            } else {
                wp_die('Incorrect File Format');
            }

        }

        // Create Plugin Theme folders
        if(!empty($_FILES['wplit_product_file_upload']['name'])){
            $supported_file_type = array('application/zip');

            $file_type = wp_check_filetype(basename($_FILES['wplit_product_file_upload']['name']));
            $upload_file_type = $file_type['type'];

            if(in_array($upload_file_type, $supported_file_type)) {
                $thefile = sanitize_file_name(basename($_FILES['wplit_product_file_upload']['name']));
                if (validate_file($thefile) !== 0) {
                    wp_die('Invalid file name');
                }

                $tmp_name = $_FILES['wplit_product_file_upload']['tmp_name'];
                if (! is_uploaded_file($tmp_name)) {
                    wp_die('Invalid upload');
                }

                // A zip file starts with "PK". The extension alone proves nothing.
                $handle = fopen( $tmp_name, 'rb' );
                $magic  = $handle ? (string) fread( $handle, 2 ) : '';
                if ( $handle ) {
                    fclose( $handle );
                }
                if ( 'PK' !== $magic ) {
                    wp_die('The uploaded file is not a zip file.');
                }

                // Each upload gets a folder with a random name, so the package cannot be found by guessing its address
                // even on servers that ignore .htaccess. Packages are only served through the license API.
                $package_directory = \Devllo\WPLicenseIt\Files\ProtectedStorage::new_package_directory( $wp_files_directory_path );
                $package_token     = basename( $package_directory );

                if ( ! move_uploaded_file( $tmp_name, $package_directory . $thefile ) ) {
                    wp_die('There was an error uploading the product to the directory.');
                }

                $file_dir_path     = 'wplit-files/' . $product_slug . '/v' . $wplit_product_version . '/' . $package_token . '/' . $thefile;
                $file_dir_location = $wplit_files_version_url . $package_token . '/' . $thefile;

                update_post_meta( $post_id, 'file_dir_location', $file_dir_location );
                update_post_meta( $post_id, 'file_dir_path', $file_dir_path );
                update_post_meta( $post_id, 'file_name', $thefile );

            } else {
                wp_die('Incorrect File Format');
            }
        }
    }
}

    new WP_License_It_Product_Admin();

