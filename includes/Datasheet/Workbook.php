<?php
/**
 * The product datasheet as a spreadsheet.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Datasheet;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the datasheet as an .xlsx workbook.
 *
 * An .xlsx is a zip holding a handful of XML parts, and the subset needed for one flat
 * sheet of text is small enough to write here. That is the same judgement `Sheet::field()`
 * makes about `fputcsv()`: the plugin has no runtime dependencies at all, and taking its
 * first one — plus the megabytes a spreadsheet library brings — to emit six static XML
 * documents and a `<row>` per product would cost more than it saves.
 *
 * **Every cell is a text cell**, written as an inline string. That is not laziness, it is
 * the reason a shop asks for this format in the first place: Excel reads the EAN column
 * of a CSV as a number and shows `8594164760334` as `8.59416E+12`, and an article number
 * like `007-001-001` loses its leading zeros the same way. Typing the cells as text is
 * what a CSV cannot say and this can. Prices stay text for the same reason they are
 * fixed to four decimal places in the CSV — the file is a record of what the shop
 * charged, not a spreadsheet to calculate in.
 *
 * Inline strings rather than a shared-strings table: one pass, no second index to keep in
 * step, and the descriptions are long enough that deduplication would win nothing.
 *
 * There is no styling, no column sizing and no frozen header. Every one of those is a
 * part to write and a content type to declare, and none of them is what a system loading
 * the file reads.
 */
final class Workbook {

	/**
	 * The worksheet's name.
	 *
	 * Untranslated, and for the same reason the header row is: it is addressable. A
	 * consumer that opens the workbook by sheet name has to find the same name whatever
	 * language the shop runs in.
	 */
	const SHEET_NAME = 'Product data';

	/**
	 * The most characters Excel will hold in one cell.
	 */
	const CELL_LIMIT = 32767;

	/**
	 * Whether this installation can write a workbook at all.
	 *
	 * ZipArchive is all but universal and WordPress prefers it for its own updates, but
	 * it is an extension and can be absent. Where it is, the format is not offered
	 * rather than being offered and failing: the answer decides whether the *link*
	 * renders, so nobody is given a button that cannot work.
	 *
	 * @return bool True when a workbook can be produced.
	 */
	public static function is_available() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Build the workbook.
	 *
	 * Returns the bytes rather than streaming them, the same seam `Sheet::render()`
	 * keeps, so the result can be asserted without a request.
	 *
	 * @param string[] $columns Headings.
	 * @param array[]  $rows    Rows, each as wide as the headings.
	 * @return string The .xlsx file, or an empty string when it could not be written.
	 */
	public static function render( array $columns, array $rows ) {
		if ( ! self::is_available() ) {
			return '';
		}

		$path = tempnam( get_temp_dir(), 'woap-datasheet' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- ZipArchive writes to a path; WP_Filesystem cannot give it one.

		if ( false === $path ) {
			return '';
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			self::discard( $path );

			return '';
		}

		$zip->addFromString( '[Content_Types].xml', self::content_types() );
		$zip->addFromString( '_rels/.rels', self::root_relationships() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_relationships() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::worksheet( $columns, $rows ) );
		$zip->close();

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading back the temporary file ZipArchive just wrote.
		self::discard( $path );

		return is_string( $bytes ) ? $bytes : '';
	}

	/**
	 * Delete the temporary file.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private static function discard( $path ) {
		wp_delete_file( $path );
	}

	/**
	 * The package's content types.
	 *
	 * @return string XML.
	 */
	private static function content_types() {
		return self::declaration()
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';
	}

	/**
	 * The package relationship pointing at the workbook.
	 *
	 * @return string XML.
	 */
	private static function root_relationships() {
		return self::declaration()
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1"'
			. ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
			. ' Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * The workbook, holding its one sheet.
	 *
	 * @return string XML.
	 */
	private static function workbook() {
		return self::declaration()
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . self::text( self::SHEET_NAME ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * The workbook relationship pointing at the worksheet.
	 *
	 * @return string XML.
	 */
	private static function workbook_relationships() {
		return self::declaration()
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1"'
			. ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
			. ' Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';
	}

	/**
	 * The worksheet: the header row followed by one row per product.
	 *
	 * @param string[] $columns Headings.
	 * @param array[]  $rows    Rows.
	 * @return string XML.
	 */
	private static function worksheet( array $columns, array $rows ) {
		$xml = self::declaration()
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

		$xml .= self::row( 1, $columns );

		foreach ( array_values( $rows ) as $index => $values ) {
			$xml .= self::row( $index + 2, (array) $values );
		}

		return $xml . '</sheetData></worksheet>';
	}

	/**
	 * One row.
	 *
	 * @param int   $number One-based row number.
	 * @param array $values Cell values, in column order.
	 * @return string XML.
	 */
	private static function row( $number, array $values ) {
		$xml = '<row r="' . (int) $number . '">';

		foreach ( array_values( $values ) as $index => $value ) {
			$reference = self::column_name( $index ) . (int) $number;

			$xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
				. self::text( $value )
				. '</t></is></c>';
		}

		return $xml . '</row>';
	}

	/**
	 * A column's spreadsheet letter.
	 *
	 * @param int $index Zero-based column index.
	 * @return string A, B, … Z, AA, AB, …
	 */
	private static function column_name( $index ) {
		$name = '';

		for ( $i = (int) $index; $i >= 0; $i = intdiv( $i, 26 ) - 1 ) {
			$name = chr( 65 + ( $i % 26 ) ) . $name;
		}

		return $name;
	}

	/**
	 * A value, safe to put inside an XML element.
	 *
	 * Two things beyond escaping. Control characters are stripped, because XML 1.0
	 * cannot represent them at all and one arriving from a product description would
	 * make the whole workbook unopenable rather than one cell wrong — the pattern is
	 * deliberately not a Unicode one, so it cannot fail on a string that is not valid
	 * UTF-8. And the value is cut to Excel's own cell limit, which no description in
	 * this shop comes close to but which Excel enforces by refusing the file.
	 *
	 * @param mixed $value The value.
	 * @return string Escaped text.
	 */
	private static function text( $value ) {
		$value   = (string) $value;
		$cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		$value   = null === $cleaned ? $value : $cleaned;

		if ( mb_strlen( $value ) > self::CELL_LIMIT ) {
			$value = mb_substr( $value, 0, self::CELL_LIMIT );
		}

		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * The XML declaration every part begins with.
	 *
	 * @return string XML.
	 */
	private static function declaration() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
	}
}
