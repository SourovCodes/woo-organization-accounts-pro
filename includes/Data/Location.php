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
	 * The address columns, in WooCommerce's shipping field naming.
	 *
	 * @var string[]
	 */
	const ADDRESS_FIELDS = array(
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
	);

	/**
	 * Every storable column and its default.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array(
			'organization_id' => 0,
			'name'            => '',
			'address_1'       => '',
			'address_2'       => '',
			'city'            => '',
			'state'           => '',
			'postcode'        => '',
			'country'         => '',
			'contact_name'    => '',
			'contact_phone'   => '',
			'contact_email'   => '',
			'is_default'      => false,
			'date_created'    => null,
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
	 * The contact name is split across the first and last name fields because that is
	 * what WooCommerce's shipping address expects, and the location's name is carried
	 * in the company field so it shows on the packing slip.
	 *
	 * @return array Map of WooCommerce shipping field to value.
	 */
	public function get_shipping_address() {
		$contact = trim( (string) $this->get( 'contact_name' ) );
		$parts   = '' === $contact ? array() : preg_split( '/\s+/', $contact, 2 );

		$address = array(
			'first_name' => isset( $parts[0] ) ? $parts[0] : '',
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
			'company'    => $this->get_name(),
			'phone'      => (string) $this->get( 'contact_phone' ),
		);

		foreach ( self::ADDRESS_FIELDS as $field ) {
			$address[ $field ] = (string) $this->get( $field );
		}

		return $address;
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
