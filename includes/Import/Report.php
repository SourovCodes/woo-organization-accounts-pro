<?php
/**
 * The per-row record of what an import did.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a line per imported row to a CSV the shop keeps.
 *
 * The counts on the finished screen say how many accounts were created. This says
 * which ones, and it is the more useful of the two: it carries the customer number the
 * old shop knew each account by beside the organization and user this one created, so
 * the two systems can be reconciled row by row, and it carries every warning — the
 * postcodes that were missing, the addresses WooCommerce would not accept, the person
 * two organizations both claimed. Those are the rows somebody has to look at, and
 * without this file the only way to find them is to read six hundred organizations.
 *
 * It is written into the same protected directory as the export and is never linked.
 * Downloading it goes through an admin-post handler with a capability check and a
 * nonce.
 */
final class Report {

	/**
	 * The report's columns.
	 *
	 * @return string[] Headings.
	 */
	public static function columns() {
		return array(
			__( 'Row', 'woo-organization-accounts-pro' ),
			__( 'Email address', 'woo-organization-accounts-pro' ),
			__( 'Customer number', 'woo-organization-accounts-pro' ),
			__( 'Result', 'woo-organization-accounts-pro' ),
			__( 'Organization', 'woo-organization-accounts-pro' ),
			__( 'Organization ID', 'woo-organization-accounts-pro' ),
			__( 'User ID', 'woo-organization-accounts-pro' ),
			__( 'Delivery addresses added', 'woo-organization-accounts-pro' ),
			__( 'Notes', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * Begin a report, replacing anything already at the path.
	 *
	 * The byte order mark is deliberate: without one, Excel reads a UTF-8 CSV as
	 * Windows-1252 and every accented company name in the report is mangled on the
	 * screen of the person trying to use it to check the import.
	 *
	 * @param string $path Absolute path.
	 * @return bool True when the report was started.
	 */
	public static function start( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Appended to once per row across several requests; WP_Filesystem would rewrite the whole file each time.
		$handle = fopen( $path, 'wb' );

		if ( false === $handle ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing through the handle opened above.
		fwrite( $handle, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		self::write_row( $handle, self::columns() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle opened above.
		fclose( $handle );

		return true;
	}

	/**
	 * Add the outcomes of one batch.
	 *
	 * @param string   $path    Absolute path.
	 * @param Result[] $results Outcomes, in row order.
	 * @return void
	 */
	public static function append( $path, array $results ) {
		if ( '' === (string) $path || empty( $results ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Appending; see the note on start().
		$handle = fopen( $path, 'ab' );

		if ( false === $handle ) {
			return;
		}

		$labels = Result::labels();

		foreach ( $results as $result ) {
			$record = $result->record();

			self::write_row(
				$handle,
				array(
					$record->number(),
					$record->email(),
					$record->customer_number(),
					$labels[ $result->action() ] ?? $result->action(),
					$record->organization_name(),
					$result->organization_id() > 0 ? $result->organization_id() : '',
					$result->user_id() > 0 ? $result->user_id() : '',
					$result->locations_added(),
					implode( ' | ', $result->messages() ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle opened above.
		fclose( $handle );
	}

	/**
	 * The name the report is offered to the browser under.
	 *
	 * @param string $source Original name of the imported file.
	 * @return string Filename.
	 */
	public static function filename( $source ) {
		$base = sanitize_file_name( pathinfo( (string) $source, PATHINFO_FILENAME ) );

		if ( '' === $base ) {
			$base = 'import';
		}

		return 'woap-import-report-' . $base . '-' . gmdate( 'Ymd-His' ) . '.csv';
	}

	/**
	 * Write one CSV row through an open handle.
	 *
	 * @param resource $handle Open file handle.
	 * @param array    $values Values.
	 * @return void
	 */
	private static function write_row( $handle, array $values ) {
		/*
		 * The escape character is passed explicitly and empty, for the same reason the
		 * reader passes it: PHP's historical backslash is not part of RFC 4180 and is
		 * deprecated as of 8.4 unless the argument is given.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Writing through the handle opened by the caller.
		fputcsv( $handle, $values, ',', '"', '' );
	}
}
