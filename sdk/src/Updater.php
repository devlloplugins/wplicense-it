<?php
/**
 * WordPress update integration.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

use stdClass;
use WP_Error;

/**
 * Makes WordPress offer updates for a licensed plugin or theme, in the normal Updates screen.
 *
 * The update data carries a marker instead of a download link. Download links from the server
 * expire after 15 minutes but WordPress keeps update data for hours, so the real link is fetched
 * at the moment the customer clicks Update (upgrader_pre_download).
 */
final class Updater {

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Update lookups.
	 *
	 * @var UpdateService
	 */
	private $updates;

	/**
	 * License manager.
	 *
	 * @var LicenseManager
	 */
	private $manager;

	/**
	 * The admin-ajax action that shows a theme's update details.
	 */
	public const DETAILS_ACTION = 'wplicense_it_theme_details';

	/**
	 * Constructor.
	 *
	 * @param Config         $config  Configuration.
	 * @param UpdateService  $updates Update lookups.
	 * @param LicenseManager $manager License manager.
	 */
	public function __construct( Config $config, UpdateService $updates, LicenseManager $manager ) {
		$this->config  = $config;
		$this->updates = $updates;
		$this->manager = $manager;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		if ( 'plugin' === $this->config->type ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'add_plugin_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
			add_action( 'after_plugin_row_' . $this->config->basename, array( $this, 'license_row' ), 10, 2 );
		} else {
			add_filter( 'pre_set_site_transient_update_themes', array( $this, 'add_theme_update' ) );
			add_action( 'wp_ajax_' . self::DETAILS_ACTION, array( $this, 'theme_details' ) );
		}

		add_filter( 'upgrader_pre_download', array( $this, 'download_package' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
	}

	/**
	 * Whether the administrator pressed "Check again" on the Updates screen. Without this, a release published within the last
	 * hours would not show up there, because the last answer from the license server is remembered.
	 */
	public static function is_forced_check(): bool {
		return isset( $_GET['force-check'] ) && function_exists( 'current_user_can' ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only skips a cache; WordPress itself reads this flag the same way.
	}

	/**
	 * Adds this plugin's update to WordPress's update data.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function add_plugin_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->updates->latest( $this->manager->state(), time(), self::is_forced_check() );

		if ( null !== $info && $info->is_newer_than( $this->config->version ) ) {
			$transient->response[ $this->config->basename ] = self::plugin_entry( $this->config, $info );
			unset( $transient->no_update[ $this->config->basename ] );
		} elseif ( isset( $transient->response[ $this->config->basename ] ) && $this->config->package_marker() === $transient->response[ $this->config->basename ]->package ) {
			unset( $transient->response[ $this->config->basename ] ); // An old entry that no longer applies.
		}

		return $transient;
	}

	/**
	 * Adds this theme's update to WordPress's update data.
	 *
	 * @param mixed $transient The update_themes transient.
	 * @return mixed
	 */
	public function add_theme_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->updates->latest( $this->manager->state(), time(), self::is_forced_check() );

		if ( null !== $info && $info->is_newer_than( $this->config->version ) ) {
			$transient->response[ $this->config->slug ] = self::theme_entry( $this->config, $info );
		}

		return $transient;
	}

	/**
	 * The entry WordPress shows on the Plugins and Updates screens.
	 *
	 * @param Config     $config Configuration.
	 * @param UpdateInfo $info   Latest version.
	 */
	public static function plugin_entry( Config $config, UpdateInfo $info ): stdClass {
		$entry              = new stdClass();
		$entry->id          = $config->server . '/' . $config->slug;
		$entry->slug        = $config->slug;
		$entry->plugin      = $config->basename;
		$entry->new_version = $info->version;
		$entry->url         = $config->server;
		$entry->package     = $config->package_marker();
		$entry->tested      = self::tested( $info->tested );
		$entry->requires    = $info->requires;
		$entry->icons       = array();
		$entry->banners     = array_filter(
			array(
				'low'  => $info->banner_low,
				'high' => $info->banner_high,
			)
		);

		return $entry;
	}

	/**
	 * The entry WordPress shows for a theme.
	 *
	 * @param Config     $config Configuration.
	 * @param UpdateInfo $info   Latest version.
	 * @return array<string, string>
	 */
	public static function theme_entry( Config $config, UpdateInfo $info ): array {
		return array(
			'theme'        => $config->slug,
			'new_version'  => $info->version,
			'url'          => self::details_url( $config ),
			'package'      => $config->package_marker(),
			'requires'     => $info->requires,
			'requires_php' => '',
		);
	}

	/**
	 * Where WordPress's "View version x details" link for a theme goes: a page on the customer's own site, filled from
	 * the update information the SDK already has (WordPress opens it in a popup frame). The seller's home page, used
	 * before, often refuses to be shown in a frame and the popup stayed empty.
	 *
	 * @param Config $config Configuration.
	 */
	public static function details_url( Config $config ): string {
		return add_query_arg(
			array(
				'action' => self::DETAILS_ACTION,
				'theme'  => $config->slug,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Shows the details page of this theme's update (see details_url()), to people who may see theme updates.
	 */
	public function theme_details(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shows information only, like WordPress's own plugin details.
		$theme = isset( $_GET['theme'] ) && is_string( $_GET['theme'] ) ? sanitize_text_field( wp_unslash( $_GET['theme'] ) ) : '';

		if ( $theme !== $this->config->slug ) {
			return; // Another theme's, answered by its own copy of the SDK.
		}

		if ( ! current_user_can( 'update_themes' ) && ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html( 'Sorry, you are not allowed to see this.' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::details_page( $this->config, $this->updates->latest( $this->manager->state(), time() ), str_replace( '_', '-', get_user_locale() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while it is built.
		exit;
	}

	/**
	 * The details page: name, version, requirements, what is new and the description, as WordPress shows them for a
	 * plugin. Everything from the server is escaped.
	 *
	 * @param Config          $config Configuration.
	 * @param UpdateInfo|null $info   Latest version, or null when there is none to show.
	 * @param string          $lang   Language of the page.
	 */
	public static function details_page( Config $config, ?UpdateInfo $info, string $lang ): string {
		$name = null !== $info && '' !== $info->name ? $info->name : $config->name;
		$body = '';

		if ( null === $info ) {
			$body = '<h1>' . esc_html( $name ) . '</h1><p>No update details are available. The license may not be active on this site: check it on the theme\'s license page.</p>';
		} else {
			$banner = '' !== $info->banner_high ? $info->banner_high : $info->banner_low;
			if ( '' !== $banner ) {
				$body .= '<img class="banner" src="' . esc_url( $banner ) . '" alt="">';
			}
			$body .= '<h1>' . esc_html( $name ) . '</h1><ul class="meta">';
			$body .= '<li><strong>Version:</strong> ' . esc_html( $info->version ) . '</li>';
			if ( '' !== $info->requires ) {
				$body .= '<li><strong>Requires WordPress:</strong> ' . esc_html( $info->requires ) . '</li>';
			}
			if ( '' !== $info->tested ) {
				$body .= '<li><strong>Tested up to:</strong> ' . esc_html( $info->tested ) . '</li>';
			}
			$body .= '</ul>';
			if ( '' !== trim( $info->changelog ) ) {
				$body .= '<h2>What is new in ' . esc_html( $info->version ) . '</h2>' . wpautop( esc_html( $info->changelog ) );
			}
			if ( '' !== trim( $info->description ) ) {
				$body .= '<h2>Description</h2>' . wpautop( esc_html( $info->description ) );
			}
		}

		return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $name . ( null !== $info ? ' ' . $info->version : '' ) ) . '</title>'
			. '<style>body{margin:0;padding:0 24px 24px;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#1d2327;background:#fff}'
			. '.banner{display:block;width:calc(100% + 48px);max-height:250px;object-fit:cover;margin:0 -24px}h1{font-size:23px;font-weight:400;margin:24px 0 8px}h2{font-size:16px;margin:24px 0 8px}'
			. '.meta{list-style:none;margin:0;padding:0;color:#50575e}.meta li{display:inline-block;margin-right:24px}</style></head><body>'
			. $body . '</body></html>';
	}

	/**
	 * Fills the "View details" popup.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugin_information, query_plugins, ...
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || $args->slug !== $this->config->slug ) {
			return $result;
		}

		$info = $this->updates->latest( $this->manager->state(), time() );

		if ( null === $info ) {
			return $result;
		}

		$details           = new stdClass();
		$details->name     = '' !== $info->name ? $info->name : $this->config->name;
		$details->slug     = $this->config->slug;
		$details->version  = $info->version;
		$details->requires = $info->requires;
		$details->tested   = self::tested( $info->tested );
		$details->homepage = $this->config->server;
		$details->sections = array( 'description' => wpautop( esc_html( $info->description ) ) );
		if ( '' !== trim( $info->changelog ) ) {
			$details->sections['changelog'] = wpautop( esc_html( $info->changelog ) );
		}
		$details->banners       = array_filter(
			array(
				'low'  => $info->banner_low,
				'high' => $info->banner_high,
			)
		);
		$details->download_link = $this->config->package_marker();

		return $details;
	}

	/**
	 * The "tested up to" version as WordPress should compare it.
	 *
	 * Sellers write "Tested up to: 6.7" and mean every 6.7.x, as on WordPress.org. WordPress compares the text
	 * exactly, so a site on 6.7.1 would be warned that the plugin "has not been tested" with its version. When the
	 * tested version is a bare major.minor and the site runs that series, the site's own version is used.
	 *
	 * @param string $tested Version from the license server.
	 */
	public static function tested( string $tested ): string {
		global $wp_version;

		if ( 1 === preg_match( '/^(\d+)\.(\d+)$/', $tested, $tested_parts ) && is_string( $wp_version ) && 1 === preg_match( '/^(\d+)\.(\d+)(?:\.|$|-)/', $wp_version, $site_parts ) && $tested_parts[1] === $site_parts[1] && $tested_parts[2] === $site_parts[2] ) {
			return $wp_version;
		}

		return $tested;
	}

	/**
	 * Replaces the marker with the real download, fetched now.
	 *
	 * @param mixed  $reply    Result so far (false to let WordPress download normally).
	 * @param string $package  Package URL from the update data.
	 * @param mixed  $upgrader The upgrader.
	 * @param mixed  $extra    Extra data about the update.
	 * @return mixed A file path, a WP_Error, or the unchanged $reply for other packages.
	 */
	public function download_package( $reply, $package, $upgrader = null, $extra = array() ) {
		if ( $package !== $this->config->package_marker() ) {
			return $reply;
		}

		$url = $this->updates->download_url( $this->manager->state() );

		if ( $url instanceof ApiResult ) {
			return new WP_Error( 'wplit_client_download', $url->message );
		}

		return download_url( $url );
	}

	/**
	 * Makes sure the unpacked folder has the name WordPress expects, so the update replaces the
	 * installed copy instead of creating a second one next to it.
	 *
	 * @param string $source        Unpacked folder (with trailing slash).
	 * @param string $remote_source Temporary folder.
	 * @param mixed  $upgrader      The upgrader.
	 * @param mixed  $extra         Extra data about the update.
	 * @return mixed
	 */
	public function fix_folder_name( $source, $remote_source = '', $upgrader = null, $extra = array() ) {
		$is_ours = is_array( $extra ) && (
			( isset( $extra['plugin'] ) && $extra['plugin'] === $this->config->basename )
			|| ( isset( $extra['theme'] ) && $extra['theme'] === $this->config->slug )
		);

		if ( ! $is_ours || ! is_string( $source ) ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . $this->config->folder() . '/';

		if ( trailingslashit( $source ) === $wanted || ! is_dir( $source ) ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return $wanted;
		}

		return new WP_Error( 'wplit_client_folder', 'Could not rename the update folder to ' . $this->config->folder() . '.' );
	}

	/**
	 * A line under the plugin on the Plugins screen when updates are not available because the license is not active.
	 *
	 * @param string $file Plugin file.
	 * @param array  $data Plugin data.
	 */
	public function license_row( $file, $data ): void {
		if ( $this->manager->is_active() ) {
			return;
		}

		global $wp_list_table;

		$columns = $wp_list_table ? $wp_list_table->get_column_count() : 3;

		echo '<tr class="plugin-update-tr active"><td colspan="' . esc_attr( (string) $columns ) . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>';
		echo esc_html( sprintf( 'Enter your %s license key to receive updates.', $this->config->name ) );

		if ( '' !== $this->config->menu_parent ) {
			echo ' <a href="' . esc_url( LicensePage::url( $this->config ) ) . '">' . esc_html( 'Manage license' ) . '</a>';
		}

		echo '</p></div></td></tr>';
	}
}
