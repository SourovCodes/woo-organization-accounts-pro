<?php
/**
 * The recommended retail price.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * One answer to "what does this product cost an end customer?".
 *
 * The shop's product sync records a recommended retail price against every product it
 * knows one for. Two things read it and they must not disagree about which products have
 * one: the datasheet's `UVP / VK3` column, which is what a reseller loads into their own
 * shop, and customer view, which is what their walk-in customer sees on the shelf. A
 * product priced in one and blank in the other would be the shop contradicting itself in
 * front of the same person.
 *
 * **A missing value is nothing, never `0`.** That is the datasheet's own rule and it
 * matters more here: a shop that has never recorded an RRP must not be made to look as
 * though it were giving the product away.
 *
 * **The stored figure is gross — it includes tax.** It is a consumer price, quoted the
 * way a consumer meets it, and it is not related by any fixed ratio to what the shop
 * charges a business: on the shop this was built against the RRP runs about 1.9 times
 * the net trade price, and the margin differs per product. So it is never derived from
 * the shop's own price and never converted; it is read, or it is absent.
 */
final class Msrp {

	/**
	 * Meta key the shop's product sync writes the recommended retail price to.
	 */
	const META = '_wksync_msrp';

	/**
	 * The stored value, exactly as the sync left it.
	 *
	 * A variation carries its own RRP where the sync recorded one and inherits the
	 * parent's otherwise, which is the same fallback every other inherited field in the
	 * datasheet takes.
	 *
	 * @param \WC_Product $product The product.
	 * @return string The stored value, or an empty string when none is recorded.
	 */
	public static function stored( \WC_Product $product ) {
		$value = (string) $product->get_meta( self::META, true );

		if ( '' !== $value ) {
			return $value;
		}

		$parent_product_id = $product->get_parent_id();

		if ( $parent_product_id <= 0 ) {
			return '';
		}

		$parent_product = wc_get_product( $parent_product_id );

		return $parent_product instanceof \WC_Product ? (string) $parent_product->get_meta( self::META, true ) : '';
	}

	/**
	 * The recommended retail price as a number.
	 *
	 * @param \WC_Product $product The product.
	 * @return float|null The price including tax, or null when none is recorded.
	 */
	public static function amount( \WC_Product $product ) {
		$value = self::stored( $product );

		/*
		 * A shop that stores something other than a number has stored something this
		 * cannot price a shelf with. The datasheet passes such a value through, because a
		 * file is a record of what the shop holds; a price tag is not, so it is treated
		 * as absent here rather than printed.
		 */
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Whether a product has a recommended retail price at all.
	 *
	 * @param \WC_Product $product The product.
	 * @return bool True when one is recorded and usable.
	 */
	public static function exists( \WC_Product $product ) {
		return null !== self::amount( $product );
	}

	/**
	 * How many published products the sync has recorded no retail price for.
	 *
	 * Every one of them shows no price at all in customer view, which is deliberate — the
	 * alternative leaks the trade price — but it is a fact about the *catalogue* rather
	 * than about the mode, and only the shop can fix it at the source. So the settings
	 * screen reports the number rather than leaving it to be discovered one product at a
	 * time by whoever is standing at the counter.
	 *
	 * A `WP_Query` rather than a hand-written statement: this asks about WordPress's own
	 * tables, and the plugin keeps SQL to `includes/Data/` and `Install`.
	 *
	 * @return int How many are missing one.
	 */
	public static function count_products_without() {
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Counting a reporting figure on one admin screen, behind manage_woocommerce.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}
}
