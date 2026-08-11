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
use WooOrgAccounts\Roles;

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
		$location = $this->dispatch_form( $action, $fields, $nonce );

		if ( null === $location ) {
			$this->fail( 'The handler did not redirect.' );
		}

		return $location;
	}

	/**
	 * Run a handler that is expected to hand the form back rather than redirect.
	 *
	 * A rejected submission is re-rendered with what was typed still in it — see
	 * `AccountHandlers::hold()` — so "did not redirect" is the passing outcome here
	 * rather than the failure it is above.
	 *
	 * @param string $action Value of the action field.
	 * @param array  $fields Everything else to post.
	 * @param string $nonce  Nonce action the handler checks.
	 * @return void
	 */
	private function submit_expecting_the_form_back( $action, array $fields, $nonce ) {
		$this->assertNull(
			$this->dispatch_form( $action, $fields, $nonce ),
			'The handler redirected, which loses everything that was typed.'
		);
	}

	/**
	 * Build the request and dispatch it, reporting where it went.
	 *
	 * @param string $action Value of the action field.
	 * @param array  $fields Everything else to post.
	 * @param string $nonce  Nonce action the handler checks.
	 * @return string|null Redirect target, or null when the handler returned instead.
	 */
	private function dispatch_form( $action, array $fields, $nonce ) {
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

		return null;
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

		$templates = glob( dirname( __DIR__ ) . '/templates/*/*.php' );

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
	 * Every field a template posts is prefixed, which is the rule the one above needs.
	 *
	 * Staying clear of the 82 query variables by inspection is luck, not a rule: the
	 * list is WordPress's to change, a plugin can add to it, and the check above only
	 * ever reports the collision that already exists. The prefix is what makes a
	 * collision impossible, and until this test the registration templates were the
	 * ones quietly outside it — they posted `password`, `tax_id`, `first_name` and five
	 * others under their bare names, and the only thing keeping them safe was that none
	 * of those eight happened to be a query variable.
	 *
	 * The exception is WooCommerce's own address blocks. `country-select.js` looks for
	 * `#billing_state` and `#shipping_state` by those exact names, so renaming them
	 * would silently stop the state field following the country.
	 */
	public function testEveryPostedFieldIsPrefixed() {
		$templates = glob( dirname( __DIR__ ) . '/templates/*/*.php' );

		$this->assertNotEmpty( $templates );

		foreach ( $templates as $template ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file from this repository, not a remote URL.
			$markup = (string) file_get_contents( $template );

			preg_match_all( '/name="([a-z_][a-z_0-9]*)(\[\])?"/', $markup, $matches );

			foreach ( $matches[1] as $field ) {
				$prefixed = 0 === strpos( $field, 'woap_' )
					|| 0 === strpos( $field, 'billing_' )
					|| 0 === strpos( $field, 'shipping_' );

				$this->assertTrue(
					$prefixed,
					sprintf(
						'%s posts a field called "%s". Every field this plugin defines is prefixed woap_; only the WooCommerce address blocks keep billing_ and shipping_.',
						basename( $template ),
						$field
					)
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
	 * An organization cannot be saved without a name.
	 *
	 * `required` on the input is the browser's opinion. Anything posting the form
	 * directly reached this with an empty name, and the empty name was stored and
	 * reported as saved — leaving an account nothing on the site could name.
	 */
	public function testAnOrganizationCannotBeLeftUnnamed() {
		$organization = $this->make_organization( array( 'name' => 'Acme Holdings AG' ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit_expecting_the_form_back(
			'save_organization',
			array( 'woap_name' => '   ' ),
			'woap_save_organization'
		);

		$this->assertSame(
			'Acme Holdings AG',
			OrganizationRepository::find( $organization->get_id() )->get_name(),
			'A refused save must leave the stored name alone.'
		);

		$errors = AccountHandlers::errors();

		$this->assertInstanceOf( \WP_Error::class, $errors );
		$this->assertNotSame( '', $errors->get_error_message( 'woap_name' ) );

		wc_clear_notices();
	}

	/**
	 * A required tax ID is required on this screen too, not only at registration.
	 *
	 * A field registration insists on and the account screen lets you blank again is
	 * not a required field. The organization's email address was exactly that until
	 * the column was retired, so the one setting that can make a detail mandatory is
	 * asserted on every screen that writes one.
	 */
	public function testARequiredTaxIdIsRefusedWhenBlank() {
		$this->set_setting( 'require_tax_id', true );

		$organization = $this->make_organization( array( 'tax_id' => 'DE811234567' ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit_expecting_the_form_back(
			'save_organization',
			array(
				'woap_name'   => 'Acme Holdings AG',
				'woap_tax_id' => '',
			),
			'woap_save_organization'
		);

		$this->assertSame(
			'DE811234567',
			OrganizationRepository::find( $organization->get_id() )->get( 'tax_id' ),
			'A refused save overwrote the tax ID it refused to replace.'
		);

		wc_clear_notices();
	}

	/**
	 * With the setting off, a blank tax ID is simply a blank tax ID.
	 */
	public function testABlankTaxIdIsAcceptedByDefault() {
		$organization = $this->make_organization( array( 'tax_id' => 'DE811234567' ) );
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit(
			'save_organization',
			array(
				'woap_name'   => 'Acme Holdings AG',
				'woap_tax_id' => '',
			),
			'woap_save_organization'
		);

		$this->assertSame( '', OrganizationRepository::find( $organization->get_id() )->get( 'tax_id' ) );

		wc_clear_notices();
	}

	/**
	 * A refused save hands back everything that was typed.
	 */
	public function testARefusedOrganizationSaveKeepsWhatWasTyped() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->submit_expecting_the_form_back(
			'save_organization',
			array(
				'woap_name'   => '',
				'woap_tax_id' => 'DE999',
			),
			'woap_save_organization'
		);

		$this->assertSame( 'DE999', AccountHandlers::value( 'woap_tax_id' ) );

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

		$this->submit_expecting_the_form_back(
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
		AccountHandlers::flush();
	}

	/**
	 * Promoting somebody to admin gives them an admin's permissions.
	 *
	 * The permissions form stores only what differs from the role's own answer, and it
	 * used to derive that difference from checkboxes drawn for the role the member held
	 * *before* the change. Promoting an employee therefore stored "everything off" as
	 * six overrides against the admin defaults, and produced an organization admin who
	 * could not manage anything. The form now asks the question outright — follow the
	 * role, or choose them one by one — and following the role stores no overrides.
	 */
	public function testPromotingToAdminGrantsTheAdminDefaults() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->submit(
			'update_member',
			array(
				'woap_member_id'         => $member->get_id(),
				'woap_role'              => Member::ROLE_ADMIN,
				'woap_status'            => Member::STATUS_ACTIVE,
				'woap_permissions_scope' => 'role',
				'woap_location_scope'    => 'all',
			),
			'woap_update_member'
		);

		$saved = MemberRepository::find( $member->get_id() );

		$this->assertTrue( $saved->is_admin() );
		$this->assertSame( array(), $saved->get_capabilities(), 'Following the role must not store overrides against it.' );
		$this->assertTrue( user_can( $saved->get_user_id(), Roles::MANAGE_MEMBERS ), 'The new admin cannot manage members.' );

		wc_clear_notices();
	}

	/**
	 * Choosing permissions one by one stores only what differs from the role.
	 */
	public function testChosenPermissionsAreStoredAsOverrides() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$member = $this->make_member( $organization, Member::ROLE_MEMBER );

		$this->submit(
			'update_member',
			array(
				'woap_member_id'         => $member->get_id(),
				'woap_role'              => Member::ROLE_MEMBER,
				'woap_status'            => Member::STATUS_ACTIVE,
				'woap_permissions_scope' => 'custom',
				'woap_capabilities'      => array( Roles::PLACE_ORDERS, Roles::MANAGE_LOCATIONS ),
				'woap_location_scope'    => 'all',
			),
			'woap_update_member'
		);

		$saved = MemberRepository::find( $member->get_id() );

		$this->assertSame( array( Roles::MANAGE_LOCATIONS => true ), $saved->get_capabilities() );
		$this->assertTrue( user_can( $saved->get_user_id(), Roles::MANAGE_LOCATIONS ) );

		wc_clear_notices();
	}

	/**
	 * "Only the ones I tick", with nothing ticked, is a question rather than an answer.
	 *
	 * An empty access list is how "every location" is stored, so accepting this would
	 * quietly save the opposite of what the form says.
	 */
	public function testRestrictingToNoLocationIsRefused() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$member = $this->make_member( $organization, Member::ROLE_MEMBER );
		$north  = $this->make_location( $organization );

		MemberRepository::set_location_ids( $member->get_id(), array( $north->get_id() ) );

		$this->submit_expecting_the_form_back(
			'update_member',
			array(
				'woap_member_id'         => $member->get_id(),
				'woap_role'              => Member::ROLE_MEMBER,
				'woap_status'            => Member::STATUS_ACTIVE,
				'woap_permissions_scope' => 'role',
				'woap_location_scope'    => 'selected',
			),
			'woap_update_member'
		);

		$this->assertSame(
			array( $north->get_id() ),
			MemberRepository::location_ids( $member->get_id() ),
			'A refused submission must not have changed anything.'
		);
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		wc_clear_notices();
		AccountHandlers::flush();
	}

	/**
	 * Making one location the default takes it off the one that had it.
	 */
	public function testMakingALocationTheDefault() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$north = $this->make_location( $organization, array( 'is_default' => true ) );
		$south = $this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		$this->submit(
			'default_location',
			array( 'woap_location_id' => $south->get_id() ),
			'woap_default_location'
		);

		$this->assertTrue( LocationRepository::find( $south->get_id() )->is_default() );
		$this->assertFalse( LocationRepository::find( $north->get_id() )->is_default(), 'Two locations are default at once.' );

		wc_clear_notices();
	}

	/**
	 * A refused invitation comes back on the form with the address still in it.
	 */
	public function testRefusedInvitationKeepsWhatWasTyped() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$existing = $this->make_member( $organization, Member::ROLE_MEMBER );
		$email    = get_userdata( $existing->get_user_id() )->user_email;

		$this->submit_expecting_the_form_back(
			'invite_member',
			array(
				'woap_email' => $email,
				'woap_role'  => Member::ROLE_ADMIN,
			),
			'woap_invite_member'
		);

		$this->assertSame( $email, AccountHandlers::value( 'woap_email' ) );
		$this->assertSame( Member::ROLE_ADMIN, AccountHandlers::value( 'woap_role' ) );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );

		// And the screen it comes back on is the form, with the reason under the field.
		$_GET[ MyAccount::INVITE_VAR ] = 'new';

		ob_start();
		( new MyAccount() )->render_organization_invitations();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . esc_attr( $email ) . '"', $markup );
		$this->assertStringContainsString( 'woocommerce-invalid', $markup );

		wc_clear_notices();
		AccountHandlers::flush();
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
