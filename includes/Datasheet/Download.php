<?php
/**
 * Downloading a product datasheet.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Datasheet;

use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the datasheet from a cart and from an order, and serves it.
 *
 * One file per cart and one per order, never per line: what a reseller wants is the data
 * for everything they just bought, and a button on every row would make them download
 * fourteen files and join them up themselves.
 *
 * **The frontend download is handled on `template_redirect`, not through
 * `admin-post.php`.** That is the rule `Frontend\AccountHandlers` sets out and its test
 * asserts: WooCommerce decides what to load from `is_admin()`, so nothing on
 * `admin-post.php` has a cart to read. wp-admin is the other way round — there
 * `admin_post_` is the right door, and `Admin\Import::handle_report()` already uses it —
 * so this registers both and hands both to one `serve()`.
 *
 * The links are ordinary GETs carrying a nonce, because two of the five places they
 * appear are the Actions column of a table, where a `<form>` would be the wrong shape.
 * Nothing is written, so a GET says what the request is.
 *
 * Authorisation reuses the answers that already exist rather than inventing a
 * capability. The cart asks `Context::can_purchase()`, which is what `Checkout\Gate`
 * asks before letting anyone near the cart at all. An order asks WooCommerce's own
 * `view_order`, which is what `Frontend\OrderDetails` asks and which routes through
 * `Capabilities::resolve_order_capabilities()` — so an organization admin gets a
 * colleague's order and a member of another organization does not. That is the
 * cross-tenant question, and it is already answered in one place.
 */
class Download {

	/**
	 * Query variable naming which datasheet is wanted.
	 */
	const QUERY_VAR = 'woap_datasheet';

	/**
	 * Query variable carrying the order ID.
	 */
	const ORDER_VAR = 'woap_order';

	/**
	 * The cart's datasheet.
	 */
	const SOURCE_CART = 'cart';

	/**
	 * An order's datasheet.
	 */
	const SOURCE_ORDER = 'order';

	/**
	 * The `admin_post_` action wp-admin downloads through.
	 */
	const ADMIN_ACTION = 'woap_datasheet';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_download' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'download_in_admin' ) );

		add_action( 'woocommerce_after_cart_table', array( $this, 'render_cart_button' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_button' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_orders_list_action' ), 10, 2 );
	}

	/**
	 * The label the button carries.
	 *
	 * @return string Translated label.
	 */
	public static function label() {
		return __( 'Download datasheet', 'woo-organization-accounts-pro' );
	}

	/**
	 * The label a table's Actions column carries, where there is no room for a sentence.
	 *
	 * @return string Translated label.
	 */
	public static function short_label() {
		return __( 'Datasheet', 'woo-organization-accounts-pro' );
	}

	/**
	 * The link that downloads the current cart's datasheet.
	 *
	 * Built on the site's front page rather than on the cart page so that the handler
	 * runs before any screen's own logic can.
	 *
	 * **Not `wp_nonce_url()`**, which returns its result already run through
	 * `esc_html()`. Every PHP caller escapes at the point of output anyway, so that
	 * would be one escape too many — and this URL is also published to the Cart block
	 * over the Store API, where React sets `href` as a DOM attribute rather than as
	 * markup. An attribute set that way is never entity-decoded, so a `&#038;` from
	 * `wp_nonce_url()` stays in the query string and the nonce arrives as part of the
	 * order ID.
	 *
	 * @return string Nonced URL.
	 */
	public static function cart_url() {
		return add_query_arg(
			array(
				self::QUERY_VAR => self::SOURCE_CART,
				'_wpnonce'      => wp_create_nonce( self::cart_nonce() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The link that downloads an order's datasheet.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Nonced URL.
	 */
	public static function order_url( \WC_Order $order ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => self::SOURCE_ORDER,
				self::ORDER_VAR => $order->get_id(),
				'_wpnonce'      => wp_create_nonce( self::order_nonce( $order->get_id() ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The same link, for shop staff on the order screen.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Nonced URL.
	 */
	public static function admin_order_url( \WC_Order $order ) {
		return add_query_arg(
			array(
				'action'        => self::ADMIN_ACTION,
				self::QUERY_VAR => self::SOURCE_ORDER,
				self::ORDER_VAR => $order->get_id(),
				'_wpnonce'      => wp_create_nonce( self::order_nonce( $order->get_id() ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Whether the current customer may download the cart's datasheet.
	 *
	 * Also false for an empty cart, so the button is never offered where the file would
	 * have nothing in it.
	 *
	 * @return bool True when the button belongs on the screen.
	 */
	public static function may_download_cart() {
		if ( ! Context::can_purchase() ) {
			return false;
		}

		$cart = WC()->cart;

		return $cart instanceof \WC_Cart && ! $cart->is_empty();
	}

	/**
	 * Whether the current user may download an order's datasheet.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool True when they may.
	 */
	public static function may_download_order( \WC_Order $order ) {
		return current_user_can( 'view_order', $order->get_id() );
	}

	/**
	 * Serve the datasheet when the frontend request is asking for one.
	 *
	 * @return void
	 */
	public function maybe_download() {
		$source = self::requested_source();

		if ( '' === $source ) {
			return;
		}

		$this->serve( $source, false );
	}

	/**
	 * Serve the datasheet a shop manager asked for in wp-admin.
	 *
	 * @return void
	 */
	public function download_in_admin() {
		$this->serve( self::requested_source(), true );
	}

	/**
	 * Print the button under the classic cart's table.
	 *
	 * @return void
	 */
	public function render_cart_button() {
		if ( ! self::may_download_cart() ) {
			return;
		}

		$this->render_button( self::cart_url(), self::label() );
	}

	/**
	 * Print the button under an order's items.
	 *
	 * Fires on the view-order screen and on order-received alike, both of which render
	 * the same template.
	 *
	 * @param \WC_Order $order The order being shown.
	 * @return void
	 */
	public function render_order_button( $order ) {
		if ( ! $order instanceof \WC_Order || ! self::may_download_order( $order ) ) {
			return;
		}

		$this->render_button( self::order_url( $order ), self::label() );
	}

	/**
	 * Add the datasheet to the actions on WooCommerce's own orders list.
	 *
	 * @param array     $actions The actions for this row.
	 * @param \WC_Order $order   The order.
	 * @return array Actions, with ours added.
	 */
	public function add_orders_list_action( $actions, $order ) {
		if ( ! $order instanceof \WC_Order || ! self::may_download_order( $order ) ) {
			return $actions;
		}

		$actions['woap-datasheet'] = array(
			'url'  => self::order_url( $order ),
			'name' => self::short_label(),
		);

		return $actions;
	}

	/**
	 * Print one download button.
	 *
	 * Woodmart already styles `.button`; `btn-style-bordered` picks its secondary
	 * variant, which is what this is beside the cart's own primary actions.
	 *
	 * @param string $url   Where it goes.
	 * @param string $label What it says.
	 * @return void
	 */
	private function render_button( $url, $label ) {
		printf(
			'<p class="woap-datasheet"><a href="%1$s" class="button btn-style-bordered woap-datasheet__link">%2$s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Which datasheet the request is asking for.
	 *
	 * @return string One of the source constants, or an empty string.
	 */
	private static function requested_source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding whether the request is ours at all; serve() checks the nonce before doing anything with it.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$source = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		return in_array( $source, array( self::SOURCE_CART, self::SOURCE_ORDER ), true ) ? $source : '';
	}

	/**
	 * The nonce action for the cart's datasheet.
	 *
	 * @return string Action.
	 */
	private static function cart_nonce() {
		return 'woap_datasheet_cart';
	}

	/**
	 * The nonce action for one order's datasheet.
	 *
	 * Bound to the order, so a link to one order's data is not also a link to another's.
	 *
	 * @param int $order_id The order.
	 * @return string Action.
	 */
	private static function order_nonce( $order_id ) {
		return 'woap_datasheet_order_' . (int) $order_id;
	}

	/**
	 * Check the request, build the file and send it.
	 *
	 * @param string $source   Which datasheet is wanted.
	 * @param bool   $in_admin Whether this arrived through admin-post.php.
	 * @return void
	 */
	private function serve( $source, $in_admin ) {
		if ( self::SOURCE_CART === $source ) {
			// The cart does not exist on an admin-post.php request, so it is never served there.
			if ( $in_admin ) {
				return;
			}

			check_admin_referer( self::cart_nonce() );

			if ( ! self::may_download_cart() ) {
				self::refuse();
			}

			$this->send( Sheet::from_cart( WC()->cart ), 'datasheet-cart-' . gmdate( 'Ymd-His' ) );

			return;
		}

		if ( self::SOURCE_ORDER !== $source ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is bound to this value, so it has to be read before it can be checked.
		$order_id = isset( $_GET[ self::ORDER_VAR ] ) ? absint( wp_unslash( $_GET[ self::ORDER_VAR ] ) ) : 0;

		check_admin_referer( self::order_nonce( $order_id ) );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			self::refuse();
		}

		$allowed = $in_admin ? current_user_can( 'edit_shop_orders' ) : self::may_download_order( $order );

		if ( ! $allowed ) {
			self::refuse();
		}

		$this->send(
			Sheet::from_order( $order ),
			'datasheet-order-' . $order->get_order_number() . '-' . gmdate( 'Ymd' )
		);
	}

	/**
	 * Send the file and stop.
	 *
	 * @param \WC_Product[] $products The products to describe.
	 * @param string        $basename Filename without its extension.
	 * @return void
	 */
	private function send( array $products, $basename ) {
		if ( empty( $products ) ) {
			wp_die(
				esc_html__( 'There is no product data to download.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 404 )
			);
		}

		$csv = Sheet::render( $products );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $basename . '.csv' ) . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV payload; escaping it for HTML would corrupt the file.
		echo $csv;

		exit;
	}

	/**
	 * Refuse the request.
	 *
	 * `wp_die()` rather than a redirect: a download that quietly returns the customer to
	 * the page they were on tells them nothing about why they have no file.
	 *
	 * @return void
	 */
	private static function refuse() {
		wp_die(
			esc_html__( 'You are not allowed to download this product data.', 'woo-organization-accounts-pro' ),
			'',
			array( 'response' => 403 )
		);
	}
}
