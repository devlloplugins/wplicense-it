<?php
/**
 * The setup checklist of a software product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Connect;

/**
 * What is done and what is left before customers can buy and activate. Built from facts the caller looked up,
 * so it does not depend on WordPress.
 */
final class Checklist {

	/**
	 * Builds the checklist.
	 *
	 * @param array{has_package:bool,version:string,plan_count:int,created_count:int,tested_count:int,published_count:int,server_https:bool} $facts What is known about the product.
	 * @return array<int, array{done:bool,label:string,hint:string}>
	 */
	public static function items( array $facts ): array {
		$plans = $facts['plan_count'];

		return array(
			array(
				'done'  => $facts['has_package'] && '' !== $facts['version'],
				'label' => __( 'Upload the plugin or theme zip', 'wplicense-it' ),
				'hint'  => __( 'The version and requirements are read from the file.', 'wplicense-it' ),
			),
			array(
				'done'  => $plans > 0,
				'label' => __( 'Add at least one plan', 'wplicense-it' ),
				'hint'  => __( 'A plan is what a customer buys: a price, a number of sites and how long it lasts.', 'wplicense-it' ),
			),
			array(
				'done'  => $plans > 0 && $facts['created_count'] >= $plans,
				'label' => __( 'Every plan has a product in WooCommerce', 'wplicense-it' ),
				'hint'  => __( 'They are created as drafts when you save the plans.', 'wplicense-it' ),
			),
			array(
				'done'  => $facts['server_https'],
				'label' => __( 'This site uses HTTPS', 'wplicense-it' ),
				'hint'  => __( 'The client code only talks to an HTTPS server, so customers\' sites can activate. Plain HTTP works only on local test sites.', 'wplicense-it' ),
			),
			array(
				'done'  => $plans > 0 && $facts['tested_count'] >= $plans,
				'label' => __( 'Test purchase passed for every plan', 'wplicense-it' ),
				'hint'  => __( 'Click "Test this plan" in each plan\'s row on the Plans tab. A plan can be published in the shop only after it passes.', 'wplicense-it' ),
			),
			array(
				'done'  => $plans > 0 && $facts['published_count'] >= $plans,
				'label' => __( 'Every plan is on sale', 'wplicense-it' ),
				'hint'  => __( 'Published in WooCommerce.', 'wplicense-it' ),
			),
		);
	}
}
