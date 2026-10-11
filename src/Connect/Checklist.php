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
				'label' => 'Upload the plugin or theme zip',
				'hint'  => 'The version and requirements are read from the file.',
			),
			array(
				'done'  => $plans > 0,
				'label' => 'Add at least one plan',
				'hint'  => 'A plan is what a customer buys: a price, a number of sites and how long it lasts.',
			),
			array(
				'done'  => $plans > 0 && $facts['created_count'] >= $plans,
				'label' => 'Every plan has a product in WooCommerce',
				'hint'  => 'They are created as drafts when you save the plans.',
			),
			array(
				'done'  => $facts['server_https'],
				'label' => 'This site uses HTTPS',
				'hint'  => 'The client code only talks to an HTTPS server, so customers\' sites can activate. Plain HTTP works only on local test sites.',
			),
			array(
				'done'  => $plans > 0 && $facts['tested_count'] >= $plans,
				'label' => 'Test purchase passed for every plan',
				'hint'  => 'A plan can be published in the shop only after this. The test purchase button is not available yet.',
			),
			array(
				'done'  => $plans > 0 && $facts['published_count'] >= $plans,
				'label' => 'Every plan is on sale',
				'hint'  => 'Published in WooCommerce.',
			),
		);
	}
}
