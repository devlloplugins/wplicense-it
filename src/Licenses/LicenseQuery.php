<?php
/**
 * License search parameters.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * What the admin list asks for: filters, sort order and page. Values are clamped to safe ones here,
 * and the repository only ever uses the whitelisted column names below.
 */
final class LicenseQuery {

	public const ORDERBY = array( 'id', 'created_at', 'expires_at', 'status', 'email', 'product_id' );

	public ?string $status = null;
	public ?int $product_id = null;
	public string $search   = '';
	public string $orderby  = 'id';
	public string $order    = 'DESC';
	public int $page        = 1;
	public int $per_page    = 20;

	/**
	 * Builds a query from loose input (for example $_GET), ignoring anything invalid.
	 *
	 * @param array<string, mixed> $input Input values: status, product_id, search, orderby, order, page, per_page.
	 */
	public static function from_input( array $input ): self {
		$query = new self();

		$status = isset( $input['status'] ) && is_string( $input['status'] ) ? $input['status'] : '';
		if ( in_array( $status, Status::all(), true ) ) {
			$query->status = $status;
		}

		$product_id = isset( $input['product_id'] ) && is_numeric( $input['product_id'] ) ? (int) $input['product_id'] : 0;
		if ( $product_id > 0 ) {
			$query->product_id = $product_id;
		}

		if ( isset( $input['search'] ) && is_string( $input['search'] ) ) {
			$query->search = substr( trim( $input['search'] ), 0, 100 );
		}

		if ( isset( $input['orderby'] ) && is_string( $input['orderby'] ) && in_array( $input['orderby'], self::ORDERBY, true ) ) {
			$query->orderby = $input['orderby'];
		}

		if ( isset( $input['order'] ) && is_string( $input['order'] ) && 'asc' === strtolower( $input['order'] ) ) {
			$query->order = 'ASC';
		}

		if ( isset( $input['page'] ) && is_numeric( $input['page'] ) ) {
			$query->page = max( 1, (int) $input['page'] );
		}

		if ( isset( $input['per_page'] ) && is_numeric( $input['per_page'] ) ) {
			$query->per_page = min( 200, max( 1, (int) $input['per_page'] ) );
		}

		return $query;
	}

	/**
	 * Rows to skip.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}
}
