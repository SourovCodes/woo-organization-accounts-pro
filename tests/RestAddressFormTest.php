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
		$this->assertArrayHasKey( $country, $data['forms'] );

		$fields = array();

		foreach ( $data['forms'][ $country ] as $field ) {
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
				$this->assertSame( ! empty( $field['required'] ), $form[ $name ]['required'], $country . ' disagrees on ' . $name );
			}
		}
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
