<?php
/**
 * Roles and capabilities.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Data\Member;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's WordPress roles and the capabilities that go with them.
 *
 * The two WordPress roles carry `read` and nothing else. Every capability this plugin
 * defines is granted at runtime by Capabilities, from the membership row, and never
 * from the role — because a role is a property of a user while membership is a
 * property of a user *and* an organization, and only one of those can be revoked by
 * an organization admin. Putting the capabilities on the role as well would create a
 * second answer to "may this person invite members?" that could disagree with the
 * first.
 */
final class Roles {

	/**
	 * WordPress role given to a user who administers an organization.
	 */
	const ROLE_ORG_ADMIN = 'woap_org_admin';

	/**
	 * WordPress role given to an ordinary organization member.
	 */
	const ROLE_MEMBER = 'woap_member';

	/**
	 * Edit the organization's own details.
	 */
	const MANAGE_ORGANIZATION = 'woap_manage_organization';

	/**
	 * Edit the organization's centralised billing address.
	 */
	const MANAGE_BILLING = 'woap_manage_billing';

	/**
	 * Add, edit and remove the organization's locations.
	 */
	const MANAGE_LOCATIONS = 'woap_manage_locations';

	/**
	 * Change roles, permissions and location access, and remove members.
	 */
	const MANAGE_MEMBERS = 'woap_manage_members';

	/**
	 * Send and revoke invitations.
	 */
	const INVITE_MEMBERS = 'woap_invite_members';

	/**
	 * See every order the organization has placed, not only one's own.
	 */
	const VIEW_ORGANIZATION_ORDERS = 'woap_view_organization_orders';

	/**
	 * Check out on the organization's behalf.
	 */
	const PLACE_ORDERS = 'woap_place_orders';

	/**
	 * Every capability the plugin defines.
	 *
	 * @return string[] Capability names.
	 */
	public static function capabilities() {
		return array(
			self::MANAGE_ORGANIZATION,
			self::MANAGE_BILLING,
			self::MANAGE_LOCATIONS,
			self::MANAGE_MEMBERS,
			self::INVITE_MEMBERS,
			self::VIEW_ORGANIZATION_ORDERS,
			self::PLACE_ORDERS,
		);
	}

	/**
	 * Every capability with a label, for the per-member permissions screen.
	 *
	 * @return array Map of capability to translated label.
	 */
	public static function labels() {
		return array(
			self::MANAGE_ORGANIZATION      => sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Manage the %s profile', 'woo-organization-accounts-pro' ),
				Labels::organization()
			),
			self::MANAGE_BILLING           => __( 'Manage the billing address', 'woo-organization-accounts-pro' ),
			self::MANAGE_LOCATIONS         => sprintf(
				/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
				__( 'Manage %s', 'woo-organization-accounts-pro' ),
				Labels::locations()
			),
			self::MANAGE_MEMBERS           => sprintf(
				/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
				__( 'Manage %s', 'woo-organization-accounts-pro' ),
				Labels::members()
			),
			self::INVITE_MEMBERS           => __( 'Send and revoke invitations', 'woo-organization-accounts-pro' ),
			self::VIEW_ORGANIZATION_ORDERS => sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'View all %s orders', 'woo-organization-accounts-pro' ),
				Labels::organization()
			),
			self::PLACE_ORDERS             => __( 'Place orders', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * The capabilities a membership role grants before per-member overrides.
	 *
	 * @param string $role One of the Member::ROLE_* constants.
	 * @return array Map of capability to boolean.
	 */
	public static function role_capabilities( $role ) {
		$granted = array_fill_keys( self::capabilities(), false );

		if ( Member::ROLE_ADMIN === $role ) {
			return array_fill_keys( self::capabilities(), true );
		}

		$granted[ self::PLACE_ORDERS ] = true;

		return $granted;
	}

	/**
	 * The WordPress role a membership role maps to.
	 *
	 * @param string $role One of the Member::ROLE_* constants.
	 * @return string WordPress role name.
	 */
	public static function wordpress_role( $role ) {
		return Member::ROLE_ADMIN === $role ? self::ROLE_ORG_ADMIN : self::ROLE_MEMBER;
	}

	/**
	 * Register the plugin's WordPress roles.
	 *
	 * The display names are deliberately fixed rather than mode-dependent: a role name
	 * is written into the database once, and re-registering it would not update the
	 * copy already stored against every user. Everything on screen uses Labels, which
	 * follows the mode, so the stored name is never shown.
	 *
	 * @return void
	 */
	public static function install() {
		remove_role( self::ROLE_ORG_ADMIN );
		remove_role( self::ROLE_MEMBER );

		add_role( self::ROLE_ORG_ADMIN, 'Organization Admin', array( 'read' => true ) );
		add_role( self::ROLE_MEMBER, 'Organization Member', array( 'read' => true ) );
	}

	/**
	 * Remove the plugin's WordPress roles.
	 *
	 * Users holding one are moved to WooCommerce's customer role rather than left with
	 * no role at all, which would lock them out of their own account page.
	 *
	 * @return void
	 */
	public static function remove() {
		foreach ( get_users( array( 'role__in' => array( self::ROLE_ORG_ADMIN, self::ROLE_MEMBER ) ) ) as $user ) {
			$user->set_role( 'customer' );
		}

		remove_role( self::ROLE_ORG_ADMIN );
		remove_role( self::ROLE_MEMBER );
	}
}
