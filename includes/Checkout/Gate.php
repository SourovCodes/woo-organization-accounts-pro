<?php
/**
 * Who may check out.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Checkout;

use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses a checkout that is not being made by an active member of an active
 * organization.
 *
 * Guest checkout is off and there is no setting for it. A guest order has no
 * organization behind it, so it has no billing address this plugin recognises, no
 * location to ship to and nobody to bill — it is not a configuration the rest of the
 * plugin has an answer for, so it is refused rather than made optional.
 *
 * The rule itself lives in Context::can_purchase(). What is here is the set of places
 * it has to be applied, and there are more of them than there look: the cart page, the
 * classic checkout's validation pass, and the Store API that the Cart and Checkout
 * blocks talk to. Missing one of those is not a cosmetic gap — the Store API route is
 * reachable directly, whatever the site's pages happen to render.
 */
class Gate {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', array( $this, 'disable_guest_checkout' ) );
		add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', array( $this, 'enable_login_reminder' ) );

		add_action( 'woocommerce_check_cart_items', array( $this, 'block_cart' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'block_classic_checkout' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'block_store_api_cart' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'block_store_api_checkout' ), 5 );
	}

	/**
	 * Force guest checkout off.
	 *
	 * A pre_option filter rather than a saved setting, so it cannot be switched back on
	 * from WooCommerce → Accounts while the plugin is active.
	 *
	 * @return string Always 'no'.
	 */
	public function disable_guest_checkout() {
		return 'no';
	}

	/**
	 * Force the "returning customer? log in" prompt on.
	 *
	 * With guest checkout gone, signing in is the only way forward, so the checkout
	 * must offer it.
	 *
	 * @return string Always 'yes'.
	 */
	public function enable_login_reminder() {
		return 'yes';
	}

	/**
	 * Refuse the cart, which is where a blocked customer should find out.
	 *
	 * Runs on both the cart and the checkout, so the message appears at the first
	 * screen that would otherwise lead to a payment.
	 *
	 * @return void
	 */
	public function block_cart() {
		if ( Context::can_purchase() ) {
			return;
		}

		wc_add_notice( Context::purchase_blocked_reason(), 'error' );
	}

	/**
	 * Refuse the classic checkout at validation time.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Errors collected so far.
	 * @return void
	 */
	public function block_classic_checkout( $data, $errors ) {
		if ( Context::can_purchase() ) {
			return;
		}

		$errors->add( 'woap_not_permitted', Context::purchase_blocked_reason() );
	}

	/**
	 * Refuse the Store API cart, which the Cart and Checkout blocks read.
	 *
	 * @param \WP_Error[]|\WP_Error $errors Errors collected so far.
	 * @param \WC_Cart              $cart   The cart being validated.
	 * @return \WP_Error[]|\WP_Error Errors, with ours added.
	 */
	public function block_store_api_cart( $errors, $cart ) {
		unset( $cart );

		if ( Context::can_purchase() ) {
			return $errors;
		}

		$error = new \WP_Error( 'woap_not_permitted', Context::purchase_blocked_reason() );

		if ( is_array( $errors ) ) {
			$errors[] = $error;

			return $errors;
		}

		if ( $errors instanceof \WP_Error ) {
			$errors->add( 'woap_not_permitted', Context::purchase_blocked_reason() );

			return $errors;
		}

		return array( $error );
	}

	/**
	 * Refuse a Store API checkout outright.
	 *
	 * The last line rather than the first: the cart errors above already stop the
	 * blocks from offering to pay. This catches a request made directly to the route,
	 * which is not a hypothetical — the route is public and the block UI is not what
	 * enforces anything.
	 *
	 * @param \WC_Order $order The order being built.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the customer may not check out.
	 */
	public function block_store_api_checkout( $order ) {
		unset( $order );

		if ( Context::can_purchase() ) {
			return;
		}

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'woap_not_permitted',
			esc_html( Context::purchase_blocked_reason() ),
			403
		);
	}
}
