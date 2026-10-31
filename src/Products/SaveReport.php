<?php
/**
 * What a product save did.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * The messages a product save leaves for the seller: things that went wrong, things that were done for them,
 * and things worth a second look. A failure never stops the rest of the form from being saved.
 */
final class SaveReport {

	/**
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * @var string[]
	 */
	public $messages = array();

	/**
	 * @var string[]
	 */
	public $warnings = array();

	/**
	 * Whether there is anything to tell the seller.
	 */
	public function is_empty(): bool {
		return ! $this->errors && ! $this->messages && ! $this->warnings;
	}

	/**
	 * Plain form, for keeping the report until the next page load.
	 *
	 * @return array{errors:string[],messages:string[],warnings:string[]}
	 */
	public function to_array(): array {
		return array(
			'errors'   => $this->errors,
			'messages' => $this->messages,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Rebuilds a report from its plain form. Anything that is not a list of text is dropped.
	 *
	 * @param mixed $data Value from to_array().
	 */
	public static function from_array( $data ): self {
		$report = new self();
		$data   = is_array( $data ) ? $data : array();

		foreach ( array( 'errors', 'messages', 'warnings' ) as $key ) {
			foreach ( is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array() as $line ) {
				if ( is_string( $line ) ) {
					$report->{$key}[] = $line;
				}
			}
		}

		return $report;
	}
}
