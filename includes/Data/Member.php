<?php
/**
 * Membership entity.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The link between a WordPress user and an organization.
 *
 * A user has at most one of these — the `user_id` column is UNIQUE — which is what
 * makes "which organization is this order for?" a question with exactly one answer.
 */
class Member extends Entity {

	/**
	 * Full control of the organization.
	 */
	const ROLE_ADMIN = 'admin';

	/**
	 * Places orders using the organization's data.
	 */
	const ROLE_MEMBER = 'member';

	/**
	 * The member may act on behalf of the organization.
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * The membership is on hold. The user keeps their account but cannot buy.
	 */
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Every storable column and its default.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array(
			'organization_id' => 0,
			'user_id'         => 0,
			'role'            => self::ROLE_MEMBER,
			'status'          => self::STATUS_ACTIVE,
			'capabilities'    => '',
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
			'user_id'         => 'int',
		);
	}

	/**
	 * Every role a member can hold, with its label.
	 *
	 * Labels follow the site's organization mode, so this cannot be a constant.
	 *
	 * @return array Map of role to translated label.
	 */
	public static function roles() {
		return array(
			self::ROLE_ADMIN  => \WooOrgAccounts\Labels::organization_admin(),
			self::ROLE_MEMBER => \WooOrgAccounts\Labels::member(),
		);
	}

	/**
	 * The organization this membership belongs to.
	 *
	 * @return int Organization ID.
	 */
	public function get_organization_id() {
		return (int) $this->get( 'organization_id' );
	}

	/**
	 * The WordPress user this membership belongs to.
	 *
	 * @return int User ID.
	 */
	public function get_user_id() {
		return (int) $this->get( 'user_id' );
	}

	/**
	 * The member's role within the organization.
	 *
	 * @return string One of the ROLE_* constants.
	 */
	public function get_role() {
		return (string) $this->get( 'role' );
	}

	/**
	 * Whether the member administers the organization.
	 *
	 * @return bool True for an organization admin.
	 */
	public function is_admin() {
		return self::ROLE_ADMIN === $this->get_role();
	}

	/**
	 * Whether the membership is in force.
	 *
	 * @return bool True when the member may act for the organization.
	 */
	public function is_active() {
		return self::STATUS_ACTIVE === (string) $this->get( 'status' );
	}

	/**
	 * The per-member capability overrides.
	 *
	 * Stored as JSON so the role system can be extended without a schema change: a
	 * capability granted or revoked for one member sits here and layers over whatever
	 * their role gives them.
	 *
	 * @return array Map of capability name to boolean.
	 */
	public function get_capabilities() {
		$raw = (string) $this->get( 'capabilities' );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$capabilities = array();

		foreach ( $decoded as $capability => $granted ) {
			$capabilities[ (string) $capability ] = (bool) $granted;
		}

		return $capabilities;
	}

	/**
	 * Replace the per-member capability overrides.
	 *
	 * @param array $capabilities Map of capability name to boolean.
	 * @return $this
	 */
	public function set_capabilities( array $capabilities ) {
		$clean = array();

		foreach ( $capabilities as $capability => $granted ) {
			$clean[ (string) $capability ] = (bool) $granted;
		}

		return $this->set( 'capabilities', empty( $clean ) ? '' : wp_json_encode( $clean ) );
	}
}
