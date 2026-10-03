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
		}

		add_filter( 'upgrader_pre_download', array( $this, 'download_package' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
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

		$info = $this->updates->latest( $this->manager->state(), time() );

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

		$info = $this->updates->latest( $this->manager->state(), time() );

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
		$entry->tested      = $info->tested;
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
			'url'          => $config->server,
			'package'      => $config->package_marker(),
			'requires'     => $info->requires,
			'requires_php' => '',
		);
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

		$details                = new stdClass();
		$details->name          = '' !== $info->name ? $info->name : $this->config->name;
		$details->slug          = $this->config->slug;
		$details->version       = $info->version;
		$details->requires      = $info->requires;
		$details->tested        = $info->tested;
		$details->homepage      = $this->config->server;
		$details->sections      = array( 'description' => wpautop( esc_html( $info->description ) ) );
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
