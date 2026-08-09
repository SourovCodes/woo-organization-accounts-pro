<?php
/**
 * The customer details block on an organization order.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

use WooOrgAccounts\Checkout\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the billing and delivery addresses on an order the viewer did not place.
 *
 * WooCommerce prints the addresses at the foot of an order only for the person whose
 * order it is. Both `order/order-details.php` and its fulfillments variant decide with
 * the same bare line:
 *
 *     $show_customer_details = $order->get_user_id() === get_current_user_id();
 *
 * That is the ownership assumption an organization account exists to break, so an admin
 * opening an employee's order got the items and the totals and no addresses at all —
 * on an account whose whole point is that goods go to organization locations, the two
 * facts most worth checking were the two that were missing.
 *
 * **There is no filter for it.** The variable is computed inside the template, so the
 * choices are to override the template or to print the block ourselves. Overriding
 * would freeze a WooCommerce template that changes between releases — and it changed as
 * recently as the fulfillments variant — against a plugin that deliberately carries no
 * backwards-compatibility shims. So this hooks `woocommerce_after_order_details`, which
 * both templates fire on the line immediately before the block they are about to skip,
 * and renders WooCommerce's own `order/order-details-customer.php` in the position it
 * would have occupied.
 *
 * It runs only when WooCommerce is *not* going to render it, so the addresses can never
 * appear twice, and only for an order the viewer may actually see — the same
 * `view_order` question `Capabilities` answers for the page itself.
 */
class OrderDetails {

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_after_order_details', array( $this, 'render_customer_details' ) );
	}

	/**
	 * Print the addresses when the viewer is not the order's own customer.
	 *
	 * @param \WC_Order $order The order being shown.
	 * @return void
	 */
	public function render_customer_details( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		/*
		 * WooCommerce prints the block itself in this case. Rendering here as well
		 * would show every address twice.
		 */
		if ( $order->get_user_id() === get_current_user_id() ) {
			return;
		}

		if ( OrderMeta::organization_id( $order ) <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'view_order', $order->get_id() ) ) {
			return;
		}

		wc_get_template( 'order/order-details-customer.php', array( 'order' => $order ) );
	}
}
