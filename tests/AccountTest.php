<?php
/**
 * My Account and registration tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Frontend\Registration;
use WooOrgAccounts\Roles;

/**
 * The account screens: which are offered, to whom, and what the order list shows.
 */
class AccountTest extends TestCase {

	/**
	 * Every endpoint is registered as a WooCommerce query variable.
	 */
	public function testEndpointsAreWooCommerceQueryVars() {
		$vars = ( new MyAccount() )->add_query_vars( array() );

		foreach ( array_keys( MyAccount::endpoints() ) as $endpoint ) {
			$this->assertArrayHasKey( $endpoint, $vars );
		}
	}

	/**
	 * An organization admin sees every screen.
	 */
	public function testAdminSeesEveryScreen() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$items = ( new MyAccount() )->add_menu_items(
			array(
				'dashboard' => 'Dashboard',
				'orders'    => 'Orders',
			)
		);

		foreach ( array_keys( MyAccount::endpoints() ) as $endpoint ) {
			$this->assertArrayHasKey( $endpoint, $items );
		}
	}

	/**
	 * An ordinary member sees none of them.
	 */
	public function testMemberSeesNoManagementScreens() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$items = ( new MyAccount() )->add_menu_items(
			array(
				'dashboard' => 'Dashboard',
				'orders'    => 'Orders',
			)
		);

		foreach ( array_keys( MyAccount::endpoints() ) as $endpoint ) {
			$this->assertArrayNotHasKey( $endpoint, $items );
		}
	}

	/**
	 * A single granted capability reveals exactly one screen.
	 */
	public function testOneCapabilityRevealsOneScreen() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$member->set_capabilities( array( Roles::VIEW_ORGANIZATION_ORDERS => true ) );
		MemberRepository::save( $member );
		$this->act_as( $member );

		$items = ( new MyAccount() )->add_menu_items(
			array(
				'dashboard' => 'Dashboard',
				'orders'    => 'Orders',
			)
		);

		$this->assertArrayHasKey( MyAccount::ENDPOINT_ORDERS, $items );
		$this->assertArrayNotHasKey( MyAccount::ENDPOINT_MEMBERS, $items );
	}

	/**
	 * The screens are inserted after Orders, not appended to the end.
	 */
	public function testScreensAreInsertedAfterOrders() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$items = ( new MyAccount() )->add_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'customer-logout' => 'Log out',
			)
		);

		$keys = array_keys( $items );

		$this->assertSame( 'dashboard', $keys[0] );
		$this->assertSame( 'orders', $keys[1] );
		$this->assertSame( MyAccount::ENDPOINT_PROFILE, $keys[2] );
		$this->assertSame( 'customer-logout', end( $keys ) );
	}

	/**
	 * WooCommerce's own address editor is removed: the address is the organization's.
	 */
	public function testWooCommerceAddressScreenIsRemoved() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$items = ( new MyAccount() )->add_menu_items(
			array(
				'orders'       => 'Orders',
				'edit-address' => 'Addresses',
			)
		);

		$this->assertArrayNotHasKey( 'edit-address', $items );
	}

	/**
	 * Somebody with no organization gets WooCommerce's menu back, untouched.
	 */
	public function testMenuIsUntouchedWithoutAnOrganization() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$original = array(
			'orders'       => 'Orders',
			'edit-address' => 'Addresses',
		);

		$this->assertSame( $original, ( new MyAccount() )->add_menu_items( $original ) );
	}

	/**
	 * The endpoint titles follow the site's organization mode.
	 */
	public function testEndpointTitles() {
		$account = new MyAccount();

		$this->assertSame( 'Company', $account->endpoint_title( 'fallback', MyAccount::ENDPOINT_PROFILE ) );
		$this->assertSame( 'fallback', $account->endpoint_title( 'fallback', 'not-ours' ) );
	}

	/**
	 * The organization order list returns that organization's orders and no others.
	 */
	public function testOrganizationOrdersAreScoped() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$mine = new \WC_Order();
		$mine->update_meta_data( OrderMeta::ORGANIZATION_ID, $ours->get_id() );
		$mine->set_status( 'processing' );
		$mine->save();

		$other = new \WC_Order();
		$other->update_meta_data( OrderMeta::ORGANIZATION_ID, $theirs->get_id() );
		$other->set_status( 'processing' );
		$other->save();

		$unrelated = new \WC_Order();
		$unrelated->set_status( 'processing' );
		$unrelated->save();

		$result = MyAccount::organization_orders( $ours->get_id() );
		$ids    = array_map(
			static function ( $order ) {
				return $order->get_id();
			},
			$result['orders']
		);

		$this->assertSame( array( $mine->get_id() ), $ids );
	}

	/**
	 * An organization with no orders gets an empty list rather than everything.
	 */
	public function testOrganizationWithoutOrders() {
		$organization = $this->make_organization();

		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		$result = MyAccount::organization_orders( $organization->get_id() );

		$this->assertSame( array(), $result['orders'] );
	}

	/**
	 * Activation creates the registration page and remembers it.
	 */
	public function testRegistrationPageIsCreatedOnce() {
		$this->set_setting( 'registration_page_id', 0 );

		$page_id = Registration::create_page();

		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertStringContainsString( '[' . Registration::SHORTCODE . ']', get_post( $page_id )->post_content );
		$this->assertSame( $page_id, (int) Settings::get( 'registration_page_id' ) );
		$this->assertSame( $page_id, Registration::create_page(), 'A second call must not create a second page.' );
	}

	/**
	 * The shortcode is registered and renders the form for a signed-out visitor.
	 */
	public function testShortcodeRendersTheForm() {
		wp_set_current_user( 0 );

		$this->assertTrue( shortcode_exists( Registration::SHORTCODE ) );

		$markup = do_shortcode( '[' . Registration::SHORTCODE . ']' );

		$this->assertStringContainsString( 'woap-registration-form', $markup );
		$this->assertStringContainsString( 'name="organization_name"', $markup );
		$this->assertStringContainsString( 'name="' . Registration::HONEYPOT_FIELD . '"', $markup );
	}

	/**
	 * A signed-in visitor is told to sign out rather than shown the form again.
	 */
	public function testShortcodeForASignedInVisitor() {
		$this->act_as( $this->make_member( $this->make_organization() ) );

		$markup = do_shortcode( '[' . Registration::SHORTCODE . ']' );

		$this->assertStringNotContainsString( 'woap-registration-form', $markup );
		$this->assertStringContainsString( 'already signed in', $markup );
	}

	/**
	 * A token in the URL turns the page into the join screen.
	 */
	public function testShortcodeRendersTheJoinForm() {
		wp_set_current_user( 0 );

		$organization = $this->make_organization();
		$result       = \WooOrgAccounts\Members\Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$_GET[ \WooOrgAccounts\Members\Invitations::QUERY_VAR ] = $result['token'];

		$markup = do_shortcode( '[' . Registration::SHORTCODE . ']' );

		$this->assertStringContainsString( 'woap-invitation-form', $markup );
		$this->assertStringContainsString( 'Acme GmbH', $markup );
	}

	/**
	 * A token that means nothing gets the same refusal as every other bad one.
	 */
	public function testShortcodeRefusesABadToken() {
		wp_set_current_user( 0 );

		$_GET[ \WooOrgAccounts\Members\Invitations::QUERY_VAR ] = 'nonsense';

		$markup = do_shortcode( '[' . Registration::SHORTCODE . ']' );

		$this->assertStringContainsString( 'woap-invitation--invalid', $markup );
		$this->assertStringNotContainsString( 'woap-invitation-form', $markup );
	}
}
