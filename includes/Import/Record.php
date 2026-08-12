<?php
/**
 * One row of an import, read through the column mapping.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AddressFields;

defined( 'ABSPATH' ) || exit;

/**
 * A source row turned into the shapes this plugin stores.
 *
 * Reading and writing are separate on purpose: this class touches no table, so the
 * preview pass that tells somebody what an import is about to do runs exactly the code
 * the import itself will run, and can be trusted to have got the same answer.
 *
 * **Nothing here refuses a row.** Every problem it finds becomes a warning carried
 * alongside the record, and `Importer` still creates the account. A customer whose
 * postcode is missing can sign in, see their orders and be repaired by the shop; a
 * customer who was never imported has to be told to register again, and some of them
 * will not. The warnings are what the report is for.
 */
final class Record {

	/**
	 * The row's position in the file, counting data rows from one.
	 *
	 * @var int
	 */
	private $number;

	/**
	 * The row's values, keyed by the importer's own field names.
	 *
	 * @var array
	 */
	private $values;

	/**
	 * The normalised billing address.
	 *
	 * @var array
	 */
	private $billing;

	/**
	 * The normalised delivery address.
	 *
	 * @var array
	 */
	private $delivery;

	/**
	 * Problems found while reading the row.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Whether the delivery address was taken from the billing address.
	 *
	 * @var bool
	 */
	private $delivery_from_billing = false;

	/**
	 * The options the import is running under.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Read a row.
	 *
	 * @param int   $number  Row number, counting data rows from one.
	 * @param array $row     Raw values keyed by column heading.
	 * @param array $mapping Map of field name to column heading.
	 * @param array $options Import options; see Run::default_options().
	 */
	public function __construct( $number, array $row, array $mapping, array $options = array() ) {
		$this->number  = (int) $number;
		$this->options = $options;
		$this->values  = array();

		foreach ( $mapping as $field => $header ) {
			$this->values[ $field ] = ( '' !== (string) $header && isset( $row[ $header ] ) ) ? trim( (string) $row[ $header ] ) : '';
		}

		$this->read_addresses();
	}

	/**
	 * Normalise both address blocks and record what was wrong with them.
	 *
	 * Both go through `AddressFields::validate()`, which is the checkout's own
	 * per-country rule set — so a postcode arrives formatted the way WooCommerce
	 * formats it and a state arrives as its code rather than its name, and the key an
	 * organization is grouped by is built from the same value a re-run will build it
	 * from. The errors it raises are kept as warnings and thrown away as refusals.
	 *
	 * @return void
	 */
	private function read_addresses() {
		$this->billing = $this->address_block( 'billing' );

		$delivery = $this->address_block( 'shipping' );

		if ( '' === $delivery['address_1'] && '' === $delivery['city'] ) {
			$delivery                    = $this->billing_as_delivery();
			$this->delivery_from_billing = true;
		}

		$this->delivery = $delivery;

		$this->normalise( AddressFields::BILLING, $this->billing, __( 'Billing address', 'woo-organization-accounts-pro' ) );
		$this->normalise( AddressFields::SHIPPING, $this->delivery, __( 'Delivery address', 'woo-organization-accounts-pro' ) );

		if ( '' === $this->email() ) {
			$this->warnings[] = __( 'No email address, so no account can be created for this row.', 'woo-organization-accounts-pro' );
		} elseif ( ! is_email( $this->email() ) ) {
			/*
			 * The address itself is not repeated into the warning, even though it is the
			 * obvious thing to put there. Warnings are tallied across the whole file so
			 * that "581 rows have no phone number" can be said in one line instead of 581,
			 * and a message carrying the row's own data is a tally of one every time.
			 * Every row's address is already beside its warnings, in the report and on
			 * the preview screen.
			 */
			$this->warnings[] = __( 'The email address is not one WordPress will accept.', 'woo-organization-accounts-pro' );
		}
	}

	/**
	 * Collect one address block from the mapped values.
	 *
	 * @param string $type 'billing' or 'shipping'.
	 * @return array Address keyed as WooCommerce names its fields.
	 */
	private function address_block( $type ) {
		$address = array();

		foreach ( Mapping::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = (string) ( $this->values[ $type . '_' . $field ] ?? '' );
		}

		$address['country'] = $this->country_code( $address['country'] );

		return $address;
	}

	/**
	 * The billing address, reshaped as a delivery address.
	 *
	 * A row whose export carried no separate delivery address still has to become an
	 * organization somebody can check out from, and an organization with no location
	 * cannot be shipped to at all. Its billing address is the one address it is known
	 * to receive post at.
	 *
	 * @return array Address keyed as WooCommerce names its shipping fields.
	 */
	private function billing_as_delivery() {
		$address = array();

		foreach ( Location::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = (string) ( $this->billing[ $field ] ?? '' );
		}

		return $address;
	}

	/**
	 * Put an address through WooCommerce's own validation, keeping only the corrections.
	 *
	 * @param string $type    AddressFields::BILLING or AddressFields::SHIPPING.
	 * @param array  $address Address, normalised in place.
	 * @param string $label   What to call this address in a warning.
	 * @return void
	 */
	private function normalise( $type, array &$address, $label ) {
		$errors  = new \WP_Error();
		$subject = $address;

		AddressFields::validate( $type, $subject, $errors );

		$address = array_merge( $address, $subject );

		foreach ( $errors->get_error_messages() as $message ) {
			$this->warnings[] = sprintf(
				/* translators: 1: which address, for example "Billing address". 2: what is wrong with it. */
				__( '%1$s: %2$s', 'woo-organization-accounts-pro' ),
				$label,
				wp_strip_all_tags( (string) $message )
			);
		}
	}

	/**
	 * Turn whatever the file calls a country into a country code.
	 *
	 * A code is taken as it is; a country name is looked up in WooCommerce's own list,
	 * which is translated, so a German export saying "Schweiz" resolves on a German
	 * site. Anything left over falls back to the shop's base country, because an
	 * address with no country cannot be validated, formatted or shipped to, and the
	 * shop's own country is the likeliest answer for a customer who was already buying
	 * there.
	 *
	 * @param string $value Raw value.
	 * @return string Two-letter country code.
	 */
	private function country_code( $value ) {
		$value     = trim( (string) $value );
		$countries = WC()->countries->get_countries();

		if ( 2 === strlen( $value ) && isset( $countries[ strtoupper( $value ) ] ) ) {
			return strtoupper( $value );
		}

		if ( '' !== $value ) {
			foreach ( $countries as $code => $name ) {
				if ( 0 === strcasecmp( $name, $value ) ) {
					return $code;
				}
			}
		}

		return WC()->countries->get_base_country();
	}

	/**
	 * The row's position in the file.
	 *
	 * @return int Row number.
	 */
	public function number() {
		return $this->number;
	}

	/**
	 * The email address the account will be created under.
	 *
	 * @return string Email address.
	 */
	public function email() {
		return sanitize_email( (string) ( $this->values['email'] ?? '' ) );
	}

	/**
	 * Whether this row can become an account at all.
	 *
	 * The one thing an account cannot be created without. Everything else is repairable
	 * afterwards; a WordPress user has to have an address to sign in with.
	 *
	 * @return bool True when the row carries a usable email address.
	 */
	public function is_importable() {
		return '' !== $this->email() && is_email( $this->email() );
	}

	/**
	 * The person's first name.
	 *
	 * Falls back to the billing address's, which is the same person on all but a
	 * handful of rows and is the only other place a name is recorded.
	 *
	 * @return string First name.
	 */
	public function first_name() {
		$name = (string) ( $this->values['first_name'] ?? '' );

		return '' !== $name ? $name : (string) $this->billing['first_name'];
	}

	/**
	 * The person's last name.
	 *
	 * @return string Last name.
	 */
	public function last_name() {
		$name = (string) ( $this->values['last_name'] ?? '' );

		return '' !== $name ? $name : (string) $this->billing['last_name'];
	}

	/**
	 * The customer number the source shop knew this account by.
	 *
	 * Not stored — this plugin has no column for it and inventing one would be a field
	 * with no destination. It goes in the report, which is what the shop reconciles
	 * against its old system.
	 *
	 * @return string Customer number.
	 */
	public function customer_number() {
		return (string) ( $this->values['customer_number'] ?? '' );
	}

	/**
	 * Whether the source row was an account still in use.
	 *
	 * An unmapped or empty column reads as active: a shop that did not export the flag
	 * is not telling us its customers are suspended.
	 *
	 * @return bool True when the account was active.
	 */
	public function is_active() {
		$value = strtolower( trim( (string) ( $this->values['active'] ?? '' ) ) );

		if ( '' === $value ) {
			return true;
		}

		return ! in_array( $value, array( '0', 'no', 'n', 'false', 'off', 'inactive', 'disabled', 'nein', 'inaktiv' ), true );
	}

	/**
	 * What the organization will be called.
	 *
	 * The company name where there is one, and the person's name where there is not — a
	 * sole trader is still an organization on a shop that sells to nobody else, and
	 * calling their account by their own name is what every screen and every parcel
	 * label needs. The email address is the last resort, so an organization is never
	 * nameless.
	 *
	 * @return string Name.
	 */
	public function organization_name() {
		foreach ( array( (string) ( $this->values['organization'] ?? '' ), (string) $this->billing['company'], trim( $this->first_name() . ' ' . $this->last_name() ) ) as $candidate ) {
			if ( '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		$email = $this->email();
		$at    = strpos( $email, '@' );

		return false === $at ? $email : substr( $email, 0, $at );
	}

	/**
	 * The organization's tax ID.
	 *
	 * @return string Tax ID.
	 */
	public function tax_id() {
		return (string) ( $this->values['tax_id'] ?? '' );
	}

	/**
	 * The billing address, normalised.
	 *
	 * The email is the account holder's where the file carried none of its own, because
	 * this is the address every order confirmation is sent to and an organization with
	 * no billing email is one whose customer never hears from the shop again.
	 *
	 * @return array Address keyed as WooCommerce names its billing fields.
	 */
	public function billing_address() {
		$address = $this->billing;

		$address['email'] = sanitize_email( (string) ( $address['email'] ?? '' ) );

		if ( '' === $address['email'] ) {
			$address['email'] = $this->email();
		}

		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( '' === trim( (string) $address[ $field ] ) ) {
				$address[ $field ] = 'first_name' === $field ? $this->first_name() : $this->last_name();
			}
		}

		return $address;
	}

	/**
	 * The delivery address this row contributes as a location.
	 *
	 * @return array Address keyed as WooCommerce names its shipping fields.
	 */
	public function delivery_address() {
		$address = array();

		foreach ( Location::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = (string) ( $this->delivery[ $field ] ?? '' );
		}

		return $address;
	}

	/**
	 * Whether the delivery address is a copy of the billing address.
	 *
	 * @return bool True when the row carried no delivery address of its own.
	 */
	public function delivery_is_billing() {
		return $this->delivery_from_billing;
	}

	/**
	 * What to call the location this row creates.
	 *
	 * The name is only the label in the checkout's delivery selector, so it wants to be
	 * whatever tells one address apart from another: the company receiving the goods
	 * where that differs, and the town where it does not.
	 *
	 * @return string Name.
	 */
	public function location_name() {
		$address = $this->delivery_address();
		$company = trim( (string) $address['company'] );
		$city    = trim( (string) $address['city'] );

		if ( '' !== $company && OrganizationKey::normalise( $company ) !== OrganizationKey::normalise( $this->organization_name() ) ) {
			return '' !== $city ? $company . ' – ' . $city : $company;
		}

		if ( '' !== $city ) {
			return $city;
		}

		return $this->organization_name();
	}

	/**
	 * The key deciding which organization this row belongs to.
	 *
	 * @return string Key.
	 */
	public function organization_key() {
		return OrganizationKey::for_address( $this->billing_address(), ! empty( $this->options['ignore_legal_form'] ) );
	}

	/**
	 * The status the organization takes when this row is the one that creates it.
	 *
	 * @return string One of the Organization::STATUS_* constants.
	 */
	public function organization_status() {
		return $this->is_active() ? Organization::STATUS_ACTIVE : Organization::STATUS_SUSPENDED;
	}

	/**
	 * Everything found wrong with the row.
	 *
	 * @return string[] Warnings.
	 */
	public function warnings() {
		return $this->warnings;
	}

	/**
	 * Add a warning raised while the row was being written.
	 *
	 * @param string $warning Message.
	 * @return void
	 */
	public function add_warning( $warning ) {
		$this->warnings[] = (string) $warning;
	}
}
