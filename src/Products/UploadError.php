<?php
/**
 * PHP upload error messages.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Turns the error code of an uploaded file into a message for the seller. Without this a file that is too big is
 * dropped silently, which is the most common way an upload fails.
 */
final class UploadError {

	/**
	 * The message for an upload error code, or null if the upload had no error.
	 *
	 * @param int    $code      Value of $_FILES[...]['error'].
	 * @param string $max_size  The server's upload limit, such as "2M", for the message.
	 */
	public static function message( int $code, string $max_size ): ?string {
		switch ( $code ) {
			case UPLOAD_ERR_OK:
				return null;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: the largest upload the server allows, or "the upload limit". */
					__( 'The file is bigger than this server allows (%s). Ask your host to raise upload_max_filesize and post_max_size, or upload a smaller zip.', 'wplicense-it' ),
					'' !== $max_size ? $max_size : __( 'the upload limit', 'wplicense-it' )
				);
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partly uploaded. Try again.', 'wplicense-it' );
			case UPLOAD_ERR_NO_FILE:
				return null;
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'The server has no temporary folder for uploads. Ask your host to fix this.', 'wplicense-it' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the file. Check the disk space and folder permissions.', 'wplicense-it' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension stopped the upload.', 'wplicense-it' );
			default:
				return sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'The upload failed (error %d).', 'wplicense-it' ),
					$code
				);
		}//end switch
	}
}
