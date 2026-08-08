<?php
/**
 * Runtime capability resolution.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

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
}
