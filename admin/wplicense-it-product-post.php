<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_License_It_Product_Post {

    public function __construct(){
        add_action( 'init', array($this, 'wp_license_it_post_type'), 0);
        add_action( 'admin_init', array($this, 'add_wp_license_it_caps'));

    }

		
	// Register Custom Post Type
	function wp_license_it_post_type() {
		$labels = array(
			'name'                  => _x( 'License It Products', 'Post Type General Name', 'wplicense-it' ),
			'singular_name'         => _x( 'License Products', 'Post Type Singular Name', 'wplicense-it' ),
			'menu_name'             => __( 'License Products', 'wplicense-it' ),
			'name_admin_bar'        => __( 'License Product', 'wplicense-it' ),
			'archives'              => __( 'Product Archives', 'wplicense-it' ),
			'attributes'            => __( 'Product Attributes', 'wplicense-it' ),
			'parent_item_colon'     => __( 'Parent Product:', 'wplicense-it' ),
			'all_items'             => __( 'All Products', 'wplicense-it' ),
			'add_new_item'          => __( 'Add New Product', 'wplicense-it' ),
			'add_new'               => __( 'Add New License Product', 'wplicense-it' ),
			'new_item'              => __( 'New License Product', 'wplicense-it' ),
			'edit_item'             => __( 'Edit License Product', 'wplicense-it' ),
			'update_item'           => __( 'Update Product', 'wplicense-it' ),
			'view_item'             => __( 'View Product', 'wplicense-it' ),
			'view_items'            => __( 'View Products', 'wplicense-it' ),
			'search_items'          => __( 'Search Product', 'wplicense-it' ),
			'not_found'             => __( 'Not found', 'wplicense-it' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'wplicense-it' ),
			'featured_image'        => __( 'Featured Image', 'wplicense-it' ),
			'set_featured_image'    => __( 'Set featured image', 'wplicense-it' ),
			'remove_featured_image' => __( 'Remove featured image', 'wplicense-it' ),
			'use_featured_image'    => __( 'Use as featured image', 'wplicense-it' ),
			'insert_into_item'      => __( 'Insert into Post', 'wplicense-it' ),
			'uploaded_to_this_item' => __( 'Uploaded to this post', 'wplicense-it' ),
			'items_list'            => __( 'Products list', 'wplicense-it' ),
			'items_list_navigation' => __( 'Products list navigation', 'wplicense-it' ),
			'filter_items_list'     => __( 'Filter posts list', 'wplicense-it' ),
		);
		$capabilities = array(
			'edit_post'             => 'edit_wp_license_it_product',
			'read_post'             => 'read_wp_license_it_product',
			'delete_post'           => 'delete_wp_license_it_product',
			'edit_posts'            => 'edit_wp_license_it_product',
			'edit_others_posts'     => 'edit_others_wp_license_it_product',
			'publish_posts'         => 'publish_wp_license_it_product',
			'read_private_posts'    => 'read_private_wp_license_it_product',

		);
		$args = array(
			'label'                 => __( 'License Products', 'wplicense-it' ),
			'description'           => __( 'WP License It Products', 'wplicense-it' ),
			'labels'                => $labels,
			'supports'           	=> array( 'title', 'author' ),
			'rewrite'				=> array('slug' => 'license'),
			'hierarchical'          => true,
			// Products are internal records that the license API and WooCommerce look up by ID. They are not web pages,
			// and are not exposed through the REST API. Without the block editor, the product screen's file uploads work.
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'menu_position'         => 20,
			'menu_icon'             => 'dashicons-plugins-checked',
			'show_in_admin_bar'     => true,
			'show_in_nav_menus'     => false,
			'can_export'            => false,
			'has_archive'           => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => false,
			'capabilities'          => $capabilities,
			'show_in_rest'          => false,
		);
		register_post_type( 'wplit_product', $args );

	}

	function add_wp_license_it_caps() {
		// gets the administrator role
		$admin = get_role( 'administrator' );

		$admin->add_cap( 'edit_wp_license_it_product' ); 
		$admin->add_cap( 'edit_wp_license_it_products' ); 
		$admin->add_cap( 'edit_others_wp_license_it_product' ); 
		$admin->add_cap( 'publish_wp_license_it_product' ); 
		$admin->add_cap( 'read_wp_license_it_product' ); 
		$admin->add_cap( 'read_private_wp_license_it_product' ); 
		$admin->add_cap( 'delete_wp_license_it_product' ); 
	}

}

new WP_License_It_Product_Post();