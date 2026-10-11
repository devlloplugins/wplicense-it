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
				return 'The file is bigger than this server allows (' . ( '' !== $max_size ? $max_size : 'the upload limit' ) . '). Ask your host to raise upload_max_filesize and post_max_size, or upload a smaller zip.';
			case UPLOAD_ERR_PARTIAL:
				return 'The file was only partly uploaded. Try again.';
			case UPLOAD_ERR_NO_FILE:
				return null;
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'The server has no temporary folder for uploads. Ask your host to fix this.';
			case UPLOAD_ERR_CANT_WRITE:
				return 'The server could not write the file. Check the disk space and folder permissions.';
			case UPLOAD_ERR_EXTENSION:
				return 'A PHP extension stopped the upload.';
			default:
				return 'The upload failed (error ' . $code . ').';
		}
	}
}
