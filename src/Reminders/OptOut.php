<?php
/**
 * Customers who stopped reminder emails.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

/**
 * The email addresses that asked for no more reminder emails (before or after expiry), with when and how. Kept per address,
 * so every license with that address is covered, now and later. The list is one option, not loaded on every page, and the
 * owner sees it in Licensing settings. The WordPress privacy tools export and erase an address's entry.
 *
 * Links in the emails carry the address and a code made from it with the site's secret keys, so nobody can stop or restart
 * someone else's emails by guessing.
 */
final class OptOut {

	public const OPTION = 'wplit_reminder_optouts';
	public const ACTION = 'wplit_reminders';

	public const VIA_EMAIL   = 'email';
	public const VIA_ACCOUNT = 'account';

	/**
	 * Every address that stopped the emails, newest first.
	 *
	 * @return array<string, array{at:string,via:string}>
	 */
	public static function all(): array {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();

		uasort( $list, static fn( $a, $b ): int => strcmp( (string) ( $b['at'] ?? '' ), (string) ( $a['at'] ?? '' ) ) );

		return $list;
	}

	/**
	 * Whether an address stopped the emails.
	 *
	 * @param string $email Address.
	 */
	public static function is_out( string $email ): bool {
		$list = get_option( self::OPTION, array() );

		return is_array( $list ) && isset( $list[ self::key( $email ) ] );
	}

	/**
	 * When and how an address stopped the emails, or null.
	 *
	 * @param string $email Address.
	 * @return array{at:string,via:string}|null
	 */
	public static function entry( string $email ): ?array {
		$list = get_option( self::OPTION, array() );

		return is_array( $list ) && isset( $list[ self::key( $email ) ] ) ? $list[ self::key( $email ) ] : null;
	}

	/**
	 * Stops the emails to an address.
	 *
	 * @param string $email Address.
	 * @param string $via   VIA_EMAIL or VIA_ACCOUNT.
	 */
	public static function stop( string $email, string $via ): void {
		$key = self::key( $email );
		if ( '' === $key || self::is_out( $key ) ) {
			return;
		}

		$list         = get_option( self::OPTION, array() );
		$list         = is_array( $list ) ? $list : array();
		$list[ $key ] = array(
			'at'  => gmdate( 'Y-m-d H:i:s' ),
			'via' => self::VIA_ACCOUNT === $via ? self::VIA_ACCOUNT : self::VIA_EMAIL,
		);

		update_option( self::OPTION, $list, false );
	}

	/**
	 * Lets the emails reach an address again.
	 *
	 * @param string $email Address.
	 * @return bool Whether it was on the list.
	 */
	public static function resume( string $email ): bool {
		$list = get_option( self::OPTION, array() );
		$key  = self::key( $email );

		if ( ! is_array( $list ) || ! isset( $list[ $key ] ) ) {
			return false;
		}

		unset( $list[ $key ] );
		update_option( self::OPTION, $list, false );

		return true;
	}

	/**
	 * The code that proves a link was made by this site for this address.
	 *
	 * @param string $email Address.
	 */
	public static function code( string $email ): string {
		return substr( hash_hmac( 'sha256', 'wplit-reminders|' . self::key( $email ), wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether a code is the right one for an address.
	 *
	 * @param string $email Address.
	 * @param string $code  Code from the link.
	 */
	public static function verify( string $email, string $code ): bool {
		return '' !== self::key( $email ) && hash_equals( self::code( $email ), $code );
	}

	/**
	 * The page where an address stops (or restarts) the emails. No login needed.
	 *
	 * @param string $email Address.
	 */
	public static function url( string $email ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'e'      => rawurlencode( self::key( $email ) ),
				'c'      => self::code( $email ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The address as it is kept: trimmed and lower case.
	 *
	 * @param string $email Address.
	 */
	public static function key( string $email ): string {
		return strtolower( trim( $email ) );
	}
}
