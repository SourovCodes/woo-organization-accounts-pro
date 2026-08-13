<?php
/**
 * Address form route tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Rest\AddressFormController;
use WooOrgAccounts\Rest\RestApi;

/**
 * The per-country address forms a till renders one-off addresses from.
 *
 * Nothing here asserts what any particular country's form looks like — that is
 * WooCommerce's answer and it changes as countries change. What is asserted is that
 * the route serialises WooCommerce's answer rather than a summary of it: the fields,
 * their order, the per-country requirements and the state lists all match what
 * `AddressFields` would render into a web form on the same shop.
 */
class RestAddressFormTest extends TestCase {

	/**
	 * The route, with its namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/' . RestApi::REST_NAMESPACE . '/' . AddressFormController::ROUTE;

	/**
	 * Fetch the forms.
	 *
	 * @param array $headers Request headers.
	 * @return \WP_REST_Response The response.
	 */
	private function fetch( array $headers = array() ) {
		$request = new \WP_REST_Request( 'GET', self::ROUTE );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Index one country's form by field name.
	 *
	 * @param array  $data    The payload.
	 * @param string $country Two-letter country code.
	 * @return array Map of field name to definition.
	 */
	private function form( array $data, $country ) {
		return $this->form_of( $data, 'forms', $country );
	}

	/**
	 * Index one country's billing form by field name.
	 *
	 * @param array  $data    The payload.
	 * @param string $country Two-letter country code.
	 * @return array Map of field name to definition.
	 */
	private function billing_form( array $data, $country ) {
		return $this->form_of( $data, 'billing_forms', $country );
	}

	/**
	 * Index one country's form of one shape by field name.
	 *
	 * @param array  $data    The payload.
	 * @param string $key     'forms' or 'billing_forms'.
	 * @param string $country Two-letter country code.
	 * @return array Map of field name to definition.
	 */
	private function form_of( array $data, $key, $country ) {
		$this->assertArrayHasKey( $country, $data[ $key ] );

		$fields = array();

		foreach ( $data[ $key ][ $country ] as $field ) {
			$fields[ $field['name'] ] = $field;
		}

		return $fields;
	}

	/**
	 * The route is registered and refused without the till's permission.
	 */
	public function testTheRouteIsRegisteredAndGuarded() {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
		$this->assertSame( 401, $this->fetch()->get_status() );
	}

	/**
	 * The countries offered are the ones the shop ships to, WooCommerce's own list.
	 */
	public function testTheCountriesAreTheShopsShippingCountries() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		$this->assertSame( WC()->countries->get_shipping_countries(), $data['countries'] );
		$this->assertSame( WC()->countries->get_base_country(), $data['default_country'] );
		$this->assertSame( array_keys( $data['countries'] ), array_keys( $data['forms'] ) );
	}

	/**
	 * A country with a state list carries it; the till never invents one.
	 */
	public function testAStateListComesFromWooCommerce() {
		$this->act_as_shop_manager();

		$state = $this->form( $this->fetch()->get_data(), 'US' )['state'];

		$this->assertTrue( $state['required'] );
		$this->assertArrayHasKey( 'CA', $state['options'] );
		$this->assertSame( WC()->countries->get_states( 'US' ), $state['options'] );
	}

	/**
	 * Per-country requirements are WooCommerce's answers, not a summary of them.
	 *
	 * Asserted against WooCommerce's own field definitions for the same shop rather
	 * than against hard-coded expectations, so a WooCommerce release that changes a
	 * country's rules changes both sides of this assertion together.
	 *
	 * `last_name` and `phone` are the two fields that are deliberately not WooCommerce's
	 * answer, and they are named here rather than skipped quietly: the till has to be
	 * told about both, or it will refuse an address the shop's own location form
	 * accepts. See `AddressFields::delivery_fields()` for why each is relaxed.
	 */
	public function testRequirementsMatchWooCommercesOwnDefinitions() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		foreach ( array( 'US', 'DE' ) as $country ) {
			$expected = WC()->countries->get_address_fields( $country, 'shipping_' );
			$form     = $this->form( $data, $country );

			foreach ( $expected as $key => $field ) {
				$name = substr( $key, strlen( 'shipping_' ) );

				$this->assertArrayHasKey( $name, $form, $country . ' is missing ' . $name );

				if ( in_array( $name, array( 'last_name', 'phone' ), true ) ) {
					$this->assertFalse( $form[ $name ]['required'], $country . ' requires ' . $name . ' on a delivery address.' );
					continue;
				}

				$this->assertSame( ! empty( $field['required'] ), $form[ $name ]['required'], $country . ' disagrees on ' . $name );
			}
		}
	}

	/**
	 * A billing form is served for every country, beside the shipping one.
	 */
	public function testABillingFormIsServedForEveryCountry() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		$this->assertArrayHasKey( 'billing_forms', $data );
		$this->assertNotEmpty( $this->billing_form( $data, 'DE' ) );
	}

	/**
	 * Each shape is keyed over its own country list, and both lists are WooCommerce's.
	 *
	 * A shop sells to more places than it ships to as soon as one customer's invoices go
	 * somewhere its couriers do not. Keying the billing forms over the *shipping* list
	 * would offer that organization's country in no picker and serve no form behind it,
	 * so the till could not record an address the shop's own admin can.
	 */
	public function testEachShapeIsKeyedOverItsOwnCountryList() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		$this->assertSame( WC()->countries->get_shipping_countries(), $data['countries'] );
		$this->assertSame( WC()->countries->get_allowed_countries(), $data['billing_countries'] );
		$this->assertSame( array_keys( $data['countries'] ), array_keys( $data['forms'] ) );
		$this->assertSame( array_keys( $data['billing_countries'] ), array_keys( $data['billing_forms'] ) );
	}

	/**
	 * Billing keeps WooCommerce's requirements where shipping relaxes them.
	 *
	 * This is the whole reason two forms are served rather than one. A till rendering a
	 * billing address from the *shipping* definitions marks `last_name` optional,
	 * because `AddressFields::delivery_fields()` relaxes it for a delivery address that
	 * belongs to a place rather than a person. Billing has no such relaxation, so the
	 * operator leaves the field blank on a screen that told them it was optional and
	 * the write refuses it. The two shapes have to be able to disagree, and here they
	 * do.
	 */
	public function testBillingKeepsTheRequirementsShippingRelaxes() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		foreach ( array( 'US', 'DE' ) as $country ) {
			$expected = WC()->countries->get_address_fields( $country, 'billing_' );
			$billing  = $this->billing_form( $data, $country );
			$shipping = $this->form( $data, $country );

			foreach ( $expected as $key => $field ) {
				$name = substr( $key, strlen( 'billing_' ) );

				$this->assertArrayHasKey( $name, $billing, $country . ' is missing billing ' . $name );
				$this->assertSame(
					! empty( $field['required'] ),
					$billing[ $name ]['required'],
					$country . ' disagrees with WooCommerce on billing ' . $name
				);
			}

			$this->assertTrue( $billing['last_name']['required'], $country . ' lets a billing surname go unasked.' );
			$this->assertFalse( $shipping['last_name']['required'], $country . ' requires a delivery surname.' );
		}
	}

	/**
	 * Neither form asks for a company.
	 *
	 * An organization already is the company — see `AddressFields::strip_company()`. A
	 * field served here is a field the app draws, so this is where a second box asking
	 * for the name again would come back.
	 */
	public function testNeitherFormAsksForACompany() {
		$this->act_as_shop_manager();

		$data = $this->fetch()->get_data();

		foreach ( array( 'US', 'DE' ) as $country ) {
			$this->assertArrayNotHasKey( 'company', $this->form( $data, $country ) );
			$this->assertArrayNotHasKey( 'company', $this->billing_form( $data, $country ) );
		}
	}

	/**
	 * The till is served the delivery phone the web form collects.
	 *
	 * A one-off delivery address is the one address a till composes itself, so a field
	 * the shop's own location form has and the served form does not would be a till
	 * quietly unable to record a number the courier will ask for.
	 */
	public function testTheServedFormOffersADeliveryPhone() {
		$this->act_as_shop_manager();

		$form = $this->form( $this->fetch()->get_data(), 'DE' );

		$this->assertArrayHasKey( 'phone', $form );
		$this->assertFalse( $form['phone']['required'] );
	}

	/**
	 * A shop's checkout-field customisation reaches the till too.
	 *
	 * The same filter the web forms apply, so a shop that renamed or relaxed a field
	 * on its checkout serves the till the customised form rather than the stock one.
	 */
	public function testShopCustomisationsFlowThrough() {
		$this->act_as_shop_manager();

		$rename = static function ( $fields ) {
			if ( isset( $fields['shipping_city'] ) ) {
				$fields['shipping_city']['label'] = 'Township';
			}

			return $fields;
		};

		add_filter( 'woo_org_accounts_address_fields', $rename );

		$form = $this->form( $this->fetch()->get_data(), 'US' );

		remove_filter( 'woo_org_accounts_address_fields', $rename );

		$this->assertSame( 'Township', $form['city']['label'] );
	}

	/**
	 * An unchanged payload is answered with a 304, like the organization snapshot.
	 */
	public function testAnUnchangedPayloadIsNotSentAgain() {
		$this->act_as_shop_manager();

		$first = $this->fetch();
		$etag  = $first->get_headers()['ETag'];

		$this->assertNotEmpty( $etag );

		$unchanged = $this->fetch( array( 'If-None-Match' => $etag ) );

		$this->assertSame( 304, $unchanged->get_status() );
		$this->assertNull( $unchanged->get_data() );
	}
}
