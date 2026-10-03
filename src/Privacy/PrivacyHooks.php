<?php
/**
 * WordPress privacy tools.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Privacy;

/**
 * Plugs WPLicense It into the "Export Personal Data" and "Erase Personal Data" tools (Tools menu), and
 * suggests text for the site's privacy policy.
 */
final class PrivacyHooks {

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['wplicense-it'] = array(
			'exporter_friendly_name' => __( 'WPLicense It licenses and orders', 'wplicense-it' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['wplicense-it'] = array(
			'eraser_friendly_name' => __( 'WPLicense It licenses and orders', 'wplicense-it' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page number, from 1.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		return $this->service()->export( (string) $email, $this->user_id( (string) $email ), (int) $page );
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page number, from 1 (unused: the first page is always the next one to erase).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		return $this->service()->erase( (string) $email, $this->user_id( (string) $email ) );
	}

	/**
	 * Suggested privacy policy text for the site owner.
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$years = (int) get_option( 'wplit_event_retention_years', 2 );

		$text  = '<h3>' . esc_html__( 'Software licenses', 'wplicense-it' ) . '</h3>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text. Review it and adapt it to how you use WPLicense It.', 'wplicense-it' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you buy or are given a software license, we store the email address and account the license was issued to, the license key, the product, the dates and the sites the license is activated on. For orders we keep the order number, the total and the date. We use this to provide the license, to let you download updates, to support you and for our accounting.', 'wplicense-it' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When a plugin or theme you installed checks for updates or activates its license, it sends the license key, the product, your site address and the installed version to our server. Nothing else about your site is sent. If our server is asked about a license that does not exist, your IP address is counted for a few minutes to stop key guessing, and is not stored in readable form.', 'wplicense-it' ) . '</p>';
		$text .= '<p>' . esc_html(
			0 === $years
				? __( 'The history of each license (activations, renewals and changes) is kept for as long as the license exists.', 'wplicense-it' )
				/* translators: %d: number of years. */
				: sprintf( _n( 'The history of each license (activations, renewals and changes) is deleted after %d year.', 'The history of each license (activations, renewals and changes) is deleted after %d years.', $years, 'wplicense-it' ), $years )
		) . '</p>';
		$text .= '<p>' . esc_html__( 'On request we remove your name, email address and account from the licenses and orders. The license key, product, dates, activated site addresses and order totals are kept because they are needed to support the license and for accounting.', 'wplicense-it' ) . '</p>';

		wp_add_privacy_policy_content( 'WPLicense It', wp_kses_post( $text ) );
	}

	/**
	 * The service, built on the live database.
	 */
	private function service(): PrivacyService {
		global $wpdb;

		return new PrivacyService( new WpdbPrivacyRepository( $wpdb ) );
	}

	/**
	 * The WordPress user with this email address, if there is one.
	 *
	 * @param string $email Email address.
	 */
	private function user_id( string $email ): ?int {
		$user = get_user_by( 'email', $email );

		return $user ? (int) $user->ID : null;
	}
}
