<?php
/**
 * The checkout surfaces the customer actually sees.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Checkout\Blocks\CheckoutIntegration;
use WooOrgAccounts\Checkout\Gate;
use WooOrgAccounts\Checkout\ShippingSelector;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Membership\Context;

/**
 * What the cart and both checkouts render, offer and refuse.
 *
 * `CheckoutTest` covers the rules — who may buy, which address is used. This covers the
 * surfaces those rules are presented through: the location selector, the guest-checkout
 * switches, and the data the block checkout is handed. A rule enforced on the server and
 * missing from the screen is a customer told nothing until they press the button.
 */
class CheckoutSurfacesTest extends TestCase {

	/**
	 * Guest checkout cannot be switched back on while the plugin is active.
	 */
	public function testGuestCheckoutStaysOffWhateverTheOptionSays() {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		( new Gate() )->register();

		$this->assertSame( 'no', get_option( 'woocommerce_enable_guest_checkout' ) );
		$this->assertSame( 'no', ( new Gate() )->disable_guest_checkout() );
	}

	/**
	 * The checkout always offers to sign in, because signing in is the only way through.
	 */
	public function testTheLoginReminderIsForcedOn() {
		update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );

		( new Gate() )->register();

		$this->assertSame( 'yes', get_option( 'woocommerce_enable_checkout_login_reminder' ) );
	}

	/**
	 * The cart page tells a customer who cannot buy, before they reach the checkout.
	 *
	 * Three hooks cover this between them because a shop may render the cart from the
	 * shortcode, the classic template or the Cart block, and the first two reach only
	 * their own. Whichever is in use, the notice has to exist before anything prints it.
	 */
	public function testEveryCartSurfaceIsCovered() {
		$gate = new Gate();
		$gate->register();

		$this->assertNotFalse( has_action( 'woocommerce_check_cart_items', array( $gate, 'block_cart' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_cart', array( $gate, 'block_cart' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( $gate, 'block_cart_page' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_store_api_cart_errors', array( $gate, 'block_store_api_cart' ) ) );
	}

	/**
	 * A blocked customer is refused by the Store API's checkout, not only its cart.
	 *
	 * The cart errors are advisory — a client can ignore them and post to the checkout
	 * route anyway, which is a public REST endpoint reachable whatever the site renders.
	 */
	public function testTheStoreApiCheckoutRefusesABlockedCustomer() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_SUSPENDED ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );

		( new Gate() )->block_store_api_checkout( wc_create_order() );
	}

	/**
	 * A permitted customer passes the same route untouched.
	 */
	public function testTheStoreApiCheckoutLetsAPermittedCustomerThrough() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		( new Gate() )->block_store_api_checkout( wc_create_order() );

		$this->assertTrue( Context::can_purchase() );
	}

	/**
	 * The selector offers the member's own locations and nobody else's.
	 */
	public function testTheSelectorOffersOnlyTheMembersOwnLocations() {
		$organization = $this->make_organization();
		$mine         = $this->make_location( $organization, array( 'name' => 'Depot Ost' ) );

		$other = $this->make_organization();
		$this->make_location( $other, array( 'name' => 'Somebody Elses Depot' ) );

		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		ob_start();
		( new ShippingSelector() )->render_selector();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Depot Ost', $markup );
		$this->assertStringNotContainsString( 'Somebody Elses Depot', $markup );
		$this->assertStringContainsString( ShippingSelector::FIELD, $markup );

		unset( $mine );
	}

	/**
	 * A member restricted to one location is offered only that one.
	 */
	public function testTheSelectorRespectsTheMembersAccessList() {
		$organization = $this->make_organization();
		$allowed      = $this->make_location( $organization, array( 'name' => 'Depot Ost' ) );
		$this->make_location( $organization, array( 'name' => 'Depot West' ) );

		$member = $this->make_member( $organization );

		MemberRepository::set_location_ids( $member->get_id(), array( $allowed->get_id() ) );

		$this->act_as( $member );

		ob_start();
		( new ShippingSelector() )->render_selector();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Depot Ost', $markup );
		$this->assertStringNotContainsString( 'Depot West', $markup );
	}

	/**
	 * The one-off address option appears only where the organization allows it.
	 */
	public function testTheOneOffOptionFollowsTheOrganizationsSetting() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );
		$this->make_location( $organization );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		ob_start();
		( new ShippingSelector() )->render_selector();
		$without = (string) ob_get_clean();

		$this->assertStringNotContainsString( ShippingSelector::CUSTOM, $without );

		$organization->set( 'allow_custom_shipping', true );
		OrganizationRepository::save( $organization );
		Context::flush();

		ob_start();
		( new ShippingSelector() )->render_selector();
		$with = (string) ob_get_clean();

		$this->assertStringContainsString( ShippingSelector::CUSTOM, $with );
	}

	/**
	 * Somebody with no organization is offered no selector at all.
	 */
	public function testThereIsNoSelectorWithoutAnOrganization() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		Context::flush();

		ob_start();
		( new ShippingSelector() )->render_selector();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The billing notice explains the locked fields, and only to a member.
	 */
	public function testTheBillingNoticeIsShownToMembersOnly() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		ob_start();
		( new ShippingSelector() )->render_billing_notice();

		$this->assertNotSame( '', trim( (string) ob_get_clean() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		Context::flush();

		ob_start();
		( new ShippingSelector() )->render_billing_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The block checkout is handed the same locations the classic selector offers.
	 *
	 * Two surfaces reading one rule. A location the classic checkout offers and the
	 * block one does not is an order the customer cannot place from half the site.
	 */
	public function testBothCheckoutsOfferTheSameLocations() {
		$organization = $this->make_organization();

		$this->make_location( $organization, array( 'name' => 'Depot Ost' ) );
		$this->make_location( $organization, array( 'name' => 'Depot West' ) );

		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$payload = ShippingSelector::location_payload();

		$this->assertCount( 2, $payload );

		$names = wp_list_pluck( $payload, 'name' );

		sort( $names );

		$this->assertSame( array( 'Depot Ost', 'Depot West' ), $names );

		ob_start();
		( new ShippingSelector() )->render_selector();
		$markup = (string) ob_get_clean();

		foreach ( $names as $name ) {
			$this->assertStringContainsString( $name, $markup );
		}
	}

	/**
	 * Every location the block checkout is sent carries a whole address.
	 *
	 * Nothing is derived at checkout, so a payload missing a field is a parcel missing
	 * a line of its label.
	 */
	public function testEveryLocationSentToTheBlocksIsComplete() {
		$organization = $this->make_organization();
		$this->make_location( $organization );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$payload = ShippingSelector::location_payload();

		foreach ( array( 'id', 'name', 'first_name', 'last_name', 'company', 'address_1', 'city', 'postcode', 'country' ) as $field ) {
			$this->assertArrayHasKey( $field, $payload[0], sprintf( 'The blocks are sent no %s.', $field ) );
		}
	}

	/**
	 * The cart data tells the blocks who the customer is and whether they may buy.
	 */
	public function testTheCartDataDescribesTheOrganization() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$this->make_location( $organization );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$data = ( new CheckoutIntegration() )->cart_data();

		$this->assertTrue( $data['has_organization'] );
		$this->assertTrue( $data['can_purchase'] );
		$this->assertSame( 'Hafen Logistik', $data['organization_name'] );
		$this->assertNotEmpty( $data['billing_address'] );
		$this->assertCount( 1, $data['locations'] );
	}

	/**
	 * A customer who may not buy is told why, through the blocks as well.
	 */
	public function testTheCartDataCarriesTheReasonACustomerCannotBuy() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$data = ( new CheckoutIntegration() )->cart_data();

		$this->assertFalse( $data['can_purchase'] );
		$this->assertNotSame( '', $data['blocked_reason'] );
	}

	/**
	 * Somebody with no organization gets an answer rather than an error.
	 */
	public function testTheCartDataAnswersForSomebodyWithNoOrganization() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		Context::flush();

		$data = ( new CheckoutIntegration() )->cart_data();

		$this->assertFalse( $data['has_organization'] );
		$this->assertFalse( $data['can_purchase'] );
		$this->assertSame( array(), $data['locations'] );
	}

	/**
	 * Every key the cart sends is a key the schema declares.
	 *
	 * The Store API validates responses against the schema, so a key sent without one
	 * is dropped before the block ever sees it — silently, and only in some contexts.
	 */
	public function testTheCartSchemaDescribesEverythingTheCartSends() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$integration = new CheckoutIntegration();

		$this->assertSame(
			array(),
			array_diff( array_keys( $integration->cart_data() ), array_keys( $integration->cart_schema() ) ),
			'The cart sends a key its schema does not declare.'
		);
	}

	/**
	 * And the other way round: nothing is declared that never arrives.
	 */
	public function testTheCartSendsEverythingItsSchemaDeclares() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$integration = new CheckoutIntegration();

		$this->assertSame(
			array(),
			array_diff( array_keys( $integration->cart_schema() ), array_keys( $integration->cart_data() ) )
		);
	}

	/**
	 * The checkout endpoint's data and schema agree in the same way.
	 */
	public function testTheCheckoutDataAndItsSchemaAgree() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$integration = new CheckoutIntegration();

		$this->assertSame(
			array(),
			array_diff( array_keys( $integration->checkout_data() ), array_keys( $integration->checkout_schema() ) )
		);
	}

	/**
	 * The integration answers to the namespace the script and the Store API share.
	 */
	public function testTheIntegrationKeepsItsName() {
		$this->assertSame( CheckoutIntegration::NAMESPACE_ID, ( new CheckoutIntegration() )->get_name() );
	}

	/**
	 * The block script is plain JavaScript, with nothing to build.
	 *
	 * The plugin builds with Composer alone, and CI's `node --check` over assets/js is
	 * only meaningful while that stays true.
	 */
	public function testTheBlockScriptNeedsNoBuildStep() {
		$scripts = glob( dirname( __DIR__ ) . '/assets/js/*.js' );

		$this->assertNotEmpty( $scripts );

		foreach ( $scripts as $script ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository.
			$source = (string) file_get_contents( $script );

			$this->assertDoesNotMatchRegularExpression(
				'/^\s*import\s|^\s*export\s/m',
				$source,
				sprintf( '%s uses module syntax, which needs a bundler.', basename( $script ) )
			);
		}
	}
}
