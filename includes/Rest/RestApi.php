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
 * Every route registered under this namespace is read-only. A till reads a snapshot
 * of the organizations to work from offline; it does not edit them, and a route that
 * let it would be a second way of writing records the account screens already own.
 * The orders it creates go through `/wc/v3/orders`, where `Orders` holds them to the
 * same rules as the checkouts.
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
		( new AddressFormController() )->register_routes();
	}
}
