<?php
/**
 * The members of one organization, over REST.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Puts people on an organization's account, and changes what they may do there.
 *
 * Adding somebody happens two ways, and the request says which, because the two are not
 * variants of one act:
 *
 * - **`invite`**, the default, issues an invitation — the flow the account screens use.
 *   The person receives a one-time link, sets their own password, and *becomes* a member
 *   at the moment they accept. Nothing exists until then, which is why an invitation
 *   cannot carry permissions or location access: there is no membership row for them to
 *   be a diff against, and a value with nowhere to land is worse than a missing one.
 * - **`create`**, for staff entering an employee on somebody's behalf. The account
 *   exists immediately, with a random password nobody holds — the importer's answer to
 *   the same problem — and the person reaches it through the shop's ordinary
 *   lost-password form. Nothing is emailed by this route.
 *
 * Two rules from elsewhere in the plugin are load-bearing here and were bugs before they
 * were rules. **A user already belonging to an organization is never moved**, because
 * the membership row is what every order they have placed is scoped by — and the
 * `user_id` column is UNIQUE, so the attempt would fail at the database anyway, which is
 * a worse way to be told. And **anybody who can manage the shop keeps their WordPress
 * role**: `set_role()` replaces every role a user holds, so writing a membership for an
 * administrator who also buys on an account would lock them out of wp-admin.
 *
 * Capabilities are stored as a **diff against the role's defaults**, never as an absolute
 * set, which is why this route reads them as "what should be true", fills the rest in
 * from the role being saved, and stores only what differs. A client sending the same
 * absolute map it read back would otherwise pin a member to permissions their role has
 * since moved away from — and promoting somebody to admin while sending the map drawn
 * for their old role is exactly how the account screen once produced an organization
 * admin who could manage nothing.
 */
final class MembersController {

	/**
	 * The route below an organization.
	 */
	const ROUTE = 'members';

	/**
	 * Add somebody by inviting them to join.
	 */
	const METHOD_INVITE = 'invite';

	/**
	 * Add somebody by creating their account outright.
	 */
	const METHOD_CREATE = 'create';

	/**
	 * The value of `capabilities` that means "whatever the role allows".
	 */
	const ROLE_DEFAULT = 'role_default';

	/**
	 * The value of `location_access` that means every location the organization has.
	 */
	const ACCESS_ALL = 'all';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$base = '/' . OrganizationsController::ROUTE . '/(?P<organization_id>[\d]+)/' . self::ROUTE;

		register_rest_route(
			RestApi::REST_NAMESPACE,
			$base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->create_params(),
				),
			)
		);

		register_rest_route(
			RestApi::REST_NAMESPACE,
			$base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->update_params(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Who may read and write members.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public function permissions_check() {
		return Writes::permission_check(
			sprintf(
				/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
				__( 'You are not allowed to manage %s.', 'woo-organization-accounts-pro' ),
				Labels::members()
			)
		);
	}

	/**
	 * Everybody on one organization's account.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The members, or an error.
	 */
	public function get_items( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$data = array();

		foreach ( MemberRepository::for_organization( $organization->get_id() ) as $member ) {
			$payload = OrganizationsController::member_payload( $member, MemberRepository::location_ids( $member->get_id() ), true );

			if ( null !== $payload ) {
				$data[] = $payload;
			}
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * One member.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The member, or an error.
	 */
	public function get_item( $request ) {
		$member = $this->find( $request );

		if ( is_wp_error( $member ) ) {
			return $member;
		}

		return $this->respond( $member, 200 );
	}

	/**
	 * Put somebody on the account, by invitation or by creating their account.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The invitation or the member, or an error.
	 */
	public function create_item( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$role   = $this->requested_role( $request, Member::ROLE_MEMBER );
		$method = (string) $request['method'];

		if ( self::METHOD_INVITE === $method ) {
			return $this->invite( $organization, $request, $role );
		}

		return $this->enrol( $organization, $request, $role );
	}

	/**
	 * Issue an invitation.
	 *
	 * The membership-shaped fields are refused rather than ignored. Permissions and
	 * location access describe a membership row, an invitation has none, and accepting
	 * values this route would then drop is how a client comes to believe it has set
	 * something it has not.
	 *
	 * @param Organization     $organization The organization to invite into.
	 * @param \WP_REST_Request $request      The request.
	 * @param string           $role         Membership role to invite as.
	 * @return \WP_REST_Response|\WP_Error The invitation, or a refusal.
	 */
	private function invite( Organization $organization, $request, $role ) {
		$unusable = array();

		foreach ( array( 'capabilities', 'location_access', 'status', 'first_name', 'last_name' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$unusable[] = $field;
			}
		}

		if ( ! empty( $unusable ) ) {
			return new \WP_Error(
				'woap_rest_invitation_extras',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'An invitation cannot carry %s. Those describe a membership, and one exists only once the invitation has been accepted — send them to the member afterwards, or add the person with "method": "create" instead.', 'woo-organization-accounts-pro' ),
					implode( ', ', $unusable )
				),
				array(
					'status' => 400,
					'params' => array_fill_keys( $unusable, __( 'Not part of an invitation.', 'woo-organization-accounts-pro' ) ),
				)
			);
		}

		$result = Invitations::create(
			$organization->get_id(),
			(string) $request['email'],
			$role,
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ), $result->get_error_code() );

			return $result;
		}

		return new \WP_REST_Response( $this->invitation_payload( $result['invitation'] ), 201 );
	}

	/**
	 * Create or adopt the WordPress account, and write the membership.
	 *
	 * @param Organization     $organization The organization to join.
	 * @param \WP_REST_Request $request      The request.
	 * @param string           $role         Membership role.
	 * @return \WP_REST_Response|\WP_Error The member, or a refusal.
	 */
	private function enrol( Organization $organization, $request, $role ) {
		$email  = (string) $request['email'];
		$errors = new \WP_Error();

		$capabilities = $this->requested_capabilities( $request, $role, $errors );
		$locations    = $this->requested_locations( $request, $organization->get_id(), $errors );

		if ( $errors->has_errors() ) {
			return Writes::refuse( 'woap_rest_invalid_member', $errors );
		}

		$user = get_user_by( 'email', $email );

		if ( $user instanceof \WP_User ) {
			$existing = MemberRepository::find_by_user( $user->ID );

			if ( null !== $existing ) {
				return $this->already_a_member( $existing, $organization );
			}
		}

		$user_id = $user instanceof \WP_User ? $user->ID : $this->create_user( $request, $email, $role );

		if ( is_wp_error( $user_id ) ) {
			$user_id->add_data( array( 'status' => 400 ), $user_id->get_error_code() );

			return $user_id;
		}

		$member = new Member();
		$member->set_props(
			array(
				'organization_id' => $organization->get_id(),
				'user_id'         => $user_id,
				'role'            => $role,
				'status'          => Member::STATUS_ACTIVE,
			)
		);

		$member->set_capabilities( null === $capabilities ? array() : $capabilities );

		if ( 0 === MemberRepository::save( $member ) ) {
			return Writes::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		if ( null !== $locations ) {
			MemberRepository::set_location_ids( $member->get_id(), $locations );
		}

		$this->apply_wordpress_role( $user_id, Roles::wordpress_role( $role ) );

		return $this->respond( $member, 201 );
	}

	/**
	 * Change a member's role, status, permissions or location access.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The member, or an error.
	 */
	public function update_item( $request ) {
		$member = $this->find( $request );

		if ( is_wp_error( $member ) ) {
			return $member;
		}

		$role   = $this->requested_role( $request, $member->get_role() );
		$status = $request->has_param( 'status' ) ? (string) $request['status'] : (string) $member->get( 'status' );
		$errors = new \WP_Error();

		$capabilities = $this->requested_capabilities( $request, $role, $errors );
		$locations    = $this->requested_locations( $request, $member->get_organization_id(), $errors );

		$losing_admin = $member->is_admin() && ( Member::ROLE_ADMIN !== $role || Member::STATUS_ACTIVE !== $status );

		if ( $losing_admin && ! MemberRepository::has_other_admin( $member->get_organization_id(), $member->get_id() ) ) {
			$errors->add( 'role', $this->last_admin_message() );
		}

		if ( $errors->has_errors() ) {
			return Writes::refuse( 'woap_rest_invalid_member', $errors );
		}

		$member->set( 'role', $role );
		$member->set( 'status', $status );

		if ( null !== $capabilities ) {
			$member->set_capabilities( $capabilities );
		}

		if ( 0 === MemberRepository::save( $member ) ) {
			return Writes::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		if ( null !== $locations ) {
			MemberRepository::set_location_ids( $member->get_id(), $locations );
		}

		$this->apply_wordpress_role( $member->get_user_id(), Roles::wordpress_role( $role ) );

		return $this->respond( $member, 200 );
	}

	/**
	 * Take somebody off the account.
	 *
	 * The WordPress account survives — deleting somebody's login because they changed
	 * jobs is not this plugin's decision — and moves to WooCommerce's customer role, so
	 * with no membership row it can no longer buy on the organization's account.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error What was removed, or an error.
	 */
	public function delete_item( $request ) {
		$member = $this->find( $request );

		if ( is_wp_error( $member ) ) {
			return $member;
		}

		if ( $member->is_admin() && ! MemberRepository::has_other_admin( $member->get_organization_id(), $member->get_id() ) ) {
			return new \WP_Error(
				'woap_rest_last_admin',
				$this->last_admin_message(),
				array( 'status' => 409 )
			);
		}

		$previous = OrganizationsController::member_payload( $member, MemberRepository::location_ids( $member->get_id() ), true );
		$user_id  = $member->get_user_id();

		if ( ! MemberRepository::delete( $member->get_id() ) ) {
			return Writes::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be removed.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		$this->apply_wordpress_role( $user_id, 'customer' );

		return new \WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			),
			200
		);
	}

	/**
	 * Create the WordPress account for somebody being entered by staff.
	 *
	 * The password is random and nobody is told it: the account exists, orders attach to
	 * it, and the person sets a password through the shop's lost-password form. Nothing
	 * is emailed from here — `wp_insert_user()` sends nothing on its own, and a route
	 * that quietly triggered the shop's new-account mail would make "add an employee"
	 * mean something different depending on which plugins the shop runs.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $email   The address to create the account for.
	 * @param string           $role    Membership role, which decides the WordPress role.
	 * @return int|\WP_Error User ID, or an error.
	 */
	private function create_user( $request, $email, $role ) {
		return wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'first_name' => (string) $request['first_name'],
				'last_name'  => (string) $request['last_name'],
				'role'       => Roles::wordpress_role( $role ),
			)
		);
	}

	/**
	 * Give a user the WordPress role their membership needs, unless they run the shop.
	 *
	 * `set_role()` replaces every role a user holds, so an administrator or a shop
	 * manager who also buys on an organization's account would be demoted out of
	 * wp-admin by a routine membership edit. They are left exactly as they are; their
	 * capabilities within the organization come from the membership row regardless.
	 *
	 * @param int    $user_id User to move.
	 * @param string $role    WordPress role name.
	 * @return void
	 */
	private function apply_wordpress_role( $user_id, $role ) {
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->set_role( $role );
		}
	}

	/**
	 * The refusal for an address that already belongs to an organization.
	 *
	 * @param Member       $existing     The membership that address already has.
	 * @param Organization $organization The organization it was being added to.
	 * @return \WP_Error A 409.
	 */
	private function already_a_member( Member $existing, Organization $organization ) {
		$message = $existing->get_organization_id() === $organization->get_id()
			? sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'That address already belongs to this %s.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			)
			: sprintf(
				/* translators: %1$s: the organization noun for the site's mode, for example "Company". */
				__( 'That address already belongs to another %1$s. Remove them from it first: a person belongs to one %1$s at a time, and every order they have placed is scoped by that membership.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			);

		return new \WP_Error(
			'woap_rest_already_member',
			$message,
			array(
				'status'          => 409,
				'organization_id' => $existing->get_organization_id(),
				'member_id'       => $existing->get_id(),
			)
		);
	}

	/**
	 * The role a request asks for, or the one already held.
	 *
	 * @param \WP_REST_Request $request  The request.
	 * @param string           $fallback Role to keep when the request does not say.
	 * @return string One of the Member::ROLE_* constants.
	 */
	private function requested_role( $request, $fallback ) {
		if ( ! $request->has_param( 'role' ) ) {
			return $fallback;
		}

		return ( Member::ROLE_ADMIN === $request['role'] ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
	}

	/**
	 * The capability overrides a request asks for, reduced to a diff.
	 *
	 * The map is read as "what should be true of this member", so a capability the
	 * request does not mention falls back to what the role gives — and only what differs
	 * from the role is stored. That is what keeps `"role": "admin"` with no capabilities
	 * meaning an admin with an admin's permissions, rather than an admin pinned to
	 * whatever their previous role happened to allow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string           $role    The role being saved, which the diff is against.
	 * @param \WP_Error        $errors  Errors to add to.
	 * @return array|null Overrides to store, or null to leave them as they are.
	 */
	private function requested_capabilities( $request, $role, \WP_Error $errors ) {
		if ( ! $request->has_param( 'capabilities' ) ) {
			return null;
		}

		$requested = $request['capabilities'];

		if ( self::ROLE_DEFAULT === $requested ) {
			return array();
		}

		if ( ! is_array( $requested ) ) {
			$errors->add(
				'capabilities',
				sprintf(
					/* translators: %s: the literal string "role_default". */
					__( 'Send "%s", or an object naming capabilities that should differ from the role.', 'woo-organization-accounts-pro' ),
					self::ROLE_DEFAULT
				)
			);

			return null;
		}

		$defaults = Roles::role_capabilities( $role );
		$unknown  = array_diff( array_keys( $requested ), Roles::capabilities() );

		if ( ! empty( $unknown ) ) {
			$errors->add(
				'capabilities',
				sprintf(
					/* translators: 1: comma-separated list of capability names, 2: comma-separated list of the capabilities that exist. */
					__( 'No such capability: %1$s. The capabilities are %2$s.', 'woo-organization-accounts-pro' ),
					implode( ', ', $unknown ),
					implode( ', ', Roles::capabilities() )
				)
			);

			return null;
		}

		$overrides = array();

		foreach ( Roles::capabilities() as $capability ) {
			$wanted = array_key_exists( $capability, $requested )
				? (bool) $requested[ $capability ]
				: (bool) $defaults[ $capability ];

			if ( $wanted !== (bool) $defaults[ $capability ] ) {
				$overrides[ $capability ] = $wanted;
			}
		}

		return $overrides;
	}

	/**
	 * The location access a request asks for.
	 *
	 * An empty list is refused rather than stored, because the stored form of "every
	 * location" *is* an empty list — so a client sending `[]` meaning "none" would get
	 * the opposite of what it asked for, silently. `"all"` is the way to say every
	 * location, and there is deliberately no way to say none: a member who may order but
	 * may ship nowhere cannot check out, and that is what the member's status is for.
	 *
	 * @param \WP_REST_Request $request         The request.
	 * @param int              $organization_id The organization the locations must belong to.
	 * @param \WP_Error        $errors          Errors to add to.
	 * @return array|null Location IDs, an empty array for "all", or null to leave them as they are.
	 */
	private function requested_locations( $request, $organization_id, \WP_Error $errors ) {
		if ( ! $request->has_param( 'location_access' ) ) {
			return null;
		}

		$requested = $request['location_access'];

		if ( self::ACCESS_ALL === $requested ) {
			return array();
		}

		if ( ! is_array( $requested ) || empty( $requested ) ) {
			$errors->add(
				'location_access',
				sprintf(
					/* translators: 1: the literal string "all", 2: the plural location noun for the site's mode. */
					__( 'Send "%1$s", or a non-empty list of %2$s to restrict this person to.', 'woo-organization-accounts-pro' ),
					self::ACCESS_ALL,
					Labels::locations()
				)
			);

			return null;
		}

		$ids = array();

		foreach ( $requested as $id ) {
			$id = absint( $id );

			if ( null === LocationRepository::find_for_organization( $id, $organization_id ) ) {
				$errors->add(
					'location_access',
					sprintf(
						/* translators: 1: the singular location noun, for example "Branch", 2: the organization noun, 3: a location ID. */
						__( 'No %1$s of this %2$s has the identifier %3$d.', 'woo-organization-accounts-pro' ),
						Labels::location(),
						Labels::organization(),
						$id
					)
				);

				return null;
			}

			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The member a request names, scoped to the organization in its path.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return Member|\WP_Error The member, or a 404.
	 */
	private function find( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$member = MemberRepository::find_for_organization( absint( $request['id'] ), $organization->get_id() );

		if ( $member instanceof Member ) {
			return $member;
		}

		return new \WP_Error(
			'woap_rest_member_not_found',
			sprintf(
				/* translators: 1: the singular member noun, for example "Employee", 2: the organization noun. */
				__( 'That %1$s does not belong to this %2$s.', 'woo-organization-accounts-pro' ),
				Labels::member(),
				Labels::organization()
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * The member as this namespace reports one, freshly read.
	 *
	 * @param Member $member The membership.
	 * @param int    $status Response status.
	 * @return \WP_REST_Response|\WP_Error The member, or an error when their user is gone.
	 */
	private function respond( Member $member, $status ) {
		$payload = OrganizationsController::member_payload( $member, MemberRepository::location_ids( $member->get_id() ), true );

		if ( null === $payload ) {
			return new \WP_Error(
				'woap_rest_member_has_no_user',
				__( 'That membership has no WordPress account behind it.', 'woo-organization-accounts-pro' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response( $payload, $status );
	}

	/**
	 * An outstanding invitation, as this namespace reports one.
	 *
	 * The token is absent and always will be: it exists in plaintext for the length of
	 * one function call, goes into one email, and is stored only as a digest.
	 *
	 * @param Invitation $invitation The invitation.
	 * @return array The payload.
	 */
	private function invitation_payload( Invitation $invitation ) {
		return array(
			'invitation_id' => $invitation->get_id(),
			'email'         => $invitation->get_email(),
			'role'          => $invitation->get_role(),
			'status'        => (string) $invitation->get( 'status' ),
			'expires_at'    => $invitation->get( 'expires_at' ),
			'invited_by'    => $invitation->get_invited_by(),
		);
	}

	/**
	 * The one message for an edit that would leave an organization unadministered.
	 *
	 * @return string Translated message.
	 */
	private function last_admin_message() {
		return sprintf(
			/* translators: 1: the organization noun, 2: the organization admin noun. */
			__( 'A %1$s must keep at least one active %2$s. Promote somebody else first.', 'woo-organization-accounts-pro' ),
			Labels::organization(),
			Labels::organization_admin()
		);
	}

	/**
	 * The parameters adding somebody accepts.
	 *
	 * @return array Argument definitions.
	 */
	private function create_params() {
		return array(
			'email'           => array(
				'description'       => __( 'The address to invite, or to create the account for.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'format'            => 'email',
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'method'          => array(
				'description'       => __( 'How to add them: invite them to join, or create their account outright.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'default'           => self::METHOD_INVITE,
				'enum'              => array( self::METHOD_INVITE, self::METHOD_CREATE ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'role'            => array(
				'description'       => __( 'The role they hold within the organization.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'default'           => Member::ROLE_MEMBER,
				'enum'              => array( Member::ROLE_ADMIN, Member::ROLE_MEMBER ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),

			/*
			 * Neither name declares a default, and that is load-bearing rather than an
			 * omission: WordPress fills a declared default into the request itself, so
			 * `''` here would make every invitation arrive carrying two of the fields an
			 * invitation is not allowed to carry, and be refused for it.
			 */
			'first_name'      => array(
				'description'       => __( 'Their first name. Only when creating the account; an invited person supplies their own.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'       => array(
				'description'       => __( 'Their surname. Only when creating the account.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'capabilities'    => array(
				'description' => __( 'Permissions that should differ from the role, or "role_default". Only when creating the account.', 'woo-organization-accounts-pro' ),
			),
			'location_access' => array(
				'description' => __( 'The locations they may ship to: "all", or a list of location IDs. Only when creating the account.', 'woo-organization-accounts-pro' ),
			),
		);
	}

	/**
	 * The parameters an edit accepts.
	 *
	 * Every one of them is optional: an edit that names only a status changes only the
	 * status, and the fields it did not mention are left where they were.
	 *
	 * @return array Argument definitions.
	 */
	private function update_params() {
		return array(
			'role'            => array(
				'description'       => __( 'The role they hold within the organization.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'enum'              => array( Member::ROLE_ADMIN, Member::ROLE_MEMBER ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'          => array(
				'description'       => __( 'Whether the membership is in force.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'enum'              => array( Member::STATUS_ACTIVE, Member::STATUS_INACTIVE ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'capabilities'    => array(
				'description' => __( 'Permissions that should differ from the role, or "role_default".', 'woo-organization-accounts-pro' ),
			),
			'location_access' => array(
				'description' => __( 'The locations they may ship to: "all", or a list of location IDs.', 'woo-organization-accounts-pro' ),
			),
		);
	}
}
