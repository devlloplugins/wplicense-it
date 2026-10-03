<?php
/**
 * Admin menu.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Licenses\LicenseService;

/**
 * Adds Licenses, Add license and Licensing settings under the License Products menu.
 */
final class AdminMenu {

	/**
	 * Screens the shared styles are loaded on.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Registers everything.
	 */
	public function register(): void {
		Capabilities::register();
		( new LicenseActions( $this->licenses ) )->register();
		( new SettingsScreen() )->register();

		add_action( 'admin_menu', array( $this, 'add_pages' ), 20 );
		add_filter( 'set-screen-option', array( LicensesScreen::class, 'save_screen_option' ), 10, 3 );
		add_action( 'admin_head', array( $this, 'print_styles' ) );
	}

	/**
	 * Adds the pages.
	 */
	public function add_pages(): void {
		$licenses = new LicensesScreen( $this->licenses );

		$list = add_submenu_page( Urls::PARENT, __( 'Licenses', 'wplicense-it' ), __( 'Licenses', 'wplicense-it' ), Capabilities::MANAGE, Urls::PAGE_LICENSES, array( $licenses, 'render' ) );
		add_action( 'load-' . $list, array( $licenses, 'load' ) );

		$add = add_submenu_page( Urls::PARENT, __( 'Add license', 'wplicense-it' ), __( 'Add license', 'wplicense-it' ), Capabilities::MANAGE, Urls::PAGE_ADD, array( new AddLicenseScreen(), 'render' ) );

		$settings = add_submenu_page( Urls::PARENT, __( 'Licensing settings', 'wplicense-it' ), __( 'Licensing settings', 'wplicense-it' ), Capabilities::MANAGE, Urls::PAGE_SETTINGS, array( new SettingsScreen(), 'render' ) );

		$this->hooks = array_filter( array( $list, $add, $settings ) );
	}

	/**
	 * Small styles for the status labels, only on our screens.
	 */
	public function print_styles(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, $this->hooks, true ) ) {
			return;
		}

		echo '<style>'
			. '.wplit-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;line-height:1.6;background:#dcdcde;color:#1d2327}'
			. '.wplit-badge-active{background:#d1e7dd;color:#0a3622}'
			. '.wplit-badge-expired{background:#fff3cd;color:#664d03}'
			. '.wplit-badge-revoked,.wplit-badge-refunded{background:#f8d7da;color:#58151c}'
			. '.column-sites{width:80px}'
			. '</style>';
	}
}
