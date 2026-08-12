<?php
/**
 * CSV reader for the customer importer.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an uploaded customer export one row at a time.
 *
 * Deliberately a streaming reader rather than a parse-the-whole-file helper. An
 * import runs in batches across several requests, and each batch resumes from a byte
 * offset the previous one recorded, so a file of any size costs one request's worth of
 * memory and a batch never re-reads the rows before it.
 *
 * `WP_Filesystem` is not used. It has no cursor: every read is the whole file, which
 * is the one thing this class exists to avoid.
 */
final class Csv {

	/**
	 * The delimiters the reader will detect, in the order they are preferred on a tie.
	 *
	 * Semicolon is second rather than last because a German or Swiss export written by
	 * Excel uses it, and this importer was written for one.
	 *
	 * @var string[]
	 */
	const DELIMITERS = array( ',', ';', "\t", '|' );

	/**
	 * Open file handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Path to the file being read.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * The delimiter detected in the header line.
	 *
	 * @var string
	 */
	private $delimiter = ',';

	/**
	 * The column headers, in file order.
	 *
	 * @var string[]
	 */
	private $headers = array();

	/**
	 * Byte offset of the first data row.
	 *
	 * @var int
	 */
	private $first_row_offset = 0;

	/**
	 * Open a file for reading.
	 *
	 * @param string $path Absolute path.
	 * @return Csv|\WP_Error The reader, or an error when the file cannot be read.
	 */
	public static function open( $path ) {
		$path = (string) $path;

		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return new \WP_Error(
				'woap_import_unreadable',
				__( 'The uploaded file could not be read. Upload it again.', 'woo-organization-accounts-pro' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A cursor is the point: the import resumes each batch from a byte offset, which WP_Filesystem cannot express.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return new \WP_Error(
				'woap_import_unreadable',
				__( 'The uploaded file could not be read. Upload it again.', 'woo-organization-accounts-pro' )
			);
		}

		$csv = new self( $handle, $path );

		$headers = $csv->read_headers();

		if ( is_wp_error( $headers ) ) {
			$csv->close();

			return $headers;
		}

		return $csv;
	}

	/**
	 * Constructor.
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $path   Path the handle was opened from.
	 */
	private function __construct( $handle, $path ) {
		$this->handle = $handle;
		$this->path   = $path;
	}

	/**
	 * Read the header line, detecting the delimiter and dropping any byte order mark.
	 *
	 * @return string[]|\WP_Error The headers, or an error when there are none.
	 */
	private function read_headers() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Reading through the open cursor; see the note on the class.
		$line = fgets( $this->handle );

		if ( false === $line || '' === trim( $line ) ) {
			return new \WP_Error(
				'woap_import_empty',
				__( 'That file has no columns in it. The first line of the file has to be the column headings.', 'woo-organization-accounts-pro' )
			);
		}

		$this->delimiter = self::detect_delimiter( $line );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the cursor to parse the header line as CSV rather than as text.
		rewind( $this->handle );

		$headers = $this->read_row();

		if ( empty( $headers ) ) {
			return new \WP_Error(
				'woap_import_empty',
				__( 'That file has no columns in it. The first line of the file has to be the column headings.', 'woo-organization-accounts-pro' )
			);
		}

		$headers[0] = self::strip_bom( (string) ( $headers[0] ?? '' ) );

		foreach ( $headers as $index => $header ) {
			$headers[ $index ] = trim( self::to_utf8( (string) $header ) );
		}

		$this->headers          = $headers;
		$this->first_row_offset = (int) ftell( $this->handle );

		return $headers;
	}

	/**
	 * The column headers, in file order.
	 *
	 * @return string[] Headers.
	 */
	public function headers() {
		return $this->headers;
	}

	/**
	 * The delimiter the file was found to use.
	 *
	 * @return string One character.
	 */
	public function delimiter() {
		return $this->delimiter;
	}

	/**
	 * The byte offset the first data row starts at.
	 *
	 * @return int Offset.
	 */
	public function first_row_offset() {
		return $this->first_row_offset;
	}

	/**
	 * The current byte offset, for resuming the next batch here.
	 *
	 * @return int Offset.
	 */
	public function offset() {
		return (int) ftell( $this->handle );
	}

	/**
	 * Move the cursor to a byte offset recorded by an earlier batch.
	 *
	 * @param int $offset Byte offset. Anything before the first data row starts there.
	 * @return void
	 */
	public function seek( $offset ) {
		$offset = max( (int) $offset, $this->first_row_offset );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Resuming the batch where the previous one stopped.
		fseek( $this->handle, $offset );
	}

	/**
	 * Read the next data row.
	 *
	 * Blank lines are skipped rather than returned, because a trailing newline is not a
	 * customer and counting one would make every progress figure wrong.
	 *
	 * @return array|null Values keyed by column heading, or null at the end of the file.
	 */
	public function next() {
		while ( true ) {
			$row = $this->read_row();

			if ( null === $row ) {
				return null;
			}

			if ( array() === $row || ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) ) {
				continue;
			}

			$values = array();

			foreach ( $this->headers as $index => $header ) {
				$values[ $header ] = trim( self::to_utf8( (string) ( $row[ $index ] ?? '' ) ) );
			}

			return $values;
		}
	}

	/**
	 * Count the data rows in the file.
	 *
	 * Run once, when the file is uploaded, so the progress bar has a denominator. The
	 * cursor is put back where it was, so this is safe to call mid-import.
	 *
	 * @return int Number of data rows.
	 */
	public function count_rows() {
		$resume = $this->offset();
		$total  = 0;

		$this->seek( $this->first_row_offset );

		while ( null !== $this->next() ) {
			++$total;
		}

		$this->seek( $resume );

		return $total;
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle this class opened.
			fclose( $this->handle );
		}

		$this->handle = null;
	}

	/**
	 * Read one raw row through the cursor.
	 *
	 * @return array|null Values by position, or null at the end of the file.
	 */
	private function read_row() {
		if ( ! is_resource( $this->handle ) ) {
			return null;
		}

		/*
		 * The escape character is passed explicitly and empty. PHP's historical default
		 * of a backslash is not part of RFC 4180, mangles a Windows path in an address
		 * line, and is deprecated as of PHP 8.4 unless the argument is given.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Reading through the open cursor; see the note on the class.
		$row = fgetcsv( $this->handle, 0, $this->delimiter, '"', '' );

		if ( false === $row || null === $row ) {
			return null;
		}

		return $row;
	}

	/**
	 * Work out which delimiter a header line uses.
	 *
	 * Counted outside quoted sections, so a company name with a comma in it does not
	 * make a semicolon-separated file look comma-separated.
	 *
	 * @param string $line The header line.
	 * @return string One character.
	 */
	private static function detect_delimiter( $line ) {
		$counts = array_fill_keys( self::DELIMITERS, 0 );
		$quoted = false;
		$length = strlen( $line );

		for ( $i = 0; $i < $length; $i++ ) {
			$character = $line[ $i ];

			if ( '"' === $character ) {
				$quoted = ! $quoted;
				continue;
			}

			if ( ! $quoted && isset( $counts[ $character ] ) ) {
				++$counts[ $character ];
			}
		}

		$best  = ',';
		$found = 0;

		foreach ( self::DELIMITERS as $delimiter ) {
			if ( $counts[ $delimiter ] > $found ) {
				$best  = $delimiter;
				$found = $counts[ $delimiter ];
			}
		}

		return $best;
	}

	/**
	 * Remove a UTF-8 byte order mark from the front of a value.
	 *
	 * Excel writes one, and it arrives glued to the first column heading, where it
	 * quietly stops that heading matching anything.
	 *
	 * @param string $value Value.
	 * @return string Value without its byte order mark.
	 */
	private static function strip_bom( $value ) {
		$bom = chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF );

		return 0 === strpos( $value, $bom ) ? substr( $value, strlen( $bom ) ) : $value;
	}

	/**
	 * Bring a value to UTF-8.
	 *
	 * A file saved out of Excel on Windows is Windows-1252, in which the German and
	 * French accents this importer is full of are single bytes that are not valid UTF-8
	 * — left alone they reach the database as replacement characters and no amount of
	 * later editing recovers the name. Anything already valid UTF-8 is returned
	 * untouched, so a correct file is never re-encoded.
	 *
	 * @param string $value Value.
	 * @return string UTF-8 value.
	 */
	private static function to_utf8( $value ) {
		if ( '' === $value || ! function_exists( 'mb_check_encoding' ) || mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}

		return (string) mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
	}
}
