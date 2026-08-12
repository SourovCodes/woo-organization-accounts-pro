<?php
/**
 * Where an import's files live while it runs.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the uploaded export and the report it produces out of public reach.
 *
 * A customer export is the most sensitive file a shop owns — six hundred names,
 * addresses, phone numbers and email addresses in one place — and the uploads
 * directory it has to be written into is served straight to the internet. Three things
 * keep it out of reach, because none of them is enough on its own:
 *
 * - **A random filename.** The only defence that works on every server, and the reason
 *   the name carries 32 characters of entropy rather than the customer's own.
 * - **A directory deny rule.** `.htaccess` covers Apache; `index.html` stops a
 *   directory listing where one is enabled. Neither does anything on nginx, which is
 *   why the filename is what is really relied on.
 * - **Deleting it.** The file is removed the moment the import finishes or is
 *   abandoned, and anything left behind by a browser closed mid-import is swept up a
 *   day later. A file that is not there cannot be served.
 *
 * The report is written here too and is never linked. It is read back through an
 * admin-post handler that checks a capability and a nonce, so downloading it is an
 * authorised request rather than a URL somebody can pass on.
 */
final class Storage {

	/**
	 * Directory name inside wp-content/uploads.
	 */
	const DIRECTORY = 'woap-imports';

	/**
	 * How long an abandoned file is kept before it is swept up, in seconds.
	 */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * The directory imports are kept in, created and protected if it is not there.
	 *
	 * @return string|\WP_Error Absolute path with a trailing slash, or an error.
	 */
	public static function directory() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'woap_import_uploads', (string) $uploads['error'] );
		}

		$directory = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY . '/';

		if ( ! wp_mkdir_p( $directory ) ) {
			return new \WP_Error(
				'woap_import_uploads',
				sprintf(
					/* translators: %s: directory path. */
					__( 'The directory %s could not be created, so there is nowhere to put the uploaded file.', 'woo-organization-accounts-pro' ),
					$directory
				)
			);
		}

		self::protect( $directory );

		return $directory;
	}

	/**
	 * Write the two files that keep the directory from being browsed.
	 *
	 * @param string $directory Directory path with a trailing slash.
	 * @return void
	 */
	private static function protect( $directory ) {
		$guards = array(
			'.htaccess'  => "Options -Indexes\ndeny from all\n",
			'index.html' => '',
		);

		foreach ( $guards as $file => $contents ) {
			if ( ! file_exists( $directory . $file ) ) {
				self::write( $directory . $file, $contents );
			}
		}
	}

	/**
	 * Take an uploaded file and put it somewhere only this plugin will look.
	 *
	 * @param array $file One entry of `$_FILES`.
	 * @return string|\WP_Error Absolute path to the stored file, or an error.
	 */
	public static function receive( array $file ) {
		$directory = self::directory();

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'woap_import_upload', __( 'No file arrived. Choose a CSV file and try again.', 'woo-organization-accounts-pro' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$type = wp_check_filetype(
			$name,
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);

		if ( empty( $type['ext'] ) ) {
			return new \WP_Error( 'woap_import_upload', __( 'That is not a CSV file. Export the customers as CSV and upload that.', 'woo-organization-accounts-pro' ) );
		}

		$path = $directory . 'woap-import-' . wp_generate_password( 32, false, false ) . '.' . $type['ext'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file -- The upload has to land in a directory outside the media library, which wp_handle_upload() cannot target without filtering the whole site's upload path.
		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new \WP_Error( 'woap_import_upload', __( 'The uploaded file could not be stored. Check that the uploads directory is writable.', 'woo-organization-accounts-pro' ) );
		}

		return $path;
	}

	/**
	 * A path for the report of a run.
	 *
	 * @return string|\WP_Error Absolute path, or an error.
	 */
	public static function report_path() {
		$directory = self::directory();

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		return $directory . 'woap-import-report-' . wp_generate_password( 32, false, false ) . '.csv';
	}

	/**
	 * Whether a path is one of ours.
	 *
	 * Every deletion and every download is checked against this, so a stored path that
	 * has been tampered with can only ever name a file inside the import directory.
	 *
	 * @param string $path Path to test.
	 * @return bool True when the file sits in the import directory.
	 */
	public static function owns( $path ) {
		$directory = self::directory();

		if ( is_wp_error( $directory ) ) {
			return false;
		}

		$path = (string) $path;
		$real = realpath( $path );

		if ( false === $real ) {
			return false;
		}

		$root = realpath( $directory );

		return false !== $root && 0 === strpos( $real, trailingslashit( $root ) );
	}

	/**
	 * Delete a file this class stored.
	 *
	 * @param string $path Absolute path.
	 * @return bool True when the file is gone.
	 */
	public static function delete( $path ) {
		if ( '' === (string) $path || ! self::owns( $path ) ) {
			return false;
		}

		return wp_delete_file_from_directory( $path, self::directory() );
	}

	/**
	 * Remove anything left behind by an import nobody finished.
	 *
	 * A browser closed halfway through leaves the export sitting on disk with no screen
	 * left pointing at it. The guard files are left where they are.
	 *
	 * @return int How many files were removed.
	 */
	public static function cleanup() {
		$directory = self::directory();

		if ( is_wp_error( $directory ) ) {
			return 0;
		}

		$removed = 0;
		$cutoff  = time() - self::MAX_AGE;
		$active  = (string) ( Run::get()['file'] ?? '' );

		foreach ( (array) glob( $directory . 'woap-import-*' ) as $file ) {
			if ( ! is_file( $file ) || $file === $active || filemtime( $file ) > $cutoff ) {
				continue;
			}

			if ( self::delete( $file ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Write a file, creating it if it is not there.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents What to write.
	 * @return bool True on success.
	 */
	private static function write( $path, $contents ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
	}
}
