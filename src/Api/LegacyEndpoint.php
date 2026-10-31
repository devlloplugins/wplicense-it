<?php
/**
 * 1.x endpoint wiring.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Serves the deprecated /api/wplicense-it-api/v1/{info|get|status} URLs.
 *
 * Only registered once the 1.x data has been migrated. Until then the 1.x code
 * in includes/wplicense-it-api.php answers these URLs from the 1.x tables.
 */
final class LegacyEndpoint {

	private const QUERY_VAR      = '__wp_license_api';
	private const REWRITE_OPTION = 'wplit_legacy_rewrite_version';
	private const REWRITE_SET    = '2.0';

	/**
	 * Constructor.
	 *
	 * @param LegacyApi $api 1.x API logic.
	 */
	public function __construct( private LegacyApi $api ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_action( 'parse_request', array( $this, 'maybe_handle' ) );
	}

	/**
	 * Adds the query variables 1.x clients use.
	 *
	 * @param string[] $vars Existing query variables.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		return array_merge( $vars, array( self::QUERY_VAR, 'p', 'e', 'l', 'k' ) );
	}

	/**
	 * Adds the rewrite rule, and flushes the rules once.
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( 'api/wplicense-it-api/v1/(info|get|status)/?', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );

		if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_SET ) {
			flush_rewrite_rules();
			update_option( self::REWRITE_OPTION, self::REWRITE_SET );
		}
	}

	/**
	 * Answers the request if it is a 1.x API call.
	 *
	 * @param \WP $wp WordPress environment.
	 */
	public function maybe_handle( \WP $wp ): void {
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$result = $this->api->handle( (string) $wp->query_vars[ self::QUERY_VAR ], $wp->query_vars, RestController::client_id() );

		if ( null !== $result->file ) {
			FileStreamer::send( $result->file );
		}

		status_header( $result->status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		if ( null !== $result->retry_after ) {
			header( 'Retry-After: ' . $result->retry_after );
		}

		echo wp_json_encode( $result->body );
		exit;
	}
}
