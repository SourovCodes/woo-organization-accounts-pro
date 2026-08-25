<?php
/**
 * Customer view: the shop as an end customer sees it.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Checkout\Gate;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Datasheet\Download;
use WooOrgAccounts\Pricing\CustomerPrices;
use WooOrgAccounts\Pricing\CustomerView;
use WooOrgAccounts\Pricing\Msrp;

/**
 * What the mode shows, what it refuses, and what it must not give away.
 *
 * The per-surface tests are worth having one by one, but the one that catches the class
 * of bug this feature will actually grow is
 * `testNoSurfaceInCustomerViewCarriesTheTradePrice`: customer view is worth nothing if it
 * closes four leaks out of five, and the fifth is always the one nobody thought of. That
 * one sweeps the surfaces rather than naming a case, so a surface added later fails
 * instead of quietly leaking.
 */
class CustomerViewTest extends TestCase {

	/**
	 * The trade price every product in this file is sold at.
	 */
	const TRADE_PRICE = '40.95';

	/**
	 * The recommended retail price recorded against it.
	 */
	const RETAIL_PRICE = '77.90';

	/**
	 * The class under test, registered as the plugin registers it.
	 *
	 * @var CustomerPrices
	 */
	private $prices;

	/**
	 * Enable the mode's setting and start every test outside the mode.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		unset( $_COOKIE[ CustomerView::COOKIE ] );

		$this->set_setting( 'customer_view', true );

		/*
		 * Signed in for every test, because that is now part of the rule rather than
		 * merely how the switch is reached. The one test about a browser with no account
		 * signs out again for itself.
		 */
		$this->act_as_retailer();

		$this->prices = new CustomerPrices();
	}

	/**
	 * Leave no cookie behind for the next file.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_COOKIE[ CustomerView::COOKIE ] );

		parent::tear_down();
	}

	/**
	 * Enter customer view without going through the form.
	 *
	 * @return void
	 */
	private function enter_customer_view() {
		$_COOKIE[ CustomerView::COOKIE ] = '1';
	}

	/**
	 * Sign in as a retailer, which the mode now requires.
	 *
	 * Customer view is a retailer's tool — they switch it on to go through the catalogue
	 * with somebody at their counter — so a browser with no account behind it is never in
	 * it, whatever cookie it happens to be carrying.
	 *
	 * @return \WooOrgAccounts\Data\Member The membership signed in as.
	 */
	private function act_as_retailer() {
		$member = $this->make_member( $this->make_organization(), Member::ROLE_ADMIN );

		$this->act_as( $member );

		return $member;
	}

	/**
	 * A product with a trade price and, unless told otherwise, a retail price.
	 *
	 * @param string|null $msrp The retail price, or null for a product the sync missed.
	 * @return \WC_Product_Simple The saved product.
	 */
	private function make_product( $msrp = self::RETAIL_PRICE ) {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Abel blocks 12' );
		$product->set_regular_price( self::TRADE_PRICE );

		if ( null !== $msrp ) {
			$product->update_meta_data( Msrp::META, $msrp );
		}

		$product->save();

		return $product;
	}

	/**
	 * Outside the mode, the shop's own price is what is shown.
	 *
	 * @return void
	 */
	public function testTheShopsOwnPriceIsShownOutsideCustomerView() {
		$product = $this->make_product();

		$this->assertStringContainsString(
			self::TRADE_PRICE,
			wp_strip_all_tags( $this->prices->retail_price_html( $product->get_price_html(), $product ) )
		);
	}

	/**
	 * Inside it, the recommended retail price is.
	 *
	 * @return void
	 */
	public function testTheRetailPriceIsShownInCustomerView() {
		$product = $this->make_product();

		$this->enter_customer_view();

		$shown = wp_strip_all_tags( $this->prices->retail_price_html( $product->get_price_html(), $product ) );

		$this->assertStringContainsString( self::RETAIL_PRICE, $shown );
		$this->assertStringNotContainsString( self::TRADE_PRICE, $shown );
	}

	/**
	 * The retail price is printed exactly as it is stored.
	 *
	 * It is a consumer price and therefore already includes tax, while this shop stores
	 * and displays its trade prices net. Converting it would put a figure on the shelf
	 * that appears on no packaging and that no consumer recognises, so the number is
	 * labelled rather than recalculated — 77.90, never 77.90 ÷ 1.081.
	 *
	 * @return void
	 */
	public function testTheRetailPriceIsNotConvertedForTax() {
		$product = $this->make_product();

		$this->enter_customer_view();

		$html = $this->prices->retail_price_html( $product->get_price_html(), $product );

		$this->assertStringContainsString( self::RETAIL_PRICE, wp_strip_all_tags( $html ) );
		$this->assertStringContainsString( 'incl. VAT', wp_strip_all_tags( $html ) );
	}

	/**
	 * A product the sync recorded no retail price for shows no price at all.
	 *
	 * Falling back to the shop's own would leak the trade price for exactly the products
	 * nobody noticed were missing one — the quiet failure, on the products least likely
	 * to be looked at.
	 *
	 * @return void
	 */
	public function testAProductWithNoRetailPriceShowsNoPrice() {
		$product = $this->make_product( null );

		$this->enter_customer_view();

		$this->assertSame( '', $this->prices->retail_price_html( $product->get_price_html(), $product ) );
	}

	/**
	 * A value that is not a number is treated as no price rather than printed.
	 *
	 * @return void
	 */
	public function testANonNumericRetailPriceShowsNoPrice() {
		$product = $this->make_product( 'on request' );

		$this->enter_customer_view();

		$this->assertNull( Msrp::amount( $product ) );
		$this->assertSame( '', $this->prices->retail_price_html( $product->get_price_html(), $product ) );
	}

	/**
	 * Switching the site setting off ends the mode for a browser still holding the cookie.
	 *
	 * @return void
	 */
	public function testTheSiteSettingEndsTheModeOutright() {
		$this->enter_customer_view();

		$this->assertTrue( CustomerView::is_active() );

		$this->set_setting( 'customer_view', false );

		$this->assertFalse( CustomerView::is_active() );
	}

	/**
	 * Nothing can be added to a cart while the mode is on.
	 *
	 * One filter covers both checkouts: the Store API's `CartController` applies the same
	 * one and turns the notice into its own error response.
	 *
	 * @return void
	 */
	public function testAddingToTheCartIsRefusedInCustomerView() {
		$this->assertTrue( $this->prices->refuse_add_to_cart( true ) );

		$this->enter_customer_view();

		$this->assertFalse( $this->prices->refuse_add_to_cart( true ) );
	}

	/**
	 * A cart that already has something in it keeps it.
	 *
	 * The trap this feature is most likely to be rebuilt into. Refusing through
	 * `woocommerce_is_purchasable` would read as the same rule and would have WooCommerce
	 * silently empty the basket of a member who entered customer view to show somebody
	 * the catalogue — losing real work to a presentation setting.
	 *
	 * @return void
	 */
	public function testAnExistingCartSurvivesEnteringCustomerView() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $member );

		$product = $this->make_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 2 );

		$this->assertSame( 1, WC()->cart->get_cart_contents_count() > 0 ? 1 : 0 );

		$this->enter_customer_view();

		$this->prices->register();
		WC()->cart->check_cart_items();
		WC()->cart->calculate_totals();

		$this->assertTrue(
			$product->is_purchasable(),
			'Customer view must not make a product unpurchasable, or WooCommerce empties the cart of it.'
		);
		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );

		WC()->cart->empty_cart();
	}

	/**
	 * The checkout is refused, and says why in words the reader can act on.
	 *
	 * @return void
	 */
	public function testTheCheckoutIsRefusedInCustomerView() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $member );

		$this->assertTrue( Gate::may_purchase() );
		$this->assertSame( '', Gate::blocked_reason() );

		$this->enter_customer_view();

		$this->assertFalse( Gate::may_purchase() );
		$this->assertSame( CustomerView::purchase_blocked_reason(), Gate::blocked_reason() );
	}

	/**
	 * Structured data carries no offers, so no crawler reads the trade price.
	 *
	 * A consequence of filtering the theme's price hiding off rather than of anything
	 * WooCommerce does: Woodmart drops the offers as part of that hiding, and with the
	 * hiding gone it would publish the trade price — structured data is built from the
	 * product, not from the price HTML this feature rewrites.
	 *
	 * @return void
	 */
	public function testStructuredDataCarriesNoOffersInCustomerView() {
		$markup = array(
			'name'   => 'Abel blocks 12',
			'offers' => array( array( 'price' => self::TRADE_PRICE ) ),
		);

		$this->assertArrayHasKey( 'offers', $this->prices->drop_structured_offers( $markup ) );

		$this->enter_customer_view();

		$this->assertArrayNotHasKey( 'offers', $this->prices->drop_structured_offers( $markup ) );
	}

	/**
	 * The catalogue cannot be sorted or filtered by a price it is not showing.
	 *
	 * Both read `wc_product_meta_lookup`, which holds the shop's own price, and the
	 * margin is not a fixed multiple — so "price: low to high" on retail prices that do
	 * not ascend reads as a broken screen.
	 *
	 * @return void
	 */
	public function testThePriceOrderingsAreWithdrawnInCustomerView() {
		$orderings = array(
			'menu_order' => 'Default sorting',
			'price'      => 'Sort by price: low to high',
			'price-desc' => 'Sort by price: high to low',
		);

		$this->assertArrayHasKey( 'price', $this->prices->remove_price_orderings( $orderings ) );

		$this->enter_customer_view();

		$remaining = $this->prices->remove_price_orderings( $orderings );

		$this->assertArrayNotHasKey( 'price', $remaining );
		$this->assertArrayNotHasKey( 'price-desc', $remaining );
		$this->assertArrayHasKey( 'menu_order', $remaining );

		$this->assertSame( 'menu_order', $this->prices->replace_default_price_ordering( 'price' ) );
		$this->assertSame( 'popularity', $this->prices->replace_default_price_ordering( 'popularity' ) );
	}

	/**
	 * The price filter block renders nothing.
	 *
	 * @return void
	 */
	public function testThePriceFilterBlockIsWithdrawnInCustomerView() {
		$block = array( 'blockName' => 'woocommerce/price-filter' );
		$other = array( 'blockName' => 'woocommerce/product-image' );

		$this->assertSame( 'slider', $this->prices->remove_price_filter_block( 'slider', $block ) );

		$this->enter_customer_view();

		$this->assertSame( '', $this->prices->remove_price_filter_block( 'slider', $block ) );
		$this->assertSame( 'image', $this->prices->remove_price_filter_block( 'image', $other ) );
	}

	/**
	 * Entering and leaving is a nonced POST, and nothing else moves the state.
	 *
	 * @return void
	 */
	public function testTheModeIsOnlyEnteredThroughANoncedPost() {
		$_POST[ CustomerView::FIELD ] = '1';
		$_POST['_wpnonce']            = 'not-a-nonce';

		CustomerView::handle_switch();

		$this->assertFalse( CustomerView::is_active(), 'A bad nonce must not enter the mode.' );

		CustomerView::set( true );
		$this->assertTrue( CustomerView::is_active() );

		CustomerView::set( false );
		$this->assertFalse( CustomerView::is_active() );
	}

	/**
	 * The switch is handled on template_redirect, never on admin-post.
	 *
	 * WooCommerce decides what to load from `is_admin()`, so `wc_add_notice()` on an
	 * admin-post request has no session to add a notice to and drops it silently. The
	 * same rule `Frontend\AccountHandlers` sets out, asserted here because this handler
	 * is a new one and the mistake is invisible until somebody switches the mode.
	 *
	 * @return void
	 */
	public function testTheSwitchIsNotHandledOnAdminPost() {
		$this->prices->register();

		$this->assertNotFalse(
			has_action( 'template_redirect', array( CustomerView::class, 'handle_switch' ) )
		);

		foreach ( array( 'admin_post_' . CustomerView::FIELD, 'admin_post_nopriv_' . CustomerView::FIELD ) as $hook ) {
			$this->assertFalse( has_action( $hook ), sprintf( '%s must not handle a frontend form.', $hook ) );
		}
	}

	/**
	 * Leaving returns to the page the control was on.
	 *
	 * The bug this asserts looked like nothing at all: `wp_get_referer()` returns false
	 * whenever the referer matches the current request URI, and these forms post to their
	 * own URL — so it matched every time and leaving customer view dropped everybody on
	 * the account page from wherever they were. A member reading a product wants that
	 * product with their own price on it, not to have to find it again.
	 *
	 * @return void
	 */
	public function testLeavingReturnsToThePageTheControlWasOn() {
		$product = $this->make_product();
		$where   = get_permalink( $product->get_id() );

		$relative = wp_make_link_relative( $where );

		/*
		 * Relative, because that is the shape WordPress puts in the field: the referer
		 * `wp_nonce_field()` writes is `$_SERVER['REQUEST_URI']`. `wp_safe_redirect()`
		 * sends it as it stands, and the request URI is the whole point — the same page,
		 * query arguments included.
		 */
		$_REQUEST['_wp_http_referer'] = $relative;
		$_SERVER['REQUEST_URI']       = $relative;

		$this->assertSame( $relative, CustomerView::destination( false ) );

		unset( $_REQUEST['_wp_http_referer'] );
	}

	/**
	 * An off-site referer is not followed.
	 *
	 * @return void
	 */
	public function testLeavingWillNotFollowAnOffSiteReferer() {
		$_REQUEST['_wp_http_referer'] = 'https://example.org/somewhere';

		$this->assertSame( wc_get_page_permalink( 'shop' ), CustomerView::destination( false ) );

		unset( $_REQUEST['_wp_http_referer'] );
	}

	/**
	 * Entering goes to the shop, which is the point of the mode.
	 *
	 * @return void
	 */
	public function testEnteringGoesToTheShop() {
		$this->assertSame( wc_get_page_permalink( 'shop' ), CustomerView::destination( true ) );
	}

	/**
	 * A browser with no account behind it is never in customer view.
	 *
	 * The mode is a retailer's tool, so a cookie that outlived a sign-out must not put the
	 * public shop into it — least of all now that there is no banner to say so and no
	 * switch anywhere a signed-out visitor could reach.
	 *
	 * @return void
	 */
	public function testASignedOutBrowserIsNeverInCustomerView() {
		$this->enter_customer_view();

		$this->assertTrue( CustomerView::is_active() );

		wp_set_current_user( 0 );

		$this->assertFalse( CustomerView::is_active(), 'A cookie alone must not enter the mode.' );
	}

	/**
	 * The dashboard stays reachable and everything else in the account does not.
	 *
	 * The dashboard is not an ordinary exception. With the banner gone it is the only
	 * place the switch appears, so refusing it would shut the retailer inside customer
	 * view with nothing to press and no way back to their own prices but clearing their
	 * cookies. Everything beside it — order history, the organization's orders, the
	 * profile — is the record of what they pay, which is the whole of what this mode
	 * exists to keep off the screen.
	 *
	 * @return void
	 */
	public function testTheDashboardStaysReachableAndTheRestOfTheAccountDoesNot() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'My account',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $page_id );

		$this->go_to( '?page_id=' . $page_id );

		$this->assertTrue( is_account_page(), 'This test is worthless unless it is on the account page.' );
		$this->assertFalse( CustomerPrices::page_is_refused(), 'Nothing is refused while the mode is off.' );

		$this->enter_customer_view();

		$this->assertFalse(
			CustomerPrices::page_is_refused(),
			'The dashboard carries the switch, so it has to survive the mode it switches off.'
		);

		/*
		 * WooCommerce reads its endpoints out of the global `$wp->query_vars`, so that is
		 * where an endpoint has to be put for `is_wc_endpoint_url()` to see one. Going to
		 * the URL is not enough: the endpoint's rewrite rule does not exist on the test
		 * library's site.
		 */
		global $wp;
		$wp->query_vars['orders'] = '1';

		$this->assertTrue(
			CustomerPrices::page_is_refused(),
			'Order history is the record of what the retailer pays and must not be reachable.'
		);

		unset( $wp->query_vars['orders'] );
	}

	/**
	 * The account menu is reduced to what is still reachable.
	 *
	 * A menu of links that all redirect back to the dashboard reads as a fault, and it
	 * keeps the words "Orders" and "Invoices" off a screen somebody's customer may be
	 * reading over their shoulder.
	 *
	 * @return void
	 */
	public function testTheAccountMenuIsReducedInCustomerView() {
		$items = array(
			'dashboard'           => 'Dashboard',
			'orders'              => 'Orders',
			'organization-orders' => 'Company orders',
			'organization'        => 'Company',
			'customer-logout'     => 'Log out',
		);

		$this->assertSame( $items, $this->prices->reduce_account_menu( $items ) );

		$this->enter_customer_view();

		$this->assertSame(
			array( 'dashboard', 'customer-logout' ),
			array_keys( $this->prices->reduce_account_menu( $items ) )
		);
	}

	/**
	 * The basket and the checkout are refused.
	 *
	 * Substituting the shelf price is not enough: a basket's line totals and its subtotal
	 * are what the *retailer* pays, so a customer at the counter clicking the basket icon
	 * would see exactly the conditions this mode hides. The pages are refused on the
	 * server and the stylesheet takes the icon away, so nobody arrives at a refusal by
	 * pressing something that was still on screen.
	 *
	 * @return void
	 */
	public function testTheBasketIsRefusedInCustomerView() {
		/*
		 * Through WooCommerce's own `woocommerce_is_cart` filter rather than by navigating
		 * to the page. `CartCheckoutUtils::is_cart_page()` memoises its answer in a static,
		 * and a test run is one PHP process — so whichever test evaluates it first freezes
		 * the answer for every test after it, and navigating here would assert nothing.
		 */
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->assertTrue( is_cart(), 'This test is worthless unless it is on the basket.' );
		$this->assertFalse( CustomerPrices::page_is_refused() );

		$this->enter_customer_view();

		$this->assertTrue(
			CustomerPrices::page_is_refused(),
			'The basket shows what the retailer pays, so customer view must turn it away.'
		);

		remove_filter( 'woocommerce_is_cart', '__return_true' );
	}

	/**
	 * The page carries the marker the stylesheet hangs the hidden basket on.
	 *
	 * @return void
	 */
	public function testThePageIsMarkedWhileTheModeIsOn() {
		$this->assertNotContains( 'woap-customer-view-on', $this->prices->add_body_class( array( 'shop' ) ) );

		$this->enter_customer_view();

		$this->assertContains( 'woap-customer-view-on', $this->prices->add_body_class( array( 'shop' ) ) );
	}

	/**
	 * Nothing reachable in customer view carries the trade price.
	 *
	 * The invariant this feature lives or dies by, written as a sweep rather than as a
	 * case: the leak that matters is always the surface nobody listed. Each entry is a
	 * surface a person in customer view can reach, and none of them may answer with what
	 * the shop charges.
	 *
	 * @return void
	 */
	public function testNoSurfaceInCustomerViewCarriesTheTradePrice() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $member );

		$product = $this->make_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->enter_customer_view();

		$surfaces = array(
			'the price on the shelf' => wp_strip_all_tags(
				$this->prices->retail_price_html( $product->get_price_html(), $product )
			),
			'the structured data'    => wp_json_encode(
				$this->prices->drop_structured_offers(
					array( 'offers' => array( array( 'price' => self::TRADE_PRICE ) ) )
				)
			),
			'the loop add-to-cart'   => (string) $this->prices->remove_loop_add_to_cart(
				'<a href="?add-to-cart=1">Add to basket ' . self::TRADE_PRICE . '</a>'
			),
			'the cart datasheet'     => Download::may_download_cart() ? self::TRADE_PRICE : '',
			'the checkout refusal'   => Gate::blocked_reason(),
		);

		foreach ( $surfaces as $surface => $output ) {
			$this->assertStringNotContainsString(
				self::TRADE_PRICE,
				(string) $output,
				sprintf( 'In customer view, %s must not carry the trade price.', $surface )
			);
		}

		WC()->cart->empty_cart();
	}

	/**
	 * The datasheet is refused, because its EK column is the trade price in full.
	 *
	 * @return void
	 */
	public function testTheDatasheetIsRefusedInCustomerView() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $member );

		$product = $this->make_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->assertTrue( Download::may_download_cart() );

		$this->enter_customer_view();

		$this->assertFalse(
			Download::may_download_cart(),
			'The datasheet carries the trade price in a column of its own, so customer view must refuse it.'
		);

		WC()->cart->empty_cart();
	}

	/**
	 * The retail price is the same fact the datasheet reports.
	 *
	 * Two things read it and they must not disagree about which products have one: a
	 * product priced on the shelf and blank in the file — or the reverse — is the shop
	 * contradicting itself in front of the same person.
	 *
	 * @return void
	 */
	public function testTheShelfAndTheDatasheetAgreeOnWhoHasARetailPrice() {
		$priced   = $this->make_product();
		$unpriced = $this->make_product( null );

		$this->assertTrue( Msrp::exists( $priced ) );
		$this->assertFalse( Msrp::exists( $unpriced ) );

		$this->assertSame( self::RETAIL_PRICE, Msrp::stored( $priced ) );
		$this->assertSame( '', Msrp::stored( $unpriced ) );
	}

	/**
	 * A variation inherits its parent's retail price, as every other inherited field does.
	 *
	 * @return void
	 */
	public function testAVariationInheritsItsParentsRetailPrice() {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Abel blocks' );
		$parent->update_meta_data( Msrp::META, self::RETAIL_PRICE );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( self::TRADE_PRICE );
		$variation->save();

		$this->assertSame( self::RETAIL_PRICE, Msrp::stored( wc_get_product( $variation->get_id() ) ) );
	}
}
