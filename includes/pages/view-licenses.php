<?php


class WPLit_View_Licenses {
    public function __construct(){
        add_shortcode( 'wplit-licenses', array($this, 'view_licenses') );

    }
    public function view_licenses($content = null){
        global $wpdb;
        global $current_user;
        wp_get_current_user();

        $user = wp_get_current_user();
        $user_id = $user->ID;

        ob_start();

        if ( ! is_user_logged_in() ) {
            ob_end_clean();
            return esc_html__( 'Please log in to view your licenses.', 'wplicense-it' );
        }

        $result = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "wplit_product_licenses WHERE user_id = %d",
                $user_id
            )
        );

            foreach ($result as $print){ 
                $license_key = $print->license_key;
                $license_email = $print->email;
                $product_api_key = $print->product_api_key;

                $user_id = $print->user_id;
                $product_id = $print->product_id;
                     $download_url = home_url( '/api/wplicense-it-api/v1/get?p=' . absint( $product_id ) . '&k=' . rawurlencode( $product_api_key ) . '&e=' . rawurlencode( $license_email ) . '&l=' . rawurlencode( $license_key ) );

                     echo '<div style="display: grid;">
                        <div> Product: '
                            . esc_html( get_the_title( $product_id ) ) .
                        '</div>
                        <div> License Key: '
                            . esc_html( $license_key ) .
                        '</div>
                        <div> License Email: '
                            . esc_html( $license_email ) .
                        '</div>
                        <div> Download Product: 
                            <a href="' . esc_url( $download_url ) . '">Download</a>
                        </div>
                    </div>';    
            }

           // return $output;
           $content = ob_get_contents();
            ob_end_clean();

            return $content;   
    }
}
new WPLit_View_Licenses();