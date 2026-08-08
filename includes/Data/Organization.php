<?php
/**
 * Organization entity.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One organization: the account the shop actually sells to.
 *
 * There is deliberately no "type" column. The site runs in exactly one organization
 * mode, chosen once in the plugin settings, and that mode changes labels rather than
 * data — a company and an institute are the same record with different nouns on the
 * screen. Storing the type per row would invite mixed sites the rest of the plugin
 * does not support.
 */
class Organization extends Entity {

	/**
	 * Awaiting review by the shop. Cannot purchase.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Approved. The only status that can purchase.
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * Temporarily blocked, and expected back. Cannot purchase.
	 */
	const STATUS_SUSPENDED = 'suspended';

	/**
	 * Turned down. Cannot purchase.
	 */
	const STATUS_REJECTED = 'rejected';

	/**
	 * The billing columns, without their `billing_` prefix.
	 *
	 * The order matches WooCommerce's own billing fields so an address can be handed
	 * straight to an order.
	 *
	 * @var string[]
	 */
	const BILLING_FIELDS = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'email',
		'phone',
	);

	/**
	 * Every storable column and its default.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array(
			'name'                  => '',
			'email'                 => '',
			'phone'                 => '',
			'tax_id'                => '',
			'status'                => self::STATUS_PENDING,
			'allow_custom_shipping' => true,
			'billing_first_name'    => '',
			'billing_last_name'     => '',
			'billing_company'       => '',
			'billing_address_1'     => '',
			'billing_address_2'     => '',
			'billing_city'          => '',
			'billing_state'         => '',
			'billing_postcode'      => '',
			'billing_country'       => '',
			'billing_email'         => '',
			'billing_phone'         => '',
			'date_created'          => null,
			'date_modified'         => null,
		);
	}

	/**
	 * Column types.
	 *
	 * @return array Map of column name to type.
	 */
	public static function casts() {
		return array(
			'allow_custom_shipping' => 'bool',
		);
	}

	/**
	 * Every status an organization can hold, with its label.
	 *
	 * @return array Map of status to translated label.
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING   => __( 'Pending', 'woo-organization-accounts-pro' ),
			self::STATUS_ACTIVE    => __( 'Active', 'woo-organization-accounts-pro' ),
			self::STATUS_SUSPENDED => __( 'Suspended', 'woo-organization-accounts-pro' ),
			self::STATUS_REJECTED  => __( 'Rejected', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * The organization's name.
	 *
	 * @return string Name.
	 */
	public function get_name() {
		return (string) $this->get( 'name' );
	}

	/**
	 * The organization's status.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function get_status() {
		return (string) $this->get( 'status' );
	}

	/**
	 * The translated label for the organization's status.
	 *
	 * @return string Label, or the raw status when it is not one we know.
	 */
	public function get_status_label() {
		$statuses = self::statuses();
		$status   = $this->get_status();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Whether the organization is allowed to buy.
	 *
	 * The one place the "only active organizations can purchase" rule is expressed.
	 *
	 * @return bool True when the status is active.
	 */
	public function is_active() {
		return self::STATUS_ACTIVE === $this->get_status();
	}

	/**
	 * Whether members may enter a one-off shipping address at checkout.
	 *
	 * @return bool True when a custom shipping address is allowed.
	 */
	public function allows_custom_shipping() {
		return (bool) $this->get( 'allow_custom_shipping' );
	}

	/**
	 * The billing address, in WooCommerce's field naming.
	 *
	 * This is the address every order from this organization is billed to. Members
	 * cannot edit it, and the copy WooCommerce stores on the order is a snapshot, so
	 * changing it here never rewrites an order that has already been placed.
	 *
	 * @return array Map of WooCommerce billing field to value.
	 */
	public function get_billing_address() {
		$address = array();

		foreach ( self::BILLING_FIELDS as $field ) {
			$address[ $field ] = (string) $this->get( 'billing_' . $field );
		}

		return $address;
	}

	/**
	 * Replace the billing address.
	 *
	 * @param array $address Map of WooCommerce billing field to value. Unknown keys are ignored.
	 * @return $this
	 */
	public function set_billing_address( array $address ) {
		foreach ( self::BILLING_FIELDS as $field ) {
			if ( array_key_exists( $field, $address ) ) {
				$this->set( 'billing_' . $field, $address[ $field ] );
			}
		}

		return $this;
	}

	/**
	 * The billing address as a formatted single string.
	 *
	 * @return string Formatted address.
	 */
	public function get_formatted_billing_address() {
		return WC()->countries->get_formatted_address( $this->get_billing_address() );
	}
}
