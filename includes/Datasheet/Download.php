<?php
/**
 * Downloading a product datasheet.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Datasheet;

use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Pricing\CustomerView;

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
 * The links are ordinary GETs carrying a nonce, because most of the places they appear
 * are the Actions column of a table, where a `<form>` would be the wrong shape. Nothing
 * is written, so a GET says what the request is.
 *
 * Authorisation reuses the answers that already exist rather than inventing a
 * capability. The cart asks `Context::can_purchase()`, which is what `Checkout\Gate`
 * asks before letting anyone near the cart at all. An order asks WooCommerce's own
 * `view_order`, which is what `Frontend\OrderDetails` asks and which routes through
 * `Capabilities::resolve_order_capabilities()` — so an organization admin gets a
 * colleague's order and a member of another organization does not. That is the
 * cross-tenant question, and it is already answered in one place.
 *
 * **Customer view refuses both.** The `EK / VK1` column *is* the trade price, so the
 * file is the one thing on the site that would hand over in full what the mode exists to
 * keep off the screen. Both `may_download_*()` answer false while it is on, which takes
 * the buttons away and refuses the URL by the same line — a button never appears where
 * the download would refuse, which is the rule this class already follows for the
 * spreadsheet format.
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
	 * Query variable naming which file format is wanted.
	 */
	const FORMAT_VAR = 'woap_format';

	/**
	 * The feed's own format, and the default.
	 *
	 * Default because it is what the 0.13 links carried before a choice existed, so a
	 * bookmarked or emailed URL from then still resolves to the file it named.
	 */
	const FORMAT_CSV = 'csv';

	/**
	 * A spreadsheet, for opening rather than parsing.
	 */
	const FORMAT_XLSX = 'xlsx';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_download' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'download_in_admin' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'woocommerce_after_cart_table', array( $this, 'render_cart_button' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_orders_list_action' ), 10, 2 );
	}

	/**
	 * The formats on offer, in the order they are shown.
	 *
	 * XLSX is dropped rather than offered and refused where ZipArchive is absent, so a
	 * link never appears that cannot produce a file.
	 *
	 * @return string[] Format keys.
	 */
	public static function formats() {
		$formats = array( self::FORMAT_CSV );

		if ( Workbook::is_available() ) {
			$formats[] = self::FORMAT_XLSX;
		}

		return $formats;
	}

	/**
	 * The label a button carries.
	 *
	 * The format is named on the button rather than chosen afterwards: one click, and
	 * the same shape works on every surface — WooCommerce's orders Actions column
	 * renders a plain anchor from a URL and a name, so it can hold no control of its own.
	 *
	 * @param string $format Format key.
	 * @return string Translated label.
	 */
	public static function label( $format = self::FORMAT_CSV ) {
		if ( self::FORMAT_XLSX === $format ) {
			return __( 'Download datasheet (Excel)', 'woo-organization-accounts-pro' );
		}

		return __( 'Download datasheet (CSV)', 'woo-organization-accounts-pro' );
	}

	/**
	 * The label a table's Actions column carries, where there is no room for a sentence.
	 *
	 * The format's name alone. Two buttons reading *Datasheet CSV* and *Datasheet Excel*
	 * beside *View* overflowed the column and wrapped onto a second line, squeezing the
	 * columns beside it; the word is redundant next to each other anyway. What the
	 * button is *for* is not lost, because both order templates take an explicit
	 * `aria-label` and `accessible_label()` supplies the whole sentence there.
	 *
	 * @param string $format Format key.
	 * @return string Translated label.
	 */
	public static function short_label( $format = self::FORMAT_CSV ) {
		if ( self::FORMAT_XLSX === $format ) {
			return __( 'Excel', 'woo-organization-accounts-pro' );
		}

		return __( 'CSV', 'woo-organization-accounts-pro' );
	}

	/**
	 * What a screen reader is told a compact button does.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $format Format key.
	 * @return string Translated label.
	 */
	public static function accessible_label( \WC_Order $order, $format = self::FORMAT_CSV ) {
		if ( self::FORMAT_XLSX === $format ) {
			/* translators: %s: the order number. */
			$template = __( 'Download the product datasheet for order %s as an Excel file', 'woo-organization-accounts-pro' );
		} else {
			/* translators: %s: the order number. */
			$template = __( 'Download the product datasheet for order %s as a CSV file', 'woo-organization-accounts-pro' );
		}

		return sprintf( $template, $order->get_order_number() );
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
	 * @param string $format Format key.
	 * @return string Nonced URL.
	 */
	public static function cart_url( $format = self::FORMAT_CSV ) {
		return add_query_arg(
			array(
				self::QUERY_VAR  => self::SOURCE_CART,
				self::FORMAT_VAR => $format,
				'_wpnonce'       => wp_create_nonce( self::cart_nonce() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The link that downloads an order's datasheet.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $format Format key.
	 * @return string Nonced URL.
	 */
	public static function order_url( \WC_Order $order, $format = self::FORMAT_CSV ) {
		return add_query_arg(
			array(
				self::QUERY_VAR  => self::SOURCE_ORDER,
				self::ORDER_VAR  => $order->get_id(),
				self::FORMAT_VAR => $format,
				'_wpnonce'       => wp_create_nonce( self::order_nonce( $order->get_id() ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The same link, for shop staff on the order screen.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $format Format key.
	 * @return string Nonced URL.
	 */
	public static function admin_order_url( \WC_Order $order, $format = self::FORMAT_CSV ) {
		return add_query_arg(
			array(
				'action'         => self::ADMIN_ACTION,
				self::QUERY_VAR  => self::SOURCE_ORDER,
				self::ORDER_VAR  => $order->get_id(),
				self::FORMAT_VAR => $format,
				'_wpnonce'       => wp_create_nonce( self::order_nonce( $order->get_id() ) ),
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
		if ( CustomerView::is_active() || ! Context::can_purchase() ) {
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
		if ( CustomerView::is_active() ) {
			return false;
		}

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

		$links = '';

		foreach ( self::formats() as $format ) {
			$links .= sprintf(
				'<a href="%1$s" class="button woap-datasheet__link">%2$s</a>',
				esc_url( self::cart_url( $format ) ),
				esc_html( self::label( $format ) )
			);
		}

		printf( '<p class="woap-datasheet">%s</p>', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_url() and esc_html() above.
	}

	/**
	 * Add the datasheet to an order's actions.
	 *
	 * **This one filter covers all three order screens**, which is not obvious and is
	 * why there is no second hook for the order itself. WooCommerce's own
	 * `order/order-details.php` renders `wc_get_account_orders_actions()` in an
	 * "Actions:" row of its footer, and that template is what both the view-order screen
	 * and order-received print. Adding a button on
	 * `woocommerce_order_details_after_order_table` as well — which is what this did
	 * first — put two of them on the same page, ninety pixels apart.
	 *
	 * @param array     $actions The actions for this row.
	 * @param \WC_Order $order   The order.
	 * @return array Actions, with ours added.
	 */
	public function add_orders_list_action( $actions, $order ) {
		if ( ! $order instanceof \WC_Order || ! self::may_download_order( $order ) ) {
			return $actions;
		}

		foreach ( self::formats() as $format ) {
			$actions[ 'woap-datasheet-' . $format ] = array(
				'url'        => self::order_url( $order, $format ),
				'name'       => self::short_label( $format ),
				'aria-label' => self::accessible_label( $order, $format ),
			);
		}

		return $actions;
	}

	/**
	 * Give the cart's button room to breathe.
	 *
	 * Woodmart makes the coupon field and *Update basket* the last row of the cart
	 * table, so a paragraph printed after the table lands directly under the coupon box
	 * with no gap at all — reading as part of the coupon rather than as an action about
	 * the cart. One margin fixes it.
	 *
	 * The row is a flex box because there is a button per format and they need a gap
	 * between them rather than the single space between two inline anchors.
	 *
	 * The second rule is Woodmart's own breakpoint. Below 768.98px the theme gives every
	 * button in the cart the full width of the form — *Update basket* and *Apply coupon*
	 * both — so a button left at its intrinsic width is the one ragged edge on the
	 * screen, and a whole row of them is worse. The figure is the theme's and was read
	 * off the rendered page rather than guessed: at 768px the update button matches the
	 * form's width and at 800px it does not.
	 *
	 * Registered against no file, so this is a `<style>` tag rather than a request for
	 * three declarations. It is our own class, which the theme does not style, so
	 * nothing here can tie with `base.css` and the load-order trap that catches theme
	 * parts does not apply.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		wp_register_style( 'woap-datasheet', false, array(), WOAP_VERSION );
		wp_enqueue_style( 'woap-datasheet' );
		wp_add_inline_style(
			'woap-datasheet',
			'.woap-datasheet{margin-top:20px;display:flex;flex-wrap:wrap;gap:10px;}'
			. '@media(max-width:768.98px){.woap-datasheet__link{flex:1 1 100%;text-align:center;}}'
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
	 * Which file format the request is asking for.
	 *
	 * Unrecognised values fall back to CSV rather than being refused: the format is a
	 * presentation choice, not a permission, and the nonce deliberately does not cover
	 * it so that the 0.13 links, which carried no format at all, still resolve.
	 *
	 * @return string One of the format constants.
	 */
	private static function requested_format() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- serve() checks the nonce; this only picks which renderer writes the same authorised data.
		$format = isset( $_GET[ self::FORMAT_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FORMAT_VAR ] ) ) : '';

		return in_array( $format, self::formats(), true ) ? $format : self::FORMAT_CSV;
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

			$this->send( Sheet::from_cart( WC()->cart ), 'datasheet-cart-' . gmdate( 'Ymd-His' ), self::requested_format() );

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
			'datasheet-order-' . $order->get_order_number() . '-' . gmdate( 'Ymd' ),
			self::requested_format()
		);
	}

	/**
	 * Send the file and stop.
	 *
	 * Both formats carry exactly the same values — `Sheet::rows()` produces them once
	 * and each writer only decides how they are spelt — so which one somebody picks can
	 * never change what the file says.
	 *
	 * @param \WC_Product[] $products The products to describe.
	 * @param string        $basename Filename without its extension.
	 * @param string        $format   Format key.
	 * @return void
	 */
	private function send( array $products, $basename, $format = self::FORMAT_CSV ) {
		if ( empty( $products ) ) {
			wp_die(
				esc_html__( 'There is no product data to download.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 404 )
			);
		}

		if ( self::FORMAT_XLSX === $format ) {
			$payload   = Workbook::render( Sheet::columns(), Sheet::rows( $products ) );
			$extension = '.xlsx';
			$type      = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
		} else {
			$payload   = Sheet::render( $products );
			$extension = '.csv';
			$type      = 'text/csv; charset=utf-8';
		}

		if ( '' === $payload ) {
			wp_die(
				esc_html__( 'That file could not be produced.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 500 )
			);
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $basename . $extension ) . '"' );
		header( 'Content-Length: ' . strlen( $payload ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A spreadsheet payload; escaping it for HTML would corrupt the file.
		echo $payload;

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
