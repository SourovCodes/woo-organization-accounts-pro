<?php
/**
 * Authorisation for organization-scoped actions.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that decides whether the current user may act on an organization.
 *
 * Two questions have to be answered together and are easy to answer separately by
 * mistake: does this user hold the capability, and does this user belong to *this*
 * organization? A capability check on its own passes for an organization admin acting
 * on somebody else's organization, which is the whole of cross-tenant access in one
 * missing comparison. Every form handler, AJAX endpoint and admin action in the plugin
 * routes through here so that comparison cannot be forgotten in one of them.
 *
 * Site administrators — anyone with `manage_woocommerce` — are outside the scoping.
 * They administer every organization on the site by definition, and the admin screens
 * would be unusable otherwise.
 */
final class Guard {

	/**
	 * Whether the current user may exercise a capability against an organization.
	 *
	 * @param int    $organization_id Organization being acted on.
	 * @param string $capability      One of the Roles constants.
	 * @return bool True when the action is allowed.
	 */
	public static function can( $organization_id, $capability ) {
		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return false;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( Context::organization_id() !== $organization_id ) {
			return false;
		}

		return current_user_can( $capability );
	}

	/**
	 * Stop the request unless the current user may exercise a capability.
	 *
	 * @param int    $organization_id Organization being acted on.
	 * @param string $capability      One of the Roles constants.
	 * @return void
	 */
	public static function check( $organization_id, $capability ) {
		if ( self::can( $organization_id, $capability ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to do that.', 'woo-organization-accounts-pro' ),
			esc_html__( 'Permission denied', 'woo-organization-accounts-pro' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Verify a nonce and a capability together, then return the organization.
	 *
	 * The shape every form handler wants: one call that establishes the request came
	 * from the screen it claims to, that the user may do this, and that the
	 * organization they named is one they may name.
	 *
	 * The organization ID is a parameter rather than something read from the request
	 * here, so the sanitising happens where the field is known — in the handler, from
	 * `$_POST` or `$_GET` by name. Passing null means the current user's own
	 * organization, which is what every frontend handler wants.
	 *
	 * @param string   $action          Nonce action.
	 * @param string   $capability      One of the Roles constants.
	 * @param int|null $organization_id Organization being acted on, or null for the user's own.
	 * @param string   $field           Request field holding the nonce.
	 * @return \WooOrgAccounts\Data\Organization The organization being acted on.
	 */
	public static function check_request( $action, $capability, $organization_id = null, $field = '_wpnonce' ) {
		check_admin_referer( $action, $field );

		$organization_id = ( null === $organization_id )
			? Context::organization_id()
			: absint( $organization_id );

		self::check( $organization_id, $capability );

		$organization = Context::organization_by_id( $organization_id );

		if ( null === $organization ) {
			wp_die(
				esc_html__( 'That account no longer exists.', 'woo-organization-accounts-pro' ),
				esc_html__( 'Not found', 'woo-organization-accounts-pro' ),
				array( 'response' => 404 )
			);
		}

		return $organization;
	}
}
