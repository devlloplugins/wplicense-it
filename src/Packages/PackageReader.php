<?php
/**
 * Reads the details of a plugin or theme from its zip.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Packages;

/**
 * Finds the plugin or theme header inside a zip and returns what it says: name, version, requirements.
 *
 * Nothing is unpacked. Only the first few kilobytes of the header files are read, so a hostile or huge zip
 * costs very little. It works without WordPress, so it can be tested on its own.
 */
final class PackageReader {

	private const MAX_ENTRIES     = 20000;
	private const MAX_PHP_FILES   = 60;
	private const HEADER_BYTES    = 8192;
	private const README_BYTES    = 65536;
	private const MAX_CHANGELOG   = 2000;
	private const MAX_NAME_LENGTH = 120;
	private const MAX_VERSION     = 40;

	/**
	 * Reads a zip.
	 *
	 * @param string $path Path of the zip file.
	 * @return PackageInfo
	 * @throws PackageException If the file is not a usable plugin or theme.
	 */
	public static function read( string $path ): PackageInfo {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new PackageException( 'This server cannot open zip files (the PHP zip extension is missing), so the details cannot be read from the file.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			throw new PackageException( 'This file is not a readable zip.' );
		}

		try {
			return self::read_archive( $zip );
		} finally {
			$zip->close();
		}
	}

	/**
	 * @param \ZipArchive $zip Open archive.
	 * @throws PackageException If the archive is not a plugin or theme.
	 */
	private static function read_archive( \ZipArchive $zip ): PackageInfo {
		if ( $zip->numFiles > self::MAX_ENTRIES ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			throw new PackageException( 'This zip has too many files to be a plugin or theme.' );
		}

		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$name = (string) $zip->getNameIndex( $i );
			if ( '' === $name || 0 === strpos( $name, '__MACOSX/' ) || 0 === strpos( basename( $name ), '._' ) ) {
				continue;
			}
			$names[] = str_replace( '\\', '/', $name );
		}

		if ( ! $names ) {
			throw new PackageException( 'This zip is empty.' );
		}

		$warnings = array();
		$folder   = self::single_folder( $names, $warnings );
		$base     = '' === $folder ? '' : $folder . '/';

		$files = array();
		foreach ( $names as $name ) {
			if ( '/' === substr( $name, -1 ) || 0 !== strpos( $name, $base ) ) {
				continue;
			}
			$rest = substr( $name, strlen( $base ) );
			if ( false === strpos( $rest, '/' ) ) {
				$files[ $rest ] = $name;
			}
		}

		$found = self::find_plugin( $zip, $files, $folder );
		if ( null === $found && isset( $files['style.css'] ) ) {
			$found = self::find_theme( $zip, $files['style.css'] );
		}

		if ( null === $found ) {
			throw new PackageException( self::not_found_message( $files ) );
		}

		list( $type, $main_file, $headers ) = $found;

		$name = self::clean( $headers['name'] ?? '', self::MAX_NAME_LENGTH );
		if ( '' === $name ) {
			throw new PackageException( 'The name in the file header is empty.' );
		}

		$raw_version = trim( (string) ( $headers['version'] ?? '' ) );
		if ( '' === $raw_version ) {
			throw new PackageException( 'The file header has no Version. Add a "Version:" line to the ' . ( PackageInfo::TYPE_THEME === $type ? 'style.css' : 'main plugin file' ) . ' and upload again.' );
		}
		$version = self::clean( $raw_version, self::MAX_VERSION );
		if ( ! preg_match( '/^[0-9]+(?:\.[0-9]+)*(?:[-+.]?[A-Za-z0-9][A-Za-z0-9.+\-]*)?$/', $version ) ) {
			throw new PackageException( 'The version "' . $version . '" is not a valid version number. Use something like 1.4.2.' );
		}

		$info               = new PackageInfo( $type, $name, $version, $folder, $main_file );
		$info->requires_wp  = self::clean( $headers['requires_wp'] ?? '', 20 );
		$info->requires_php = self::clean( $headers['requires_php'] ?? '', 20 );
		$info->text_domain  = self::clean( $headers['text_domain'] ?? '', 80 );
		$info->author       = self::clean( $headers['author'] ?? '', self::MAX_NAME_LENGTH );
		$info->warnings     = $warnings;

		self::apply_readme( $zip, $files, $info );

		if ( '' === $info->requires_wp ) {
			$info->warnings[] = 'No "Requires at least" (minimum WordPress version) was found. Customers will see no minimum.';
		}
		if ( '' === $info->requires_php ) {
			$info->warnings[] = 'No "Requires PHP" (minimum PHP version) was found. Customers will see no minimum.';
		}

		return $info;
	}

	/**
	 * Returns the one folder every file lives in, or '' with a warning if there is not exactly one.
	 *
	 * @param string[] $names    Entry names.
	 * @param string[] $warnings Warnings, added to.
	 * @throws PackageException If there are several folders.
	 */
	private static function single_folder( array $names, array &$warnings ): string {
		$folders = array();
		$root    = false;
		foreach ( $names as $name ) {
			$pos = strpos( $name, '/' );
			if ( false === $pos ) {
				$root = true;
			} else {
				$folders[ substr( $name, 0, $pos ) ] = true;
			}
		}

		if ( 1 === count( $folders ) && ! $root ) {
			return (string) key( $folders );
		}

		if ( count( $folders ) > 1 && ! $root ) {
			throw new PackageException( 'This zip has more than one folder at the top (' . implode( ', ', array_slice( array_keys( $folders ), 0, 3 ) ) . '). A plugin or theme zip should contain just one folder.' );
		}

		$warnings[] = 'The zip should contain one folder with all the files inside it. WordPress uses that folder name when it installs and updates, so a zip without it can install under the wrong name.';

		return '';
	}

	/**
	 * @param \ZipArchive          $zip    Archive.
	 * @param array<string,string> $files  File name to entry name, for the base folder.
	 * @param string               $folder Folder name.
	 * @return array{string,string,array<string,string>}|null
	 */
	private static function find_plugin( \ZipArchive $zip, array $files, string $folder ): ?array {
		$php = array();
		foreach ( $files as $file => $entry ) {
			if ( '.php' === strtolower( substr( $file, -4 ) ) ) {
				$php[ $file ] = $entry;
			}
		}

		// The usual name first, so a normal plugin is found on the first read.
		uksort(
			$php,
			static function ( $a, $b ) use ( $folder ) {
				$by_main = ( $b === $folder . '.php' ) <=> ( $a === $folder . '.php' );

				return 0 !== $by_main ? $by_main : strcmp( $a, $b );
			}
		);

		$checked = 0;
		foreach ( $php as $entry ) {
			if ( ++$checked > self::MAX_PHP_FILES ) {
				break;
			}
			$head = self::head( $zip, $entry );
			$name = self::header( $head, 'Plugin Name' );
			if ( '' === $name ) {
				continue;
			}

			return array(
				PackageInfo::TYPE_PLUGIN,
				$entry,
				array(
					'name'         => $name,
					'version'      => self::header( $head, 'Version' ),
					'requires_wp'  => self::header( $head, 'Requires at least' ),
					'requires_php' => self::header( $head, 'Requires PHP' ),
					'text_domain'  => self::header( $head, 'Text Domain' ),
					'author'       => self::header( $head, 'Author' ),
				),
			);
		}//end foreach

		return null;
	}

	/**
	 * @param \ZipArchive $zip   Archive.
	 * @param string      $entry style.css entry name.
	 * @return array{string,string,array<string,string>}|null
	 */
	private static function find_theme( \ZipArchive $zip, string $entry ): ?array {
		$head = self::head( $zip, $entry );
		$name = self::header( $head, 'Theme Name' );
		if ( '' === $name ) {
			return null;
		}

		return array(
			PackageInfo::TYPE_THEME,
			$entry,
			array(
				'name'         => $name,
				'version'      => self::header( $head, 'Version' ),
				'requires_wp'  => self::header( $head, 'Requires at least' ),
				'requires_php' => self::header( $head, 'Requires PHP' ),
				'text_domain'  => self::header( $head, 'Text Domain' ),
				'author'       => self::header( $head, 'Author' ),
			),
		);
	}

	/**
	 * Fills gaps from readme.txt and warns if its stable tag disagrees with the header.
	 *
	 * @param \ZipArchive          $zip   Archive.
	 * @param array<string,string> $files File name to entry name.
	 * @param PackageInfo          $info  Details, updated.
	 */
	private static function apply_readme( \ZipArchive $zip, array $files, PackageInfo $info ): void {
		if ( ! isset( $files['readme.txt'] ) ) {
			return;
		}

		$head = self::head( $zip, $files['readme.txt'], self::README_BYTES );

		if ( '' === $info->requires_wp ) {
			$info->requires_wp = self::clean( self::header( $head, 'Requires at least' ), 20 );
		}
		if ( '' === $info->requires_php ) {
			$info->requires_php = self::clean( self::header( $head, 'Requires PHP' ), 20 );
		}
		$info->tested_up_to = self::clean( self::header( $head, 'Tested up to' ), 20 );

		$info->changelog = self::changelog_for( $head, $info->version );

		$stable = self::clean( self::header( $head, 'Stable tag' ), self::MAX_VERSION );
		if ( '' !== $stable && 'trunk' !== strtolower( $stable ) && $stable !== $info->version ) {
			$info->warnings[] = 'readme.txt says Stable tag ' . $stable . ' but the file header says version ' . $info->version . '. Update one so they match.';
		}
	}

	/**
	 * @param array<string,string> $files Files in the base folder.
	 */
	private static function not_found_message( array $files ): string {
		foreach ( $files as $file => $entry ) {
			if ( '.zip' === strtolower( substr( $file, -4 ) ) ) {
				return 'This zip contains another zip. Upload the inner zip instead.';
			}
		}

		return 'No plugin or theme was found in this zip. A plugin needs a PHP file with a "Plugin Name:" header directly inside its folder. A theme needs a style.css with a "Theme Name:" header.';
	}

	/**
	 * The entry for one version from the "== Changelog ==" section of a readme.txt, as plain lines.
	 *
	 * @param string $readme  Start of readme.txt.
	 * @param string $version Version to find.
	 */
	private static function changelog_for( string $readme, string $version ): string {
		if ( ! preg_match( '/^==\s*Changelog\s*==\s*$(.*?)(?=^==[^=].*==\s*$|\z)/ims', $readme, $section ) ) {
			return '';
		}

		$pattern = '/^=\s*v?' . preg_quote( $version, '/' ) . '(?:\s[^=\n]*)?=\s*$(.*?)(?=^=[^=].*=\s*$|\z)/ims';
		if ( ! preg_match( $pattern, $section[1], $entry ) ) {
			return '';
		}

		$lines = array();
		foreach ( explode( "\n", $entry[1] ) as $line ) {
			$line = trim( strip_tags( $line ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Plain text is wanted, and this also runs without WordPress.
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$text = implode( "\n", $lines );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_CHANGELOG ) : substr( $text, 0, self::MAX_CHANGELOG );
	}

	/**
	 * Reads the first bytes of a file in the zip.
	 *
	 * @param \ZipArchive $zip   Archive.
	 * @param string      $entry Entry name.
	 * @param int         $bytes How much to read.
	 */
	private static function head( \ZipArchive $zip, string $entry, int $bytes = self::HEADER_BYTES ): string {
		$stream = $zip->getStream( $entry );
		if ( ! is_resource( $stream ) ) {
			return '';
		}
		// fread() on a zip stream returns at most one chunk (8 KB), so read until the amount is collected.
		$data = (string) stream_get_contents( $stream, $bytes );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A stream inside a zip, not a file on disk.

		return str_replace( "\r", "\n", $data );
	}

	/**
	 * Reads one "Label: value" line from a file header, the way WordPress does.
	 *
	 * @param string $content File start.
	 * @param string $label   Header label.
	 */
	private static function header( string $content, string $label ): string {
		if ( ! preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $content, $match ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
	}

	/**
	 * Strips tags and control characters and limits the length.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum length.
	 */
	private static function clean( string $value, int $length ): string {
		$value = strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Plain text is wanted, and this also runs without WordPress.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
