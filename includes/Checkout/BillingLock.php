<?php
/**
 * Centralised billing at checkout.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Checkout;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Bills every order to the organization's address, whatever the checkout submitted.
 *
 * The enforcement is the server-side overwrite, not the readonly attributes. Marking
 * an input readonly is a hint to a browser and nothing more: the field can be edited
 * from the console, removed from the DOM, or never rendered at all if the request is
 * made straight to the Store API. So the posted billing data is not validated,
 * corrected or compared — it is discarded, and the organization's address is written
 * in its place at the last point before the order is saved.
 *
 * What lands on the order is a copy, which is the point. WooCommerce stores the
 * address on the order itself, so editing the organization's billing address next
 * month leaves every order already placed saying exactly what it said before.
 */
class BillingLock {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'lock_fields' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'replace_posted_data' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'apply_to_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'apply_to_store_api_order' ), 20 );
	}

	/**
	 * Fill the billing fields with the organization's address and lock them.
	 *
	 * Presentation only — see the class comment. Fields the organization has no value
	 * for are still locked rather than left editable, because a blank the customer can
	 * fill in is a blank they will expect to be used.
	 *
	 * @param array $fields Checkout fields.
	 * @return array Fields, with billing locked.
	 */
	public function lock_fields( $fields ) {
		$organization = Context::organization();

		if ( null === $organization || ! isset( $fields['billing'] ) ) {
			return $fields;
		}

		$address = $organization->get_billing_address();

		foreach ( $fields['billing'] as $key => $field ) {
			$name = substr( $key, strlen( 'billing_' ) );

			if ( ! array_key_exists( $name, $address ) ) {
				continue;
			}

			$fields['billing'][ $key ]['default']  = $address[ $name ];
			$fields['billing'][ $key ]['value']    = $address[ $name ];
			$fields['billing'][ $key ]['required'] = false;

			$fields['billing'][ $key ]['custom_attributes'] = array_merge(
				isset( $field['custom_attributes'] ) ? (array) $field['custom_attributes'] : array(),
				array( 'readonly' => 'readonly' )
			);

			$fields['billing'][ $key ]['class'] = array_merge(
				isset( $field['class'] ) ? (array) $field['class'] : array(),
				array( 'woap-locked-field' )
			);
		}

		if ( isset( $fields['billing']['billing_country'] ) ) {
			$fields['billing']['billing_country']['type'] = 'hidden';
		}

		if ( isset( $fields['billing']['billing_state'] ) ) {
			$fields['billing']['billing_state']['type'] = 'hidden';
		}

		return $fields;
	}

	/**
	 * Replace the posted billing data with the organization's, before validation.
	 *
	 * Doing this before WooCommerce validates means the checkout is validated against
	 * the address that will actually be used. Overwriting only at order-creation time
	 * would let a submission fail validation over a field the customer was never able
	 * to change.
	 *
	 * @param array $data Posted checkout data.
	 * @return array Data, with billing replaced.
	 */
	public function replace_posted_data( $data ) {
		$organization = Context::organization();

		if ( null === $organization ) {
			return $data;
		}

		foreach ( $organization->get_billing_address() as $field => $value ) {
			$data[ 'billing_' . $field ] = $value;
		}

		return $data;
	}

	/**
	 * Write the organization's billing address onto a classic-checkout order.
	 *
	 * @param \WC_Order $order Order being created.
	 * @param array     $data  Posted checkout data.
	 * @return void
	 */
	public function apply_to_order( $order, $data ) {
		unset( $data );

		$organization = Context::organization();

		if ( null === $organization ) {
			return;
		}

		self::write( $order, $organization );
	}

	/**
	 * Write the organization's billing address onto a Store API order.
	 *
	 * @param \WC_Order $order Order being created.
	 * @return void
	 */
	public function apply_to_store_api_order( $order ) {
		$organization = Context::organization();

		if ( null === $organization ) {
			return;
		}

		self::write( $order, $organization );
	}

	/**
	 * Copy an organization's billing address onto an order.
	 *
	 * @param \WC_Order    $order        Order being created.
	 * @param Organization $organization Organization to bill.
	 * @return void
	 */
	private static function write( \WC_Order $order, Organization $organization ) {
		$order->set_address( $organization->get_billing_address(), 'billing' );
	}

	/**
	 * The note shown above the locked billing fields.
	 *
	 * @return string Translated message.
	 */
	public static function locked_notice() {
		return sprintf(
			/* translators: %s: the organization noun for the site's mode, for example "Company". */
			__( 'Orders are billed to your %s. Ask an administrator to change the billing address.', 'woo-organization-accounts-pro' ),
			Labels::organization()
		);
	}
}
