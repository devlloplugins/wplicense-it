<?php
/**
 * License settings page.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * A small admin page where the site owner enters, activates and deactivates the license key.
 * Plugins that prefer their own settings screen can call Client::render_license_form() instead.
 */
final class LicensePage {

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * License manager.
	 *
	 * @var LicenseManager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param Config         $config  Configuration.
	 * @param LicenseManager $manager License manager.
	 */
	public function __construct( Config $config, LicenseManager $manager ) {
		$this->config  = $config;
		$this->manager = $manager;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . $this->action( 'do' ), array( $this, 'handle' ) );

		if ( '' !== $this->config->menu_parent ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
		}
	}

	/**
	 * The URL of the license page.
	 *
	 * @param Config $config Configuration.
	 */
	public static function url( Config $config ): string {
		// A submenu of a core screen (options-general.php, tools.php, ...) lives at that file; under a custom menu it is admin.php.
		$file = '.php' === substr( $config->menu_parent, -4 ) && false === strpos( $config->menu_parent, '?' ) ? $config->menu_parent : 'admin.php';

		return add_query_arg( 'page', $config->slug . '-license', admin_url( $file ) );
	}

	/**
	 * Adds the page to the admin menu.
	 */
	public function add_page(): void {
		add_submenu_page( $this->config->menu_parent, $this->config->menu_title, $this->config->menu_title, 'manage_options', $this->config->slug . '-license', array( $this, 'render_page' ) );
	}

	/**
	 * Renders the page.
	 */
	public function render_page(): void {
		echo '<div class="wrap"><h1>' . esc_html( $this->config->menu_title ) . '</h1>';
		$this->render_form();
		echo '</div>';
	}

	/**
	 * Renders the license form and status. Safe to call from any settings screen.
	 */
	public function render_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_notice();

		$state  = $this->manager->state();
		$active = $this->manager->is_active();

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html( 'Status' ) . '</th><td>' . esc_html( $this->status_text( $state, $active ) ) . '</td></tr>';

		if ( '' !== $state->key ) {
			echo '<tr><th scope="row">' . esc_html( 'License key' ) . '</th><td><code>' . esc_html( self::mask( $state->key ) ) . '</code></td></tr>';
			echo '<tr><th scope="row">' . esc_html( 'Expires' ) . '</th><td>' . esc_html( null === $state->expires_at ? 'Never' : wp_date( get_option( 'date_format' ), $state->expires_at ) ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html( 'Sites' ) . '</th><td>' . esc_html( $state->used . ' of ' . ( 0 === $state->limit ? 'unlimited' : $state->limit ) . ' in use' ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( '' === $state->key || ! $active ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $this->action( 'do' ) ) . '"><input type="hidden" name="do" value="activate">';
			wp_nonce_field( $this->action( 'activate' ) );
			echo '<p><label for="wplit-client-key"><strong>' . esc_html( 'License key' ) . '</strong></label><br>';
			echo '<input type="text" id="wplit-client-key" name="license_key" class="regular-text" autocomplete="off" spellcheck="false" maxlength="64"> ';
			echo '<button type="submit" class="button button-primary">' . esc_html( 'Activate' ) . '</button></p></form>';
		}

		if ( '' !== $state->key ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display: inline-block; margin-right: 8px;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $this->action( 'do' ) ) . '"><input type="hidden" name="do" value="refresh">';
			wp_nonce_field( $this->action( 'refresh' ) );
			echo '<button type="submit" class="button">' . esc_html( 'Check now' ) . '</button></form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display: inline-block;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $this->action( 'do' ) ) . '"><input type="hidden" name="do" value="deactivate">';
			wp_nonce_field( $this->action( 'deactivate' ) );
			echo '<button type="submit" class="button">' . esc_html( 'Deactivate on this site' ) . '</button></form>';
		}
	}

	/**
	 * Handles the Activate, Check now and Deactivate buttons.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'You are not allowed to manage this license.' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below.
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( ! in_array( $do, array( 'activate', 'refresh', 'deactivate' ), true ) ) {
			wp_die( esc_html( 'Unknown action.' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( $this->action( $do ) );

		if ( 'activate' === $do ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			$result = $this->manager->activate( $key );
			$text   = $result->ok ? 'License activated. Thank you!' : $result->message;
		} elseif ( 'refresh' === $do ) {
			$result = $this->manager->refresh();
			$text   = null === $result ? 'There is no license to check.' : ( $result->ok ? 'License checked: all good.' : $this->state_message( $result ) );
			$result = $result ?? new ApiResult( false, 'none', $text );
		} else {
			$result = $this->manager->deactivate();

			// If the server cannot be reached, offer to forget the license locally on the next try.
			if ( ! $result->ok && $result->is_temporary() ) {
				$result = $this->manager->deactivate( true );
				$text   = 'The license server could not be reached, so the license was only removed from this site. Deactivate it from your account to free the slot.';
				$result = new ApiResult( true, 'deactivated', $text );
			} else {
				$text = $result->ok ? 'License deactivated on this site.' : $result->message;
			}
		}

		set_transient( $this->notice_key(), array( 'ok' => $result->ok, 'text' => $text ), 60 );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url( $this->config ) );
		exit;
	}

	/**
	 * The message to show after "Check now" found a problem.
	 *
	 * @param ApiResult $result Result.
	 */
	private function state_message( ApiResult $result ): string {
		return '' !== $result->message ? $result->message : 'The license could not be checked right now. Nothing was changed.';
	}

	/**
	 * Shows the result of the last action, once.
	 */
	private function render_notice(): void {
		$notice = get_transient( $this->notice_key() );

		if ( ! is_array( $notice ) || ! isset( $notice['text'] ) ) {
			return;
		}

		delete_transient( $this->notice_key() );

		printf(
			'<div class="notice %s"><p>%s</p></div>',
			! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error',
			esc_html( (string) $notice['text'] )
		);
	}

	/**
	 * Status in words.
	 *
	 * @param LicenseState $state  State.
	 * @param bool         $active Whether the license is active now.
	 */
	private function status_text( LicenseState $state, bool $active ): string {
		if ( $active ) {
			return 'Active. You will receive updates.';
		}

		if ( '' === $state->key ) {
			return 'Not activated. Enter your license key to receive updates.';
		}

		if ( LicenseState::ACTIVE === $state->status ) {
			return 'Expired. Renew your license to keep receiving updates.';
		}

		return '' !== $state->message ? ucfirst( $state->status ) . '. ' . $state->message : ucfirst( $state->status ) . '.';
	}

	/**
	 * Shows only the end of the key.
	 *
	 * @param string $key License key.
	 */
	public static function mask( string $key ): string {
		$length = strlen( $key );

		return $length <= 6 ? str_repeat( '•', $length ) : str_repeat( '•', $length - 5 ) . substr( $key, -5 );
	}

	/**
	 * A name for this product's nonces, hooks and actions.
	 *
	 * @param string $what The action.
	 */
	private function action( string $what ): string {
		return 'wplit_client_' . $this->config->slug . '_' . $what;
	}

	/**
	 * The transient that carries a message across the redirect, one per user.
	 */
	private function notice_key(): string {
		return substr( 'wplit_cn_' . $this->config->slug . '_' . get_current_user_id(), 0, 160 );
	}
}
