<?php
/**
 * The locations of one organization, over REST.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Locations\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the places an organization has goods delivered to.
 *
 * A sub-resource of the organization rather than a collection of its own, because a
 * location has no meaning apart from the organization that owns it and every lookup
 * here is scoped to one: `LocationRepository::find_for_organization()` resolves an ID
 * from another organization to nothing rather than to a location. That is the
 * cross-tenant question, and it is answered separately from the capability question
 * even though this route only admits shop staff — the two are easy to answer separately
 * by mistake, and answering only one of them is the whole of cross-tenant access.
 *
 * The address is WooCommerce's shipping address, validated by `AddressFields` exactly
 * as the account screen validates it: the same per-country rules the checkout will
 * apply, including the two relaxations a stored delivery address gets and a typed one
 * does not — no required surname, because "Warehouse North" has none, and no required
 * phone, because a shop turning that setting on must not make every address it has
 * already saved undeliverable.
 */
final class LocationsController {

	/**
	 * The route below an organization.
	 */
	const ROUTE = 'locations';

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
					'args'                => $this->write_params( true ),
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
					'args'                => $this->write_params( false ),
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
	 * Who may read and write locations.
	 *
	 * @return true|\WP_Error True when permitted.
	 */
	public function permissions_check() {
		return Writes::permission_check(
			sprintf(
				/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
				__( 'You are not allowed to manage %s.', 'woo-organization-accounts-pro' ),
				Labels::locations()
			)
		);
	}

	/**
	 * Every location of one organization.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The locations, or an error.
	 */
	public function get_items( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$data = array();

		foreach ( LocationRepository::for_organization( $organization->get_id() ) as $location ) {
			$data[] = OrganizationsController::location_payload( $location );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * One location.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The location, or an error.
	 */
	public function get_item( $request ) {
		$location = $this->find( $request );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		return new \WP_REST_Response( OrganizationsController::location_payload( $location ), 200 );
	}

	/**
	 * Add a location to an organization.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The location, or an error.
	 */
	public function create_item( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		return $this->save( new Location(), $organization, $request, 201 );
	}

	/**
	 * Edit a location.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The location, or an error.
	 */
	public function update_item( $request ) {
		$location = $this->find( $request );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		return $this->save( $location, $organization, $request, 200 );
	}

	/**
	 * Remove a location.
	 *
	 * The repository takes the location out of every member's access list as it goes,
	 * so no membership is left pointing at an address that no longer exists.
	 *
	 * Deleting the last one is allowed, because the account screen allows it and a rule
	 * this route enforced alone would be a rule the shop's own staff could walk around.
	 * It does leave the organization unable to check out until another is added, which
	 * is what the `organization_can_ship` flag in the response is for.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error What was deleted, or an error.
	 */
	public function delete_item( $request ) {
		$location = $this->find( $request );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$previous        = OrganizationsController::location_payload( $location );
		$organization_id = $location->get_organization_id();
		$deleted         = Locations::delete( $location );

		if ( is_wp_error( $deleted ) ) {
			return Writes::not_saved( $deleted->get_error_message() );
		}

		return new \WP_REST_Response(
			array(
				'deleted'               => true,
				'previous'              => $previous,
				'organization_can_ship' => Locations::can_ship( $organization_id ),
			),
			200
		);
	}

	/**
	 * Validate a submission and store it.
	 *
	 * @param Location         $location     The location being written, new or existing.
	 * @param Organization     $organization The organization it belongs to.
	 * @param \WP_REST_Request $request      The request.
	 * @param int              $status       Response status when the write succeeds.
	 * @return \WP_REST_Response|\WP_Error The location, or a refusal.
	 */
	private function save( Location $location, Organization $organization, $request, $status ) {
		$saved = Locations::save( $organization, $location, $this->submitted( $request ) );

		if ( is_wp_error( $saved ) ) {
			return 'woap_not_saved' === $saved->get_error_code()
				? Writes::not_saved( $saved->get_error_message() )
				: Writes::refuse( 'woap_rest_invalid_location', $saved );
		}

		return new \WP_REST_Response( OrganizationsController::location_payload( $saved ), $status );
	}

	/**
	 * What the request asked to change, keyed the way the service reads it.
	 *
	 * A location is flat in the snapshot — the address fields sit beside the name rather
	 * than under an `address` key — so it is flat here too. One shape per noun, whichever
	 * direction it is travelling in.
	 *
	 * Only the fields the request actually carried are included, because presence is how
	 * the service tells "set this to empty" from "leave it alone" — the same distinction
	 * `has_param()` draws, carried across a boundary that has no request object.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array Submitted values, keyed without a prefix.
	 */
	private function submitted( $request ) {
		$submitted = array();

		foreach ( array_merge( array( 'name', 'is_default' ), Location::ADDRESS_FIELDS ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$submitted[ $field ] = $request[ $field ];
			}
		}

		return $submitted;
	}

	/**
	 * The location a request names, scoped to the organization in its path.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return Location|\WP_Error The location, or a 404.
	 */
	private function find( $request ) {
		$organization = Writes::organization( $request['organization_id'] );

		if ( is_wp_error( $organization ) ) {
			return $organization;
		}

		$location = LocationRepository::find_for_organization( absint( $request['id'] ), $organization->get_id() );

		if ( $location instanceof Location ) {
			return $location;
		}

		return new \WP_Error(
			'woap_rest_location_not_found',
			sprintf(
				/* translators: 1: the singular location noun, for example "Branch", 2: the organization noun. */
				__( 'That %1$s does not belong to this %2$s.', 'woo-organization-accounts-pro' ),
				Labels::location(),
				Labels::organization()
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * The parameters a write accepts.
	 *
	 * The address fields are not enumerated here, because which of them exist depends on
	 * the country and on whatever the shop has done to its checkout fields.
	 * `AddressFields` is the authority on that, and it is asked at the point of use.
	 *
	 * @param bool $creating True for the create route.
	 * @return array Argument definitions.
	 */
	private function write_params( $creating ) {
		return array(
			'name'       => array(
				'description'       => __( 'The label this location is chosen by at the checkout.', 'woo-organization-accounts-pro' ),
				'type'              => 'string',
				'required'          => (bool) $creating,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'is_default' => array(
				'description'       => __( 'Whether new orders should start at this location.', 'woo-organization-accounts-pro' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}
}
