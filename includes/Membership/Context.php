<?php
/**
 * Which organization the current request is acting for.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Membership;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The membership and organization behind a user, and what they are allowed to do.
 *
 * Everything that needs to know "who is this order for?" asks here, so the rule is
 * written once. A user has at most one membership, so there is nothing to choose
 * between and no organization switcher to get wrong.
 */
final class Context {

	/**
	 * Organizations already loaded this request, keyed by ID.
	 *
	 * @var array
	 */
	private static $organizations = array();

	/**
	 * Resolve a user ID, defaulting to the current user.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return int User ID, or 0 when nobody is logged in.
	 */
	private static function resolve_user( $user_id ) {
		$user_id = absint( $user_id );

		return $user_id > 0 ? $user_id : get_current_user_id();
	}

	/**
	 * The membership belonging to a user.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return Member|null Membership, or null when the user belongs to no organization.
	 */
	public static function member( $user_id = 0 ) {
		$user_id = self::resolve_user( $user_id );

		if ( 0 === $user_id ) {
			return null;
		}

		return MemberRepository::find_by_user( $user_id );
	}

	/**
	 * The organization a user belongs to.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return Organization|null Organization, or null when the user belongs to none.
	 */
	public static function organization( $user_id = 0 ) {
		$member = self::member( $user_id );

		if ( null === $member ) {
			return null;
		}

		return self::organization_by_id( $member->get_organization_id() );
	}

	/**
	 * Load an organization once per request.
	 *
	 * @param int $organization_id Organization ID.
	 * @return Organization|null Organization, or null when there is no such row.
	 */
	public static function organization_by_id( $organization_id ) {
		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return null;
		}

		if ( ! array_key_exists( $organization_id, self::$organizations ) ) {
			self::$organizations[ $organization_id ] = OrganizationRepository::find( $organization_id );
		}

		return self::$organizations[ $organization_id ];
	}

	/**
	 * The ID of the organization a user belongs to.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return int Organization ID, or 0 when the user belongs to none.
	 */
	public static function organization_id( $user_id = 0 ) {
		$member = self::member( $user_id );

		return null === $member ? 0 : $member->get_organization_id();
	}

	/**
	 * Whether a user administers their organization.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return bool True for an active organization admin.
	 */
	public static function is_organization_admin( $user_id = 0 ) {
		$member = self::member( $user_id );

		return null !== $member && $member->is_active() && $member->is_admin();
	}

	/**
	 * Whether a user may check out on their organization's behalf.
	 *
	 * The single expression of the purchase rule: logged in, an active member of an
	 * organization, that organization active, and holding the capability to buy.
	 * Everything that blocks a checkout — classic, Store API, cart page — asks this.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return bool True when the user may purchase.
	 */
	public static function can_purchase( $user_id = 0 ) {
		$user_id = self::resolve_user( $user_id );

		if ( 0 === $user_id ) {
			return false;
		}

		$member = self::member( $user_id );

		if ( null === $member || ! $member->is_active() ) {
			return false;
		}

		$organization = self::organization_by_id( $member->get_organization_id() );

		if ( null === $organization || ! $organization->is_active() ) {
			return false;
		}

		return user_can( $user_id, Roles::PLACE_ORDERS );
	}

	/**
	 * Why a user may not check out.
	 *
	 * Returned as a message rather than a code because every caller shows it and none
	 * branches on it. The reasons are deliberately specific — "your organization is
	 * awaiting approval" is actionable in a way that "you cannot check out" is not —
	 * and none of them leak anything the user does not already know about themselves.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return string Translated message, or an empty string when the user may purchase.
	 */
	public static function purchase_blocked_reason( $user_id = 0 ) {
		$user_id = self::resolve_user( $user_id );

		if ( 0 === $user_id ) {
			return __( 'You must be logged in to check out.', 'woo-organization-accounts-pro' );
		}

		$member = self::member( $user_id );

		if ( null === $member ) {
			return sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Your account is not linked to a %s, so it cannot place orders.', 'woo-organization-accounts-pro' ),
				\WooOrgAccounts\Labels::organization()
			);
		}

		if ( ! $member->is_active() ) {
			return __( 'Your membership is not active, so you cannot place orders.', 'woo-organization-accounts-pro' );
		}

		$organization = self::organization_by_id( $member->get_organization_id() );

		if ( null === $organization ) {
			return sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Your account is not linked to a %s, so it cannot place orders.', 'woo-organization-accounts-pro' ),
				\WooOrgAccounts\Labels::organization()
			);
		}

		if ( Organization::STATUS_PENDING === $organization->get_status() ) {
			return sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Your %s is still awaiting approval. You will be able to order as soon as it is approved.', 'woo-organization-accounts-pro' ),
				\WooOrgAccounts\Labels::organization()
			);
		}

		if ( ! $organization->is_active() ) {
			return sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Your %s cannot place orders at the moment. Please contact the shop.', 'woo-organization-accounts-pro' ),
				\WooOrgAccounts\Labels::organization()
			);
		}

		if ( ! user_can( $user_id, Roles::PLACE_ORDERS ) ) {
			return __( 'You do not have permission to place orders.', 'woo-organization-accounts-pro' );
		}

		return '';
	}

	/**
	 * Forget everything loaded this request.
	 *
	 * Needed by the test suite, and by any code that changes an organization and then
	 * has to read it back within the same request.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$organizations = array();
		MemberRepository::flush_cache();
	}
}
