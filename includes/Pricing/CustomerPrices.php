<?php
/**
 * Every place customer view has to be applied.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Pricing;

use WooOrgAccounts\Frontend\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the recommended retail price, suppresses buying, and closes what would otherwise
 * show a retailer's own conditions to the person standing next to them.
 *
 * The rule itself is `CustomerView::is_active()`. What is here is the list of places it
 * has to be applied, which is the same split `Checkout\Gate` documents — and for the same
 * reason: there are more of them than there look, and the ones that are missed are not
 * cosmetic.
 *
 * **Who this is for decides what has to be hidden.** A retailer turns it on to go through
 * the catalogue with a customer at their counter, on their own screen. So it is not
 * enough to change the price on the shelf: the basket carries line totals at what the
 * retailer pays, the order history carries every price they have ever paid, and the
 * datasheet carries the trade price in a column of its own. Each of those is one stray
 * click away from the customer they are serving, so each is refused rather than restyled.
 *
 * **There is no banner, deliberately.** An earlier version announced the mode on every
 * page, which is right for a shop being transparent with a consumer and wrong here — the
 * customer at the counter is being shown a catalogue, not a disclosure. What replaces it
 * is the switch on the retailer's own dashboard, which reports the state plainly to the
 * one person who needs to know it. That is also why the dashboard stays reachable while
 * every other account screen does not: it is the way out, and with the banner gone it is
 * the only one.
 */
class CustomerPrices {

	/**
	 * Catalogue orderings that sort by a price customer view does not show.
	 *
	 * @var string[]
	 */
	const PRICE_ORDERINGS = array( 'price', 'price-desc' );

	/**
	 * Blocks that filter the catalogue by a price customer view does not show.
	 *
	 * @var string[]
	 */
	const PRICE_FILTER_BLOCKS = array(
		'woocommerce/price-filter',
		'woocommerce/product-filter-price',
		'woocommerce/collection-price-filter',
	);

	/**
	 * Account menu items that survive customer view.
	 *
	 * The dashboard, because it carries the switch, and signing out, because refusing
	 * somebody the way out of their own account would be worse than anything this mode
	 * hides.
	 *
	 * @var string[]
	 */
	const ACCOUNT_MENU_KEPT = array( 'dashboard', 'customer-logout' );

	/**
	 * Register the hooks.
	 *
	 * Every callback asks `CustomerView::is_active()` for itself rather than the whole
	 * set being registered conditionally. The mode is entered part-way through a request
	 * — `handle_switch()` runs on `template_redirect`, long after `plugins_loaded` — so a
	 * registration decided up front would answer for the request that switched it on
	 * using the state it had before.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( CustomerView::class, 'handle_switch' ) );

		add_action( 'template_redirect', array( $this, 'send_no_cache_headers' ), 1 );
		add_action( 'template_redirect', array( $this, 'refuse_pages' ), 5 );
		add_action( 'template_redirect', array( $this, 'remove_add_to_cart_output' ), 20 );

		add_filter( 'woocommerce_get_price_html', array( $this, 'retail_price_html' ), 10, 2 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'drop_structured_offers' ) );

		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'remove_loop_add_to_cart' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'refuse_add_to_cart' ) );

		add_filter( 'woocommerce_catalog_orderby', array( $this, 'remove_price_orderings' ) );
		add_filter( 'woocommerce_default_catalog_orderby', array( $this, 'replace_default_price_ordering' ) );
		add_filter( 'widget_display_callback', array( $this, 'remove_price_filter_widget' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'remove_price_filter_block' ), 10, 2 );

		/*
		 * After `Frontend\MyAccount::add_menu_items()`, which is on the default priority:
		 * this takes items away and that one puts the organization screens in, so running
		 * first would leave exactly those behind.
		 */
		add_filter( 'woocommerce_account_menu_items', array( $this, 'reduce_account_menu' ), 20 );

		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_toggle' ) );
	}

	/**
	 * Keep a request in customer view out of every cache.
	 *
	 * Two versions of every page exist while this feature is on and they differ in the
	 * one respect the shop cares most about. A full-page cache serving the retail version
	 * to an ordinary customer would hide the trade prices they are entitled to; serving
	 * the ordinary version to somebody in customer view would show a walk-in customer the
	 * price their retailer pays. `DONOTCACHEPAGE` is the constant every major caching
	 * plugin honours, and `nocache_headers()` covers the ones in front of it.
	 *
	 * @return void
	 */
	public function send_no_cache_headers() {
		if ( ! CustomerView::is_active() || headers_sent() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Not ours to name: it is the constant the caching plugins in front of this site read, and a prefixed one would be read by nothing.
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}

	/**
	 * The retail price, in place of the shop's own.
	 *
	 * **The stored figure is printed as it is.** It already includes tax, which is what a
	 * consumer price means, and this shop displays its trade prices excluding tax — so
	 * the number is labelled rather than converted. Converting it would put a figure on
	 * the shelf that no consumer recognises and that appears on no packaging.
	 *
	 * **A product with no recommended retail price shows no price at all.** Falling back
	 * to the shop's own would show the retailer's buying price for exactly the products
	 * nobody noticed the sync had missed — the quiet failure, on the products least
	 * likely to be looked at.
	 *
	 * @param string      $price_html The price WooCommerce was going to show.
	 * @param \WC_Product $product    The product.
	 * @return string The retail price, or an empty string.
	 */
	public function retail_price_html( $price_html, $product ) {
		if ( ! CustomerView::is_active() || ! $product instanceof \WC_Product ) {
			return $price_html;
		}

		$amount = Msrp::amount( $product );

		if ( null === $amount ) {
			return '';
		}

		$html = wc_price( $amount ) . ' <small class="woocommerce-price-suffix">'
			. esc_html__( 'incl. VAT', 'woo-organization-accounts-pro' ) . '</small>';

		/**
		 * Filter the price shown in customer view.
		 *
		 * @since 0.15.0
		 *
		 * @param string      $html    The retail price as markup.
		 * @param float       $amount  The recommended retail price, including tax.
		 * @param \WC_Product $product The product.
		 */
		return (string) apply_filters( 'woo_org_accounts_customer_price_html', $html, $amount, $product );
	}

	/**
	 * Publish no prices to a crawler while the mode is on.
	 *
	 * Structured data is built from the product rather than from the price HTML this
	 * class rewrites, so left alone it would publish the trade price into the page's
	 * JSON-LD. Dropping it is the honest answer either way: the shop's own price is not
	 * for publication and a recommended retail price is not an offer the shop is making.
	 *
	 * @param array $markup The structured data.
	 * @return array The markup, without its offers.
	 */
	public function drop_structured_offers( $markup ) {
		if ( ! CustomerView::is_active() || ! is_array( $markup ) ) {
			return $markup;
		}

		unset( $markup['offers'] );

		return $markup;
	}

	/**
	 * Take the add-to-cart buttons off the catalogue and the product page.
	 *
	 * Presentation only — `refuse_add_to_cart()` is what actually stops an item reaching
	 * a basket. Removed at request time because that is when the mode is known, and after
	 * WooCommerce has registered its own template hooks.
	 *
	 * @return void
	 */
	public function remove_add_to_cart_output() {
		if ( ! CustomerView::is_active() ) {
			return;
		}

		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	/**
	 * Blank the loop's add-to-cart link.
	 *
	 * A theme that builds its own product card calls this filter rather than the action
	 * removed above — Woodmart is one of them — so both seams are taken.
	 *
	 * @param string $link The link markup.
	 * @return string The markup, or an empty string.
	 */
	public function remove_loop_add_to_cart( $link ) {
		return CustomerView::is_active() ? '' : $link;
	}

	/**
	 * Refuse to add anything to a basket while the mode is on.
	 *
	 * This is the server-side refusal, and **one filter covers both checkouts**: the
	 * Store API's `CartController::validate_add_to_cart()` applies the same filter and
	 * turns the notice below into its own error response, so the Cart and Checkout blocks
	 * are refused by the same line as the classic shop.
	 *
	 * **`woocommerce_is_purchasable` is deliberately not used for this.** WooCommerce
	 * silently removes anything that is not purchasable from the basket, so a retailer who
	 * entered customer view to show somebody the catalogue would come back to an emptied
	 * basket — losing real work to a presentation setting. Refusing the *addition* leaves
	 * what is already there untouched, which is what makes it safe to leave the basket
	 * alone rather than clearing it.
	 *
	 * @param bool $passed Whether validation has passed so far.
	 * @return bool False while the mode is on.
	 */
	public function refuse_add_to_cart( $passed ) {
		if ( ! CustomerView::is_active() ) {
			return $passed;
		}

		$message = CustomerView::purchase_blocked_reason();

		if ( function_exists( 'wc_add_notice' ) && ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}

		return false;
	}

	/**
	 * Take the price orderings off the catalogue.
	 *
	 * Both of them sort by `wc_product_meta_lookup`, which holds the shop's own price and
	 * is not what is on the shelf in customer view. A shop sorted "price: low to high"
	 * showing retail prices that do not ascend reads as a broken screen, and the margin
	 * is not a fixed multiple, so the two orders genuinely differ.
	 *
	 * @param array $orderings The orderings on offer.
	 * @return array The orderings, without the price ones.
	 */
	public function remove_price_orderings( $orderings ) {
		if ( ! CustomerView::is_active() || ! is_array( $orderings ) ) {
			return $orderings;
		}

		foreach ( self::PRICE_ORDERINGS as $ordering ) {
			unset( $orderings[ $ordering ] );
		}

		return $orderings;
	}

	/**
	 * Fall back when the shop's default ordering is one that has just been removed.
	 *
	 * Without this the select shows nothing selected and the catalogue is still sorted by
	 * a price nobody can see.
	 *
	 * @param string $ordering The shop's default ordering.
	 * @return string An ordering that is still on offer.
	 */
	public function replace_default_price_ordering( $ordering ) {
		if ( ! CustomerView::is_active() ) {
			return $ordering;
		}

		return in_array( $ordering, self::PRICE_ORDERINGS, true ) ? 'menu_order' : $ordering;
	}

	/**
	 * Take the price filter widget off the page.
	 *
	 * Same reason as the orderings: its range is the shop's own prices, so a slider set
	 * to 40–80 would return products whose shelf price reads 77–150.
	 *
	 * @param array      $instance The widget's settings.
	 * @param \WP_Widget $widget   The widget.
	 * @return array|false The settings, or false to render nothing.
	 */
	public function remove_price_filter_widget( $instance, $widget ) {
		if ( ! CustomerView::is_active() ) {
			return $instance;
		}

		return $widget instanceof \WC_Widget_Price_Filter ? false : $instance;
	}

	/**
	 * The same, for the block the widget became.
	 *
	 * @param string $block_content The rendered block.
	 * @param array  $block         The block being rendered.
	 * @return string The block, or an empty string.
	 */
	public function remove_price_filter_block( $block_content, $block ) {
		if ( ! CustomerView::is_active() || ! isset( $block['blockName'] ) ) {
			return $block_content;
		}

		return in_array( $block['blockName'], self::PRICE_FILTER_BLOCKS, true ) ? '' : $block_content;
	}

	/**
	 * Reduce the account menu to what is still reachable.
	 *
	 * Every other screen redirects back to the dashboard while the mode is on, and a menu
	 * of links that all lead to the same place reads as a fault. Leaving them out also
	 * keeps the words *Orders* and *Invoices* off a screen somebody's customer may be
	 * looking at over their shoulder.
	 *
	 * @param array $items The menu items.
	 * @return array The items that are still reachable.
	 */
	public function reduce_account_menu( $items ) {
		if ( ! CustomerView::is_active() || ! is_array( $items ) ) {
			return $items;
		}

		return array_intersect_key( $items, array_flip( self::ACCOUNT_MENU_KEPT ) );
	}

	/**
	 * Send somebody back to the shop from a page customer view does not serve.
	 *
	 * At priority 5, which is where `Frontend\MyAccount::refuse_address_endpoint()` runs
	 * and for the same reason: WooCommerce's own form handlers are on this action at the
	 * default priority, and refusing after them would refuse the screen while keeping
	 * whatever it submitted.
	 *
	 * @return void
	 */
	public function refuse_pages() {
		if ( ! self::page_is_refused() ) {
			return;
		}

		wc_add_notice(
			__( 'That page is not available while customer view is on. Turn it off in your account to see your own prices again.', 'woo-organization-accounts-pro' ),
			'notice'
		);

		$shop = wc_get_page_permalink( 'shop' );

		wp_safe_redirect( $shop ? $shop : home_url( '/' ) );
		exit;
	}

	/**
	 * Whether this request is a page customer view has to turn away.
	 *
	 * Three groups, and each is a place the retailer's own conditions are written down:
	 * the basket and the checkout, whose line totals are what *they* pay rather than what
	 * is on the shelf; and every account screen except the dashboard — order history,
	 * the organization's orders, the profile — which is the record of every price they
	 * have ever been charged.
	 *
	 * **The dashboard is the exception and has to be.** With no banner on the site it is
	 * the only place the switch appears, so refusing it would shut the retailer inside
	 * customer view with nothing to press. WooCommerce's account pages are the account
	 * page plus an endpoint, so "the dashboard" is exactly the account page carrying
	 * none.
	 *
	 * Separated from the redirect above so the decision can be asserted: a method ending
	 * in `exit` is not testable, which is the same separation `Datasheet\Sheet` keeps
	 * from `Datasheet\Download`.
	 *
	 * @return bool True when the page must be refused.
	 */
	public static function page_is_refused() {
		if ( ! CustomerView::is_active() ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return true;
		}

		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}

		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url();
	}

	/**
	 * Mark the page, so the stylesheet can take the basket off it.
	 *
	 * A class rather than a rule that is only printed while the mode is on, because the
	 * stylesheet is a static file and this is the one thing in it that depends on state.
	 *
	 * @param array $classes The body classes.
	 * @return array The classes, with ours added while the mode is on.
	 */
	public function add_body_class( $classes ) {
		if ( CustomerView::is_active() && is_array( $classes ) ) {
			$classes[] = 'woap-customer-view-on';
		}

		return $classes;
	}

	/**
	 * Load the stylesheet, and only where something of ours is on the page.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! CustomerView::is_offered() ) {
			return;
		}

		/*
		 * Everywhere while the mode is on, because the rule that takes the basket off the
		 * header applies to every page — and on the account page either way, because the
		 * switch is rendered there in both states.
		 */
		$on_account = function_exists( 'is_account_page' ) && is_account_page();

		if ( ! CustomerView::is_active() && ! $on_account ) {
			return;
		}

		wp_enqueue_style(
			'woap-customer-view',
			WOAP_PLUGIN_URL . 'assets/css/customer-view.css',
			array(),
			WOAP_VERSION
		);
	}

	/**
	 * The switch, on the retailer's own dashboard.
	 *
	 * Rendered in both states, because with no banner anywhere this is the only thing on
	 * the site that says which prices the shop is currently showing — and the only way
	 * back. It reports the state in words rather than as a checkbox somebody has to
	 * interpret, for the same reason the member form asks outright which of two things it
	 * is being told.
	 *
	 * @return void
	 */
	public function render_toggle() {
		if ( ! CustomerView::is_offered() ) {
			return;
		}

		$active = CustomerView::is_active();

		Templates::render(
			'customer-view/toggle.php',
			array(
				'active'   => $active,
				'fields'   => CustomerView::form_fields( ! $active ),
				'headline' => $active
					? __( 'Customer view is on', 'woo-organization-accounts-pro' )
					: __( 'Show a customer the retail prices', 'woo-organization-accounts-pro' ),
				'note'     => $active
					? __( 'Every price on the shop is the recommended retail price including VAT. Your own prices, your basket and your orders are hidden, and nothing can be ordered until you turn this off.', 'woo-organization-accounts-pro' )
					: __( 'Turn this on to go through the catalogue with a customer on your screen. Every price then shows the recommended retail price including VAT, and your own prices, your basket and your orders are hidden until you turn it off again.', 'woo-organization-accounts-pro' ),
				'button'   => $active
					? __( 'Turn off customer view', 'woo-organization-accounts-pro' )
					: __( 'Turn on customer view', 'woo-organization-accounts-pro' ),
			)
		);
	}
}
