<?php
/**
 * Organization enforcement on the WooCommerce orders REST route.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Checkout\ShippingSelector;
use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Makes `/wc/v3/orders` follow the same rules as the checkouts.
 *
 * That route is a third way an order comes into existence, beside the classic checkout
 * and the Store API — and it is the one a till uses to ring an order up on a member's
 * behalf. Left alone, it was also the documented way around everything this plugin
 * enforces: an order created there carried whatever billing address the device posted,
 * no organization, no location and no `woap_place_orders` check, so it billed to the
 * wrong address and then appeared in no organization's order list at all.
 *
 * This extends WooCommerce's own route rather than adding a parallel one, for the same
 * reason the snapshot route exists only for nouns WooCommerce lacks: a `woap/v1` order
 * route would re-implement line items, tax, coupons and stock, and split "every order
 * carries one organization" across two code paths — which is exactly the hole being
 * closed here.
 *
 * Everything lands on `woocommerce_rest_pre_insert_shop_order_object`, which fires
 * after the request has been written onto the order and before the order is saved, so
 * a refusal is a clean error and no order exists. Nothing uses a `register_rest_field`
 * update callback: `WC_REST_CRUD_Controller` calls those *after* the order is saved
 * and discards their return value, so a refusal from one would arrive too late and
 * then be thrown away.
 *
 * **The rules are applied to the order's customer, never to the API user.** A till's
 * key maps to a user holding `manage_woocommerce`, and `Capabilities` grants that user
 * everything for every organization — a gate asked about the key would always be open.
 * `Context::can_purchase( $customer_id )` asks about the member the order is for,
 * which is the member the answer belongs to.
 */
final class Orders {

	/**
	 * Request parameter naming the delivery location.
	 *
	 * The same name the classic checkout posts its selection under, so the till and the
	 * checkout speak of one field.
	 */
	const LOCATION_PARAM = ShippingSelector::FIELD;

	/**
	 * Collection parameter for filtering the order list by organization.
	 */
	const FILTER_PARAM = 'woap_organization';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( $this, 'enforce' ), 20, 3 );
		add_filter( 'woocommerce_rest_orders_prepare_object_query', array( $this, 'scope_query' ), 10, 2 );
		add_filter( 'rest_shop_order_collection_params', array( $this, 'add_collection_params' ) );
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Apply the organization rules to an order arriving over REST.
	 *
	 * Runs on creation only. An update to an existing order over this route is shop
	 * staff editing a record they already own — the same act as editing it in
	 * wp-admin, which this plugin also leaves alone. The one update this route accepts
	 * a `woap_location_id` for is handled separately in move_delivery(), because
	 * re-running the whole gate against an old order would refuse edits to history
	 * whenever a member has since left.
	 *
	 * @param \WC_Order|\WP_Error $order    Order as built from the request, or an error from an earlier filter.
	 * @param \WP_REST_Request    $request  The request.
	 * @param bool                $creating True when the order is being created.
	 * @return \WC_Order|\WP_Error The order, or a refusal.
	 */
	public function enforce( $order, $request, $creating ) {
		if ( ! $order instanceof \WC_Order ) {
			return $order;
		}

		if ( ! $creating ) {
			return $this->move_delivery( $order, $request );
		}

		$customer_id = $order->get_customer_id();
		$member      = $customer_id > 0 ? Context::member( $customer_id ) : null;

		/*
		 * A customer with no membership is not this plugin's business — the same rule
		 * the order capabilities follow. On a strict organization shop there should be
		 * no such customer, but the shop's own staff placing a manual test order must
		 * not be refused by a gate about organizations they do not belong to.
		 */
		if ( null === $member ) {
			return $order;
		}

		if ( ! Context::can_purchase( $customer_id ) ) {
			return new \WP_Error(
				'woap_rest_cannot_purchase',
				Context::purchase_blocked_reason( $customer_id ),
				array( 'status' => 403 )
			);
		}

		// Never null here: can_purchase() just required an active organization.
		$organization = Context::organization_by_id( $member->get_organization_id() );

		/*
		 * Billing is the organization's address, and what the request carried is
		 * discarded rather than validated — the same overwrite the checkouts perform,
		 * for the same reason: the copy on the order is the historical snapshot.
		 */
		$order->set_address( $organization->get_billing_address(), 'billing' );

		$selection = $this->requested_location( $request );

		if ( null !== $selection && $selection > 0 ) {
			$problem = ShippingSelector::destination_error_for( $organization, $member, (string) $selection );

			if ( '' !== $problem ) {
				return new \WP_Error( 'woap_rest_shipping_destination', $problem, array( 'status' => 400 ) );
			}

			$location = ShippingSelector::resolve_location_for( $member, (string) $selection );

			$order->set_address( ShippingSelector::order_shipping_address( $location, $organization ), 'shipping' );
			OrderMeta::stamp( $order, $organization, $location, $customer_id );

			return $order;
		}

		/*
		 * No location named. Whether that is acceptable is the checkout's own rule:
		 * an order that needs no shipping address needs no location either — a
		 * walk-out sale at the counter has no parcel — and one that does falls to the
		 * organization's one-off-address switch. needs_shipping_address() reads the
		 * order's shipping lines, which the request has already been written onto.
		 */
		if ( $order->needs_shipping_address() ) {
			$problem = ShippingSelector::destination_error_for( $organization, $member, '' );

			if ( '' !== $problem ) {
				return new \WP_Error( 'woap_rest_shipping_destination', $problem, array( 'status' => 400 ) );
			}

			$invalid = $this->validate_one_off_address( $order );

			if ( $invalid instanceof \WP_Error ) {
				return $invalid;
			}
		}

		OrderMeta::stamp( $order, $organization, null, $customer_id );

		return $order;
	}

	/**
	 * Hold a one-off shipping address to the checkout's own per-country rules.
	 *
	 * At the checkout, an address the customer types is validated by WooCommerce per
	 * country — required fields the country actually has, postcode format, a state
	 * from the country's list. `/wc/v3/orders` performs none of that, because staff
	 * creating orders by hand are trusted with partial addresses; a till taking a
	 * customer's delivery address at the counter is the checkout case, not the staff
	 * case. `AddressFields::validate()` is deliberately the same rules in the same
	 * order as `WC_Checkout::validate_posted_data()`, so an address accepted here is
	 * one the shop's own checkout would have accepted — and it normalises in place,
	 * so the order stores what WooCommerce would store: the postcode formatted, a
	 * state name upper-cased to its code.
	 *
	 * @param \WC_Order $order Order carrying the posted one-off address.
	 * @return \WP_Error|null A refusal, or null when the address is one the checkout would take.
	 */
	private function validate_one_off_address( \WC_Order $order ) {
		$address = $order->get_address( 'shipping' );
		$errors  = new \WP_Error();

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		if ( $errors->has_errors() ) {
			return new \WP_Error(
				'woap_rest_shipping_address',
				// The messages carry <strong> markup for the web forms; a REST client wants text.
				wp_strip_all_tags( implode( ' ', $errors->get_error_messages() ) ),
				array( 'status' => 400 )
			);
		}

		$order->set_address( $address, 'shipping' );

		return null;
	}

	/**
	 * Re-point an existing organization order at another of the organization's locations.
	 *
	 * The one organization-shaped edit an update may carry. The resolution is scoped
	 * to the organization on the order rather than to the member's access list: that
	 * list governs what a *member* may choose, and this is the shop redirecting a
	 * parcel it is fulfilling. Cross-organization is still the line — a location ID
	 * from another organization resolves to nothing, exactly as at the checkout.
	 *
	 * The member stamped on the order is left as it was: which employee placed the
	 * order is a fact about what happened, and moving the parcel does not change it.
	 *
	 * @param \WC_Order        $order   Order being updated, with the request applied.
	 * @param \WP_REST_Request $request The request.
	 * @return \WC_Order|\WP_Error The order, or a refusal.
	 */
	private function move_delivery( \WC_Order $order, $request ) {
		$selection = $this->requested_location( $request );

		if ( null === $selection ) {
			return $order;
		}

		$organization = Context::organization_by_id( OrderMeta::organization_id( $order ) );

		if ( null === $organization ) {
			return new \WP_Error(
				'woap_rest_not_an_organization_order',
				sprintf(
					/* translators: 1: the organization noun for the site's mode, for example "Company", 2: the singular location noun. */
					__( 'This order does not belong to a %1$s, so it cannot be delivered to a %2$s.', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					Labels::location()
				),
				array( 'status' => 400 )
			);
		}

		$location = LocationRepository::find_for_organization( $selection, $organization->get_id() );

		if ( ! $location instanceof Location ) {
			return new \WP_Error(
				'woap_rest_shipping_destination',
				sprintf(
					/* translators: 1: the singular location noun, for example "Branch", 2: the organization noun. */
					__( 'That %1$s does not belong to this order’s %2$s.', 'woo-organization-accounts-pro' ),
					Labels::location(),
					Labels::organization()
				),
				array( 'status' => 400 )
			);
		}

		$order->set_address( ShippingSelector::order_shipping_address( $location, $organization ), 'shipping' );
		$order->update_meta_data( OrderMeta::LOCATION_ID, $location->get_id() );
		$order->update_meta_data( OrderMeta::LOCATION_NAME, $location->get_name() );

		return $order;
	}

	/**
	 * The location the request names, if it names one.
	 *
	 * Null and zero are different answers: null means the parameter was not sent, zero
	 * means it was sent empty. Creation treats both as "no location" and lets the
	 * shipping rules decide; an update ignores the former and refuses the latter,
	 * because an explicit zero on an update asks for a state — an organization order
	 * with its location removed — that nothing here supports writing.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return int|null Location ID, 0 for explicitly none, or null when absent.
	 */
	private function requested_location( $request ) {
		$value = $request->get_param( self::LOCATION_PARAM );

		if ( null === $value ) {
			return null;
		}

		return absint( $value );
	}

	/**
	 * Let the order list be filtered to one organization.
	 *
	 * `?woap_organization=<id>` on the orders collection, so a till can show an
	 * organization's order history without paging the whole shop through the device.
	 * The same indexed meta lookup the account's own organization orders screen runs.
	 *
	 * @param array            $args    Arguments bound for wc_get_orders().
	 * @param \WP_REST_Request $request The request.
	 * @return array Arguments, scoped when the filter was asked for.
	 */
	public function scope_query( $args, $request ) {
		$organization_id = absint( $request->get_param( self::FILTER_PARAM ) );

		if ( 0 === $organization_id ) {
			return $args;
		}

		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The organization ID is the only way to scope the order list, and it is an indexed lookup in the HPOS meta table.
		}

		$args['meta_query'][] = array(
			'key'   => OrderMeta::ORGANIZATION_ID,
			'value' => $organization_id,
		);

		return $args;
	}

	/**
	 * Document the organization filter on the collection.
	 *
	 * @param array $params Collection parameters.
	 * @return array Parameters, with the filter added.
	 */
	public function add_collection_params( $params ) {
		$params[ self::FILTER_PARAM ] = array(
			'description'       => __( 'Limit the result to orders belonging to one organization.', 'woo-organization-accounts-pro' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Expose what an order records about its organization.
	 *
	 * Read-only, and read from the order's own meta rather than the live tables: an
	 * order says what it was for at the time, which is the answer that survives a
	 * rename or a deletion. `woap_location_id` is the one field a request may also
	 * send, and the writes happen in enforce() — see the class comment for why no
	 * field here carries an update callback.
	 *
	 * @return void
	 */
	public function register_fields() {
		$fields = array(
			'woap_organization_id'   => array(
				'description' => __( 'The organization this order belongs to. Zero when the order has none.', 'woo-organization-accounts-pro' ),
				'read'        => array( OrderMeta::class, 'organization_id' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'woap_organization_name' => array(
				'description' => __( 'The organization name as it was when the order was placed.', 'woo-organization-accounts-pro' ),
				'read'        => array( OrderMeta::class, 'organization_name' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'woap_location_id'       => array(
				'description' => __( 'The delivery location. Send it when creating an order for a member; zero means a one-off address was used.', 'woo-organization-accounts-pro' ),
				'read'        => array( OrderMeta::class, 'location_id' ),
				'type'        => 'integer',
				'readonly'    => false,
			),
			'woap_location_name'     => array(
				'description' => __( 'The location name as it was when the order was placed.', 'woo-organization-accounts-pro' ),
				'read'        => array( OrderMeta::class, 'location_name' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'woap_member_user_id'    => array(
				'description' => __( 'The member this order was placed for.', 'woo-organization-accounts-pro' ),
				'read'        => array( OrderMeta::class, 'member_user_id' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
		);

		foreach ( $fields as $name => $field ) {
			register_rest_field(
				'shop_order',
				$name,
				array(
					'get_callback' => $this->field_reader( $field['read'] ),
					'schema'       => array(
						'description' => $field['description'],
						'type'        => $field['type'],
						'context'     => array( 'view', 'edit' ),
						'readonly'    => $field['readonly'],
					),
				)
			);
		}
	}

	/**
	 * Adapt an OrderMeta reader to the register_rest_field callback shape.
	 *
	 * The callback is handed the prepared response array, not the order, so the order
	 * is fetched back by ID — a cache hit, since the controller has just built it.
	 *
	 * @param callable $reader OrderMeta method taking the order.
	 * @return callable Callback for register_rest_field.
	 */
	private function field_reader( $reader ) {
		return static function ( $response ) use ( $reader ) {
			$order = isset( $response['id'] ) ? wc_get_order( absint( $response['id'] ) ) : false;

			if ( ! $order instanceof \WC_Order ) {
				return null;
			}

			return call_user_func( $reader, $order );
		};
	}
}
