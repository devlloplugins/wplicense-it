<?php
/**
 * License actions.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseChanges;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\WooCommerce\PeriodParser;
use InvalidArgumentException;

/**
 * Handles every button and form on the license screens (admin-post.php). Each request must come
 * from a user with the manage capability and carry a nonce tied to the action and the license.
 */
final class LicenseActions {

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . Urls::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Dispatches an action and redirects back with a result code.
	 */
	public function handle(): void {
		Capabilities::require_manage();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() just below.
		$do         = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$license_id = isset( $_REQUEST['license'] ) ? absint( wp_unslash( $_REQUEST['license'] ) ) : 0;
		// phpcs:enable

		check_admin_referer( 'wplit_license_' . $do . '_' . $license_id );

		try {
			$result = $this->dispatch( $do, $license_id );
		} catch ( LicenseException $e ) {
			$this->redirect( $license_id > 0 ? Urls::view( $license_id ) : Urls::add(), array( 'wplit_error' => $e->reason() ) );

			return;
		}

		$this->redirect( $result['url'], array( 'wplit_notice' => $result['notice'] ) );
	}

	/**
	 * Performs an action.
	 *
	 * @param string $do         Action name.
	 * @param int    $license_id License ID.
	 * @return array{url:string,notice:string}
	 * @throws LicenseException If the action fails.
	 */
	private function dispatch( string $do, int $license_id ): array {
		$view = Urls::view( $license_id );

		switch ( $do ) {
			case 'revoke':
				$this->licenses->revoke_license( $license_id );

				return array( 'url' => $view, 'notice' => 'revoked' );

			case 'refund':
				$this->licenses->revoke_license( $license_id, Status::REFUNDED );

				return array( 'url' => $view, 'notice' => 'refunded' );

			case 'reinstate':
				$this->licenses->reinstate_license( $license_id );

				return array( 'url' => $view, 'notice' => 'reinstated' );

			case 'renew':
				$this->licenses->renew_license( $license_id, $this->renewal_period() );

				return array( 'url' => $view, 'notice' => 'renewed' );

			case 'update':
				$this->licenses->update_license( $license_id, $this->changes_from_request() );

				return array( 'url' => $view, 'notice' => 'updated' );

			case 'deactivate_site':
				return $this->deactivate_site( $license_id );

			case 'add':
				return $this->add_license();
		}

		throw new LicenseException( LicenseException::INVALID_INPUT, 'Unknown action.' );
	}

	/**
	 * Frees one site of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array{url:string,notice:string}
	 * @throws LicenseException If the license or the site is not found.
	 */
	private function deactivate_site( int $license_id ): array {
		$license = $this->licenses->find( $license_id );

		if ( null === $license ) {
			throw new LicenseException( LicenseException::NOT_FOUND, 'License not found.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$site   = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$result = $this->licenses->deactivate( $license->license_key, $site );

		if ( ! $result->is_success() ) {
			throw new LicenseException( 'no_site', 'Site not active.' );
		}

		return array( 'url' => Urls::view( $license_id ), 'notice' => 'deactivated' );
	}

	/**
	 * Issues a license by hand.
	 *
	 * @return array{url:string,notice:string}
	 * @throws LicenseException If the input is invalid.
	 */
	private function add_license(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$user_ref   = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$limit      = isset( $_POST['activation_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['activation_limit'] ) ) : '';
		$mode       = isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : 'lifetime';
		$period     = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$date       = isset( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : '';
		$send       = ! empty( $_POST['send_email'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$post = $product_id > 0 ? get_post( $product_id ) : null;
		if ( ! $post || 'wplit_product' !== $post->post_type || 'publish' !== $post->post_status ) {
			throw new LicenseException( 'no_product', 'Product not found.' );
		}

		$user_id = null;
		if ( '' !== $user_ref ) {
			$user = get_user_by( is_email( $user_ref ) ? 'email' : 'login', $user_ref );
			if ( ! $user ) {
				throw new LicenseException( 'no_user', 'User not found.' );
			}

			$user_id = (int) $user->ID;
			$email   = '' === $email ? $user->user_email : $email;
		}

		if ( '' !== $limit && ! ctype_digit( $limit ) ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'Bad limit.' );
		}

		$default_limit = (string) get_post_meta( $product_id, 'wplit_default_activation_limit', true );
		$activation    = '' !== $limit ? (int) $limit : ( ctype_digit( $default_limit ) ? (int) $default_limit : 1 );

		$expires = null;
		if ( 'period' === $mode ) {
			try {
				$interval = PeriodParser::parse( $period );
			} catch ( InvalidArgumentException $e ) {
				throw new LicenseException( LicenseException::INVALID_INPUT, 'Bad period.' );
			}

			$expires = null === $interval ? null : ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->add( $interval );
		} elseif ( 'date' === $mode ) {
			$expires = DateInput::end_of_day_utc( $date, wp_timezone() );
			if ( null === $expires ) {
				throw new LicenseException( LicenseException::INVALID_INPUT, 'Bad date.' );
			}
		}

		$license = $this->licenses->issue_license( $product_id, $email, $user_id, null, $activation, $expires );

		if ( $send ) {
			$this->email_customer( $license, $post->post_title );

			return array( 'url' => Urls::view( $license->id ), 'notice' => 'emailed' );
		}

		return array( 'url' => Urls::view( $license->id ), 'notice' => 'issued' );
	}

	/**
	 * The period chosen on the Renew form.
	 *
	 * @throws LicenseException If the period is not one of the choices.
	 */
	private function renewal_period(): \DateInterval {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';

		try {
			$interval = PeriodParser::parse( $period );
		} catch ( InvalidArgumentException $e ) {
			$interval = null;
		}

		if ( null === $interval ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'Choose a period to renew for.' );
		}

		return $interval;
	}

	/**
	 * Reads the Edit form.
	 *
	 * @throws LicenseException If a value is invalid.
	 */
	private function changes_from_request(): LicenseChanges {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$limit = isset( $_POST['activation_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['activation_limit'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$mode  = isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : '';
		$date  = isset( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$changes = new LicenseChanges();

		if ( '' !== $limit ) {
			if ( ! ctype_digit( $limit ) ) {
				throw new LicenseException( LicenseException::INVALID_INPUT, 'Bad limit.' );
			}
			$changes->activation_limit = (int) $limit;
		}

		if ( '' !== $email ) {
			$changes->email = $email;
		}

		if ( 'lifetime' === $mode ) {
			$changes->change_expiry = true;
			$changes->expires_at    = null;
		} elseif ( 'date' === $mode ) {
			$expires = DateInput::end_of_day_utc( $date, wp_timezone() );
			if ( null === $expires ) {
				throw new LicenseException( LicenseException::INVALID_INPUT, 'Bad date.' );
			}
			$changes->change_expiry = true;
			$changes->expires_at    = $expires;
		}

		return $changes;
	}

	/**
	 * Emails the key to the customer.
	 *
	 * @param License $license      The license.
	 * @param string  $product_name Product name.
	 */
	private function email_customer( License $license, string $product_name ): void {
		$expires = null === $license->expires_at ? __( 'Never', 'wplicense-it' ) : wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() );

		$message = sprintf(
			/* translators: 1: product name, 2: license key, 3: expiry date. */
			__( "Thank you. Your license for %1\$s is ready.\n\nLicense key: %2\$s\nExpires: %3\$s\n", 'wplicense-it' ),
			$product_name,
			$license->license_key,
			$expires
		);

		/* translators: %s: product name. */
		wp_mail( $license->email, sprintf( __( 'Your license for %s', 'wplicense-it' ), $product_name ), $message );
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string                $url  Target.
	 * @param array<string, scalar> $args Query arguments to add.
	 */
	private function redirect( string $url, array $args ): void {
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}
}
