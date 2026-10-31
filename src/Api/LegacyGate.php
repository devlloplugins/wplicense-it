<?php
/**
 * What happens before a call to an old 1.x API address is answered.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Runs before both answers to the 1.x addresses (the 1.x code before the migration, LegacyEndpoint after it): counts the
 * call, answers 410 Gone when the site owner switched the addresses off, and, before the migration, stops a client that
 * sent too many wrong keys. After the migration LegacyApi does that itself.
 *
 * Old plugins and themes in the field can only call these addresses with the license key, email and product key in the
 * query string, so they stay on until the owner sees no more calls and switches them off (Licensing settings).
 */
final class LegacyGate {

	public const OPTION_ENABLED   = 'wplit_legacy_api_enabled';
	public const WRONG_KEY_ACTION = 'wplicense_it_legacy_wrong_key';
	public const QUERY_VAR        = '__wp_license_api';

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $limiter  Counts wrong keys per client.
	 * @param bool        $migrated Whether the 1.x data was migrated (then LegacyApi limits wrong keys).
	 */
	public function __construct( private RateLimiter $limiter, private bool $migrated ) {
	}

	/**
	 * Registers the hooks. Runs before the answers, which are hooked at the default priority.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'before' ), 1 );

		if ( ! $this->migrated ) {
			add_action( self::WRONG_KEY_ACTION, array( $this, 'wrong_key' ) );
		}
	}

	/**
	 * Whether the 1.x addresses are answered. On unless the site owner switched them off.
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * The answer that stops the call, or null to let it through.
	 *
	 * @param bool        $enabled  Whether the addresses are on.
	 * @param bool        $migrated Whether the 1.x data was migrated.
	 * @param RateLimiter $limiter  Counts wrong keys per client.
	 * @param string      $client   Client identifier (IP address).
	 */
	public static function decide( bool $enabled, bool $migrated, RateLimiter $limiter, string $client ): ?ApiResponse {
		if ( ! $enabled ) {
			return new ApiResponse( 410, array( 'error' => 'This address is no longer available. Update the plugin or theme to keep receiving updates.' ) );
		}

		if ( ! $migrated && $limiter->blocked_for( $client ) > 0 ) {
			$response              = new ApiResponse( 429, array( 'error' => 'Too many invalid requests. Try again later.' ) );
			$response->retry_after = $limiter->blocked_for( $client );

			return $response;
		}

		return null;
	}

	/**
	 * Counts a 1.x call and stops it if it must not be answered.
	 *
	 * @param \WP $wp WordPress environment.
	 */
	public function before( \WP $wp ): void {
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$state = get_option( LegacyUsage::OPTION, array() );
		update_option( LegacyUsage::OPTION, LegacyUsage::record( is_array( $state ) ? $state : array(), absint( $wp->query_vars['p'] ?? 0 ), time() ), false );

		$stop = self::decide( self::enabled(), $this->migrated, $this->limiter, RestController::client_id() );

		if ( null === $stop ) {
			return;
		}

		nocache_headers();

		if ( null !== $stop->retry_after ) {
			header( 'Retry-After: ' . $stop->retry_after );
		}

		wp_send_json( $stop->body, $stop->status );
	}

	/**
	 * The 1.x code found a wrong key or an unknown product: count it against the client.
	 */
	public function wrong_key(): void {
		$this->limiter->record_failure( RestController::client_id() );
	}
}
