<?php
/**
 * Runtime capability resolution.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\MemberRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `current_user_can()` for the plugin's capabilities from the membership row.
 *
 * Hooking `user_has_cap` rather than inventing a parallel permission function is what
 * makes the whole permission system ordinary WordPress: every screen, every nonce
 * check and every REST `permission_callback` can ask `current_user_can()` and get an
 * answer that already accounts for the membership role and for whatever an
 * organization admin granted or revoked for that one member.
 *
 * The answer is authoritative in both directions. A user with no active membership is
 * refused every capability here even if something else granted it, because a
 * capability that outlives the membership it came from is exactly how somebody keeps
 * buying on an organization's account after being removed from it.
 */
class Capabilities {

	/**
	 * Register the filter.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'user_has_cap', array( $this, 'resolve' ), 10, 4 );
		add_filter( 'user_has_cap', array( $this, 'resolve_view_order' ), 10, 4 );
	}

	/**
	 * Fill in the plugin's capabilities for the user being checked.
	 *
	 * @param array    $allcaps Capabilities the user already has.
	 * @param string[] $caps    Capabilities being checked, after mapping.
	 * @param array    $args    The original arguments to the capability check.
	 * @param \WP_User $user    The user being checked.
	 * @return array Capabilities, with this plugin's decided.
	 */
	public function resolve( $allcaps, $caps, $args, $user ) {
		$ours = array_intersect( (array) $caps, Roles::capabilities() );

		if ( empty( $ours ) ) {
			return $allcaps;
		}

		/*
		 * Read straight out of the array rather than calling user_can(), which would
		 * re-enter this filter. Anyone who can manage the shop can act on any
		 * organization in it; the admin screens rely on this.
		 */
		if ( ! empty( $allcaps['manage_woocommerce'] ) ) {
			return array_merge( $allcaps, array_fill_keys( Roles::capabilities(), true ) );
		}

		$member = ( $user instanceof \WP_User ) ? MemberRepository::find_by_user( $user->ID ) : null;

		if ( null === $member || ! $member->is_active() ) {
			return array_merge( $allcaps, array_fill_keys( Roles::capabilities(), false ) );
		}

		$resolved = array_merge(
			Roles::role_capabilities( $member->get_role() ),
			$member->get_capabilities()
		);

		foreach ( Roles::capabilities() as $capability ) {
			$allcaps[ $capability ] = ! empty( $resolved[ $capability ] );
		}

		return $allcaps;
	}

	/**
	 * Let a member open an organization order that is not their own.
	 *
	 * `woap_view_organization_orders` is this plugin's capability and answers this
	 * plugin's own screen. The page that screen *links to* is WooCommerce's, and
	 * WooCommerce asks a different question: `wc_customer_has_capability()` grants
	 * `view_order` only when the order's customer is the person asking. So an
	 * organization admin was shown a list of their organization's orders in which every
	 * row placed by somebody else led to "Invalid order."
	 *
	 * Holding the capability and being able to reach what it describes are two facts,
	 * and only the first of them was ours to assert.
	 *
	 * The grant is deliberately one-directional — it only ever sets `view_order` true,
	 * never false. WooCommerce's own rule still grants a member their own order, and a
	 * plain customer on a shop that also sells to individuals is untouched. Denying here
	 * would mean this plugin quietly deciding who may read every order on the site.
	 *
	 * It is also scoped to orders that carry an organization. An order with no
	 * `_woap_organization_id` is not this plugin's business, whoever is asking.
	 *
	 * Only `view_order` is granted, of the five capabilities WooCommerce keys on the
	 * order's customer. `pay_for_order`, `order_again` and `cancel_order` are not
	 * reading an order but spending or changing one, which belongs to
	 * `woap_place_orders` and to a decision nobody has asked for yet; `download_file`
	 * is keyed on the download rather than the order. Granting them here because they
	 * sit in the same switch statement would be inventing policy.
	 *
	 * @param array    $allcaps Capabilities the user already has.
	 * @param string[] $caps    Capabilities being checked, after mapping.
	 * @param array    $args    The original arguments to the capability check.
	 * @param \WP_User $user    The user being checked.
	 * @return array Capabilities, with `view_order` granted if the organization allows it.
	 */
	public function resolve_view_order( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( 'view_order', (array) $caps, true ) || ! empty( $allcaps['view_order'] ) ) {
			return $allcaps;
		}

		if ( ! isset( $args[2] ) || ! function_exists( 'wc_get_order' ) ) {
			return $allcaps;
		}

		$order = wc_get_order( $args[2] );

		if ( ! $order instanceof \WC_Order ) {
			return $allcaps;
		}

		$organization_id = OrderMeta::organization_id( $order );

		if ( $organization_id <= 0 ) {
			return $allcaps;
		}

		/*
		 * Read straight out of the array rather than calling user_can(), which would
		 * re-enter this filter.
		 */
		if ( ! empty( $allcaps['manage_woocommerce'] ) ) {
			$allcaps['view_order'] = true;

			return $allcaps;
		}

		$member = ( $user instanceof \WP_User ) ? MemberRepository::find_by_user( $user->ID ) : null;

		if ( null === $member || ! $member->is_active() || $member->get_organization_id() !== $organization_id ) {
			return $allcaps;
		}

		$resolved = array_merge(
			Roles::role_capabilities( $member->get_role() ),
			$member->get_capabilities()
		);

		if ( ! empty( $resolved[ Roles::VIEW_ORGANIZATION_ORDERS ] ) ) {
			$allcaps['view_order'] = true;
		}

		return $allcaps;
	}
}
