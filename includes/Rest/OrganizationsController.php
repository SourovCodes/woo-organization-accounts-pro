<?php
/**
 * The organization snapshot route.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Serves organizations, with their members and locations, as one snapshot.
 *
 * Written for a till that syncs on an interval and then works offline, which decides
 * nearly everything about the shape:
 *
 * **It is a full snapshot, not a delta.** Only `woap_organizations` carries a
 * `date_modified`; members and locations record a `date_created` and nothing else, and
 * every delete in this plugin is a hard delete with no tombstone left behind. So a
 * `modified_after` parameter could be offered but not honoured — a device asking for
 * changes since yesterday would never be told that an employee left or that a location
 * was removed, and would go on offering a delivery address the organization has
 * abandoned. A snapshot answers deletions by omission, which is the one thing this
 * schema can say truthfully.
 *
 * **Members and locations are embedded, not paged separately.** Three collections
 * paged independently can be torn by a write that lands between two requests, leaving
 * the device holding a member whose only permitted location it never fetched. A page
 * here is internally consistent because it is one set of queries.
 *
 * **The page is ordered by ID**, which is stable under renames and status changes.
 * Ordering by name would let a rename between page one and page two drop an
 * organization out of the sync entirely.
 *
 * **Answers are sent, not inputs.** `can_place_orders` is decided here by
 * `Context::can_purchase()`, location access is reported as "all" or a list, and
 * permissions are the role's defaults with the member's overrides already layered on.
 * A device re-deriving any of those from roles, statuses and a stored diff would be a
 * second expression of rules this plugin deliberately states once.
 *
 * **The writes are for a back office, not for the till**, and the till is why they are
 * shaped as they are. A device that syncs and sells offline has no business editing the
 * records it is selling from; the consumer these serve is the screen where somebody
 * reviews a registration, approves it, adds a branch and puts an employee on the
 * account. Two things follow. Reading and writing are the same capability —
 * `manage_woocommerce`, never one of this plugin's own, which are granted from a
 * membership and answer a question about one organization. And **the status has a route
 * of its own** rather than a field on the edit: moving an organization between statuses
 * is what the approval and rejection emails hang off and what decides whether its
 * members may sign in, and a field on a general-purpose edit is exactly what a client
 * sends back without meaning to.
 */
final class OrganizationsController {

	/**
	 * The route, below the namespace.
	 */
	const ROUTE = 'organizations';

	/**
	 * Organizations per page when the request does not say.
	 */
	const DEFAULT_PER_PAGE = 50;

	/**
	 * The most organizations one page may carry.
	 *
	 * A page is bounded because it is not one row: each organization brings its members,
	 * its locations and a capability check per member with it.
	 */
	const MAX_PER_PAGE = 200;

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			RestApi::REST_NAMESPACE,
			'/' . self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => $this->write_params( true ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			RestApi::REST_NAMESPACE,
			'/' . self::ROUTE . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => $this->write_params( false ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			RestApi::REST_NAMESPACE,
			'/' . self::ROUTE . '/(?P<id>[\d]+)/status',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => $this->status_params(),
				),
			)
		);
	}

	/**
	 * Who may read the snapshot.
	 *
	 * `manage_woocommerce`, which is what a till's REST key is issued against. This is
	 * deliberately not one of the plugin's own capabilities: those are granted from a
	 * membership and answer "what may this person do to their own organization?", and
	 * the answer to that is never "read every organization on the site".
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public function get_items_permissions_check() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'woap_rest_forbidden',
			__( 'You are not allowed to list organizations.', 'woo-organization-accounts-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Who may create an organization, edit one, or change its status.
	 *
	 * The same capability as reading, for the same reason: this is the shop's own
	 * back-office asking, not an organization asking about itself.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public function write_permissions_check() {
		return Writes::permission_check(
			sprintf(
				/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
				__( 'You are not allowed to manage %s.', 'woo-organization-accounts-pro' ),
				Labels::organizations()
			)
		);
	}

	/**
	 * Return one page of organizations.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The page, a 304, or an error.
	 */
	public function get_items( $request ) {
		$page     = max( 1, absint( $request['page'] ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request['per_page'] ) ) );
		$status   = (string) $request['status'];

		$total = OrganizationRepository::count( array( 'status' => $status ) );
		$pages = (int) ceil( $total / $per_page );

		if ( $total > 0 && $page > $pages ) {
			return new \WP_Error(
				'woap_rest_invalid_page_number',
				__( 'The requested page does not exist.', 'woo-organization-accounts-pro' ),
				array( 'status' => 400 )
			);
		}

		$organizations = OrganizationRepository::query(
			array(
				'status'  => $status,
				'orderby' => 'id',
				'order'   => 'ASC',
				'limit'   => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
			)
		);

		$response = Etag::respond( self::prepare_page( $organizations ), $request );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * One organization, in the same shape the snapshot reports it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The organization, or a 404.
	 */
	public function get_item( $request ) {
		$organization = Writes::organization( $request['id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		return Etag::respond( self::organization_payload( $organization ), $request );
	}

	/**
	 * Create an organization.
	 *
	 * Nothing is emailed. The registration form's own hook is
	 * `woo_org_accounts_organization_registered`, which the shop's new-account mail hangs
	 * off and which carries the member who registered — and there is no such member here,
	 * because staff entering an account is not somebody signing up. That is the same
	 * distinction the importer draws, and for the same reason.
	 *
	 * The status defaults to pending, so an account entered here goes through the review
	 * the shop already has rather than around it. Approving it is the status route, which
	 * is where the approval mail comes from.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The organization, or a refusal.
	 */
	public function create_item( $request ) {
		$organization = new Organization();

		$details = array(
			'name'   => (string) $request['name'],
			'tax_id' => (string) $request['tax_id'],
			'status' => (string) $request['status'],
		);

		$address = Writes::address(
			AddressFields::BILLING,
			$request['billing'],
			array_fill_keys( Organization::BILLING_FIELDS, '' )
		);

		$errors = new \WP_Error();

		Organization::validate_details( $details, $errors );
		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		if ( $errors->has_errors() ) {
			return $this->refuse( $errors );
		}

		$organization->set_props(
			array(
				'name'                  => $details['name'],
				'tax_id'                => $details['tax_id'],
				'status'                => $details['status'],
				'allow_custom_shipping' => (bool) $request['allow_custom_shipping'],
			)
		);

		$organization->set_billing_address( $address );

		if ( 0 === OrganizationRepository::save( $organization ) ) {
			return Writes::not_saved( $this->not_saved_message() );
		}

		return new \WP_REST_Response( self::organization_payload( $organization ), 201 );
	}

	/**
	 * Edit an organization's own details and its billing address.
	 *
	 * The status is deliberately not editable here. Moving an organization between
	 * statuses is what the approval and rejection emails hang off, and a field on a
	 * general-purpose edit is something a client sends back without meaning to — a form
	 * that round-trips what it fetched would approve an account by saving a corrected
	 * postcode. It has its own route, which is the whole of what that route is for.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The organization, or a refusal.
	 */
	public function update_item( $request ) {
		$organization = Writes::organization( $request['id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		/*
		 * Refused rather than ignored. A client that fetched an organization, changed the
		 * postcode and sent the whole thing back carries a status it never meant to set;
		 * swallowing it silently would mean the app believed it had approved an account,
		 * or had not. Neither is a thing to be quiet about.
		 */
		if ( $request->has_param( 'status' ) ) {
			return new \WP_Error(
				'woap_rest_status_has_its_own_route',
				sprintf(
					/* translators: %s: the URL path of the status route. */
					__( 'The status is changed at %s, not here — that is what sends the approval and rejection emails.', 'woo-organization-accounts-pro' ),
					'POST /' . RestApi::REST_NAMESPACE . '/' . self::ROUTE . '/' . $organization->get_id() . '/status'
				),
				array( 'status' => 400 )
			);
		}

		$details = array();

		foreach ( array( 'name', 'tax_id' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$details[ $field ] = (string) $request[ $field ];
			}
		}

		$errors  = new \WP_Error();
		$address = Writes::address( AddressFields::BILLING, $request['billing'], $organization->get_billing_address() );

		Organization::validate_details( $details, $errors );

		/*
		 * The address is checked only when the request carried one. An organization
		 * stored before this plugin validated anything, or whose country has grown
		 * stricter since, must not be impossible to rename — a rule applied to a
		 * keystroke is not automatically fair applied retroactively to a record that
		 * already exists.
		 */
		if ( $request->has_param( 'billing' ) ) {
			AddressFields::validate( AddressFields::BILLING, $address, $errors );
		}

		if ( $errors->has_errors() ) {
			return $this->refuse( $errors );
		}

		if ( $request->has_param( 'allow_custom_shipping' ) ) {
			$details['allow_custom_shipping'] = (bool) $request['allow_custom_shipping'];
		}

		$organization->set_props( $details );

		if ( $request->has_param( 'billing' ) ) {
			$organization->set_billing_address( $address );
		}

		if ( 0 === OrganizationRepository::save( $organization ) ) {
			return Writes::not_saved( $this->not_saved_message() );
		}

		return new \WP_REST_Response( self::organization_payload( $organization ), 200 );
	}

	/**
	 * Approve, suspend, reject or re-open an organization.
	 *
	 * The whole of the review flow, and the reason it goes through
	 * `OrganizationRepository::set_status()` rather than writing the column: that is what
	 * fires `woo_org_accounts_organization_status_changed`, which the approval and
	 * rejection emails listen for and which `LoginGate` reads when it decides whether the
	 * organization's members may sign in at all. Writing the column directly would
	 * approve an account and tell nobody.
	 *
	 * Asking for the status it already holds is not an error — a review screen pressed
	 * twice, or two people approving the same account, should not need to care — so it
	 * answers 200 with `changed` false and sends no mail.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The organization and whether it moved, or an error.
	 */
	public function update_status( $request ) {
		$organization = Writes::organization( $request['id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$status = (string) $request['status'];

		if ( $organization->get_status() === $status ) {
			return new \WP_REST_Response(
				array(
					'changed'      => false,
					'organization' => self::organization_payload( $organization ),
				),
				200
			);
		}

		if ( ! OrganizationRepository::set_status( $organization->get_id(), $status ) ) {
			return Writes::not_saved( $this->not_saved_message() );
		}

		return new \WP_REST_Response(
			array(
				'changed'      => true,
				'organization' => self::organization_payload( OrganizationRepository::find( $organization->get_id() ) ),
			),
			200
		);
	}

	/**
	 * Turn the validators' form-shaped errors into one refusal.
	 *
	 * @param \WP_Error $errors The errors.
	 * @return \WP_Error The refusal.
	 */
	private function refuse( \WP_Error $errors ) {
		return Writes::refuse( 'woap_rest_invalid_organization', $errors, array( 'billing_' => 'billing.' ) );
	}

	/**
	 * The message for a write the database would not take.
	 *
	 * @return string Translated message.
	 */
	private function not_saved_message() {
		return sprintf(
			/* translators: %s: the organization noun for the site's mode, for example "Company". */
			__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
			Labels::organization()
		);
	}

	/**
	 * One organization with its members and locations, read fresh.
	 *
	 * The single-item form of what the snapshot builds a page of. Both go through the
	 * same three builders, so an organization read one at a time and the same
	 * organization read in a page cannot describe themselves differently.
	 *
	 * @param Organization $organization The organization.
	 * @return array The payload.
	 */
	public static function organization_payload( Organization $organization ) {
		$id      = $organization->get_id();
		$members = MemberRepository::for_organization( $id );
		$access  = array();

		foreach ( $members as $member ) {
			$access[ $member->get_id() ] = MemberRepository::location_ids( $member->get_id() );
		}

		return self::prepare_organization( $organization, $members, LocationRepository::for_organization( $id ), $access );
	}

	/**
	 * The parameters a create or an edit accepts.
	 *
	 * The billing fields are not enumerated: which of them exist depends on the country
	 * and on whatever the shop has done to its checkout fields, and `AddressFields` is
	 * the authority on that. A field the country does not have is dropped rather than
	 * refused, the same answer the web forms give.
	 *
	 * An edit declares no defaults at all, which is what makes it a partial edit: a
	 * default would be filled into the request and then written, so `tax_id` defaulting
	 * to an empty string would blank the tax ID of every organization renamed through
	 * this route.
	 *
	 * @param bool $creating True for the create route.
	 * @return array Argument definitions.
	 */
	private function write_params( $creating ) {
		$params = array(
			'name'                  => array(
				'description'       => __( 'The organization name.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'required'          => (bool) $creating,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tax_id'                => array(
				'description'       => __( 'VAT number, tax ID or registration number. Required when the shop asks for one.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'allow_custom_shipping' => array(
				'description'       => __( 'Whether members may ship to a one-off address instead of a location.', 'woo-organization-accounts-pro' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'billing'               => array(
				'description' => __( 'The centralised billing address, as WooCommerce names its billing fields.', 'woo-organization-accounts-pro' ),
				'type'        => 'object',
			),
		);

		if ( ! $creating ) {
			return $params;
		}

		$params['tax_id']['default']                = '';
		$params['allow_custom_shipping']['default'] = true;

		$params['status'] = array(
			'description'       => __( 'The status a new organization starts in. Changing it afterwards is the status route.', 'woo-organization-accounts-pro' ),
			'type'              => 'string',
			'default'           => Organization::STATUS_PENDING,
			'enum'              => array_keys( Organization::statuses() ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * The parameters the status route accepts.
	 *
	 * @return array Argument definitions.
	 */
	private function status_params() {
		return array(
			'status' => array(
				'description'       => __( 'The status to move the organization to.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array_keys( Organization::statuses() ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Build the payload for a page of organizations.
	 *
	 * Every read the page needs is batched: one query for the members of all of them,
	 * one for the locations, one for the access lists, and one to prime the user cache.
	 * Asked per organization instead, a hundred-row page would be several hundred
	 * queries — which is exactly the shape a till syncing on an interval would run into
	 * first.
	 *
	 * @param Organization[] $organizations The page.
	 * @return array Payload, one entry per organization.
	 */
	private static function prepare_page( array $organizations ) {
		$organization_ids = array();

		foreach ( $organizations as $organization ) {
			$organization_ids[] = $organization->get_id();
		}

		$members_by_organization   = MemberRepository::for_organizations( $organization_ids );
		$locations_by_organization = LocationRepository::for_organizations( $organization_ids );

		$member_ids = array();
		$user_ids   = array();

		foreach ( $members_by_organization as $members ) {
			foreach ( $members as $member ) {
				$member_ids[] = $member->get_id();
				$user_ids[]   = $member->get_user_id();
			}
		}

		$access = MemberRepository::location_ids_for_members( $member_ids );

		if ( ! empty( $user_ids ) ) {
			cache_users( $user_ids );
		}

		$data = array();

		foreach ( $organizations as $organization ) {
			$id = $organization->get_id();

			$data[] = self::prepare_organization(
				$organization,
				isset( $members_by_organization[ $id ] ) ? $members_by_organization[ $id ] : array(),
				isset( $locations_by_organization[ $id ] ) ? $locations_by_organization[ $id ] : array(),
				$access
			);
		}

		return $data;
	}

	/**
	 * One organization, with everything the till needs to sell to it.
	 *
	 * The tax ID is deliberately absent. Everything here lands on a device that can be
	 * left on a counter or lost, and a tax identifier is the one field in the table that
	 * is regulated rather than merely private. A shop whose till prints invoices can add
	 * it here.
	 *
	 * @param Organization $organization The organization.
	 * @param Member[]     $members      Its members.
	 * @param Location[]   $locations    Its locations.
	 * @param array        $access       Map of member ID to permitted location IDs.
	 * @return array The payload.
	 */
	private static function prepare_organization( Organization $organization, array $members, array $locations, array $access ) {
		$prepared_members = array();

		foreach ( $members as $member ) {
			$prepared = self::member_payload( $member, isset( $access[ $member->get_id() ] ) ? $access[ $member->get_id() ] : array() );

			if ( null !== $prepared ) {
				$prepared_members[] = $prepared;
			}
		}

		$prepared_locations = array();

		foreach ( $locations as $location ) {
			$prepared_locations[] = self::location_payload( $location );
		}

		return array(
			'id'                    => $organization->get_id(),
			'name'                  => $organization->get_name(),
			'status'                => $organization->get_status(),
			'allow_custom_shipping' => $organization->allows_custom_shipping(),
			'billing'               => $organization->get_billing_address(),
			'billing_formatted'     => self::format_address( $organization->get_billing_address() ),
			'members'               => $prepared_members,
			'locations'             => $prepared_locations,
			'date_modified_gmt'     => (string) $organization->get( 'date_modified' ),
		);
	}

	/**
	 * An address as WooCommerce would print it for its country.
	 *
	 * Which line the postcode sits on, whether the city precedes it and where the
	 * country goes all differ per country, and WooCommerce's formatter knows every
	 * variant. Serving the formatted form beside the fields means the device never
	 * invents an envelope layout of its own — the same reuse rule as everything else
	 * here. Newline-separated rather than WooCommerce's default `<br/>`, because the
	 * consumer is an app, not a page.
	 *
	 * @param array $address Address fields, unprefixed.
	 * @return string The address as lines of text.
	 */
	private static function format_address( array $address ) {
		return (string) WC()->countries->get_formatted_address( $address, "\n" );
	}

	/**
	 * One member.
	 *
	 * A membership whose WordPress user has since been deleted is left out entirely.
	 * The row survives the user because nothing cascades, but there is nobody left to
	 * name and nobody who could place an order — offering the counter a nameless person
	 * would be worse than a shorter list.
	 *
	 * **The stored capability overrides are never sent**, on any route. They are a diff
	 * against the role's defaults, so read raw they are not merely unhelpful but actively
	 * misleading: an empty set means "whatever the role allows", not "nothing".
	 * `can_place_orders` is the resolved answer, and it is the only part of any of it a
	 * till acts on.
	 *
	 * **The permissions are sent only where they are edited**, which is the members
	 * routes, and never in the snapshot. A device that syncs a copy to sell from offline
	 * has no use for the permission configuration of every employee of every organization
	 * on the shop — and it is a device that gets left on a counter. When they are asked
	 * for, what is sent is the *resolved* map rather than the diff, beside a flag saying
	 * whether anything was overridden at all. Those are exactly the two facts the member
	 * form asks for outright, and it asks because a diff is meaningless without knowing
	 * what it is against: deriving one from permissions drawn for the role somebody held
	 * *before* a promotion is how an organization admin was once created who could manage
	 * nothing.
	 *
	 * @param Member $member      The membership.
	 * @param array  $permitted   The location IDs this member is restricted to, empty for all.
	 * @param bool   $permissions Whether to include the resolved permissions.
	 * @return array|null The payload, or null when the user is gone.
	 */
	public static function member_payload( Member $member, array $permitted, $permissions = false ) {
		$user = get_userdata( $member->get_user_id() );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$payload = array(
			'member_id'        => $member->get_id(),
			'user_id'          => $member->get_user_id(),
			'name'             => $user->display_name,
			'email'            => $user->user_email,
			'role'             => $member->get_role(),
			'status'           => $member->is_active() ? Member::STATUS_ACTIVE : Member::STATUS_INACTIVE,
			'can_place_orders' => Context::can_purchase( $member->get_user_id() ),
			'location_access'  => empty( $permitted ) ? 'all' : $permitted,
		);

		if ( ! $permissions ) {
			return $payload;
		}

		$overrides = $member->get_capabilities();

		$payload['capabilities']             = array_merge( Roles::role_capabilities( $member->get_role() ), $overrides );
		$payload['capabilities_follow_role'] = empty( $overrides );

		return $payload;
	}

	/**
	 * One delivery location.
	 *
	 * @param Location $location The location.
	 * @return array The payload.
	 */
	public static function location_payload( Location $location ) {
		return array_merge(
			array(
				'id'         => $location->get_id(),
				'name'       => $location->get_name(),
				'is_default' => $location->is_default(),
				'formatted'  => self::format_address( $location->get_shipping_address() ),
			),
			$location->get_shipping_address()
		);
	}

	/**
	 * The parameters the collection accepts.
	 *
	 * @return array Argument definitions.
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'woo-organization-accounts-pro' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Organizations per page.', 'woo-organization-accounts-pro' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'   => array(
				'description'       => __( 'Return only organizations with this status. Omit for all of them.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'default'           => '',
				'enum'              => array_merge( array( '' ), array_keys( Organization::statuses() ) ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The schema for one organization.
	 *
	 * @return array JSON schema.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'woap_organization',
			'type'       => 'object',
			'properties' => array(
				'id'                    => array(
					'description' => __( 'Unique identifier for the organization.', 'woo-organization-accounts-pro' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'                  => array(
					'description' => __( 'The organization name.', 'woo-organization-accounts-pro' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'                => array(
					'description' => __( 'The organization status.', 'woo-organization-accounts-pro' ),
					'type'        => 'string',
					'enum'        => array_keys( Organization::statuses() ),
					'readonly'    => true,
				),
				'allow_custom_shipping' => array(
					'description' => __( 'Whether members may ship to a one-off address instead of a location.', 'woo-organization-accounts-pro' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'billing'               => array(
					'description' => __( 'The centralised billing address every order for this organization is billed to.', 'woo-organization-accounts-pro' ),
					'type'        => 'object',
					'properties'  => $this->address_schema( Organization::BILLING_FIELDS ),
					'readonly'    => true,
				),
				'billing_formatted'     => array(
					'description' => __( 'The billing address formatted for its country, as lines of text.', 'woo-organization-accounts-pro' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'members'               => array(
					'description' => __( 'The people who may order for this organization.', 'woo-organization-accounts-pro' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'member_id'        => array(
								'description' => __( 'Unique identifier for the membership.', 'woo-organization-accounts-pro' ),
								'type'        => 'integer',
							),
							'user_id'          => array(
								'description' => __( 'The WordPress user this membership belongs to. Use it as the order customer.', 'woo-organization-accounts-pro' ),
								'type'        => 'integer',
							),
							'name'             => array(
								'description' => __( 'The member display name.', 'woo-organization-accounts-pro' ),
								'type'        => 'string',
							),
							'email'            => array(
								'description' => __( 'The member email address.', 'woo-organization-accounts-pro' ),
								'type'        => 'string',
							),
							'role'             => array(
								'description' => __( 'The member role within the organization.', 'woo-organization-accounts-pro' ),
								'type'        => 'string',
								'enum'        => array( Member::ROLE_ADMIN, Member::ROLE_MEMBER ),
							),
							'status'           => array(
								'description' => __( 'Whether the membership is in force.', 'woo-organization-accounts-pro' ),
								'type'        => 'string',
								'enum'        => array( Member::STATUS_ACTIVE, Member::STATUS_INACTIVE ),
							),
							'can_place_orders' => array(
								'description' => __( 'Whether this member may order right now, decided by the shop rather than by the device.', 'woo-organization-accounts-pro' ),
								'type'        => 'boolean',
							),
							'location_access'  => array(
								'description' => __( 'The locations this member may ship to: the string "all", or a list of location IDs.', 'woo-organization-accounts-pro' ),
								'oneOf'       => array(
									array(
										'type' => 'string',
										'enum' => array( 'all' ),
									),
									array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
								),
							),
						),
					),
				),
				'locations'             => array(
					'description' => __( 'The addresses this organization has goods delivered to.', 'woo-organization-accounts-pro' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array_merge(
							array(
								'id'         => array(
									'description' => __( 'Unique identifier for the location.', 'woo-organization-accounts-pro' ),
									'type'        => 'integer',
								),
								'name'       => array(
									'description' => __( 'The label this location is chosen by.', 'woo-organization-accounts-pro' ),
									'type'        => 'string',
								),
								'is_default' => array(
									'description' => __( 'Whether this is the organization default location.', 'woo-organization-accounts-pro' ),
									'type'        => 'boolean',
								),
								'formatted'  => array(
									'description' => __( 'The address formatted for its country, as lines of text.', 'woo-organization-accounts-pro' ),
									'type'        => 'string',
								),
							),
							$this->address_schema( Location::ADDRESS_FIELDS )
						),
					),
				),
				'date_modified_gmt'     => array(
					'description' => __( 'When the organization record last changed, in GMT. Its members and locations carry no such date, which is why this route serves whole snapshots.', 'woo-organization-accounts-pro' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Schema properties for a set of address fields.
	 *
	 * Built from the entity's own field list rather than written out, so an address that
	 * gains a column cannot end up described here as if it had not.
	 *
	 * @param string[] $fields Field names.
	 * @return array Map of field name to schema.
	 */
	private function address_schema( array $fields ) {
		$properties = array();

		foreach ( $fields as $field ) {
			$properties[ $field ] = array( 'type' => 'string' );
		}

		return $properties;
	}
}
