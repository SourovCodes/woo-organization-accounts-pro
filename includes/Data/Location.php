<?php
/**
 * Location entity.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A place an organization can have goods delivered to.
 *
 * A shop, branch or warehouse in business mode; a campus, department or office in
 * educational mode. The record is the same either way — only the noun on the screen
 * changes, and that comes from the site's organization mode.
 */
class Location extends Entity {

	/**
	 * The address columns, named exactly as WooCommerce names a shipping address.
	 *
	 * Kept identical on purpose: a location is handed to an order as-is, so there is
	 * nothing to translate between the two shapes and nothing to guess. The previous
	 * schema stored one "contact name" and split it at checkout, which gave every
	 * one-word contact an empty surname.
	 *
	 * @var string[]
	 */
	const ADDRESS_FIELDS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'phone',
	);

	/**
	 * Every storable column and its default.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array_merge(
			array(
				'organization_id' => 0,
				'name'            => '',
			),
			array_fill_keys( self::ADDRESS_FIELDS, '' ),
			array(
				'is_default'   => false,
				'date_created' => null,
			)
		);
	}

	/**
	 * Column types.
	 *
	 * @return array Map of column name to type.
	 */
	public static function casts() {
		return array(
			'organization_id' => 'int',
			'is_default'      => 'bool',
		);
	}

	/**
	 * The organization this location belongs to.
	 *
	 * @return int Organization ID.
	 */
	public function get_organization_id() {
		return (int) $this->get( 'organization_id' );
	}

	/**
	 * The location's name.
	 *
	 * @return string Name.
	 */
	public function get_name() {
		return (string) $this->get( 'name' );
	}

	/**
	 * Whether this is the organization's default delivery location.
	 *
	 * @return bool True for the default location.
	 */
	public function is_default() {
		return (bool) $this->get( 'is_default' );
	}

	/**
	 * The address as a WooCommerce shipping address.
	 *
	 * A straight copy: the columns are WooCommerce's field names, so there is nothing
	 * to derive, reshape or split. Whatever a person typed into the delivery contact's
	 * surname is the surname the courier gets.
	 *
	 * @return array Map of WooCommerce shipping field to value.
	 */
	public function get_shipping_address() {
		$address = array();

		foreach ( self::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = (string) $this->get( $field );
		}

		return $address;
	}

	/**
	 * Replace the address.
	 *
	 * @param array $address Map of WooCommerce shipping field to value. Unknown keys are ignored.
	 * @return $this
	 */
	public function set_shipping_address( array $address ) {
		foreach ( self::ADDRESS_FIELDS as $field ) {
			if ( array_key_exists( $field, $address ) ) {
				$this->set( $field, $address[ $field ] );
			}
		}

		return $this;
	}

	/**
	 * The delivery contact's full name, for display.
	 *
	 * @return string Name, or an empty string when nobody is named.
	 */
	public function get_contact_name() {
		return trim( $this->get( 'first_name' ) . ' ' . $this->get( 'last_name' ) );
	}

	/**
	 * The address as a formatted single string.
	 *
	 * @return string Formatted address.
	 */
	public function get_formatted_address() {
		return WC()->countries->get_formatted_address( $this->get_shipping_address() );
	}
}
