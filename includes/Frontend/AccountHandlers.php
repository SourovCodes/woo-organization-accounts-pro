<?php
/**
 * Form handlers for the My Account organization screens.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Guard;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Every write the organization screens can make.
 *
 * Each form posts back to the account page it came from and is handled on
 * `template_redirect`, before anything has been sent to the browser, so a handler can
 * still redirect and a refresh after saving does not save again. Each one opens with
 * Guard::check_request(), which verifies the nonce and the capability together and
 * hands back the organization being acted on — so a handler that forgets to scope its
 * writes cannot be written without deleting that line first.
 *
 * These deliberately do *not* go through `admin-post.php`, which is the obvious place
 * to put a form handler and the wrong one here. WooCommerce decides what to load from
 * `is_admin()`, and `admin-post.php` is an admin request: `wc_load_cart()` never runs,
 * so `wc_add_notice()` is not defined and `WC()->session` does not exist. A handler
 * there cannot tell the customer what happened — it fatals trying.
 */
class AccountHandlers {

	/**
	 * Field naming which handler a submission is for.
	 */
	const ACTION_FIELD = 'woap_account_action';

	/**
	 * Errors found in the submission being handled, if any.
	 *
	 * @var \WP_Error|null
	 */
	private static $errors = null;

	/**
	 * What was submitted, so a form that failed can be handed back filled in.
	 *
	 * @var array
	 */
	private static $values = array();

	/**
	 * The errors found in this request's submission.
	 *
	 * @return \WP_Error|null Errors, or null when nothing was rejected.
	 */
	public static function errors() {
		return self::$errors;
	}

	/**
	 * A value from the submission being re-rendered.
	 *
	 * @param string $key      Field name, prefixed for address fields.
	 * @param mixed  $fallback Returned when the field was not submitted.
	 * @return mixed The submitted value, or the fallback.
	 */
	public static function value( $key, $fallback = '' ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $fallback;
	}

	/**
	 * Whether this request is re-rendering a rejected submission.
	 *
	 * @return bool True when there is something to re-render.
	 */
	public static function has_submission() {
		return ! empty( self::$values );
	}

	/**
	 * Keep a rejected submission and let the screen render again.
	 *
	 * Deliberately not a redirect. Redirecting with an error notice is how a customer
	 * loses a twelve-field address to one mistyped postcode; the whole submission is
	 * kept here instead and handed back to the form, which is what WooCommerce's own
	 * checkout does with a failed validation pass.
	 *
	 * @param \WP_Error $errors Everything wrong with the submission.
	 * @param array     $values What was submitted.
	 * @return void
	 */
	private static function hold( \WP_Error $errors, array $values ) {
		self::$errors = $errors;
		self::$values = $values;

		foreach ( $errors->get_error_messages() as $message ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Re-key an address by its prefixed field names, as the form posts them.
	 *
	 * @param string $type   AddressFields::BILLING or AddressFields::SHIPPING.
	 * @param array  $values Values keyed without the prefix.
	 * @return array Values keyed with it.
	 */
	private static function prefixed( $type, array $values ) {
		$prefixed = array();

		foreach ( $values as $key => $value ) {
			$prefixed[ $type . '_' . $key ] = $value;
		}

		return $prefixed;
	}

	/**
	 * Forget any held submission.
	 *
	 * Only the test suite needs this; within a request the state belongs to the one
	 * submission being handled.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$errors = null;
		self::$values = array();
	}

	/**
	 * The handlers, keyed by the value that selects them.
	 *
	 * @return array Map of action name to method name.
	 */
	public static function actions() {
		return array(
			'save_organization' => 'save_organization',
			'save_billing'      => 'save_billing',
			'save_location'     => 'save_location',
			'delete_location'   => 'delete_location',
			'invite_member'     => 'invite_member',
			'revoke_invitation' => 'revoke_invitation',
			'resend_invitation' => 'resend_invitation',
			'update_member'     => 'update_member',
			'remove_member'     => 'remove_member',
		);
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'dispatch' ) );
	}

	/**
	 * Run the handler this submission asked for, if any.
	 *
	 * Only the choice of handler is made here. Each one verifies its own nonce and
	 * capability, so naming a handler in the request buys nothing on its own.
	 *
	 * @return void
	 */
	public function dispatch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only selects which handler runs; the handler verifies the nonce before it does anything.
		$requested = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		$actions = self::actions();

		if ( '' === $requested || ! isset( $actions[ $requested ] ) ) {
			return;
		}

		$this->{$actions[ $requested ]}();
	}

	/**
	 * Save the organization's own details.
	 *
	 * @return void
	 */
	public function save_organization() {
		$organization = Guard::check_request( 'woap_save_organization', Roles::MANAGE_ORGANIZATION );

		$organization->set_props(
			array(
				'name'                  => self::posted( 'woap_name' ),
				'email'                 => self::posted_email( 'woap_email' ),
				'phone'                 => self::posted( 'woap_phone' ),
				'tax_id'                => self::posted( 'woap_tax_id' ),
				'allow_custom_shipping' => self::posted_checkbox( 'woap_allow_custom_shipping' ),
			)
		);

		OrganizationRepository::save( $organization );

		self::finish(
			MyAccount::ENDPOINT_PROFILE,
			sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( '%s details saved.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			)
		);
	}

	/**
	 * Save the organization's centralised billing address.
	 *
	 * @return void
	 */
	public function save_billing() {
		$organization = Guard::check_request( 'woap_save_billing', Roles::MANAGE_BILLING );

		$errors  = new \WP_Error();
		$address = AddressFields::posted( AddressFields::BILLING );

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		if ( $errors->has_errors() ) {
			self::hold( $errors, self::prefixed( AddressFields::BILLING, $address ) );

			return;
		}

		$organization->set_billing_address( $address );
		OrganizationRepository::save( $organization );

		self::finish( MyAccount::ENDPOINT_PROFILE, __( 'Billing address saved.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Create or update a location.
	 *
	 * @return void
	 */
	public function save_location() {
		$organization = Guard::check_request( 'woap_save_location', Roles::MANAGE_LOCATIONS );

		$location_id = self::posted_int( 'woap_location_id' );
		$location    = $location_id > 0
			? LocationRepository::find_for_organization( $location_id, $organization->get_id() )
			: new Location();

		if ( null === $location ) {
			self::finish( MyAccount::ENDPOINT_LOCATIONS, __( 'That entry no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$errors  = new \WP_Error();
		$name    = self::posted( 'woap_name' );
		$address = AddressFields::posted( AddressFields::SHIPPING );

		if ( '' === $name ) {
			$errors->add(
				'name',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Please give the %s a name.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		AddressFields::validate( AddressFields::SHIPPING, $address, $errors );

		if ( $errors->has_errors() ) {
			self::hold(
				$errors,
				array_merge(
					self::prefixed( AddressFields::SHIPPING, $address ),
					array(
						'woap_name'        => $name,
						'woap_location_id' => $location_id,
						'woap_is_default'  => self::posted_checkbox( 'woap_is_default' ),
					)
				)
			);

			return;
		}

		/*
		 * A shipping label with no company on it is a parcel nobody at the receiving
		 * end recognises, so a blank company falls back to the organization's name
		 * rather than being left empty. It is stored rather than resolved at checkout,
		 * so what the screen shows is what the courier will get.
		 */
		if ( '' === trim( (string) $address['company'] ) ) {
			$address['company'] = $organization->get_name();
		}

		$location->set_props(
			array(
				'organization_id' => $organization->get_id(),
				'name'            => $name,
				'is_default'      => self::posted_checkbox( 'woap_is_default' ),
			)
		);

		$location->set_shipping_address( $address );

		LocationRepository::save( $location );

		self::finish(
			MyAccount::ENDPOINT_LOCATIONS,
			sprintf(
				/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
				__( '%s saved.', 'woo-organization-accounts-pro' ),
				Labels::location()
			)
		);
	}

	/**
	 * Delete a location.
	 *
	 * @return void
	 */
	public function delete_location() {
		$organization = Guard::check_request( 'woap_delete_location', Roles::MANAGE_LOCATIONS );
		$location     = LocationRepository::find_for_organization( self::posted_int( 'woap_location_id' ), $organization->get_id() );

		if ( null === $location ) {
			self::finish( MyAccount::ENDPOINT_LOCATIONS, __( 'That entry no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		LocationRepository::delete( $location->get_id() );

		self::finish(
			MyAccount::ENDPOINT_LOCATIONS,
			sprintf(
				/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
				__( '%s deleted.', 'woo-organization-accounts-pro' ),
				Labels::location()
			)
		);
	}

	/**
	 * Send an invitation.
	 *
	 * @return void
	 */
	public function invite_member() {
		$organization = Guard::check_request( 'woap_invite_member', Roles::INVITE_MEMBERS );

		$result = Invitations::create(
			$organization->get_id(),
			self::posted_email( 'woap_email' ),
			self::posted( 'woap_role' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			self::finish( MyAccount::ENDPOINT_INVITATIONS, $result->get_error_message(), 'error' );
		}

		self::finish( MyAccount::ENDPOINT_INVITATIONS, __( 'Invitation sent.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Withdraw an invitation.
	 *
	 * @return void
	 */
	public function revoke_invitation() {
		$organization = Guard::check_request( 'woap_revoke_invitation', Roles::INVITE_MEMBERS );
		$invitation   = InvitationRepository::find_for_organization( self::posted_int( 'woap_invitation_id' ), $organization->get_id() );

		if ( null === $invitation || ! Invitations::revoke( $invitation ) ) {
			self::finish( MyAccount::ENDPOINT_INVITATIONS, __( 'That invitation could not be withdrawn.', 'woo-organization-accounts-pro' ), 'error' );
		}

		self::finish( MyAccount::ENDPOINT_INVITATIONS, __( 'Invitation withdrawn.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Issue a fresh token for an invitation and send it again.
	 *
	 * The old token stops working, which is the point: a re-send is a replacement, not
	 * a second key to the same door.
	 *
	 * @return void
	 */
	public function resend_invitation() {
		$organization = Guard::check_request( 'woap_resend_invitation', Roles::INVITE_MEMBERS );
		$invitation   = InvitationRepository::find_for_organization( self::posted_int( 'woap_invitation_id' ), $organization->get_id() );

		if ( null === $invitation || Invitation::STATUS_PENDING !== (string) $invitation->get( 'status' ) ) {
			self::finish( MyAccount::ENDPOINT_INVITATIONS, __( 'That invitation could not be sent again.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$result = Invitations::create(
			$organization->get_id(),
			$invitation->get_email(),
			$invitation->get_role(),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			self::finish( MyAccount::ENDPOINT_INVITATIONS, $result->get_error_message(), 'error' );
		}

		self::finish( MyAccount::ENDPOINT_INVITATIONS, __( 'Invitation sent again.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Change a member's role, permissions, status or location access.
	 *
	 * @return void
	 */
	public function update_member() {
		$organization = Guard::check_request( 'woap_update_member', Roles::MANAGE_MEMBERS );
		$member       = MemberRepository::find_for_organization( self::posted_int( 'woap_member_id' ), $organization->get_id() );

		if ( null === $member ) {
			self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'That member no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$role   = ( Member::ROLE_ADMIN === self::posted( 'woap_role' ) ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
		$status = ( Member::STATUS_INACTIVE === self::posted( 'woap_status' ) ) ? Member::STATUS_INACTIVE : Member::STATUS_ACTIVE;

		$losing_admin = $member->is_admin() && ( Member::ROLE_ADMIN !== $role || Member::STATUS_ACTIVE !== $status );

		if ( $losing_admin && ! MemberRepository::has_other_admin( $organization->get_id(), $member->get_id() ) ) {
			self::finish(
				MyAccount::ENDPOINT_MEMBERS,
				sprintf(
					/* translators: 1: the organization noun, 2: the organization admin noun. */
					__( 'A %1$s must keep at least one active %2$s. Promote somebody else first.', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					Labels::organization_admin()
				),
				'error'
			);
		}

		$member->set( 'role', $role );
		$member->set( 'status', $status );
		$member->set_capabilities( self::posted_capabilities( $role ) );

		MemberRepository::save( $member );
		MemberRepository::set_location_ids( $member->get_id(), self::posted_location_ids( $organization->get_id() ) );

		$user = get_user_by( 'id', $member->get_user_id() );

		if ( $user instanceof \WP_User ) {
			$user->set_role( Roles::wordpress_role( $role ) );
		}

		self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'Member updated.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Remove a member from the organization.
	 *
	 * The WordPress account survives — deleting somebody's login because they changed
	 * jobs is not this plugin's decision — but it is moved to WooCommerce's customer
	 * role, and with no membership row it can no longer buy on the organization's
	 * account.
	 *
	 * @return void
	 */
	public function remove_member() {
		$organization = Guard::check_request( 'woap_remove_member', Roles::MANAGE_MEMBERS );
		$member       = MemberRepository::find_for_organization( self::posted_int( 'woap_member_id' ), $organization->get_id() );

		if ( null === $member ) {
			self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'That member no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		if ( $member->get_user_id() === get_current_user_id() ) {
			self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'You cannot remove yourself.', 'woo-organization-accounts-pro' ), 'error' );
		}

		if ( $member->is_admin() && ! MemberRepository::has_other_admin( $organization->get_id(), $member->get_id() ) ) {
			self::finish(
				MyAccount::ENDPOINT_MEMBERS,
				sprintf(
					/* translators: 1: the organization noun, 2: the organization admin noun. */
					__( 'A %1$s must keep at least one active %2$s. Promote somebody else first.', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					Labels::organization_admin()
				),
				'error'
			);
		}

		$user = get_user_by( 'id', $member->get_user_id() );

		MemberRepository::delete( $member->get_id() );

		if ( $user instanceof \WP_User ) {
			$user->set_role( 'customer' );
		}

		self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'Member removed.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * The capability overrides a permissions form submitted.
	 *
	 * Stored only where they differ from the role's own answer, so a member left on
	 * the defaults carries no overrides and follows the role if the role ever changes.
	 *
	 * @param string $role The role the member will hold.
	 * @return array Map of capability to boolean.
	 */
	private static function posted_capabilities( $role ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		$granted = isset( $_POST['woap_capabilities'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['woap_capabilities'] ) ) : array();

		$defaults  = Roles::role_capabilities( $role );
		$overrides = array();

		foreach ( Roles::capabilities() as $capability ) {
			$wanted = in_array( $capability, $granted, true );

			if ( $wanted !== (bool) $defaults[ $capability ] ) {
				$overrides[ $capability ] = $wanted;
			}
		}

		return $overrides;
	}

	/**
	 * The locations a permissions form restricted a member to.
	 *
	 * Anything that is not a location of this organization is dropped rather than
	 * stored, so a hand-edited form cannot grant access to another organization's row.
	 *
	 * @param int $organization_id Organization the member belongs to.
	 * @return int[] Location IDs.
	 */
	private static function posted_location_ids( $organization_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		$posted = isset( $_POST['woap_location_access'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['woap_location_access'] ) ) : array();

		if ( empty( $posted ) ) {
			return array();
		}

		$allowed = array();

		foreach ( LocationRepository::for_organization( $organization_id ) as $location ) {
			$allowed[] = $location->get_id();
		}

		return array_values( array_intersect( $posted, $allowed ) );
	}

	/**
	 * Read a posted text field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a posted email field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted_email( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		return isset( $_POST[ $key ] ) ? sanitize_email( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a posted integer field.
	 *
	 * @param string $key Field name.
	 * @return int Value.
	 */
	private static function posted_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	/**
	 * Read a posted checkbox.
	 *
	 * @param string $key Field name.
	 * @return bool True when it was ticked.
	 */
	private static function posted_checkbox( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Report the outcome and go back to the screen the form was on.
	 *
	 * Never returns: every handler ends here, so a redirect cannot be forgotten and a
	 * refresh cannot repeat the write.
	 *
	 * @param string $endpoint My Account endpoint to return to.
	 * @param string $message  Message to show.
	 * @param string $type     WooCommerce notice type.
	 * @return void
	 */
	private static function finish( $endpoint, $message, $type = 'success' ) {
		wc_add_notice( $message, $type );

		wp_safe_redirect( wc_get_account_endpoint_url( $endpoint ) );
		exit;
	}
}
