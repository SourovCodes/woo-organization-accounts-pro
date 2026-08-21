<?php
/**
 * The product datasheet.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Datasheet;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the product data file a customer downloads from a cart or an order.
 *
 * The organizations buying here resell what they buy, so what they need out of an order
 * is not a receipt but the *product data* behind it — article number, EAN, manufacturer,
 * description, the price they paid and the recommended retail price — in the layout
 * their supplier feed already arrives in, ready to load into their own shop.
 *
 * **The header row is not translated.** It is a machine format whose column names the
 * consuming system matches on, so a German shop must send exactly the same header as an
 * English one. That is the opposite of every other string in this plugin and the reason
 * none of these headings carries a text domain.
 *
 * Everything here is a pure static over a `WC_Product`: no request, no headers, no
 * output. `Download` is what turns the returned string into a file, which is the same
 * separation `Import\Report` keeps between building rows and streaming them — and the
 * only reason any of this can be asserted in a test.
 */
final class Sheet {

	/**
	 * Meta key the shop's product sync writes the recommended retail price to.
	 *
	 * Empty when it is not set: the column is a fact about the product, and a shop that
	 * has never recorded one must not be made to look as though the RRP were zero.
	 */
	const MSRP_META = '_wksync_msrp';

	/**
	 * WooCommerce's own brands taxonomy, native since 9.6.
	 */
	const BRAND_TAXONOMY = 'product_brand';

	/**
	 * How many gallery columns the file carries beyond the main image.
	 */
	const IMAGE_COLUMNS = 9;

	/**
	 * Decimal places both price columns are written to, as in the source feed.
	 */
	const DECIMALS = 4;

	/**
	 * The field separator. Semicolons, not commas, as the feed uses.
	 */
	const DELIMITER = ';';

	/**
	 * The file's columns, in order.
	 *
	 * Deliberately untranslated — see the class docblock. A shop adding a column here
	 * has to add its value in `row()` through the matching filter, or every row after
	 * it is one field short.
	 *
	 * @return string[] Headings.
	 */
	public static function columns() {
		$columns = array(
			'Artnr',
			'Artean',
			'Hersteller',
			'Bez1',
			'Langtext',
			'EK / VK1',
			'UVP / VK3',
			'MainImageURL',
		);

		for ( $index = 1; $index <= self::IMAGE_COLUMNS; $index++ ) {
			$columns[] = 'ImageURL_' . $index;
		}

		/**
		 * Filter the datasheet's columns.
		 *
		 * Pairs with `woo_org_accounts_datasheet_row`: a column added here without a
		 * value added there leaves every row short of the header.
		 *
		 * @since 0.13.0
		 *
		 * @param string[] $columns Headings, in order.
		 */
		return (array) apply_filters( 'woo_org_accounts_datasheet_columns', $columns );
	}

	/**
	 * The row describing one product.
	 *
	 * A variation is the case that breaks a naive mapping. It carries its own SKU, GTIN,
	 * price and image, usually no description of its own, and never the brand — those
	 * terms are on the parent post. So every inherited field falls back to the parent
	 * rather than being written out empty.
	 *
	 * @param \WC_Product $product The product.
	 * @return string[] One value per column.
	 */
	public static function row( \WC_Product $product ) {
		$parent_product = self::parent_of( $product );

		$row = array(
			self::inherited( $product, $parent_product, 'get_sku' ),
			self::inherited( $product, $parent_product, 'get_global_unique_id' ),
			self::brand( $product, $parent_product ),
			$product->get_name(),
			self::description( $product, $parent_product ),
			self::price( $product ),
			self::msrp( $product, $parent_product ),
		);

		$row = array_merge( $row, self::image_urls( $product, $parent_product ) );

		/**
		 * Filter one finished datasheet row.
		 *
		 * @since 0.13.0
		 *
		 * @param string[]    $row     The values, in column order.
		 * @param \WC_Product $product The product the row describes.
		 */
		return (array) apply_filters( 'woo_org_accounts_datasheet_row', $row, $product );
	}

	/**
	 * The rows describing a list of products.
	 *
	 * Quantity is not a column, so a product that appears on two lines of the same order
	 * is one row. First seen wins, which keeps the file in the order of the cart.
	 *
	 * @param \WC_Product[] $products The products.
	 * @return array[] Rows.
	 */
	public static function rows( array $products ) {
		$rows = array();
		$seen = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$id = $product->get_id();

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$rows[]      = self::row( $product );
		}

		return $rows;
	}

	/**
	 * The whole file, as a string.
	 *
	 * The byte order mark is the same deliberate choice `Import\Report` makes: the
	 * person downloading this opens it in Excel, and Excel reads a UTF-8 CSV without one
	 * as Windows-1252 — which turns every umlaut in a German product description into
	 * mojibake. Every RFC 4180 parser skips a BOM, so the machine consumer is unaffected.
	 *
	 * @param \WC_Product[] $products The products.
	 * @return string CSV.
	 */
	public static function render( array $products ) {
		$lines = array( self::line( self::columns() ) );

		foreach ( self::rows( $products ) as $row ) {
			$lines[] = self::line( $row );
		}

		return chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * The products on a cart, in the order they were added.
	 *
	 * @param \WC_Cart $cart The cart.
	 * @return \WC_Product[] Products.
	 */
	public static function from_cart( \WC_Cart $cart ) {
		$products = array();

		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$products[] = $item['data'];
			}
		}

		return $products;
	}

	/**
	 * The products on an order.
	 *
	 * A line whose product has since been deleted is skipped rather than written out as
	 * an empty row: the order keeps enough to say what was bought, but there is no
	 * product data left to hand over.
	 *
	 * @param \WC_Order $order The order.
	 * @return \WC_Product[] Products.
	 */
	public static function from_order( \WC_Order $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( $product instanceof \WC_Product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * The parent of a variation, when there is one.
	 *
	 * @param \WC_Product $product The product.
	 * @return \WC_Product|null The parent, or null for a top-level product.
	 */
	private static function parent_of( \WC_Product $product ) {
		$parent_product_id = $product->get_parent_id();

		if ( $parent_product_id <= 0 ) {
			return null;
		}

		$parent_product = wc_get_product( $parent_product_id );

		return $parent_product instanceof \WC_Product ? $parent_product : null;
	}

	/**
	 * A product's own value for a getter, falling back to its parent's.
	 *
	 * @param \WC_Product      $product The product.
	 * @param \WC_Product|null $parent_product Its parent, when it has one.
	 * @param string           $getter  Name of the getter to call on both.
	 * @return string The value, or an empty string when neither has one.
	 */
	private static function inherited( \WC_Product $product, $parent_product, $getter ) {
		$value = (string) $product->{$getter}();

		if ( '' === $value && $parent_product instanceof \WC_Product ) {
			$value = (string) $parent_product->{$getter}();
		}

		return $value;
	}

	/**
	 * The long description, as the feed carries it.
	 *
	 * The markup is kept — a consumer that renders HTML wants the lists and the line
	 * breaks the shop wrote — but two things are normalised on the way out, both because
	 * of what the shop's product sync actually stores.
	 *
	 * **Entities are decoded.** The sync writes numeric character references, so a
	 * description arrives holding `&#xFC;` rather than `ü` and `&#x2013;` rather than an
	 * en dash. Passed through, every umlaut in the file reads as an escape sequence on
	 * the screen of anyone treating the column as text. Decoding is a full
	 * `html_entity_decode()` rather than a numeric-only pass, so `&amp;` becomes `&` as
	 * well — which is the price of it: a description that deliberately escaped a tag as
	 * `&lt;br&gt;` to show it as text comes out as a real tag. Nothing in this shop's
	 * data does that, and the umlauts are in all of it.
	 *
	 * **Every run of whitespace becomes one space**, so the field never spans a physical
	 * line. A quoted field holding a line break is valid RFC 4180 and every real parser
	 * reads it, but it turns one record into several lines for anything splitting the
	 * file by line first — and the feed this has to look like runs its bullet lines
	 * together for exactly that reason. Nothing is lost: whitespace between tags is
	 * insignificant in HTML, and `<br>` still carries the breaks.
	 *
	 * @param \WC_Product      $product        The product.
	 * @param \WC_Product|null $parent_product Its parent, when it has one.
	 * @return string The description.
	 */
	private static function description( \WC_Product $product, $parent_product ) {
		$value = self::inherited( $product, $parent_product, 'get_description' );

		if ( '' === $value ) {
			return '';
		}

		$value     = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$collapsed = preg_replace( '/\s+/', ' ', $value );

		// preg_replace() returns null on a string it cannot walk; keep what we have.
		return trim( null === $collapsed ? $value : $collapsed );
	}

	/**
	 * The product's manufacturer.
	 *
	 * WooCommerce's own brands taxonomy, and the first term when a product carries
	 * several — the column holds one name. The terms live on the parent post, so a
	 * variation is asked about through it.
	 *
	 * @param \WC_Product      $product The product.
	 * @param \WC_Product|null $parent_product Its parent, when it has one.
	 * @return string Brand name, or an empty string.
	 */
	private static function brand( \WC_Product $product, $parent_product ) {
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return '';
		}

		$ids = array( $product->get_id() );

		if ( $parent_product instanceof \WC_Product ) {
			$ids[] = $parent_product->get_id();
		}

		foreach ( $ids as $id ) {
			$terms = get_the_terms( $id, self::BRAND_TAXONOMY );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				return (string) $terms[0]->name;
			}
		}

		return '';
	}

	/**
	 * What the customer pays for the product, net of tax.
	 *
	 * @param \WC_Product $product The product.
	 * @return string Price to four decimal places, or an empty string when it has none.
	 */
	private static function price( \WC_Product $product ) {
		$price = $product->get_price();

		if ( '' === $price || null === $price ) {
			return '';
		}

		$net = wc_get_price_excluding_tax( $product );

		return is_numeric( $net ) ? self::amount( $net ) : '';
	}

	/**
	 * The recommended retail price the shop's product sync recorded.
	 *
	 * @param \WC_Product      $product The product.
	 * @param \WC_Product|null $parent_product Its parent, when it has one.
	 * @return string The price, or an empty string when none is stored.
	 */
	private static function msrp( \WC_Product $product, $parent_product ) {
		$value = (string) $product->get_meta( self::MSRP_META, true );

		if ( '' === $value && $parent_product instanceof \WC_Product ) {
			$value = (string) $parent_product->get_meta( self::MSRP_META, true );
		}

		if ( '' === $value ) {
			return '';
		}

		// A shop that stores something other than a number keeps whatever it stored.
		return is_numeric( $value ) ? self::amount( $value ) : $value;
	}

	/**
	 * Format an amount the way the feed does.
	 *
	 * @param float|string $value The amount.
	 * @return string Fixed-point, with a full stop and no thousands separator.
	 */
	private static function amount( $value ) {
		return number_format( (float) $value, self::DECIMALS, '.', '' );
	}

	/**
	 * The main image and the gallery, padded to a fixed number of columns.
	 *
	 * The padding is what keeps every row the same width as the header. A product with
	 * two gallery images and one with nine have to produce the same number of fields, or
	 * the file is ragged and the consumer reads the next row's first column as an image.
	 *
	 * @param \WC_Product      $product The product.
	 * @param \WC_Product|null $parent_product Its parent, when it has one.
	 * @return string[] The main image URL followed by exactly IMAGE_COLUMNS gallery URLs.
	 */
	private static function image_urls( \WC_Product $product, $parent_product ) {
		$main_id = (int) $product->get_image_id();
		$gallery = $product->get_gallery_image_ids();

		if ( $parent_product instanceof \WC_Product ) {
			if ( 0 === $main_id ) {
				$main_id = (int) $parent_product->get_image_id();
			}

			if ( empty( $gallery ) ) {
				$gallery = $parent_product->get_gallery_image_ids();
			}
		}

		$urls = array();

		foreach ( array_slice( (array) $gallery, 0, self::IMAGE_COLUMNS ) as $id ) {
			$urls[] = self::attachment_url( (int) $id );
		}

		return array_merge(
			array( self::attachment_url( $main_id ) ),
			array_pad( $urls, self::IMAGE_COLUMNS, '' )
		);
	}

	/**
	 * An attachment's URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string URL, or an empty string.
	 */
	private static function attachment_url( $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * One line of the file.
	 *
	 * @param array $values Values, in column order.
	 * @return string The line, without its terminator.
	 */
	private static function line( array $values ) {
		return implode( self::DELIMITER, array_map( array( __CLASS__, 'field' ), $values ) );
	}

	/**
	 * One field, quoted only where RFC 4180 requires it.
	 *
	 * **Deliberately not `fputcsv()`**, which is what this was written with first. PHP
	 * quotes any field containing a space, so `EK / VK1` came out of the header as
	 * `"EK / VK1"` and every product name on the site was quoted too. Both files parse
	 * to the same values, but the feed this one has to look like quotes nothing it does
	 * not have to — and a consumer matching the header as a literal string sees a
	 * different column name. So the rule here is the standard's own: quote only for the
	 * delimiter, a double quote, or a line break, and double any quote inside.
	 *
	 * Line breaks are normalised in one pass rather than by replacing `\n` with
	 * `\r\n`, because a description that already holds CRLF would otherwise come out as
	 * CR CR LF. Converting the breaks inside a quoted description is what RFC 4180 asks
	 * for.
	 *
	 * @param mixed $value The value.
	 * @return string The field.
	 */
	private static function field( $value ) {
		$value = (string) $value;
		$value = str_replace( "\n", "\r\n", str_replace( array( "\r\n", "\r" ), "\n", $value ) );

		if ( false === strpbrk( $value, self::DELIMITER . "\"\r\n" ) ) {
			return $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
