<?php
/**
 * Address field tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Frontend\AddressFields;

/**
 * That the plugin's address forms are WooCommerce's address forms.
 *
 * The point of every test here is the same: an address collected by this plugin has
 * to be one the checkout will accept, because it is the address the checkout will use.
 * The first version asked every country for the same eight fields and validated almost
 * none of them, which produced organizations that registered happily and then could
 * not order.
 */
class AddressFieldsTest extends TestCase {

	/**
	 * Which fields exist depends on the country, because WooCommerce says so.
	 */
	public function testFieldsFollowTheCountry() {
		$germany = AddressFields::keys( AddressFields::SHIPPING, 'DE' );
		$states  = AddressFields::keys( AddressFields::SHIPPING, 'US' );

		$this->assertContains( 'first_name', $germany );
		$this->assertContains( 'last_name', $germany );
		$this->assertContains( 'postcode', $germany );
		$this->assertContains( 'state', $states );
	}

	/**
	 * The labels are the country's own words, not one hardcoded set.
	 */
	public function testLabelsFollowTheCountry() {
		$us = AddressFields::fields( AddressFields::SHIPPING, 'US' );
		$ca = AddressFields::fields( AddressFields::SHIPPING, 'CA' );
		$gb = AddressFields::fields( AddressFields::SHIPPING, 'GB' );

		$this->assertSame( 'State', $us['shipping_state']['label'] );
		$this->assertSame( 'Province', $ca['shipping_state']['label'] );
		$this->assertSame( 'County', $gb['shipping_state']['label'] );
	}

	/**
	 * Whether a field is required depends on the country too.
	 */
	public function testRequirednessFollowsTheCountry() {
		$us = AddressFields::fields( AddressFields::SHIPPING, 'US' );
		$de = AddressFields::fields( AddressFields::SHIPPING, 'DE' );

		$this->assertTrue( ! empty( $us['shipping_state']['required'] ), 'A US address needs a state.' );
		$this->assertTrue( empty( $de['shipping_state']['required'] ), 'A German address does not.' );
	}

	/**
	 * A complete address passes.
	 */
	public function testValidAddressPasses() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Grace',
			'last_name'  => 'Hopper',
			'company'    => 'Acme GmbH',
			'address_1'  => '9 Lagerweg',
			'address_2'  => '',
			'city'       => 'Hamburg',
			'state'      => '',
			'postcode'   => '20095',
			'country'    => 'DE',
			'phone'      => '+49 40 123456',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		$this->assertFalse( $errors->has_errors(), implode( ' | ', $errors->get_error_messages() ) );
	}

	/**
	 * A postcode that is not valid for its country is refused.
	 */
	public function testPostcodeIsValidatedAgainstItsCountry() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Grace',
			'last_name'  => 'Hopper',
			'address_1'  => '1 Main Street',
			'city'       => 'Columbus',
			'state'      => 'OH',
			'postcode'   => 'not-a-zip',
			'country'    => 'US',
			'phone'      => '+1 555 0100',
		);

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		$this->assertContains( 'billing_postcode', $errors->get_error_codes() );
	}

	/**
	 * A postcode is normalised the way WooCommerce normalises one.
	 */
	public function testPostcodeIsFormatted() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Ada',
			'last_name'  => 'Byron',
			'address_1'  => '1 High Street',
			'city'       => 'London',
			'state'      => '',
			'postcode'   => 'sw1a1aa',
			'country'    => 'GB',
			'phone'      => '+44 20 7946 0000',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		$this->assertSame( 'SW1A 1AA', $address['postcode'] );
	}

	/**
	 * A state that is not one of the country's is refused; a full name is accepted
	 * and normalised to its code.
	 */
	public function testStateIsCheckedAgainstTheCountrysList() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Grace',
			'last_name'  => 'Hopper',
			'address_1'  => '1 Main Street',
			'city'       => 'Columbus',
			'state'      => 'Neverland',
			'postcode'   => '43004',
			'country'    => 'US',
			'phone'      => '+1 555 0100',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		$this->assertContains( 'shipping_state', $errors->get_error_codes() );

		$spelled_out          = $address;
		$spelled_out['state'] = 'Ohio';
		$second               = new \WP_Error();

		AddressFields::validate( AddressFields::SHIPPING, $spelled_out, $second );

		$this->assertNotContains( 'shipping_state', $second->get_error_codes() );
		$this->assertSame( 'OH', $spelled_out['state'], 'A state typed in full should be stored as its code.' );
	}

	/**
	 * A country with no state list accepts whatever was typed.
	 */
	public function testStateIsNotCheckedWhereThereIsNoList() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Ada',
			'last_name'  => 'Byron',
			'address_1'  => '1 High Street',
			'city'       => 'London',
			'state'      => 'Greater London',
			'postcode'   => 'SW1A 1AA',
			'country'    => 'GB',
			'phone'      => '+44 20 7946 0000',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		$this->assertNotContains( 'shipping_state', $errors->get_error_codes() );
		$this->assertSame( 'Greater London', $address['state'] );
	}

	/**
	 * A missing required field is named in the error, not reported generically.
	 */
	public function testRequiredFieldsAreNamed() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => '',
			'last_name'  => '',
			'address_1'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => 'DE',
			'phone'      => '',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		foreach ( array( 'shipping_first_name', 'shipping_address_1', 'shipping_city', 'shipping_postcode' ) as $code ) {
			$this->assertContains( $code, $errors->get_error_codes() );
		}

		$this->assertStringContainsString( 'required', $errors->get_error_message( 'shipping_first_name' ) );
	}

	/**
	 * A delivery address may be addressed to a place rather than to a person.
	 *
	 * WooCommerce requires a surname on every address it defines, which is right for
	 * the person paying and wrong for "Warehouse North": demanding one produces an
	 * invented surname on a parcel label. The first name stays required, so a parcel
	 * is always addressed to something.
	 */
	public function testADeliveryAddressDoesNotRequireASurname() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Warehouse North',
			'last_name'  => '',
			'address_1'  => '1 Hauptstrasse',
			'city'       => 'Berlin',
			'postcode'   => '10115',
			'country'    => 'DE',
		);

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		$this->assertNotContains( 'shipping_last_name', $errors->get_error_codes() );
		$this->assertSame( array(), AddressFields::missing( AddressFields::SHIPPING, $address ) );
	}

	/**
	 * Billing still asks for a surname, because a person is paying.
	 */
	public function testABillingAddressStillRequiresASurname() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Ada',
			'last_name'  => '',
			'address_1'  => '1 Hauptstrasse',
			'city'       => 'Berlin',
			'postcode'   => '10115',
			'country'    => 'DE',
			'email'      => 'ada@acme.test',
		);

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		$this->assertContains( 'billing_last_name', $errors->get_error_codes() );
	}

	/**
	 * The shipping form offers a phone number, which is what fills the location column.
	 */
	public function testTheShippingFormOffersAPhoneNumber() {
		$fields = AddressFields::fields( AddressFields::SHIPPING, 'DE' );

		$this->assertArrayHasKey( 'shipping_phone', $fields );
		$this->assertContains( 'phone', (array) $fields['shipping_phone']['validate'] );
		$this->assertContains( 'phone', AddressFields::keys( AddressFields::SHIPPING, 'DE' ) );
	}

	/**
	 * The delivery phone is optional however the shop has set its checkout phone.
	 *
	 * `missing()` decides whether a location can be shipped to at all, so inheriting
	 * that setting would mean a shop switching it on made every location it had already
	 * saved undeliverable — a refusal at somebody's checkout, about a record only an
	 * admin can edit. The setting is a rule about the person buying, which is billing.
	 */
	public function testTheDeliveryPhoneIsOptionalEvenWhenTheShopRequiresOne() {
		update_option( 'woocommerce_checkout_phone_field', 'required' );

		$shipping = AddressFields::fields( AddressFields::SHIPPING, 'DE' );
		$billing  = AddressFields::fields( AddressFields::BILLING, 'DE' );

		$this->assertArrayHasKey( 'shipping_phone', $shipping );
		$this->assertFalse( (bool) $shipping['shipping_phone']['required'] );
		$this->assertTrue( (bool) $billing['billing_phone']['required'], 'Billing must still follow the shop setting.' );

		delete_option( 'woocommerce_checkout_phone_field' );
	}

	/**
	 * A shop that wants no phone numbers is not given one here either.
	 *
	 * WooCommerce removes the field itself; the point of the assertion is that nothing
	 * in `delivery_fields()` puts one back.
	 */
	public function testNoDeliveryPhoneWhenTheShopHidesTheField() {
		update_option( 'woocommerce_checkout_phone_field', 'hidden' );

		$this->assertArrayNotHasKey( 'shipping_phone', AddressFields::fields( AddressFields::SHIPPING, 'DE' ) );

		delete_option( 'woocommerce_checkout_phone_field' );
	}

	/**
	 * An invented country is refused before anything else is looked at.
	 */
	public function testUnknownCountryIsRefused() {
		$errors  = new \WP_Error();
		$address = array( 'country' => 'ZZ' );

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		$this->assertContains( 'billing_country', $errors->get_error_codes() );
	}

	/**
	 * A bad email in the billing block is caught.
	 */
	public function testBillingEmailIsValidated() {
		$errors = new \WP_Error();

		$address = array(
			'first_name' => 'Ada',
			'last_name'  => 'Byron',
			'address_1'  => '1 Hauptstrasse',
			'city'       => 'Berlin',
			'state'      => '',
			'postcode'   => '10115',
			'country'    => 'DE',
			'email'      => 'not-an-email',
			'phone'      => '+49 30 123456',
		);

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		$this->assertContains( 'billing_email', $errors->get_error_codes() );
	}

	/**
	 * Reading a submission takes the country's field set, not a fixed one.
	 */
	public function testPostedDropsFieldsTheCountryDoesNotHave() {
		$_POST = array(
			'shipping_country'    => 'DE',
			'shipping_first_name' => 'Grace',
			'shipping_city'       => 'Hamburg',
			'shipping_nonsense'   => 'ignored',
		);

		$values = AddressFields::posted( AddressFields::SHIPPING );

		$this->assertSame( 'Grace', $values['first_name'] );
		$this->assertSame( 'DE', $values['country'] );
		$this->assertArrayNotHasKey( 'nonsense', $values );
	}

	/**
	 * The rendered markup is what WooCommerce's own scripts expect to find.
	 *
	 * `country-select.js` looks for `#shipping_state` inside
	 * `.woocommerce-shipping-fields`. Rename either and the state field quietly stops
	 * following the country, on a screen that still looks correct.
	 */
	public function testRenderedMarkupMatchesWhatWooCommerceLooksFor() {
		ob_start();
		AddressFields::render( AddressFields::SHIPPING, array( 'country' => 'US' ) );
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'woocommerce-shipping-fields', $markup );
		$this->assertStringContainsString( 'id="shipping_state"', $markup );
		$this->assertStringContainsString( 'id="shipping_country"', $markup );
		$this->assertStringContainsString( 'country_to_state', $markup );

		ob_start();
		AddressFields::render( AddressFields::BILLING, array( 'country' => 'DE' ) );
		$billing = ob_get_clean();

		$this->assertStringContainsString( 'woocommerce-billing-fields', $billing );
		$this->assertStringContainsString( 'id="billing_country"', $billing );
	}

	/**
	 * Values reach the rendered fields.
	 */
	public function testRenderedFieldsCarryTheirValues() {
		ob_start();
		AddressFields::render(
			AddressFields::SHIPPING,
			array(
				'country'    => 'DE',
				'first_name' => 'Grace',
				'city'       => 'Hamburg',
			)
		);
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'value="Grace"', $markup );
		$this->assertStringContainsString( 'value="Hamburg"', $markup );
	}
}
