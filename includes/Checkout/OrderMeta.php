<?php
/**
 * What an order records about the organization behind it.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Checkout;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the organization details stored on an order.
 *
 * Every key is written through the order CRUD object, never with post meta functions:
 * under High-Performance Order Storage an order is not a post, and `get_post_meta()`
 * on an order ID reads nothing at all.
 *
 * The IDs are the link back to the live records; the names beside them are a snapshot.
 * Both are needed. An organization that is later renamed, or a location that is later
 * deleted, must not change what a two-year-old order says it was for — and an order
 * list that has to load five hundred organizations to print a column is not a list
 * anybody wants to open.
 */
class OrderMeta {

	/**
	 * Order meta key holding the organization ID.
	 */
	const ORGANIZATION_ID = '_woap_organization_id';

	/**
	 * Order meta key holding the organization's name as it was at the time.
	 */
	const ORGANIZATION_NAME = '_woap_organization_name';

	/**
	 * Order meta key holding the delivery location ID, when one was chosen.
	 */
	const LOCATION_ID = '_woap_location_id';

	/**
	 * Order meta key holding the location's name as it was at the time.
	 */
	const LOCATION_NAME = '_woap_location_name';

	/**
	 * Order meta key holding the user who placed the order.
	 *
	 * The same as the order's customer ID today. It is recorded separately because the
	 * customer on an order can be reassigned from the admin, and "which member placed
	 * this?" is a question about what happened rather than about who owns it now.
	 */
	const MEMBER_USER_ID = '_woap_member_user_id';

	/**
	 * Record the organization, location and member on an order.
	 *
	 * Called before the order is saved, so no separate save is needed here.
	 *
	 * @param \WC_Order     $order        Order being created.
	 * @param Organization  $organization Organization the order belongs to.
	 * @param Location|null $location     Delivery location, when one was chosen.
	 * @param int           $user_id      User placing the order.
	 * @return void
	 */
	public static function stamp( \WC_Order $order, Organization $organization, $location, $user_id ) {
		$order->update_meta_data( self::ORGANIZATION_ID, $organization->get_id() );
		$order->update_meta_data( self::ORGANIZATION_NAME, $organization->get_name() );
		$order->update_meta_data( self::MEMBER_USER_ID, absint( $user_id ) );

		if ( $location instanceof Location ) {
			$order->update_meta_data( self::LOCATION_ID, $location->get_id() );
			$order->update_meta_data( self::LOCATION_NAME, $location->get_name() );

			return;
		}

		$order->delete_meta_data( self::LOCATION_ID );
		$order->delete_meta_data( self::LOCATION_NAME );
	}

	/**
	 * The organization an order belongs to.
	 *
	 * @param \WC_Order $order Order.
	 * @return int Organization ID, or 0 when the order predates the plugin.
	 */
	public static function organization_id( \WC_Order $order ) {
		return absint( $order->get_meta( self::ORGANIZATION_ID ) );
	}

	/**
	 * The organization's name as recorded on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string Name, or an empty string.
	 */
	public static function organization_name( \WC_Order $order ) {
		return (string) $order->get_meta( self::ORGANIZATION_NAME );
	}

	/**
	 * The delivery location recorded on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int Location ID, or 0 when the order used a one-off address.
	 */
	public static function location_id( \WC_Order $order ) {
		return absint( $order->get_meta( self::LOCATION_ID ) );
	}

	/**
	 * The location's name as recorded on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string Name, or an empty string.
	 */
	public static function location_name( \WC_Order $order ) {
		return (string) $order->get_meta( self::LOCATION_NAME );
	}

	/**
	 * The member who placed an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int User ID, or 0.
	 */
	public static function member_user_id( \WC_Order $order ) {
		return absint( $order->get_meta( self::MEMBER_USER_ID ) );
	}
}
