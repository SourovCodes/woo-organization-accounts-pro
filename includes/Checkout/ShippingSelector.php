<?php
/**
 * Shipping to an organization location.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Checkout;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\Templates;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Ships orders to one of the organization's locations.
 *
 * The customer picks a location rather than typing an address, and the address is
 * then read out of the database by ID. What the shipping fields contain when the form
 * is submitted is never used: they are filled in by script for the customer to read,
 * and a location ID that does not belong to the member's organization resolves to
 * nothing rather than to somebody else's warehouse.
 *
 * A one-off address is allowed only when the organization has that switch on. That is
 * a per-organization setting because it is a per-organization risk: a shop with
 * controlled delivery points does not want an order redirected to a home address, and
 * a consultancy does.
 */
class ShippingSelector {

	/**
	 * Field the chosen location arrives in.
	 */
	const FIELD = 'woap_location_id';

	/**
	 * Value meaning "not one of the saved locations".
	 */
	const CUSTOM = 'custom';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_ship_to_different_address_checked', array( $this, 'always_ship_separately' ) );
		add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'render_selector' ) );
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_billing_notice' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'shape_shipping_fields' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'replace_posted_data' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'apply_to_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'apply_to_store_api_order' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Always treat the shipping address as separate from the billing address.
	 *
	 * They genuinely are: billing is the organization's registered address and
	 * shipping is a location. Copying one to the other would be wrong in both
	 * directions.
	 *
	 * @param bool $checked WooCommerce's answer.
	 * @return bool True when the cart needs shipping at all.
	 */
	public function always_ship_separately( $checked ) {
		if ( ! Context::can_purchase() ) {
			return $checked;
		}

		return WC()->cart instanceof \WC_Cart ? WC()->cart->needs_shipping_address() : $checked;
	}

	/**
	 * Load the checkout script and stylesheet, on the checkout only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! self::is_checkout_page() ) {
			return;
		}

		wp_enqueue_style(
			'woap-checkout',
			WOAP_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			WOAP_VERSION
		);

		/*
		 * Loaded on the checkout whichever way it renders. Whether the page *contains*
		 * the Checkout block is not the same question as whether the block is what gets
		 * rendered — a theme is free to swap in its own classic template, and several
		 * do — so there is nothing reliable to branch on here. The script is small, it
		 * does nothing at all when the classic markup is absent, and jQuery is already
		 * on any WooCommerce page; guessing wrong and shipping no selector would be far
		 * worse than loading it needlessly.
		 */
		wp_enqueue_script(
			'woap-checkout',
			WOAP_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			WOAP_VERSION,
			true
		);

		wp_localize_script(
			'woap-checkout',
			'woapCheckout',
			array(
				'field'     => self::FIELD,
				'custom'    => self::CUSTOM,
				'locations' => self::location_payload(),
			)
		);
	}

	/**
	 * Whether this request is rendering the checkout.
	 *
	 * `is_checkout()` is not used on its own, and cannot be: WooCommerce memoises the
	 * answer in a static the first time anything asks, and a theme or plugin that asks
	 * during `init` — before the main query has resolved — poisons it to false for the
	 * rest of the request. Asking WordPress directly, and treating WooCommerce's answer
	 * as a supplement rather than the source, survives that.
	 *
	 * @return bool True on the checkout.
	 */
	private static function is_checkout_page() {
		$page_id = wc_get_page_id( 'checkout' );

		if ( $page_id > 0 && is_page( $page_id ) ) {
			return true;
		}

		return function_exists( 'is_checkout' ) && is_checkout();
	}

	/**
	 * Render the location selector above the shipping fields.
	 *
	 * @return void
	 */
	public function render_selector() {
		$organization = Context::organization();
		$member       = Context::member();

		if ( null === $organization || null === $member ) {
			return;
		}

		Templates::render(
			'checkout/location-selector.php',
			array(
				'field'         => self::FIELD,
				'custom'        => self::CUSTOM,
				'locations'     => LocationRepository::for_member( $member ),
				'selected'      => self::posted_selection(),
				'allow_custom'  => $organization->allows_custom_shipping(),
				'location_noun' => Labels::location(),
			)
		);
	}

	/**
	 * Explain the locked billing fields above them.
	 *
	 * @return void
	 */
	public function render_billing_notice() {
		if ( null === Context::organization() ) {
			return;
		}

		printf(
			'<p class="woap-checkout-note">%s</p>',
			esc_html( BillingLock::locked_notice() )
		);
	}

	/**
	 * Build the shipping fieldset for the country the order is actually going to.
	 *
	 * WooCommerce derives the checkout fields from the posted `shipping_country`. This
	 * plugin then replaces the posted shipping address with the chosen location's — so
	 * without this, a German location submitted from a form that happened to carry
	 * `shipping_country=US` is validated under US rules and rejected for having no
	 * state. The data and the rules it is judged by have to come from the same place.
	 *
	 * @param array $fields Checkout fields.
	 * @return array Fields, with the shipping set rebuilt for the destination.
	 */
	public function shape_shipping_fields( $fields ) {
		$location = self::resolve_location( self::posted_selection() );

		if ( ! $location instanceof Location || ! isset( $fields['shipping'] ) ) {
			return $fields;
		}

		$address = $location->get_shipping_address();

		$fields['shipping'] = AddressFields::fields( AddressFields::SHIPPING, $address['country'] );

		foreach ( $fields['shipping'] as $key => $field ) {
			$name = substr( $key, strlen( 'shipping_' ) );

			if ( ! array_key_exists( $name, $address ) ) {
				continue;
			}

			$fields['shipping'][ $key ]['default'] = $address[ $name ];
			$fields['shipping'][ $key ]['value']   = $address[ $name ];
		}

		return $fields;
	}

	/**
	 * Replace the posted shipping address with the chosen location's.
	 *
	 * @param array $data Posted checkout data.
	 * @return array Data, with the shipping address resolved from the location.
	 */
	public function replace_posted_data( $data ) {
		$location = self::resolve_location( self::posted_selection() );

		if ( null === $location ) {
			return $data;
		}

		$data['ship_to_different_address'] = true;

		foreach ( $location->get_shipping_address() as $field => $value ) {
			$data[ 'shipping_' . $field ] = $value;
		}

		return $data;
	}

	/**
	 * Refuse a checkout whose delivery destination is not allowed.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Errors collected so far.
	 * @return void
	 */
	public function validate( $data, $errors ) {
		unset( $data );

		if ( ! Context::can_purchase() ) {
			return;
		}

		if ( WC()->cart instanceof \WC_Cart && ! WC()->cart->needs_shipping_address() ) {
			return;
		}

		$problem = self::destination_error( self::posted_selection() );

		if ( '' !== $problem ) {
			$errors->add( 'woap_shipping_destination', $problem );
		}
	}

	/**
	 * Write the resolved destination and the organization details onto the order.
	 *
	 * @param \WC_Order $order Order being created.
	 * @param array     $data  Posted checkout data.
	 * @return void
	 */
	public function apply_to_order( $order, $data ) {
		unset( $data );

		self::bind( $order, self::resolve_location( self::posted_selection() ) );
	}

	/**
	 * Write the resolved destination onto a Store API order.
	 *
	 * @param \WC_Order        $order   Order being created.
	 * @param \WP_REST_Request $request The checkout request.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the destination is not allowed.
	 */
	public function apply_to_store_api_order( $order, $request ) {
		$selection = self::request_selection( $request );
		$problem   = self::destination_error( $selection );

		if ( '' !== $problem && $order->needs_shipping_address() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'woap_shipping_destination',
				esc_html( $problem ),
				400
			);
		}

		$location = self::resolve_location( $selection );

		if ( $location instanceof Location ) {
			$order->set_address( $location->get_shipping_address(), 'shipping' );
		}

		self::bind( $order, $location );
	}

	/**
	 * Set the shipping address and stamp the organization details on an order.
	 *
	 * @param \WC_Order     $order    Order being created.
	 * @param Location|null $location Chosen location, or null for a one-off address.
	 * @return void
	 */
	private static function bind( \WC_Order $order, $location ) {
		$organization = Context::organization();

		if ( null === $organization ) {
			return;
		}

		if ( $location instanceof Location ) {
			$address = $location->get_shipping_address();

			/*
			 * A parcel with no company on the label is one nobody at a loading bay
			 * recognises. The location's own company wins when it has one — a branch may
			 * trade under a different name — and the organization's name fills in when
			 * it does not.
			 */
			if ( '' === trim( $address['company'] ) ) {
				$address['company'] = $organization->get_name();
			}

			$order->set_address( $address, 'shipping' );
		}

		OrderMeta::stamp( $order, $organization, $location, get_current_user_id() );
	}

	/**
	 * Why a destination cannot be used, if it cannot.
	 *
	 * @param string $selection The submitted selection.
	 * @return string Translated message, or an empty string when the destination is fine.
	 */
	public static function destination_error( $selection ) {
		$organization = Context::organization();
		$member       = Context::member();

		if ( null === $organization || null === $member ) {
			return '';
		}

		$locations = LocationRepository::for_member( $member );

		if ( self::CUSTOM === $selection || '' === $selection ) {
			if ( $organization->allows_custom_shipping() ) {
				return '';
			}

			if ( empty( $locations ) ) {
				return sprintf(
					/* translators: 1: plural location noun, 2: the organization admin noun. */
					__( 'No %1$s have been set up to deliver to, and one-off addresses are not allowed. Ask your %2$s to add one.', 'woo-organization-accounts-pro' ),
					Labels::locations(),
					Labels::organization_admin()
				);
			}

			return sprintf(
				/* translators: %s: the singular location noun, for example "Branch". */
				__( 'Please choose the %s this order is going to.', 'woo-organization-accounts-pro' ),
				Labels::location()
			);
		}

		$location = self::resolve_location( $selection );

		if ( null === $location ) {
			return sprintf(
				/* translators: %s: the singular location noun, for example "Branch". */
				__( 'Please choose a %s you have access to.', 'woo-organization-accounts-pro' ),
				Labels::location()
			);
		}

		/*
		 * A location can be stored incomplete — saved before this plugin validated
		 * addresses, or moved to a country with stricter rules — and WooCommerce would
		 * then refuse the order with "Shipping Last name is a required field", which
		 * tells the customer to fix a field they cannot see and do not own. Naming the
		 * destination and what it is missing at least points at the right person.
		 */
		$missing = AddressFields::missing( AddressFields::SHIPPING, $location->get_shipping_address() );

		if ( ! empty( $missing ) ) {
			return sprintf(
				/* translators: 1: the location's name, 2: comma-separated list of the missing fields, 3: the organization admin noun. */
				__( '“%1$s” is missing %2$s, so it cannot be delivered to. Ask your %3$s to complete it, or choose somewhere else.', 'woo-organization-accounts-pro' ),
				$location->get_name(),
				implode( ', ', $missing ),
				Labels::organization_admin()
			);
		}

		return '';
	}

	/**
	 * Turn a submitted selection into a location the current member may use.
	 *
	 * The scoping is the whole point: the lookup is by location ID *and* organization,
	 * and then filtered to the member's own access list, so an ID from another
	 * organization comes back as null rather than as an address.
	 *
	 * @param string $selection The submitted selection.
	 * @return Location|null The location, or null for a one-off address or a refused ID.
	 */
	public static function resolve_location( $selection ) {
		$location_id = absint( $selection );

		if ( 0 === $location_id ) {
			return null;
		}

		$member = Context::member();

		if ( null === $member ) {
			return null;
		}

		foreach ( LocationRepository::for_member( $member ) as $location ) {
			if ( $location->get_id() === $location_id ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * The selection submitted with a classic checkout.
	 *
	 * @return string Raw selection: a location ID, 'custom', or an empty string.
	 */
	private static function posted_selection() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verifies woocommerce-process-checkout-nonce before any of these filters run; the value is validated against the member's own locations regardless.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) );
	}

	/**
	 * The selection submitted with a Store API checkout.
	 *
	 * @param \WP_REST_Request $request The checkout request.
	 * @return string Raw selection.
	 */
	private static function request_selection( $request ) {
		$extensions = $request['extensions'];

		if ( ! is_array( $extensions ) || ! isset( $extensions[ Blocks\CheckoutIntegration::NAMESPACE_ID ]['location_id'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $extensions[ Blocks\CheckoutIntegration::NAMESPACE_ID ]['location_id'] );
	}

	/**
	 * The member's locations, in the shape the checkout script wants.
	 *
	 * @return array List of locations with their address fields.
	 */
	public static function location_payload() {
		$member = Context::member();

		if ( null === $member ) {
			return array();
		}

		$payload = array();

		foreach ( LocationRepository::for_member( $member ) as $location ) {
			$payload[] = array_merge(
				array(
					'id'   => $location->get_id(),
					'name' => $location->get_name(),
				),
				$location->get_shipping_address()
			);
		}

		return $payload;
	}

	/**
	 * Whether an organization lets its members type a one-off address.
	 *
	 * @param Organization|null $organization Organization, or null for the current one.
	 * @return bool True when a one-off address is allowed.
	 */
	public static function allows_custom( $organization = null ) {
		$organization = ( $organization instanceof Organization ) ? $organization : Context::organization();

		return $organization instanceof Organization && $organization->allows_custom_shipping();
	}
}
