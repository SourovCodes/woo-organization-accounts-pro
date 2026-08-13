<?php
/**
 * The address form definitions a till renders one-off addresses from.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Frontend\AddressFields;

defined( 'ABSPATH' ) || exit;

/**
 * Serves WooCommerce's own address forms, per country, as data.
 *
 * A till that may take a one-off delivery address has to render an address form, and
 * an address form written by hand is wrong in a different way in every country: it
 * asks a German customer for a state they do not have, offers a Canadian free text
 * where their courier expects a province from a list, and calls a ZIP a postcode in
 * Ohio. Every form this plugin renders on the web avoids that by asking
 * `WC()->countries` through `AddressFields`; this route serialises exactly those
 * definitions so the app can do the same offline. Nothing here decides what an
 * address looks like — including the shop's own checkout-field customisations, which
 * flow through the same filter the web forms apply.
 *
 * Both shapes are served, because a till edits both: `forms` for a delivery address,
 * `billing_forms` for the address an organization is billed at. They are not
 * interchangeable, however similar they look. `AddressFields::delivery_fields()`
 * relaxes `last_name` and `phone` for shipping and deliberately leaves billing under
 * WooCommerce's own rules, and a shop filtering `woocommerce_billing_fields` can part
 * them further. Rendering one from the other's definitions marks a field optional that
 * the write path then requires — a refusal at the counter, over a rule the screen had
 * already told the operator did not apply.
 *
 * Each shape carries its own country list too — `countries` is what the shop ships to,
 * `billing_countries` what it sells to, and WooCommerce keeps them separately because
 * they genuinely differ.
 *
 * The whole set is served at once — one form per country, per shape — rather than per
 * country on demand, because the consumer works offline between syncs. It changes when
 * WooCommerce or the shop's settings do, which is rarely, so the same ETag revalidation
 * as the organization snapshot makes the routine poll a 304.
 */
final class AddressFormController {

	/**
	 * The route, below the namespace.
	 */
	const ROUTE = 'address-form';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			RestApi::REST_NAMESPACE,
			'/' . self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Who may read the form definitions.
	 *
	 * The same answer as the snapshot: the till's key. The data itself is what any
	 * visitor's checkout renders, but an unauthenticated route would be the namespace's
	 * one exception, and an exception is a thing somebody later has to know about.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public function get_item_permissions_check() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'woap_rest_forbidden',
			__( 'You are not allowed to read the address forms.', 'woo-organization-accounts-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Return both address forms, each over its own country list.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The forms, or a 304.
	 */
	public function get_item( $request ) {
		/*
		 * Two country lists, because WooCommerce keeps two. A shop sells to more places
		 * than it ships to whenever it has a customer whose invoices go somewhere its
		 * couriers do not, and offering only the ship-to list would make that
		 * organization's billing country unselectable — an address the shop's own admin
		 * can save and the till cannot.
		 */
		$shipping_countries = WC()->countries->get_shipping_countries();
		$billing_countries  = WC()->countries->get_allowed_countries();

		$shipping = array();
		$billing  = array();

		foreach ( array_keys( $shipping_countries ) as $code ) {
			$shipping[ $code ] = $this->form_for( AddressFields::SHIPPING, $code );
		}

		// Keyed over the billing list rather than the shipping one, or a sell-to country
		// would arrive in the picker with no form behind it.
		foreach ( array_keys( $billing_countries ) as $code ) {
			$billing[ $code ] = $this->form_for( AddressFields::BILLING, $code );
		}

		return Etag::respond(
			array(
				'default_country'   => WC()->countries->get_base_country(),
				'countries'         => $shipping_countries,
				'billing_countries' => $billing_countries,
				'forms'             => $shipping,
				'billing_forms'     => $billing,
			),
			$request
		);
	}

	/**
	 * One country's form of one shape, in display order.
	 *
	 * A serialisation of `AddressFields::fields()` — WooCommerce's field definitions
	 * with the country's locale already applied, so "required" and the labels are the
	 * checkout's own answers for that country, not a summary of them. The state field
	 * carries its options where the country has a list; where it has none, free text
	 * is the correct control, which is also what the checkout renders.
	 *
	 * @param string $type    AddressFields::BILLING or AddressFields::SHIPPING.
	 * @param string $country Two-letter country code.
	 * @return array Field definitions the app can render directly.
	 */
	private function form_for( $type, $country ) {
		$form = array();

		foreach ( AddressFields::fields( $type, $country ) as $key => $field ) {
			$name = substr( $key, strlen( $type ) + 1 );

			$definition = array(
				'name'     => $name,
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
				'required' => ! empty( $field['required'] ),
				'hidden'   => ! empty( $field['hidden'] ),
				'type'     => isset( $field['type'] ) ? (string) $field['type'] : 'text',
			);

			if ( isset( $field['placeholder'] ) && '' !== $field['placeholder'] ) {
				$definition['placeholder'] = (string) $field['placeholder'];
			}

			if ( 'state' === $name ) {
				$states = WC()->countries->get_states( $country );

				if ( ! empty( $states ) && is_array( $states ) ) {
					$definition['options'] = $states;
				}
			}

			$form[] = $definition;
		}

		return $form;
	}

	/**
	 * The schema for the form payload.
	 *
	 * @return array JSON schema.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woap_address_form',
			'type'       => 'object',
			'properties' => array(
				'default_country'   => array(
					'description' => __( 'The shop base country, for preselecting.', 'woo-organization-accounts-pro' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'countries'         => array(
					'description' => __( 'The countries the shop ships to, code to name.', 'woo-organization-accounts-pro' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'billing_countries' => array(
					'description' => __( 'The countries the shop sells to, code to name.', 'woo-organization-accounts-pro' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'forms'             => array_merge(
					array( 'description' => __( 'The shipping address form per country, fields in display order.', 'woo-organization-accounts-pro' ) ),
					self::forms_schema()
				),
				'billing_forms'     => array_merge(
					array( 'description' => __( 'The billing address form per country, fields in display order.', 'woo-organization-accounts-pro' ) ),
					self::forms_schema()
				),
			),
		);
	}

	/**
	 * The shape both `forms` and `billing_forms` share.
	 *
	 * Written once because they are the same structure, and a schema that describes one
	 * of them accurately and the other from memory is worse than no schema.
	 *
	 * @return array The partial schema, without a description.
	 */
	private static function forms_schema() {
		return array(
			'type'                 => 'object',
			'readonly'             => true,
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array(
							'description' => __( 'The field name, as the order API expects it.', 'woo-organization-accounts-pro' ),
							'type'        => 'string',
						),
						'label'       => array(
							'description' => __( 'The label, in the shop language and correct for the country.', 'woo-organization-accounts-pro' ),
							'type'        => 'string',
						),
						'required'    => array(
							'description' => __( 'Whether the country requires the field.', 'woo-organization-accounts-pro' ),
							'type'        => 'boolean',
						),
						'hidden'      => array(
							'description' => __( 'Whether the country hides the field entirely.', 'woo-organization-accounts-pro' ),
							'type'        => 'boolean',
						),
						'type'        => array(
							'description' => __( 'The control type WooCommerce renders for the field.', 'woo-organization-accounts-pro' ),
							'type'        => 'string',
						),
						'placeholder' => array(
							'description' => __( 'Placeholder text, when the field has one.', 'woo-organization-accounts-pro' ),
							'type'        => 'string',
						),
						'options'     => array(
							'description' => __( 'For the state field: the values the country accepts, code to name. Absent means free text.', 'woo-organization-accounts-pro' ),
							'type'        => 'object',
						),
					),
				),
			),
		);
	}
}
