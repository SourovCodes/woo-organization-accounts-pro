<?php
/**
 * The members of one organization, over REST.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Membership\Members;

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
 *
 * **An edit reaches the WordPress account as well as the membership row**, because a name
 * and an address are what a person *is* and neither is stored here: `woap_members` has no
 * column for either. A back office correcting a misspelt surname or moving an employee to
 * the address they now read mail at is doing the same act as changing their role, and
 * having to reach for `/wp/v2/users` for half of it is how an app comes to hold two
 * answers about one person. Three consequences are worth knowing, and each is asserted:
 *
 * - **The login name never changes.** WordPress does not allow it, so an account created
 *   from an address keeps that address as its `user_login` — a fact of no consequence,
 *   because WordPress signs somebody in by either.
 * - **The display name follows the names, unless a shop has set one by hand.** Every
 *   screen in this plugin prints `display_name`, so a rename nothing displayed would be a
 *   field with no destination.
 * - **An address belongs to one account.** Moving somebody onto one that already has an
 *   account is refused rather than merged, the same answer and the same 409 as adding one.
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
	 *
	 * Named after the service rather than repeated, because this is one value with two
	 * audiences — a documented wire literal and the way every screen says the same thing —
	 * and two copies is how a rename reaches one of them.
	 */
	const ROLE_DEFAULT = Members::ROLE_DEFAULT;

	/**
	 * The value of `location_access` that means every location the organization has.
	 */
	const ACCESS_ALL = Members::ACCESS_ALL;

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
		$values = array_merge(
			$this->submitted( $request, array( 'first_name', 'last_name', 'capabilities', 'location_access' ) ),
			array(
				'email' => (string) $request['email'],
				'role'  => $role,
			)
		);

		$member = Members::add( $organization, $values );

		if ( is_wp_error( $member ) ) {
			return $this->refusal( $member );
		}

		return $this->respond( $member, 201 );
	}

	/**
	 * Change a member's name, address, role, status, permissions or location access.
	 *
	 * The order of the writes is deliberate. Everything that can be refused is settled
	 * before anything is written, and the WordPress account goes first of the two — a
	 * refusal there is an ordinary thing a client will meet (the address is somebody
	 * else's), whereas a repository answering 0 is a database anomaly. Doing it the other
	 * way round would leave a member promoted to admin by a request that then failed on
	 * the address it also carried.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The member, or an error.
	 */
	public function update_item( $request ) {
		$member = $this->find( $request );

		if ( is_wp_error( $member ) ) {
			return $member;
		}

		$changes = $this->submitted(
			$request,
			array( 'first_name', 'last_name', 'email', 'role', 'status', 'capabilities', 'location_access' )
		);

		$saved = Members::update( $member, $changes );

		if ( is_wp_error( $saved ) ) {
			return $this->refusal( $saved );
		}

		return $this->respond( $saved, 200 );
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

		$previous = OrganizationsController::member_payload( $member, MemberRepository::location_ids( $member->get_id() ), true );
		$removed  = Members::remove( $member );

		if ( is_wp_error( $removed ) ) {
			return $this->refusal( $removed );
		}

		return new \WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			),
			200
		);
	}

	/**
	 * What the request asked for, keyed the way the service reads it.
	 *
	 * Only the fields the request actually carried are included, because presence is how
	 * the service tells "set this to empty" from "leave it alone" — the same distinction
	 * `has_param()` draws, carried across a boundary that has no request object. It is also
	 * why none of the arguments this route declares has a `default`: WordPress fills a
	 * declared default into the request, so `'default' => ''` on a name would make every
	 * edit that never mentioned one blank it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param array            $fields  The fields to carry across if they are there.
	 * @return array Submitted values.
	 */
	private function submitted( $request, array $fields ) {
		$submitted = array();

		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$submitted[ $field ] = $request[ $field ];
			}
		}

		return $submitted;
	}

	/**
	 * Put a service refusal on the wire.
	 *
	 * The service names its refusals after the rule that was broken, and knows nothing about
	 * HTTP; this is where they become the codes and statuses `docs/rest-api.md` documents and
	 * a client keys on. Anything not in the map is a validation error keyed by field name,
	 * which is what `Writes::refuse()` turns into a `params` map.
	 *
	 * @param \WP_Error $error The refusal the service gave.
	 * @return \WP_Error The refusal a client receives.
	 */
	private function refusal( \WP_Error $error ) {
		$conflicts = array(
			Members::ERROR_ALREADY_MEMBER => array( 'woap_rest_already_member', 409 ),
			Members::ERROR_EMAIL_TAKEN    => array( 'woap_rest_email_taken', 409 ),
			Members::ERROR_LAST_ADMIN     => array( 'woap_rest_last_admin', 409 ),
			Members::ERROR_NO_USER        => array( 'woap_rest_no_user', 500 ),
			Members::ERROR_NOT_SAVED      => array( 'woap_rest_not_saved', 500 ),
		);

		$code = (string) $error->get_error_code();

		if ( ! isset( $conflicts[ $code ] ) ) {
			return Writes::refuse( 'woap_rest_invalid_member', $error );
		}

		list( $wire_code, $status ) = $conflicts[ $code ];

		$data = (array) $error->get_error_data( $code );

		return new \WP_Error(
			$wire_code,
			$error->get_error_message( $code ),
			array_merge( $data, array( 'status' => $status ) )
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
			return $this->no_user_behind_it();
		}

		return new \WP_REST_Response( $payload, $status );
	}

	/**
	 * The refusal for a membership whose WordPress account has been deleted.
	 *
	 * @return \WP_Error A 500.
	 */
	private function no_user_behind_it() {
		return new \WP_Error(
			'woap_rest_member_has_no_user',
			__( 'That membership has no WordPress account behind it.', 'woo-organization-accounts-pro' ),
			array( 'status' => 500 )
		);
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
	 * **None of them declares a default**, which is what makes that true. WordPress fills a
	 * declared default into the request itself, so `'default' => ''` on a name would blank
	 * the surname of every member whose role was changed through this route — the same trap
	 * `tax_id` presented on the organization edit, and the reason both are read with
	 * `has_param()` rather than by truthiness.
	 *
	 * @return array Argument definitions.
	 */
	private function update_params() {
		return array(
			'first_name'      => array(
				'description'       => __( 'Their first name, on the WordPress account behind the membership.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'       => array(
				'description'       => __( 'Their surname, on the WordPress account behind the membership.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email'           => array(
				'description'       => __( 'The address they sign in with and the shop writes to. The login name they were created with does not change.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
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
