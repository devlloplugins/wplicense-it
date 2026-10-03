<?php
/**
 * Personal data export and erasure.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Privacy;

use Devllo\WPLicenseIt\Licenses\Activation;
use Devllo\WPLicenseIt\Licenses\License;

/**
 * The logic behind WordPress's "Export Personal Data" and "Erase Personal Data" tools.
 *
 * What is stored about a person: the email on a license, the account it belongs to, the sites it was
 * activated on, order records (and, for orders migrated from 1.x, name, address, phone) and the history
 * of the license.
 *
 * Erasing removes the identity but keeps the license itself: its key, product and dates, the technical
 * site addresses and the order totals. That is what lets the seller keep supporting the license, count
 * activations and keep accounting records, and the tool says so to the administrator.
 */
final class PrivacyService {

	public const PER_PAGE = 25;

	private const BILLING_LABELS = array(
		'first_name'      => 'First name',
		'last_name'       => 'Last name',
		'billing_company' => 'Company',
		'billing_address' => 'Address',
		'billing_city'    => 'City',
		'billing_state'   => 'State',
		'postal_code'     => 'Postal code',
		'billing_country' => 'Country',
		'billing_phone'   => 'Phone',
		'order_email'     => 'Order email',
		'discount_code'   => 'Discount code',
	);

	/**
	 * Constructor.
	 *
	 * @param PrivacyRepository $repository Storage.
	 */
	public function __construct( private PrivacyRepository $repository ) {
	}

	/**
	 * One page of a person's data in the format WordPress's exporter wants.
	 *
	 * @param string   $email   Email address of the person.
	 * @param int|null $user_id WordPress user ID, if they have an account.
	 * @param int      $page    Page number, from 1.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, ?int $user_id, int $page ): array {
		$email    = strtolower( trim( $email ) );
		$page     = max( 1, $page );
		$licenses = $this->repository->licenses( $email, $user_id, $page, self::PER_PAGE );
		$items    = array();

		foreach ( $licenses as $license ) {
			$items[] = $this->license_item( $license );
		}

		foreach ( $this->repository->orders( $licenses, 1 === $page ? $user_id : null ) as $order ) {
			$items[] = $this->order_item( $order );
		}

		// The 1.x tables are small and only needed once.
		if ( 1 === $page ) {
			$legacy = $this->repository->legacy_rows( $email, $user_id );

			foreach ( $legacy['licenses'] as $row ) {
				$items[] = $this->legacy_license_item( $row );
			}
			foreach ( $legacy['orders'] as $row ) {
				$items[] = $this->legacy_order_item( $row );
			}
		}

		return array(
			'data' => $items,
			'done' => count( $licenses ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymises up to one page of a person's data.
	 *
	 * Call it again while "done" is false. Licenses that were anonymised no longer match the person, so
	 * every call looks at the first page.
	 *
	 * @param string   $email   Email address of the person.
	 * @param int|null $user_id WordPress user ID, if they have an account.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email, ?int $user_id ): array {
		$email    = strtolower( trim( $email ) );
		$licenses = $this->repository->licenses( $email, $user_id, 1, self::PER_PAGE );
		$ids      = array();

		foreach ( $licenses as $license ) {
			$this->repository->anonymize_license( $license->id, self::placeholder( $license->id ) );
			$ids[] = $license->id;
		}

		$orders = $this->repository->anonymize_orders( $ids, $user_id );
		$legacy = $this->repository->anonymize_legacy( $email, $user_id );

		$removed = array() !== $ids || $orders > 0 || $legacy > 0;

		return array(
			'items_removed'  => $removed,
			'items_retained' => array() !== $ids,
			'messages'       => array() === $ids ? array() : array(
				__( 'WPLicense It: the name, email address and account were removed from the licenses and orders. The license keys, products, dates, activated site addresses and order totals were kept, because they are needed to support the licenses and for accounting.', 'wplicense-it' ),
			),
			'done'           => count( $licenses ) < self::PER_PAGE,
		);
	}

	/**
	 * The email that replaces a real one. ".invalid" can never be a real address.
	 *
	 * @param int $license_id License ID.
	 */
	public static function placeholder( int $license_id ): string {
		return 'erased-' . $license_id . '@erased.invalid';
	}

	/**
	 * Whether an email is one of the placeholders.
	 *
	 * @param string $email Email address.
	 */
	public static function is_placeholder( string $email ): bool {
		return 1 === preg_match( '/^erased-\d+@erased\.invalid$/', $email );
	}

	/**
	 * A license and what hangs off it.
	 *
	 * @param License $license License.
	 * @return array<string, mixed>
	 */
	private function license_item( License $license ): array {
		$data = array(
			array( 'name' => __( 'License key', 'wplicense-it' ), 'value' => $license->license_key ),
			array( 'name' => __( 'Email', 'wplicense-it' ), 'value' => $license->email ),
			array( 'name' => __( 'Product ID', 'wplicense-it' ), 'value' => (string) $license->product_id ),
			array( 'name' => __( 'Status', 'wplicense-it' ), 'value' => $license->status ),
			array( 'name' => __( 'Sites allowed', 'wplicense-it' ), 'value' => 0 === $license->activation_limit ? __( 'Unlimited', 'wplicense-it' ) : (string) $license->activation_limit ),
			array( 'name' => __( 'Expires', 'wplicense-it' ), 'value' => null === $license->expires_at ? __( 'Never', 'wplicense-it' ) : $license->expires_at->format( 'Y-m-d H:i:s' ) . ' UTC' ),
			array( 'name' => __( 'Created', 'wplicense-it' ), 'value' => $license->created_at->format( 'Y-m-d H:i:s' ) . ' UTC' ),
		);

		foreach ( $this->repository->activations( $license->id ) as $activation ) {
			$data[] = array(
				'name'  => __( 'Activated site', 'wplicense-it' ),
				'value' => $this->activation_text( $activation ),
			);
		}

		foreach ( $this->repository->events( $license->id, 200 ) as $event ) {
			$data[] = array(
				'name'  => __( 'History', 'wplicense-it' ),
				'value' => $event['created_at'] . ' UTC: ' . $event['type'] . self::details( $event['data'] ),
			);
		}

		return array(
			'group_id'          => 'wplicense-it-licenses',
			'group_label'       => __( 'Licenses', 'wplicense-it' ),
			'group_description' => __( 'Software licenses bought or issued to this person.', 'wplicense-it' ),
			'item_id'           => 'wplicense-it-license-' . $license->id,
			'data'              => $data,
		);
	}

	/**
	 * An order record.
	 *
	 * @param array<string, mixed> $order Order record.
	 * @return array<string, mixed>
	 */
	private function order_item( array $order ): array {
		$data = array(
			array( 'name' => __( 'Order number', 'wplicense-it' ), 'value' => $order['order_number'] ),
			array( 'name' => __( 'Source', 'wplicense-it' ), 'value' => $order['source'] ),
			array( 'name' => __( 'Total', 'wplicense-it' ), 'value' => number_format( $order['total_minor'] / 100, 2, '.', '' ) . ' ' . $order['currency'] ),
			array( 'name' => __( 'Status', 'wplicense-it' ), 'value' => $order['status'] ),
			array( 'name' => __( 'Date', 'wplicense-it' ), 'value' => $order['created_at'] . ' UTC' ),
		);

		foreach ( self::BILLING_LABELS as $field => $label ) {
			if ( isset( $order['billing'][ $field ] ) && '' !== $order['billing'][ $field ] ) {
				$data[] = array( 'name' => $label, 'value' => $order['billing'][ $field ] );
			}
		}

		return array(
			'group_id'          => 'wplicense-it-orders',
			'group_label'       => __( 'License orders', 'wplicense-it' ),
			'group_description' => __( 'Orders for software licenses.', 'wplicense-it' ),
			'item_id'           => 'wplicense-it-order-' . $order['id'],
			'data'              => $data,
		);
	}

	/**
	 * A license row from the 1.x table.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function legacy_license_item( array $row ): array {
		return array(
			'group_id'          => 'wplicense-it-legacy-licenses',
			'group_label'       => __( 'Licenses (WPLicense It 1.x)', 'wplicense-it' ),
			'group_description' => __( 'Records kept from before WPLicense It 2.0.', 'wplicense-it' ),
			'item_id'           => 'wplicense-it-legacy-license-' . (int) $row['id'],
			'data'              => array(
				array( 'name' => __( 'License key', 'wplicense-it' ), 'value' => (string) $row['license_key'] ),
				array( 'name' => __( 'Email', 'wplicense-it' ), 'value' => (string) $row['email'] ),
				array( 'name' => __( 'Product ID', 'wplicense-it' ), 'value' => (string) $row['product_id'] ),
				array( 'name' => __( 'Valid until', 'wplicense-it' ), 'value' => (string) $row['valid_until'] ),
				array( 'name' => __( 'Created', 'wplicense-it' ), 'value' => (string) $row['created_at'] ),
			),
		);
	}

	/**
	 * An order row from the 1.x table.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function legacy_order_item( array $row ): array {
		$data = array(
			array( 'name' => __( 'Order number', 'wplicense-it' ), 'value' => (string) $row['order_number'] ),
			array( 'name' => __( 'Total', 'wplicense-it' ), 'value' => (string) $row['order_total'] ),
			array( 'name' => __( 'Date', 'wplicense-it' ), 'value' => (string) $row['created_at'] ),
		);

		foreach ( self::BILLING_LABELS as $field => $label ) {
			if ( isset( $row[ $field ] ) && '' !== (string) $row[ $field ] ) {
				$data[] = array( 'name' => $label, 'value' => (string) $row[ $field ] );
			}
		}

		return array(
			'group_id'          => 'wplicense-it-legacy-orders',
			'group_label'       => __( 'License orders (WPLicense It 1.x)', 'wplicense-it' ),
			'group_description' => __( 'Records kept from before WPLicense It 2.0.', 'wplicense-it' ),
			'item_id'           => 'wplicense-it-legacy-order-' . (int) $row['id'],
			'data'              => $data,
		);
	}

	/**
	 * One activation in words.
	 *
	 * @param Activation $activation Activation.
	 */
	private function activation_text( Activation $activation ): string {
		return $activation->site . ' (' . $activation->status . ', ' . $activation->activated_at->format( 'Y-m-d' ) . ')';
	}

	/**
	 * Event details on one line, like ": site: one.com, email: a@x.com → b@x.com".
	 *
	 * @param array<string, mixed> $data Event data.
	 */
	private static function details( array $data ): string {
		$parts = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$parts[] = $key . ': ' . implode( ' → ', array_map( static fn( $v ): string => null === $v ? '-' : (string) $v, $value ) );
			} elseif ( is_scalar( $value ) ) {
				$parts[] = $key . ': ' . (string) $value;
			}
		}

		return array() === $parts ? '' : ' (' . implode( ', ', $parts ) . ')';
	}
}
