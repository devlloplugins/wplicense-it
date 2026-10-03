<?php

class WPLit_Add_License {

    public function __construct() {

    }

    // Add Order to Database
    function wplit_add_order(){

        global $wpdb;
        global $current_user;
        wp_get_current_user();
        $user = wp_get_current_user();
        $user_id = $user->ID;

        $product_id = isset($_COOKIE['wplit_product_id']) ? intval($_COOKIE['wplit_product_id']) : 0;
        
        $today = date("Ymd");
        $order_number = strtoupper('#' .$today. '-' .wp_generate_password( 3, false, false ));
        // $order_number = strtoupper(wp_generate_password( 10, false, false ));

        $amount = get_post_meta( $product_id, 'wplit_product_price', true );
        $total_amount = $amount;
        $order_email = (string) $current_user->user_email;

        // Defaults, used for free products that skip the billing form
        $first_name = $last_name = $billing_company = $billing_address = '';
        $billing_state = $billing_city = $billing_country = $postal_code = $billing_phone = '';
        $discount_code = '';
        $order_status = 'completed';

        if(isset($_POST['action']) && $_POST['action'] == 'stripe' && isset($_POST['stripe_nonce']) && wp_verify_nonce($_POST['stripe_nonce'], 'stripe-nonce')) {
            $first_name = isset($_POST['wplit_billing_user_first']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_user_first'])) : '';
            $last_name = isset($_POST['wplit_billing_user_last']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_user_last'])) : '';
            $billing_company = isset($_POST['wplit_billing_company']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_company'])) : '';
            $billing_address = isset($_POST['wplit_billing_address']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_address'])) : '';
            $billing_state = isset($_POST['wplit_billing_state']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_state'])) : '';
            $billing_city = isset($_POST['wplit_billing_city']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_city'])) : '';
            $billing_country = isset($_POST['wplit_billing_countryl']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_countryl'])) : '';
            $postal_code = isset($_POST['wplit_billing_postal']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_postal'])) : '';
            $billing_phone = isset($_POST['wplit_billing_phone']) ? sanitize_text_field(wp_unslash($_POST['wplit_billing_phone'])) : '';
        }

        $table_name = $wpdb->prefix . 'wplit_orders';
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'product_id' => $product_id,
                'order_number' => $order_number,
                'order_sub_total' => $amount,
                'order_total' => $total_amount,
                'order_email' => $order_email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'billing_company' => $billing_company,
                'billing_address' => $billing_address,
                'billing_state' => $billing_state,
                'billing_city' => $billing_city,
                'billing_country' => $billing_country,
                'billing_phone' => $billing_phone,
                'postal_code' => $postal_code,
                'order_status' => $order_status,
                'discount_code' => $discount_code,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' )
            ),
            array(
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s'
            )
        );
    }

    // Add License to Databse
    function wplit_add_license( $echo_notice = true ) {

        if (isset($_COOKIE['wplit_product_id'] )){

            $product_id = intval($_COOKIE['wplit_product_id']); 

            // The product must exist and be published
            $product = get_post($product_id);
            if (! $product || 'wplit_product' !== $product->post_type || 'publish' !== $product->post_status || ! is_user_logged_in()) {
                return;
            }

            do_action('wplit_before_add_license');

            global $wpdb;
            global $current_user;
            wp_get_current_user();
            $user = wp_get_current_user();
            $user_id = $user->ID;

            // Continue if user doesn't have license for this product
            $email = (string) $current_user->user_email;
            // Nonce valid, handle data
            // $email = sanitize_text_field( $_POST['email'] );
            // $product_id = intval( $_POST['product'] );

            $product_api_key = get_post_meta( $product_id, 'wplit_product_api_key', true );

            $wplit_expire = get_post_meta( $product_id, 'wplit_expire', true );

            $wplit_expire_time = get_post_meta( $product_id, 'wplit_expire_time', true );

            // Default to no expiry
            $valid_until = '0000-00-00 00:00:00';

            if ($wplit_expire == 'yes'){
                if ($wplit_expire_time == '1-year' ){
                    $valid_until = date('Y-m-d', strtotime('+1 year'));
                } elseif ($wplit_expire_time == '1-month' ){
                    $valid_until = date('Y-m-d', strtotime('+1 month'));
                }
            }
            
            
            $license_key = wp_generate_password( 24, false, false );

            // Once the 1.x data is migrated, the 2.0 tables are what the API reads. Issue the
            // license there and reuse its key, so the 1.x copy below (still shown on the
            // licenses page) matches.
            if ( \Devllo\WPLicenseIt\Plugin::legacy_data_migrated() ) {
                try {
                    $expires = null;
                    if ( '0000-00-00 00:00:00' !== $valid_until ) {
                        $expires = ( new \DateTimeImmutable( $valid_until, wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
                    }

                    // 1.x never limited sites, so neither does this checkout unless the product sets a limit.
                    $activation_limit = max( 0, (int) get_post_meta( $product_id, 'wplit_default_activation_limit', true ) );

                    $issued      = \Devllo\WPLicenseIt\Plugin::instance()->licenses()->issue_license( $product_id, $email, $user_id, null, $activation_limit, $expires );
                    $license_key = $issued->license_key;
                } catch ( \Throwable $e ) {
                    error_log( 'WPLicense It: could not issue the license in the 2.0 tables: ' . $e->getMessage() );
                }
            }
            // Save data to database
            $table_name = $wpdb->prefix . 'wplit_product_licenses';
            $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'product_id' => $product_id,
                    'email' => $email,
                    'license_key' => $license_key,
                    'product_api_key' => $product_api_key,
                    'license_status' => 'active',
                    'valid_until' => $valid_until,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' )
                ),
                array(
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s'
                )
            );

           $this->wplit_add_order();

            do_action('wplit_after_add_license');

            if ($echo_notice) {
                echo '<br/>License Purchased. Please visit Licenses page for License Information.';
            }

        }
    }


}