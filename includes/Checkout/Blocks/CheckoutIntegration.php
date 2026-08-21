<?php
/**
 * Cart and Checkout blocks integration.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Checkout\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use WooOrgAccounts\Checkout\BillingLock;
use WooOrgAccounts\Checkout\ShippingSelector;
use WooOrgAccounts\Datasheet\Download as Datasheet;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Teaches the Cart and Checkout blocks about organizations.
 *
 * WooCommerce 11 gives a new store the block checkout by default, so supporting only
 * the classic shortcode would leave the ordinary installation unprotected. The blocks
 * talk to the Store API, and the Store API is a public REST route — which is why every
 * rule this plugin has is enforced on the server, in Gate, BillingLock and
 * ShippingSelector. Nothing here decides anything. It exposes the organization's
 * billing address and locations so the block checkout can *show* the customer what
 * will happen, and it accepts the chosen location on the way back.
 *
 * The script is plain JavaScript against the `wc-blocks-checkout` and `wp-element`
 * globals WooCommerce already enqueues, with no JSX and no bundler, so the plugin
 * keeps building with nothing but Composer and the release zip stays reviewable.
 */
class CheckoutIntegration implements IntegrationInterface {

	/**
	 * The Store API extension namespace, and this integration's name.
	 */
	const NAMESPACE_ID = 'woo-organization-accounts-pro';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'extend_store_api' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_integration' ) );
	}

	/**
	 * Add this integration to a block's registry.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry Registry.
	 * @return void
	 */
	public function register_integration( $registry ) {
		$registry->register( $this );
	}

	/**
	 * Publish the organization data on the cart, and accept the location on checkout.
	 *
	 * @return void
	 */
	public function extend_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE_ID,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE_ID,
				'data_callback'   => array( $this, 'checkout_data' ),
				'schema_callback' => array( $this, 'checkout_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * What the blocks are told about the current customer's organization.
	 *
	 * @return array Data for the cart response.
	 */
	public function cart_data() {
		$organization = Context::organization();
		$member       = Context::member();

		if ( null === $organization || null === $member ) {
			return array(
				'has_organization'      => false,
				'can_purchase'          => false,
				'blocked_reason'        => Context::purchase_blocked_reason(),
				'organization_name'     => '',
				'billing_address'       => array(),
				'billing_notice'        => '',
				'allow_custom_shipping' => false,
				'locations'             => array(),
				'location_label'        => '',
				'custom_label'          => '',
				'datasheet_url'         => '',
				'datasheet_label'       => '',
			);
		}

		return array(
			'has_organization'      => true,
			'can_purchase'          => Context::can_purchase(),
			'blocked_reason'        => Context::purchase_blocked_reason(),
			'organization_name'     => $organization->get_name(),
			'billing_address'       => $organization->get_billing_address(),
			'billing_notice'        => BillingLock::locked_notice(),
			'allow_custom_shipping' => $organization->allows_custom_shipping(),
			'locations'             => ShippingSelector::location_payload(),
			'location_label'        => sprintf(
				/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
				__( 'Deliver to which %s?', 'woo-organization-accounts-pro' ),
				Labels::location()
			),
			'custom_label'          => __( 'A different address (enter it below)', 'woo-organization-accounts-pro' ),

			/*
			 * Empty rather than absent when the customer may not buy, so the block has
			 * one thing to test — the same shape every other key here takes. The nonce
			 * inside it is minted per Store API request, which is per-session and never
			 * page-cached.
			 */
			'datasheet_url'         => Datasheet::may_download_cart() ? Datasheet::cart_url() : '',
			'datasheet_label'       => Datasheet::label(),
		);
	}

	/**
	 * The shape of the cart data.
	 *
	 * @return array JSON schema.
	 */
	public function cart_schema() {
		$string = array(
			'type'     => 'string',
			'readonly' => true,
			'context'  => array( 'view', 'edit' ),
		);

		return array(
			'has_organization'      => array(
				'description' => __( 'Whether the customer belongs to an organization.', 'woo-organization-accounts-pro' ),
				'type'        => 'boolean',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
			'can_purchase'          => array(
				'description' => __( 'Whether the customer may check out.', 'woo-organization-accounts-pro' ),
				'type'        => 'boolean',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
			'blocked_reason'        => array_merge( $string, array( 'description' => __( 'Why the customer may not check out.', 'woo-organization-accounts-pro' ) ) ),
			'organization_name'     => array_merge( $string, array( 'description' => __( 'The name of the customer\'s organization.', 'woo-organization-accounts-pro' ) ) ),
			'billing_address'       => array(
				'description'          => __( 'The organization billing address every order is billed to.', 'woo-organization-accounts-pro' ),
				'type'                 => 'object',
				'readonly'             => true,
				'context'              => array( 'view', 'edit' ),
				'additionalProperties' => array( 'type' => 'string' ),
			),
			'billing_notice'        => array_merge( $string, array( 'description' => __( 'Explanation shown above the locked billing address.', 'woo-organization-accounts-pro' ) ) ),
			'allow_custom_shipping' => array(
				'description' => __( 'Whether a one-off shipping address is allowed.', 'woo-organization-accounts-pro' ),
				'type'        => 'boolean',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
			),
			'locations'             => array(
				'description' => __( 'The locations this member may ship to.', 'woo-organization-accounts-pro' ),
				'type'        => 'array',
				'readonly'    => true,
				'context'     => array( 'view', 'edit' ),
				'items'       => array( 'type' => 'object' ),
			),
			'location_label'        => array_merge( $string, array( 'description' => __( 'Label for the location selector.', 'woo-organization-accounts-pro' ) ) ),
			'custom_label'          => array_merge( $string, array( 'description' => __( 'Label for the one-off address option.', 'woo-organization-accounts-pro' ) ) ),
			'datasheet_url'         => array_merge( $string, array( 'description' => __( 'Link that downloads the product data for the cart.', 'woo-organization-accounts-pro' ) ) ),
			'datasheet_label'       => array_merge( $string, array( 'description' => __( 'Label for the product data download.', 'woo-organization-accounts-pro' ) ) ),
		);
	}

	/**
	 * What the checkout endpoint returns for this namespace.
	 *
	 * Nothing: the extension exists on this endpoint so the block can *send* the chosen
	 * location, and the server has nothing to say back about it.
	 *
	 * @return array Empty data.
	 */
	public function checkout_data() {
		return array( 'location_id' => '' );
	}

	/**
	 * The shape of the data the checkout endpoint accepts.
	 *
	 * @return array JSON schema.
	 */
	public function checkout_schema() {
		return array(
			'location_id' => array(
				'description' => __( 'The organization location this order is being delivered to.', 'woo-organization-accounts-pro' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'arg_options' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		);
	}

	/**
	 * The integration's name.
	 *
	 * @return string Name.
	 */
	public function get_name() {
		return self::NAMESPACE_ID;
	}

	/**
	 * Register the script the blocks will load.
	 *
	 * @return void
	 */
	public function initialize() {
		wp_register_script(
			'woap-blocks-checkout',
			WOAP_PLUGIN_URL . 'assets/js/blocks/checkout.js',
			array( 'wc-blocks-checkout', 'wp-element', 'wp-plugins', 'wp-data', 'wp-i18n' ),
			WOAP_VERSION,
			true
		);

		wp_set_script_translations( 'woap-blocks-checkout', 'woo-organization-accounts-pro', WOAP_PLUGIN_DIR . 'languages' );

		/*
		 * No stylesheet is enqueued here. WooCommerce initialises every registered
		 * integration when it registers its block types, which happens on `init` on
		 * every request — so anything enqueued from this method loads on the whole site
		 * rather than on the checkout. The stylesheet is loaded by ShippingSelector
		 * instead, which is gated on is_checkout() and so covers the block checkout and
		 * the classic one alike.
		 */
	}

	/**
	 * Scripts loaded on the storefront.
	 *
	 * @return string[] Script handles.
	 */
	public function get_script_handles() {
		return array( 'woap-blocks-checkout' );
	}

	/**
	 * Scripts loaded in the block editor.
	 *
	 * None: the editor preview does not need the customer's own organization.
	 *
	 * @return string[] Script handles.
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data passed to the script through the `wcSettings` registry.
	 *
	 * @return array Settings.
	 */
	public function get_script_data() {
		return array(
			'namespace' => self::NAMESPACE_ID,
			'custom'    => ShippingSelector::CUSTOM,
		);
	}
}
