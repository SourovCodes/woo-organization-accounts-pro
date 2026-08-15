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
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Guard;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Locations\Locations;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Membership\Members;
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
	 * Re-key the organization's own details by the names the form posts them under.
	 *
	 * @param array $values Details keyed without their prefix.
	 * @return array Values keyed with it.
	 */
	private static function prefixed_details( array $values ) {
		return self::prefixed( 'woap', $values );
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
			'default_location'  => 'default_location',
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

		$details = array(
			'name'   => self::posted( 'woap_name' ),
			'tax_id' => self::posted( 'woap_tax_id' ),
		);

		$errors = new \WP_Error();

		Organization::validate_details( $details, $errors );

		/*
		 * `required` on the input is the browser's opinion and nothing more: anything
		 * that posts the form directly reaches this with an empty name, and before this
		 * check the empty name was stored and reported as saved.
		 */
		if ( $errors->has_errors() ) {
			self::hold( $errors, self::prefixed_details( $details ) );

			return;
		}

		$organization->set_props(
			array_merge(
				$details,
				array( 'allow_custom_shipping' => self::posted_checkbox( 'woap_allow_custom_shipping' ) )
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

		$name    = self::posted( 'woap_name' );
		$address = AddressFields::posted( AddressFields::SHIPPING );

		$saved = Locations::save(
			$organization,
			$location,
			array_merge(
				$address,
				array(
					'name'       => $name,
					'is_default' => self::posted_checkbox( 'woap_is_default' ),
				)
			)
		);

		if ( is_wp_error( $saved ) ) {
			/*
			 * Handed back rather than redirected away: losing a twelve-field address to one
			 * mistyped postcode is not an acceptable way to report an error. The service
			 * keys its errors by the record's own field names, and the address half has to
			 * carry the `shipping_` prefix again to reach `AddressFields::render()`, which
			 * marks a field by the name the input actually has.
			 */
			self::hold(
				self::location_errors( $saved ),
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
	 * Make one location the default at checkout.
	 *
	 * A row in the list rather than a trip through the form: choosing which of four
	 * addresses a new order should start at is not editing an address, and making
	 * somebody open a fourteen-field form and save it to tick one box invites them to
	 * change something they did not mean to. The repository is what keeps at most one
	 * default per organization, so nothing here has to clear the old one.
	 *
	 * @return void
	 */
	public function default_location() {
		$organization = Guard::check_request( 'woap_default_location', Roles::MANAGE_LOCATIONS );
		$location     = LocationRepository::find_for_organization( self::posted_int( 'woap_location_id' ), $organization->get_id() );

		if ( null === $location ) {
			self::finish( MyAccount::ENDPOINT_LOCATIONS, __( 'That entry no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$location->set( 'is_default', true );
		LocationRepository::save( $location );

		self::finish(
			MyAccount::ENDPOINT_LOCATIONS,
			sprintf(
				/* translators: %s: the name of the location that is now the default. */
				__( '“%s” is now the default at checkout.', 'woo-organization-accounts-pro' ),
				$location->get_name()
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

		$email = self::posted_email( 'woap_email' );
		$role  = self::posted( 'woap_role' );

		$result = Invitations::create( $organization->get_id(), $email, $role, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			/*
			 * Handed back rather than redirected away. Every refusal here is about the
			 * address that was typed — it already has an invitation, or it already has a
			 * membership — so the answer is worth reading next to the field it is about,
			 * with the address still in it.
			 */
			$errors = new \WP_Error( 'woap_email', $result->get_error_message() );

			self::hold(
				$errors,
				array(
					'woap_email' => $email,
					'woap_role'  => $role,
				)
			);

			return;
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

		$role         = ( Member::ROLE_ADMIN === self::posted( 'woap_role' ) ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
		$status       = ( Member::STATUS_INACTIVE === self::posted( 'woap_status' ) ) ? Member::STATUS_INACTIVE : Member::STATUS_ACTIVE;
		$perm_scope   = ( 'custom' === self::posted( 'woap_permissions_scope' ) ) ? 'custom' : 'role';
		$location_ids = self::posted_location_ids( $organization->get_id() );

		$errors = new \WP_Error();

		/*
		 * An empty access list means "every location", so "only the ones I tick" with
		 * nothing ticked would silently store the opposite of what it says. It is a
		 * question, not a mistake to swallow — and it is asked here rather than by the
		 * service because it is a question about the control this form drew. The service
		 * knows only that an empty list is never what gets stored.
		 */
		if ( self::restricting_locations() && empty( $location_ids ) ) {
			$errors->add(
				'woap_location_access',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Choose at least one %s, or give access to all of them.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		if ( ! $errors->has_errors() ) {
			$saved = Members::update(
				$member,
				array(
					'role'            => $role,
					'status'          => $status,
					'capabilities'    => 'custom' === $perm_scope
						? self::posted_capabilities()
						: Members::ROLE_DEFAULT,
					'location_access' => self::restricting_locations() ? $location_ids : Members::ACCESS_ALL,
				)
			);

			if ( is_wp_error( $saved ) ) {
				$errors = self::prefixed_errors( $saved );
			}
		}

		if ( $errors->has_errors() ) {
			self::hold(
				$errors,
				array(
					'woap_role'              => $role,
					'woap_status'            => $status,
					'woap_permissions_scope' => $perm_scope,
					'woap_location_scope'    => self::restricting_locations() ? 'selected' : 'all',
					'woap_capabilities'      => self::posted_list( 'woap_capabilities' ),
					'woap_location_access'   => $location_ids,
				)
			);

			return;
		}

		self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'Member updated.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * Re-key a location refusal the way the location form's fields are named.
	 *
	 * `Locations::save()` reports an error against the column it is about — `postcode`,
	 * `address_1`, `name` — because that is what the record calls it and what a REST client
	 * sends. The form's address half is rendered by `AddressFields::render()`, which marks a
	 * field by its prefixed input name, so the address fields get their prefix back and the
	 * name field does not have one to get.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return \WP_Error The same messages, keyed the way this form names its fields.
	 */
	private static function location_errors( \WP_Error $error ) {
		$rekeyed = new \WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$code = (string) $code;
			$key  = in_array( $code, Location::ADDRESS_FIELDS, true )
				? AddressFields::SHIPPING . '_' . $code
				: $code;

			$rekeyed->add( $key, $error->get_error_message( $code ) );
		}

		return $rekeyed;
	}

	/**
	 * Re-key a service refusal the way this form's fields are named.
	 *
	 * The service names an error after the thing that was wrong — `role`, `location_access`
	 * — because that is the one name both a form and an API can agree on. Every field this
	 * plugin renders is prefixed `woap_`, for the reason recorded in CLAUDE.md: WordPress
	 * reads its public query variables out of `$_POST` as readily as out of the URL. So the
	 * prefix goes back on here, at the point the error meets a field.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return \WP_Error The same messages, keyed by field name.
	 */
	private static function prefixed_errors( \WP_Error $error ) {
		$prefixed = new \WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$code = (string) $code;
			$key  = 0 === strpos( $code, 'woap_' ) ? $code : 'woap_' . $code;

			$prefixed->add( $key, $error->get_error_message( $code ) );
		}

		return $prefixed;
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

		/*
		 * The one refusal that belongs to this screen rather than to the rule underneath.
		 * A back office removing somebody is not removing themselves, so the service has no
		 * opinion on it; here it is the difference between an organization admin tidying up
		 * a list and locking themselves out of their own account.
		 */
		if ( $member->get_user_id() === get_current_user_id() ) {
			self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'You cannot remove yourself.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$removed = Members::remove( $member );

		if ( is_wp_error( $removed ) ) {
			self::finish( MyAccount::ENDPOINT_MEMBERS, $removed->get_error_message(), 'error' );
		}

		self::finish( MyAccount::ENDPOINT_MEMBERS, __( 'Member removed.', 'woo-organization-accounts-pro' ) );
	}

	/**
	 * What a permissions form said should be true of this member.
	 *
	 * An absolute map, every capability named, because a group of checkboxes says as much by
	 * the ones it leaves unticked as by the ones it ticks. Reducing it to the overrides
	 * worth storing is `Members::capability_overrides()`, and it happens there rather than
	 * here because the diff is against *the role being saved* — which this form is often in
	 * the act of changing, and which was the whole of the bug that made promoting somebody
	 * to organization admin produce an admin who could manage nothing.
	 *
	 * @return array Map of capability to boolean.
	 */
	private static function posted_capabilities() {
		$granted = self::posted_list( 'woap_capabilities' );
		$wanted  = array();

		foreach ( Roles::capabilities() as $capability ) {
			$wanted[ $capability ] = in_array( $capability, $granted, true );
		}

		return $wanted;
	}

	/**
	 * Whether the member form asked for a restricted list of locations at all.
	 *
	 * The stored form of "every location" is an empty list, so which of the two an
	 * empty submission means cannot be read off the checkboxes — it is the radio above
	 * them that says. Without it, unticking the last location would look identical to
	 * granting access to all of them.
	 *
	 * @return bool True when the form asked to restrict access.
	 */
	private static function restricting_locations() {
		return 'selected' === self::posted( 'woap_location_scope' );
	}

	/**
	 * The locations a permissions form restricted a member to.
	 *
	 * Anything that is not a location of this organization is dropped rather than
	 * stored, so a hand-edited form cannot grant access to another organization's row.
	 *
	 * @param int $organization_id Organization the member belongs to.
	 * @return int[] Location IDs. Empty means every location.
	 */
	private static function posted_location_ids( $organization_id ) {
		if ( ! self::restricting_locations() ) {
			return array();
		}

		$posted = array_map( 'absint', self::posted_list( 'woap_location_access' ) );

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
	 * Read a posted list of keys, as a group of checkboxes submits one.
	 *
	 * @param string $key Field name.
	 * @return string[] Sanitised values.
	 */
	private static function posted_list( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Guard::check_request() verified the nonce before this runs.
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $key ] ) );
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
