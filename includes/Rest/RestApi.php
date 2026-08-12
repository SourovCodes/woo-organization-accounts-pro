<?php
/**
 * The plugin's own REST routes.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's REST surface.
 *
 * Two halves, split by whether WooCommerce already has the noun. The plugin's own
 * routes exist only for what WooCommerce cannot say: `/wc/v3/customers` returns
 * WordPress users, and nothing there answers "which organizations exist, who works
 * for them, and where do their goods go?". Anything WooCommerce *does* model — an
 * order above all — is extended on its own route by `Orders` rather than duplicated
 * here, so there is one code path per thing.
 *
 * The namespace serves two consumers, and it is worth knowing which a route is for.
 *
 * **A till** reads the organization snapshot and the address forms, and writes nothing
 * here: it sells from a copy it syncs on an interval, and the orders it creates go
 * through `/wc/v3/orders`, where `Orders` holds them to the same rules as the two
 * checkouts. Everything it needs is a read.
 *
 * **A back office** reviews a registration and approves it, corrects a billing address,
 * adds a branch, puts an employee on an account. Those are writes, and until 0.8.0 this
 * namespace had none — the shop's own wp-admin screens were the only way to do any of
 * it, which is fine for a shop and useless for an app. They are here rather than in a
 * namespace of their own because they are the same nouns: an organization written
 * through one route and read back through the other must not be able to describe itself
 * differently, and it cannot, because both go through the same payload builders.
 *
 * What has not changed is that this plugin's own screens remain the only *member-facing*
 * way to edit an organization. Every route here is gated on `manage_woocommerce`, so
 * none of them is a second way for a member to write records the account screens own —
 * they are the shop acting on any organization, which is a question those screens never
 * answer.
 */
final class RestApi {

	/**
	 * The REST namespace the plugin's own routes live under.
	 *
	 * **The `wc-` prefix is what makes a consumer key work here, and it is not
	 * decoration.** WooCommerce authenticates a key/secret pair from
	 * `WC_REST_Authentication`, on `determine_current_user` — but only after
	 * `is_request_to_rest_api()` agrees the request is one of its own, and that method
	 * decides by looking for `wc/` or `wc-` in the request URI. A namespace of `woap/v1`
	 * registers and routes perfectly well and then refuses every till on the shop floor
	 * with a 401, because the credentials they were issued are never even read. The
	 * `wc-` prefix is WooCommerce's documented opening for exactly this — the comment
	 * beside it in core reads "Allow third party plugins use our authentication
	 * methods" — so borrowing it is the supported route rather than a trick.
	 *
	 * Filtering `woocommerce_rest_is_request_to_rest_api` would have been the other way
	 * to reach the same place, and is worse: it turns key authentication on for a URL
	 * pattern rather than for these routes, and the pattern is the whole site's.
	 *
	 * Versioned separately from the plugin: a `v2` would be added beside this one and
	 * both served for as long as a till in the field still asks for the older shape.
	 */
	const REST_NAMESPACE = 'wc-woap/v1';

	/**
	 * Hook the routes into the REST server.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		/*
		 * Not on rest_api_init: the order rules hang on WooCommerce filters that have
		 * to be in place before the REST server dispatches anything.
		 */
		( new Orders() )->register();
	}

	/**
	 * Register every controller in the namespace.
	 *
	 * @return void
	 */
	public function register_routes() {
		( new OrganizationsController() )->register_routes();
		( new LocationsController() )->register_routes();
		( new MembersController() )->register_routes();
		( new AddressFormController() )->register_routes();
	}
}
