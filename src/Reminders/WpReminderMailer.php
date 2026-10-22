<?php
/**
 * Sends expiry reminders with wp_mail().
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\WooCommerce\AccountPage;

/**
 * Plain text email. The license key is left out on purpose: the email says which license, and where to renew.
 */
final class WpReminderMailer implements ReminderMailer {

	/**
	 * Constructor.
	 *
	 * @param ProductCatalog $catalog Product lookup, for the product's name.
	 */
	public function __construct( private ProductCatalog $catalog ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param License $license   The license.
	 * @param int     $days_left Whole days left.
	 */
	public function send( License $license, int $days_left ): bool {
		$email = $this->build( $license, $days_left );

		return (bool) wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * The email for a license. The wplicense_it_reminder_email filter can change any part of it.
	 *
	 * @param License $license   The license.
	 * @param int     $days_left Whole days left, or minus the days since it expired (the email after expiry).
	 * @return array{to:string,subject:string,message:string,headers:string[]}
	 */
	public function build( License $license, int $days_left ): array {
		$product = $this->catalog->find( $license->product_id );
		$name    = null !== $product && '' !== $product->name ? $product->name : __( 'your product', 'wplicense-it' );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$date    = null === $license->expires_at ? '' : wp_date( (string) get_option( 'date_format' ), $license->expires_at->getTimestamp() );
		$url     = $this->renew_url( $license );

		if ( $days_left < 1 ) {
			$subject = sprintf(
				/* translators: %s: product name. */
				__( 'Your %s license has expired', 'wplicense-it' ),
				$name
			);

			$message = sprintf(
				/* translators: 1: product name, 2: expiry date, 3: renewal address, 4: site name. */
				__( "Hello,\n\nYour license for %1\$s expired on %2\$s. Since then you no longer receive updates for it.\n\nRenew it here to get them again:\n%3\$s\n\nThank you,\n%4\$s\n", 'wplicense-it' ),
				$name,
				$date,
				$url,
				$site
			);
		} else {
			$subject = sprintf(
				/* translators: 1: product name, 2: number of days. */
				_n( 'Your %1$s license expires in %2$d day', 'Your %1$s license expires in %2$d days', $days_left, 'wplicense-it' ),
				$name,
				$days_left
			);

			$message = sprintf(
				/* translators: 1: product name, 2: expiry date, 3: renewal address, 4: site name. */
				__( "Hello,\n\nYour license for %1\$s expires on %2\$s. After that date you will no longer receive updates for it.\n\nRenew it here to keep receiving them:\n%3\$s\n\nThank you,\n%4\$s\n", 'wplicense-it' ),
				$name,
				$date,
				$url,
				$site
			);
		}//end if

		$stop     = OptOut::url( $license->email );
		$message .= "\n--\n" . sprintf(
			/* translators: %s: address of the page that stops these emails. */
			__( "Don't want these reminders? Stop them here:\n%s\n", 'wplicense-it' ),
			$stop
		);

		$email = array(
			'to'      => $license->email,
			'subject' => $subject,
			'message' => $message,
			// Mail programs show their own unsubscribe button for these (RFC 2369 and 8058).
			'headers' => array(
				'List-Unsubscribe: <' . $stop . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			),
		);

		/**
		 * Filters a reminder email before it is sent.
		 *
		 * @param array{to:string,subject:string,message:string,headers:string[]} $email     The email.
		 * @param License                                                         $license   The license.
		 * @param int                                                             $days_left Whole days left.
		 */
		$filtered = apply_filters( 'wplicense_it_reminder_email', $email, $license, $days_left );

		return array_merge( $email, (array) $filtered );
	}

	/**
	 * Where the customer renews: the address set in the settings, else the My Account licenses page of the store (for a license
	 * with an account), else the home page.
	 *
	 * @param License $license The license.
	 */
	public function renew_url( License $license ): string {
		$url = ReminderSettings::custom_url();

		if ( '' === $url && null !== $license->user_id && function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = (string) wc_get_account_endpoint_url( AccountPage::ENDPOINT );
		}

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		/**
		 * Filters the address a reminder email sends the customer to.
		 *
		 * @param string  $url     The address.
		 * @param License $license The license.
		 */
		return (string) apply_filters( 'wplicense_it_reminder_renew_url', $url, $license );
	}
}
