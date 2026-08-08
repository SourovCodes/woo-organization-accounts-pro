<?php
/**
 * Account form handler tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;

/**
 * The writes the My Account organization screens make.
 *
 * These run end to end — dispatch, nonce, capability, database, notice, redirect —
 * because the interesting failures live in the seams between those, not inside any one
 * of them. The first version of this plugin routed these forms through
 * `admin-post.php`, where every individual piece was correct and the whole thing
 * fatalled on `wc_add_notice()`: WooCommerce loads its notice functions and starts its
 * session only for frontend requests, and `admin-post.php` is an admin request.
 */
class AccountHandlersTest extends TestCase {

	/**
	 * Run a handler and return where it redirected to.
	 *
	 * @param string $action Value of the action field.
	 * @param array  $fields Everything else to post.
	 * @param string $nonce  Nonce action the handler checks.
	 * @return string Redirect target.
	 */
	private function submit( $action, array $fields, $nonce ) {
		$_POST = array_merge(
			$fields,
			array(
				AccountHandlers::ACTION_FIELD => $action,
				'_wpnonce'                    => wp_create_nonce( $nonce ),
			)
		);

		/*
		 * check_admin_referer() reads the nonce from $_REQUEST, which the web server
		 * fills in from the body and PHPUnit does not.
		 */
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request a handler will then verify; the nonce is right above.

		$catch = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $catch );

		try {
			( new AccountHandlers() )->dispatch();
		} catch ( RedirectException $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}

		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * The handlers run on the frontend, where WooCommerce's notices exist.
	 *
	 * `wc_add_notice()` is defined only when WooCommerce decided the request was a
	 * frontend one, and it does nothing at all without a session. A handler hooked
	 * somewhere `is_admin()` is true has neither.
	 */
	public function testHandlersRunWhereWooCommerceNoticesWork() {
		$this->assertNotFalse(
			has_action( 'template_redirect' ),
			'The handlers must run on template_redirect, not on admin_post.'
		);

		$this->assertTrue( function_exists( 'wc_add_notice' ) );
		$this->assertNotNull( WC()->session, 'wc_add_notice() silently does nothing without a session.' );

		foreach ( array_keys( AccountHandlers::actions() ) as $action ) {
			$this->assertFalse(
				has_action( 'admin_post_woap_' . $action ),
				$action . ' is still hooked to admin-post.php, where wc_add_notice() does not exist.'
			);
		}
	}

	/**
	 * Saving a location stores it, says so, and returns to the locations screen.
	 */
	public function testSaveLocation() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$location = $this->submit(
			'save_location',
			array(
				'name'      => 'Depot Ost',
				'country'   => 'DE',
				'city'      => 'Leipzig',
				'address_1' => '4 Ringstrasse',
			),
			'woap_save_location'
		);

		$this->assertStringContainsString( MyAccount::ENDPOINT_LOCATIONS, $location );

		$saved = LocationRepository::for_organization( $organization->get_id() );

		$this->assertCount( 1, $saved );
		$this->assertSame( 'Depot Ost', $saved[0]->get_name() );
		$this->assertSame( 'Leipzig', $saved[0]->get( 'city' ) );
		$this->assertNotEmpty( wc_get_notices( 'success' ) );

		wc_clear_notices();
	}

	/**
	 * Saving the organization's details stores them.
	 */
	public function testSaveOrganization() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit(
			'save_organization',
			array(
				'name'   => 'Acme Holdings AG',
				'email'  => 'hello@acme.test',
				'tax_id' => 'DE999',
			),
			'woap_save_organization'
		);

		$saved = OrganizationRepository::find( $organization->get_id() );

		$this->assertSame( 'Acme Holdings AG', $saved->get_name() );
		$this->assertSame( 'DE999', $saved->get( 'tax_id' ) );
		$this->assertFalse( $saved->allows_custom_shipping(), 'An unticked checkbox must switch the setting off.' );

		wc_clear_notices();
	}

	/**
	 * Saving the billing address stores it.
	 */
	public function testSaveBilling() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit(
			'save_billing',
			array(
				'billing_first_name' => 'Grace',
				'billing_city'       => 'Munich',
				'billing_country'    => 'DE',
			),
			'woap_save_billing'
		);

		$address = OrganizationRepository::find( $organization->get_id() )->get_billing_address();

		$this->assertSame( 'Grace', $address['first_name'] );
		$this->assertSame( 'Munich', $address['city'] );

		wc_clear_notices();
	}

	/**
	 * A member without the capability is refused, and nothing is written.
	 */
	public function testHandlerRefusesAMemberWithoutTheCapability() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization ) );

		$_POST    = array(
			AccountHandlers::ACTION_FIELD => 'save_location',
			'_wpnonce'                    => wp_create_nonce( 'woap_save_location' ),
			'name'                        => 'Sneaky Depot',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request the handler will verify; the nonce is set above.

		try {
			( new AccountHandlers() )->dispatch();
			$this->fail( 'A member without the capability was allowed to add a location.' );
		} catch ( \WPDieException $refused ) {
			// The refusal has to be the capability check, not a nonce that failed for
			// some unrelated reason — those look identical from the outside otherwise.
			$this->assertStringContainsString( 'permission', $refused->getMessage() );
		}

		$this->assertCount( 0, LocationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * A submission naming no handler does nothing.
	 */
	public function testUnknownActionIsIgnored() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$_POST = array( AccountHandlers::ACTION_FIELD => 'drop_everything' );

		( new AccountHandlers() )->dispatch();

		$this->assertCount( 0, LocationRepository::for_organization( $organization->get_id() ) );
	}

	/**
	 * The last active admin cannot be demoted, and is told why.
	 */
	public function testLastAdminCannotBeDemoted() {
		$organization = $this->make_organization();
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $admin );

		$this->submit(
			'update_member',
			array(
				'member_id' => $admin->get_id(),
				'role'      => Member::ROLE_MEMBER,
				'status'    => Member::STATUS_ACTIVE,
			),
			'woap_update_member'
		);

		$this->assertTrue( MemberRepository::find( $admin->get_id() )->is_admin() );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		wc_clear_notices();
	}

	/**
	 * A member of another organization cannot be touched, even by an admin.
	 */
	public function testForeignMemberCannotBeRemoved() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->make_member( $ours, Member::ROLE_ADMIN );
		$victim = $this->make_member( $theirs );

		$this->act_as( MemberRepository::for_organization( $ours->get_id() )[0] );

		$this->submit(
			'remove_member',
			array( 'member_id' => $victim->get_id() ),
			'woap_remove_member'
		);

		$this->assertNotNull( MemberRepository::find( $victim->get_id() ), 'A foreign member was removed.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		wc_clear_notices();
	}
}
