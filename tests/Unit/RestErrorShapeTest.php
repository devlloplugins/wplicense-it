<?php
/**
 * Tests for the rewrite of WordPress's own parameter errors into the API's error shape.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\RestController;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Api\RestController::documented_error
 */
final class RestErrorShapeTest extends TestCase {

	public function test_a_missing_parameter_gets_the_documented_shape(): void {
		$body = RestController::documented_error(
			array(
				'code'    => 'rest_missing_callback_param',
				'message' => 'Missing parameter(s): license_key, product_id',
				'data'    => array(
					'status' => 400,
					'params' => array( 'license_key', 'product_id' ),
				),
			),
			400
		);

		$this->assertSame(
			array(
				'success' => false,
				'code'    => 'invalid_request',
				'message' => 'A required parameter is missing: license_key, product_id.',
			),
			$body
		);
	}

	public function test_an_invalid_parameter_gets_the_documented_shape(): void {
		$body = RestController::documented_error(
			array(
				'code' => 'rest_invalid_param',
				'data' => array(
					'status' => 400,
					'params' => array( 'product_id' => 'product_id must be greater than or equal to 1' ),
				),
			),
			400
		);

		$this->assertSame( 'invalid_request', $body['code'] );
		$this->assertFalse( $body['success'] );
		$this->assertSame( 'A parameter is not valid: product_id.', $body['message'] );
	}

	public function test_a_broken_json_body_gets_the_documented_shape(): void {
		$body = RestController::documented_error(
			array(
				'code' => 'rest_invalid_json',
				'data' => array( 'status' => 400 ),
			),
			400
		);

		$this->assertSame( 'invalid_request', $body['code'] );
		$this->assertFalse( $body['success'] );
	}

	public function test_only_safe_parameter_names_are_repeated(): void {
		$body = RestController::documented_error(
			array(
				'code' => 'rest_invalid_param',
				'data' => array(
					'params' => array(
						'<script>' => 'x',
						'site_url' => 'y',
					),
				),
			),
			400
		);

		$this->assertSame( 'A parameter is not valid: site_url.', $body['message'] );
	}

	/**
	 * @dataProvider other_responses
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status Status.
	 */
	public function test_other_responses_are_left_alone( $data, int $status ): void {
		$this->assertNull( RestController::documented_error( $data, $status ) );
	}

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public function other_responses(): array {
		return array(
			'our own error' => array(
				array(
					'success' => false,
					'code'    => 'not_found',
					'message' => 'x',
				),
				404,
			),
			'a success'     => array( array( 'success' => true ), 200 ),
			'a core 404'    => array(
				array(
					'code'    => 'rest_no_route',
					'message' => 'x',
				),
				404,
			),
			'wrong status'  => array( array( 'code' => 'rest_invalid_param' ), 200 ),
			'not an array'  => array( 'text', 400 ),
		);
	}
}
