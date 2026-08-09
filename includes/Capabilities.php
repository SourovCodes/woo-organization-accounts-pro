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
		add_filter( 'user_has_cap', array( $this, 'resolve_order_capabilities' ), 10, 4 );
	}

	/**
	 * The WooCommerce capabilities that are keyed on an order's own customer.
	 *
	 * Every one of these is granted by `wc_customer_has_capability()` only when the
	 * order's customer is the person asking, which is the assumption an organization
	 * account exists to break.
	 *
	 * @return string[] Capability names.
	 */
	public static function order_capabilities() {
		return array( 'view_order', 'order_again', 'cancel_order', 'pay_for_order', 'download_file' );
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
	 * Give a member the same control over an organization order as over their own.
	 *
	 * `woap_view_organization_orders` is this plugin's capability and answers this
	 * plugin's own screens. The pages those screens *link to* are WooCommerce's, and
	 * WooCommerce asks a different question: `wc_customer_has_capability()` grants each
	 * of these only when the order's customer is the person asking. That is precisely
	 * the assumption an organization account exists to break — an order belongs to the
	 * organization, and the employee who placed it is a detail of how it got there.
	 *
	 * Holding one of our capabilities and being able to reach what it describes are two
	 * facts, and only the first of them was ever ours to assert.
	 *
	 * `pay_for_order` additionally requires `woap_place_orders`. Paying is spending, and
	 * that capability is this plugin's whole expression of who may spend; an admin holds
	 * it by default, so this only bites where somebody has been explicitly refused it.
	 * Without the extra condition, revoking a member's right to buy would leave them
	 * able to settle the organization's unpaid orders instead — and `Checkout\Gate`
	 * would not catch it, because `WC_Form_Handler::pay_action()` validates the order
	 * key rather than running the cart and checkout hooks the gate is attached to.
	 *
	 * Three properties are deliberate:
	 *
	 * - **Grant only, never deny.** WooCommerce's own rule still gives a member their
	 *   own order, and a plain customer on a shop that also sells to individuals is
	 *   untouched. Answering in both directions would make this plugin the arbiter of
	 *   who may read every order on the site.
	 * - **Scoped to orders carrying `_woap_organization_id`.** An order with no
	 *   organization is not this plugin's business, whoever is asking.
	 * - **Scoped to the asker's own organization**, which is the cross-tenant question
	 *   and is separate from the capability one.
	 *
	 * @param array    $allcaps Capabilities the user already has.
	 * @param string[] $caps    Capabilities being checked, after mapping.
	 * @param array    $args    The original arguments to the capability check.
	 * @param \WP_User $user    The user being checked.
	 * @return array Capabilities, with the order capability granted if the organization allows it.
	 */
	public function resolve_order_capabilities( $allcaps, $caps, $args, $user ) {
		$capability = $this->order_capability_being_checked( $caps );

		if ( null === $capability || ! empty( $allcaps[ $capability ] ) ) {
			return $allcaps;
		}

		$organization_id = $this->organization_for_subject( $capability, $args );

		if ( $organization_id <= 0 ) {
			return $allcaps;
		}

		/*
		 * Read straight out of the array rather than calling user_can(), which would
		 * re-enter this filter.
		 */
		if ( ! empty( $allcaps['manage_woocommerce'] ) ) {
			$allcaps[ $capability ] = true;

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

		if ( empty( $resolved[ Roles::VIEW_ORGANIZATION_ORDERS ] ) ) {
			return $allcaps;
		}

		if ( 'pay_for_order' === $capability && empty( $resolved[ Roles::PLACE_ORDERS ] ) ) {
			return $allcaps;
		}

		$allcaps[ $capability ] = true;

		return $allcaps;
	}

	/**
	 * Which order capability, if any, this check is about.
	 *
	 * One at a time: WordPress checks a single meta capability per call, and granting a
	 * second one nobody asked about would put an answer in `$allcaps` that outlives this
	 * request's arguments.
	 *
	 * @param string[] $caps Capabilities being checked, after mapping.
	 * @return string|null The capability, or null if this check is not one of ours.
	 */
	private function order_capability_being_checked( $caps ) {
		$found = array_intersect( self::order_capabilities(), (array) $caps );

		if ( empty( $found ) ) {
			return null;
		}

		return reset( $found );
	}

	/**
	 * The organization an order capability check is being made about.
	 *
	 * `download_file` is handed a `WC_Customer_Download` rather than an order, so the
	 * order has to be reached through it. Every other capability is handed the order
	 * itself, as an ID or an object.
	 *
	 * @param string $capability The order capability being checked.
	 * @param array  $args       The original arguments to the capability check.
	 * @return int Organization ID, or 0 when there is no organization order in question.
	 */
	private function organization_for_subject( $capability, $args ) {
		if ( ! isset( $args[2] ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$subject = $args[2];

		if ( 'download_file' === $capability ) {
			if ( ! $subject instanceof \WC_Customer_Download ) {
				return 0;
			}

			$subject = $subject->get_order_id();
		}

		$order = wc_get_order( $subject );

		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		return OrderMeta::organization_id( $order );
	}
}
