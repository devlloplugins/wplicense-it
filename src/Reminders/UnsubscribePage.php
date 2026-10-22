<?php
/**
 * The page a reminder email links to, to stop the emails.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

/**
 * Opening the link only asks (mail security scanners open every link in an email); the Stop button does it. Mail
 * programs that offer their own unsubscribe button send a POST with "List-Unsubscribe=One-Click" (RFC 8058), which
 * stops the emails at once. After stopping, the page offers to turn them back on.
 */
final class UnsubscribePage {

	/**
	 * Registers the page, for visitors who are logged in and those who are not.
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . OptOut::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . OptOut::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Answers the link, the buttons and the mail programs' one-click request.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The code in the link, made from the address with the site's keys, stands in for a nonce: the visitor is usually not logged in.
		$email = isset( $_REQUEST['e'] ) ? sanitize_email( wp_unslash( $_REQUEST['e'] ) ) : '';
		$code  = isset( $_REQUEST['c'] ) ? sanitize_key( wp_unslash( $_REQUEST['c'] ) ) : '';
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$click = isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) );
		$post  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable WordPress.Security.NonceVerification

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );

		if ( ! OptOut::verify( $email, $code ) ) {
			wp_die( esc_html__( 'This link is not valid. Use the link from the most recent email, or contact us.', 'wplicense-it' ), esc_html__( 'Reminder emails', 'wplicense-it' ), array( 'response' => 403 ) );
		}

		if ( $post && ( $click || 'stop' === $do ) ) {
			OptOut::stop( $email, OptOut::VIA_EMAIL );

			if ( $click ) {
				status_header( 200 );
				exit;
			}
		} elseif ( $post && 'resume' === $do ) {
			OptOut::resume( $email );
		}

		$this->page( $email, $code, $post ? ( 'resume' === $do ? 'resumed' : 'stopped' ) : '' );
	}

	/**
	 * The page: the question, or what was done and the way back.
	 *
	 * @param string $email Address.
	 * @param string $code  Code from the link.
	 * @param string $done  '' (asking), 'stopped' or 'resumed'.
	 */
	private function page( string $email, string $code, string $done ): void {
		$out  = OptOut::is_out( $email );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$html = '';

		if ( 'stopped' === $done ) {
			/* translators: 1: email address, 2: site name. */
			$html .= '<p id="wplit-reminders-state">' . esc_html( sprintf( __( 'Done. %1$s will get no more reminder emails about licenses from %2$s.', 'wplicense-it' ), $email, $site ) ) . '</p>';
		} elseif ( 'resumed' === $done ) {
			/* translators: %s: email address. */
			$html .= '<p id="wplit-reminders-state">' . esc_html( sprintf( __( 'Done. %s will get reminder emails again before a license expires.', 'wplicense-it' ), $email ) ) . '</p>';
		} elseif ( $out ) {
			/* translators: %s: email address. */
			$html .= '<p id="wplit-reminders-state">' . esc_html( sprintf( __( 'Reminder emails to %s are stopped.', 'wplicense-it' ), $email ) ) . '</p>';
		} else {
			/* translators: 1: email address, 2: site name. */
			$html .= '<p id="wplit-reminders-state">' . esc_html( sprintf( __( 'Stop the emails that remind %1$s before a license from %2$s expires (and after it expired)? Your licenses keep working, and orders and invoices are still emailed.', 'wplicense-it' ), $email, $site ) ) . '</p>';
		}

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( OptOut::ACTION ) . '">';
		$html .= '<input type="hidden" name="e" value="' . esc_attr( $email ) . '">';
		$html .= '<input type="hidden" name="c" value="' . esc_attr( $code ) . '">';
		$html .= $out
			? '<input type="hidden" name="do" value="resume"><button type="submit" class="button" id="wplit-reminders-resume">' . esc_html__( 'Turn reminder emails back on', 'wplicense-it' ) . '</button>'
			: '<input type="hidden" name="do" value="stop"><button type="submit" class="button button-primary" id="wplit-reminders-stop">' . esc_html__( 'Stop reminder emails', 'wplicense-it' ) . '</button>';
		$html .= '</form>';

		wp_die( $html, esc_html__( 'Reminder emails', 'wplicense-it' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while it was built.
	}
}
