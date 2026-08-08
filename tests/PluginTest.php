<?php
/**
 * Bootstrap tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Install;
use WooOrgAccounts\Plugin;
use WP_UnitTestCase;

/**
 * The plugin boots, declares what it supports, and refuses what it cannot support.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The suite runs against High-Performance Order Storage, because the plugin
	 * requires it and would not have booted otherwise.
	 */
	public function testHposIsRequiredAndEnabled() {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assertTrue( \WooOrgAccounts\is_hpos_enabled() );
	}

	/**
	 * The theme requirement is satisfied by Woodmart and by a child of it, and by
	 * nothing else.
	 *
	 * The suite itself runs under the test library's default theme with the filter
	 * forced on, so the check is exercised here with the filter removed. Everything
	 * the plugin renders assumes Woodmart's markup and design tokens, and against any
	 * other theme it produces a screen that is styled by nothing.
	 */
	public function testWoodmartIsRequired() {
		remove_filter( 'woap_theme_supported', '__return_true' );

		$this->assertNotSame( 'woodmart', get_template() );
		$this->assertFalse( \WooOrgAccounts\is_theme_supported(), 'Another theme satisfied the requirement.' );

		add_filter( 'woap_theme_supported', '__return_true' );

		$this->assertTrue( \WooOrgAccounts\is_theme_supported(), 'The documented escape hatch did not work.' );
	}

	/**
	 * Compatibility with HPOS and with the Cart and Checkout blocks is declared.
	 *
	 * Both matter: without the first the site is told the plugin is incompatible with
	 * the order storage it requires, and without the second a shop running the block
	 * checkout is warned away from a plugin that supports it.
	 */
	public function testWooCommerceCompatibilityIsDeclared() {
		$compatible = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( 'woo-organization-accounts-pro/woo-organization-accounts-pro.php', $compatible['compatible'] );

		$blocks = FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );
		$this->assertContains( 'woo-organization-accounts-pro/woo-organization-accounts-pro.php', $blocks['compatible'] );
	}

	/**
	 * The version is stated once in the header and once as a constant, and they agree.
	 */
	public function testVersionsAgree() {
		$header = get_file_data(
			WOAP_PLUGIN_FILE,
			array( 'Version' => 'Version' )
		);

		$this->assertSame( WOAP_VERSION, $header['Version'] );
	}

	/**
	 * The plugin declares the floors it actually enforces.
	 */
	public function testRequirementHeaders() {
		$headers = get_file_data(
			WOAP_PLUGIN_FILE,
			array(
				'RequiresWP'      => 'Requires at least',
				'RequiresPHP'     => 'Requires PHP',
				'RequiresPlugins' => 'Requires Plugins',
				'TextDomain'      => 'Text Domain',
				'UpdateURI'       => 'Update URI',
			)
		);

		$this->assertSame( '8.2', $headers['RequiresPHP'] );
		$this->assertSame( '7.0', $headers['RequiresWP'] );
		$this->assertSame( 'woocommerce', $headers['RequiresPlugins'] );
		$this->assertSame( Plugin::TEXT_DOMAIN, $headers['TextDomain'] );
		$this->assertNotSame( '', $headers['UpdateURI'] );
	}

	/**
	 * Booting twice registers the hooks once.
	 */
	public function testInitIsIdempotent() {
		$before = has_filter( 'woocommerce_checkout_fields' );

		Plugin::instance()->init();

		$this->assertSame( $before, has_filter( 'woocommerce_checkout_fields' ) );
	}

	/**
	 * The plugin's own tables were created, and the schema version recorded.
	 */
	public function testSchemaIsInstalled() {
		$this->assertTrue( Install::tables_exist() );
		$this->assertSame( WOAP_DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	/**
	 * The components that have to run on the frontend are hooked.
	 */
	public function testComponentsAreRegistered() {
		$this->assertTrue( shortcode_exists( \WooOrgAccounts\Frontend\Registration::SHORTCODE ) );
		$this->assertNotFalse( has_filter( 'user_has_cap' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_query_vars' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_account_menu_items' ) );
		$this->assertNotFalse( has_filter( 'pre_option_woocommerce_enable_guest_checkout' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_checkout_posted_data' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_email_classes' ) );
	}

	/**
	 * Every account endpoint is a rewrite endpoint WordPress knows about.
	 */
	public function testAccountEndpointsAreRegistered() {
		global $wp;

		MyAccount::add_endpoints();

		foreach ( array_keys( MyAccount::endpoints() ) as $endpoint ) {
			$this->assertContains( $endpoint, $wp->public_query_vars, $endpoint . ' is not a public query var.' );
		}
	}

	/**
	 * The plugin loads through wp-content/plugins, which is what keeps everything
	 * keyed on the plugin slug — the compatibility declaration and the language
	 * directory both — behaving under test as they do on a real site.
	 */
	public function testPluginBasenameIsTheSlug() {
		$this->assertSame(
			'woo-organization-accounts-pro/woo-organization-accounts-pro.php',
			plugin_basename( WOAP_PLUGIN_FILE )
		);
	}
}
