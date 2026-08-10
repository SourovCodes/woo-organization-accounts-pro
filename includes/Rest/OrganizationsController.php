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
use WooOrgAccounts\Membership\Context;

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
 * `Context::can_purchase()`, and location access is reported as "all" or a list. A
 * device re-deriving either from roles, statuses and capability overrides would be a
 * second expression of rules this plugin deliberately states once.
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
				'schema' => array( $this, 'get_item_schema' ),
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

		$data = $this->prepare_page( $organizations );
		$etag = '"' . md5( (string) wp_json_encode( $data ) ) . '"';

		if ( $this->matches_etag( $request->get_header( 'if_none_match' ), $etag ) ) {
			$response = new \WP_REST_Response( null, 304 );
		} else {
			$response = new \WP_REST_Response( $data, 200 );
		}

		$response->header( 'ETag', $etag );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * Whether the client already holds this exact page.
	 *
	 * The header may carry several validators and may mark them weak. Weak and strong
	 * are the same answer here — the payload either hashes to the same thing or it does
	 * not — so the `W/` prefix is stripped rather than rejected.
	 *
	 * @param string|null $header The If-None-Match header, if any.
	 * @param string      $etag   The ETag this page hashes to, quoted.
	 * @return bool True when the client's copy is current.
	 */
	private function matches_etag( $header, $etag ) {
		if ( empty( $header ) ) {
			return false;
		}

		foreach ( explode( ',', (string) $header ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '*' === $candidate ) {
				return true;
			}

			if ( 0 === stripos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}

			if ( hash_equals( $etag, $candidate ) ) {
				return true;
			}
		}

		return false;
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
	private function prepare_page( array $organizations ) {
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

			$data[] = $this->prepare_organization(
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
	private function prepare_organization( Organization $organization, array $members, array $locations, array $access ) {
		$prepared_members = array();

		foreach ( $members as $member ) {
			$prepared = $this->prepare_member( $member, $access );

			if ( null !== $prepared ) {
				$prepared_members[] = $prepared;
			}
		}

		$prepared_locations = array();

		foreach ( $locations as $location ) {
			$prepared_locations[] = $this->prepare_location( $location );
		}

		return array(
			'id'                    => $organization->get_id(),
			'name'                  => $organization->get_name(),
			'status'                => $organization->get_status(),
			'allow_custom_shipping' => $organization->allows_custom_shipping(),
			'billing'               => $organization->get_billing_address(),
			'members'               => $prepared_members,
			'locations'             => $prepared_locations,
			'date_modified_gmt'     => (string) $organization->get( 'date_modified' ),
		);
	}

	/**
	 * One member.
	 *
	 * A membership whose WordPress user has since been deleted is left out entirely.
	 * The row survives the user because nothing cascades, but there is nobody left to
	 * name and nobody who could place an order — offering the counter a nameless person
	 * would be worse than a shorter list.
	 *
	 * The capability overrides are never sent. They are stored as a diff against the
	 * role's defaults, so on their own they are not merely unhelpful but misleading:
	 * an empty set means "whatever the role allows", not "nothing". `can_place_orders`
	 * is the resolved answer, which is the only part of it a till acts on.
	 *
	 * @param Member $member The membership.
	 * @param array  $access Map of member ID to permitted location IDs.
	 * @return array|null The payload, or null when the user is gone.
	 */
	private function prepare_member( Member $member, array $access ) {
		$user = get_userdata( $member->get_user_id() );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$permitted = isset( $access[ $member->get_id() ] ) ? $access[ $member->get_id() ] : array();

		return array(
			'member_id'        => $member->get_id(),
			'user_id'          => $member->get_user_id(),
			'name'             => $user->display_name,
			'email'            => $user->user_email,
			'role'             => $member->get_role(),
			'status'           => $member->is_active() ? Member::STATUS_ACTIVE : Member::STATUS_INACTIVE,
			'can_place_orders' => Context::can_purchase( $member->get_user_id() ),
			'location_access'  => empty( $permitted ) ? 'all' : $permitted,
		);
	}

	/**
	 * One delivery location.
	 *
	 * @param Location $location The location.
	 * @return array The payload.
	 */
	private function prepare_location( Location $location ) {
		return array_merge(
			array(
				'id'         => $location->get_id(),
				'name'       => $location->get_name(),
				'is_default' => $location->is_default(),
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
