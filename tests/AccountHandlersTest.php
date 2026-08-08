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
	 * No form field is named after one of WordPress's public query variables.
	 *
	 * These forms post back to the page they are on, and `WP::parse_request()` reads
	 * every public query variable out of `$_POST` as readily as out of the URL. A field
	 * called `name` therefore sets the post-slug query var, the main query resolves to
	 * nothing, and the whole submission returns a 404 — after saving, so the write
	 * lands and the customer is told the page does not exist. The plugin's own fields
	 * are prefixed to stay clear of all 82 of them; only the WooCommerce address blocks
	 * keep their own names, and those are prefixed `billing_` and `shipping_` anyway.
	 */
	public function testNoFieldNameCollidesWithAWordPressQueryVar() {
		global $wp;

		$templates = glob( dirname( __DIR__ ) . '/templates/myaccount/*.php' );

		$this->assertNotEmpty( $templates );

		foreach ( $templates as $template ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
			$markup = (string) file_get_contents( $template );

			preg_match_all( '/name="([a-z_][a-z_0-9]*)(\[\])?"/', $markup, $matches );

			foreach ( $matches[1] as $field ) {
				$this->assertNotContains(
					$field,
					$wp->public_query_vars,
					sprintf( '%s posts a field called "%s", which WordPress reads as a query variable.', basename( $template ), $field )
				);
			}
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
				'woap_name'           => 'Depot Ost',
				'shipping_first_name' => 'Grace',
				'shipping_last_name'  => 'Hopper',
				'shipping_country'    => 'DE',
				'shipping_address_1'  => '4 Ringstrasse',
				'shipping_postcode'   => '04109',
				'shipping_city'       => 'Leipzig',
				'shipping_phone'      => '+49 341 123456',
			),
			'woap_save_location'
		);

		$this->assertStringContainsString( MyAccount::ENDPOINT_LOCATIONS, $location );

		$saved = LocationRepository::for_organization( $organization->get_id() );

		$this->assertCount( 1, $saved );
		$this->assertSame( 'Depot Ost', $saved[0]->get_name() );
		$this->assertSame( 'Leipzig', $saved[0]->get( 'city' ) );
		$this->assertSame( 'Grace', $saved[0]->get( 'first_name' ) );
		$this->assertSame( 'Hopper', $saved[0]->get( 'last_name' ) );
		$this->assertNotEmpty( wc_get_notices( 'success' ) );

		wc_clear_notices();
	}

	/**
	 * An address the checkout would refuse is refused here too, and handed back.
	 *
	 * Collecting an address the checkout will later reject is worse than refusing it:
	 * the location looks saved, and the failure surfaces at the till.
	 */
	public function testIncompleteLocationAddressIsRefused() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$_POST = array(
			AccountHandlers::ACTION_FIELD => 'save_location',
			'_wpnonce'                    => wp_create_nonce( 'woap_save_location' ),
			'woap_name'                   => 'Depot Ost',
			'shipping_country'            => 'DE',
			'shipping_city'               => 'Leipzig',
		);

		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request the handler will verify; the nonce is set above.

		( new AccountHandlers() )->dispatch();

		$this->assertCount( 0, LocationRepository::for_organization( $organization->get_id() ), 'A half-filled address was stored.' );

		$errors = AccountHandlers::errors();

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$this->assertContains( 'shipping_first_name', $errors->get_error_codes() );
		$this->assertContains( 'shipping_address_1', $errors->get_error_codes() );
		$this->assertContains( 'shipping_postcode', $errors->get_error_codes() );

		// What was typed comes back rather than being thrown away with a redirect.
		$this->assertSame( 'Depot Ost', AccountHandlers::value( 'woap_name' ) );
		$this->assertSame( 'Leipzig', AccountHandlers::value( 'shipping_city' ) );

		wc_clear_notices();
	}

	/**
	 * A blank company falls back to the organization, so the label names somebody.
	 */
	public function testBlankCompanyFallsBackToTheOrganization() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit(
			'save_location',
			array(
				'woap_name'           => 'Depot Ost',
				'shipping_first_name' => 'Grace',
				'shipping_last_name'  => 'Hopper',
				'shipping_country'    => 'DE',
				'shipping_address_1'  => '4 Ringstrasse',
				'shipping_postcode'   => '04109',
				'shipping_city'       => 'Leipzig',
				'shipping_phone'      => '+49 341 123456',
			),
			'woap_save_location'
		);

		$saved = LocationRepository::for_organization( $organization->get_id() );

		$this->assertSame( 'Acme GmbH', $saved[0]->get( 'company' ) );

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
				'woap_name'   => 'Acme Holdings AG',
				'woap_email'  => 'hello@acme.test',
				'woap_tax_id' => 'DE999',
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
				'billing_last_name'  => 'Hopper',
				'billing_country'    => 'DE',
				'billing_address_1'  => '3 Sendlinger Strasse',
				'billing_postcode'   => '80331',
				'billing_city'       => 'Munich',
				'billing_email'      => 'invoices@acme.test',
				'billing_phone'      => '+49 89 123456',
			),
			'woap_save_billing'
		);

		$address = OrganizationRepository::find( $organization->get_id() )->get_billing_address();

		$this->assertSame( 'Grace', $address['first_name'] );
		$this->assertSame( 'Munich', $address['city'] );
		$this->assertSame( '80331', $address['postcode'] );

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
				'woap_member_id' => $admin->get_id(),
				'woap_role'      => Member::ROLE_MEMBER,
				'woap_status'    => Member::STATUS_ACTIVE,
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
			array( 'woap_member_id' => $victim->get_id() ),
			'woap_remove_member'
		);

		$this->assertNotNull( MemberRepository::find( $victim->get_id() ), 'A foreign member was removed.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		wc_clear_notices();
	}
}
