<?php
/**
 * Plugin Name:          WooCommerce Organization Accounts Pro
 * Plugin URI:           https://github.com/SourovCodes/woo-organization-accounts-pro
 * Update URI:           https://github.com/SourovCodes/woo-organization-accounts-pro
 * Description:          Turns WooCommerce into a strict organization-based B2B system: organization accounts, members, locations, centralised billing and organization-scoped orders.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               Sourov Biswas
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-organization-accounts-pro
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.0
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

defined( 'ABSPATH' ) || exit;

define( 'WOAP_VERSION', '0.1.0' );
define( 'WOAP_PLUGIN_FILE', __FILE__ );
define( 'WOAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOAP_MIN_WC_VERSION', '11.0' );

/**
 * Schema version of the plugin's own tables.
 *
 * Bumped whenever includes/Install.php changes a table, which is what makes an
 * existing site run dbDelta again on the next load.
 */
define( 'WOAP_DB_VERSION', '1.0.0' );

/**
 * Load the Composer autoloader.
 *
 * A distributed build always ships vendor/, but a git checkout may not have run
 * `composer install` yet. Fail loudly in the admin rather than fatally.
 *
 * @return bool True when the autoloader was loaded.
 */
function load_autoloader() {
	$autoloader = WOAP_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! is_readable( $autoloader ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );
		return false;
	}

	require_once $autoloader;
	return true;
}

/**
 * Show an admin notice explaining that the Composer dependencies are missing.
 *
 * @return void
 */
function render_missing_autoloader_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'WooCommerce Organization Accounts Pro is missing its dependencies. Run "composer install" in the plugin directory.',
			'woo-organization-accounts-pro'
		)
	);
}

/**
 * Show an admin notice explaining that WooCommerce is required.
 *
 * @return void
 */
function render_missing_woocommerce_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'WooCommerce Organization Accounts Pro requires WooCommerce %s or newer to be installed and active.', 'woo-organization-accounts-pro' ),
				WOAP_MIN_WC_VERSION
			)
		)
	);
}

/**
 * Show an admin notice explaining that High-Performance Order Storage is required.
 *
 * @return void
 */
function render_hpos_required_notice() {
	printf(
		'<div class="notice notice-error"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html__(
			'WooCommerce Organization Accounts Pro requires High-Performance Order Storage. Organization accounts stay disabled until it is enabled.',
			'woo-organization-accounts-pro'
		),
		esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ),
		esc_html__( 'Enable High-Performance Order Storage', 'woo-organization-accounts-pro' )
	);
}

/**
 * Determine whether a supported version of WooCommerce is active.
 *
 * The "Requires Plugins" header covers WordPress 6.5 and newer, but it does not
 * enforce a minimum WooCommerce version, so check that here too.
 *
 * @return bool True when WooCommerce is active and new enough.
 */
function is_woocommerce_supported() {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, WOAP_MIN_WC_VERSION, '>=' );
}

/**
 * Determine whether High-Performance Order Storage is the active order store.
 *
 * The plugin requires it. Every order this plugin writes carries the organization it
 * belongs to, and every organization order list reads it back; running against the
 * legacy post-based store would silently scope those queries to the wrong data rather
 * than fail loudly.
 *
 * @return bool True when HPOS is enabled.
 */
function is_hpos_enabled() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
		return false;
	}

	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * High-Performance Order Storage must be declared before WooCommerce initialises,
 * otherwise the plugin is listed as incompatible and HPOS stays disabled. The blocks
 * declaration matters just as much here: the checkout rules are enforced on the Store
 * API as well as on the classic checkout, and a shop running the Cart and Checkout
 * blocks must not be warned away from a plugin that supports them.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOAP_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOAP_PLUGIN_FILE, true );
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * @return void
 */
function bootstrap() {
	if ( ! is_woocommerce_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );
		return;
	}

	if ( ! is_hpos_enabled() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_hpos_required_notice' );
		return;
	}

	Plugin::instance()->init();
}

if ( load_autoloader() ) {
	/*
	 * Registered ahead of the requirement gates, not inside bootstrap(). An update is
	 * often the thing that fixes a plugin sitting inert behind one of those gates, so
	 * a site whose WooCommerce is too old must still be offered the version that
	 * supports it.
	 */
	( new Updates\Updater() )->register();

	add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

	register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
}
