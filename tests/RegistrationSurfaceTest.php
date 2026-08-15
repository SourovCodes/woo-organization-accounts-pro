<?php
/**
 * Registration, emails, and the data-layer corners nothing else reaches.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Emails\Emails;
use WooOrgAccounts\Frontend\Registration;
use WooOrgAccounts\Labels;
use WooOrgAccounts\LoginGate;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Roles;

/**
 * The way in, the way it is announced, and what holds it up.
 *
 * Registration is the only door into the shop — WooCommerce's own is switched off — so
 * a link that leads nowhere here is a customer who cannot become one.
 */
class RegistrationSurfaceTest extends TestCase {

	/**
	 * Give the site a registration page, the way activation does.
	 *
	 * @return int Page ID.
	 */
	private function make_registration_page() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[' . Registration::SHORTCODE . ']',
			)
		);

		$this->set_setting( 'registration_page_id', $page_id );

		return $page_id;
	}

	/**
	 * A complete registration submission, as the form posts it.
	 *
	 * @param array $overrides Fields to add or replace.
	 * @return array The submission.
	 */
	private function registration_submission( array $overrides = array() ) {
		return array_merge(
			array(
				'woap_action'            => 'register',
				'_wpnonce'               => wp_create_nonce( Registration::REGISTER_ACTION ),
				'woap_organization_name' => 'Acme Holdings AG',
				'woap_tax_id'            => '',
				'woap_admin_first_name'  => 'Ada',
				'woap_admin_last_name'   => 'Byron',
				'woap_admin_email'       => 'ada@acme.test',
				'woap_password'          => 'correct horse battery',
				'woap_password_confirm'  => 'correct horse battery',
				'billing_first_name'     => 'Ada',
				'billing_last_name'      => 'Byron',
				'billing_company'        => '',
				'billing_address_1'      => '1 Hauptstrasse',
				'billing_city'           => 'Berlin',
				'billing_postcode'       => '10115',
				'billing_country'        => 'DE',
				'billing_email'          => 'invoices@acme.test',
				'billing_phone'          => '+49 30 123456',
			),
			$overrides
		);
	}

	/**
	 * Post a registration and report where it ended up.
	 *
	 * @param array $overrides Fields to add or replace.
	 * @return string Redirect target, or an empty string when the form came back.
	 */
	private function register( array $overrides = array() ) {
		/*
		 * A visitor registering is by definition signed out, and `process_registration()`
		 * returns early for anybody who is not — so a second call in one test would
		 * otherwise be answered by the account the first one signed in.
		 */
		wp_set_current_user( 0 );

		$_POST = $this->registration_submission( $overrides );

		/*
		 * check_admin_referer() reads the nonce from $_REQUEST, which the web server
		 * fills in from the body and PHPUnit does not.
		 */
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request the handler will then verify; the nonce is in the submission above.

		$redirect = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $redirect );

		try {
			( new Registration() )->maybe_process();

			$location = '';
		} catch ( RedirectException $caught ) {
			$location = $caught->location;
		} finally {
			remove_filter( 'wp_redirect', $redirect );

			$_POST    = array();
			$_REQUEST = array();
		}

		return $location;
	}

	/**
	 * Registering asks for one email address and one phone number, and uses both.
	 *
	 * The form used to ask for three of each — an organization pair beside the billing
	 * pair beside the account holder's — and only the billing pair reached an order.
	 * What is asserted here is that what is left is sufficient: the organization is
	 * created, and its billing address is the address that was typed.
	 */
	public function testRegisteringStoresTheBillingAddressThatWasTyped() {
		$this->make_registration_page();
		$this->set_setting( 'require_approval', false );

		$this->register();

		$user = get_user_by( 'email', 'ada@acme.test' );

		$this->assertInstanceOf( \WP_User::class, $user );

		$member = MemberRepository::find_by_user( $user->ID );

		$this->assertNotNull( $member );

		$organization = \WooOrgAccounts\Data\OrganizationRepository::find( $member->get_organization_id() );

		$this->assertSame( 'Acme Holdings AG', $organization->get_name() );
		$this->assertSame( 'invoices@acme.test', $organization->get( 'billing_email' ) );
		$this->assertSame( '+49 30 123456', $organization->get( 'billing_phone' ) );

		// A blank billing company still falls back to the organization's name.
		$this->assertSame( 'Acme Holdings AG', $organization->get( 'billing_company' ) );
	}

	/**
	 * A tax ID is optional by default and required when the site says so.
	 *
	 * Both halves matter: the setting exists because insisting on a VAT number is
	 * right for one shop and wrong for the next, and a shop that has not asked for it
	 * must not find registrations refused.
	 */
	public function testATaxIdIsRequiredOnlyWhenTheSettingSaysSo() {
		$this->make_registration_page();
		$this->set_setting( 'require_approval', false );

		$this->assertNotSame( '', $this->register(), 'A blank tax ID was refused with the setting off.' );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'ada@acme.test' ) );

		$this->set_setting( 'require_tax_id', true );

		$this->assertSame(
			'',
			$this->register( array( 'woap_admin_email' => 'grace@acme.test' ) ),
			'A blank tax ID was accepted with the setting on.'
		);

		$this->assertFalse( get_user_by( 'email', 'grace@acme.test' ), 'A refused registration created an account anyway.' );

		/*
		 * And the same submission goes through once the number is supplied, so the
		 * refusal is about the tax ID rather than about anything else on the form.
		 */
		$this->assertNotSame(
			'',
			$this->register(
				array(
					'woap_admin_email' => 'grace@acme.test',
					'woap_tax_id'      => 'DE811234567',
				)
			)
		);

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'grace@acme.test' ) );
	}

	/**
	 * Post the invitation acceptance form and report where it ended up.
	 *
	 * @param string $token     Raw token from the link.
	 * @param array  $overrides Fields to add or replace.
	 * @return string Redirect target, or an empty string when the form came back.
	 */
	private function join( $token, array $overrides = array() ) {
		$_POST = array_merge(
			array(
				'woap_action'           => 'join',
				'_wpnonce'              => wp_create_nonce( Registration::JOIN_ACTION ),
				Invitations::QUERY_VAR  => $token,
				'woap_first_name'       => 'Grace',
				'woap_last_name'        => 'Hopper',
				'woap_password'         => 'correct horse battery',
				'woap_password_confirm' => 'correct horse battery',
			),
			$overrides
		);

		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request the handler will then verify; the nonce is in the submission above.

		$redirect = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $redirect );

		try {
			( new Registration() )->maybe_process();

			$location = '';
		} catch ( RedirectException $caught ) {
			$location = $caught->location;
		} finally {
			remove_filter( 'wp_redirect', $redirect );

			$_POST    = array();
			$_REQUEST = array();
		}

		return $location;
	}

	/**
	 * Redeeming an invitation through the form creates the account and the membership.
	 *
	 * The rest of the suite drives `Invitations::accept()` directly, which is the half
	 * of this flow that holds the security properties — and which is why the *form* half
	 * could be renamed out from under it without a single test failing. Everything from
	 * the token in the hidden field to the name on the new account goes through here.
	 */
	public function testAcceptingAnInvitationThroughTheFormCreatesTheAccount() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$this->make_registration_page();

		$result = Invitations::create( $organization->get_id(), 'newcomer@acme.test', Member::ROLE_MEMBER );

		$this->assertNotWPError( $result );

		wp_set_current_user( 0 );

		$this->assertNotSame( '', $this->join( $result['token'] ), 'The form did not accept a good submission.' );

		$user = get_user_by( 'email', 'newcomer@acme.test' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'Grace', $user->first_name );
		$this->assertSame( 'Hopper', $user->last_name );

		$member = MemberRepository::find_by_user( $user->ID );

		$this->assertNotNull( $member, 'The invitee did not end up a member.' );
		$this->assertSame( $organization->get_id(), $member->get_organization_id() );
	}

	/**
	 * The join form reports a missing first name rather than accepting a blank one.
	 *
	 * The half of the rename that a happy-path test cannot catch: a field read under
	 * the wrong name is empty every time, so validation would refuse every submission
	 * while the form looked perfectly correct.
	 */
	public function testTheJoinFormRefusesABlankFirstName() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$this->make_registration_page();

		$result = Invitations::create( $organization->get_id(), 'newcomer@acme.test', Member::ROLE_MEMBER );

		wp_set_current_user( 0 );

		$this->assertSame( '', $this->join( $result['token'], array( 'woap_first_name' => '' ) ) );
		$this->assertFalse( get_user_by( 'email', 'newcomer@acme.test' ) );
	}

	/**
	 * WordPress's own registration is off as well as WooCommerce's.
	 *
	 * An account created outside an organization cannot buy anything, so offering to
	 * create one produces a customer who can only be told no at the checkout.
	 */
	public function testBothRegistrationFormsAreSwitchedOff() {
		$registration = new Registration();

		$this->assertSame( 'no', $registration->disable_woocommerce_registration() );
		$this->assertSame( '0', $registration->disable_wordpress_registration() );
	}

	/**
	 * The registration link points at a page that exists and carries the shortcode.
	 */
	public function testTheRegistrationLinkLeadsToTheShortcode() {
		$page_id = $this->make_registration_page();

		$this->assertSame( $page_id, Registration::page_id() );
		$this->assertSame( get_permalink( $page_id ), Registration::page_url() );

		ob_start();
		( new Registration() )->render_registration_link();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( get_permalink( $page_id ), html_entity_decode( $markup ) );
		$this->assertStringContainsString( Labels::organization(), $markup );
	}

	/**
	 * With no page configured there is no link, rather than a link to nothing.
	 */
	public function testThereIsNoLinkWithoutAPage() {
		$this->set_setting( 'registration_page_id', 0 );

		$this->assertSame( '', Registration::page_url() );

		ob_start();
		( new Registration() )->render_registration_link();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The registration page is forced to the theme's full-width layout.
	 *
	 * It is an ordinary page, so Woodmart gives it the site default — on a stock install
	 * a sidebar of blog widgets beside a twenty-field form.
	 */
	public function testTheRegistrationPageIsFullWidth() {
		$page_id = $this->make_registration_page();

		$this->go_to( get_permalink( $page_id ) );

		$this->assertSame( 'full-width', ( new Registration() )->page_layout( 'sidebar-right' ) );
	}

	/**
	 * A layout set on the page itself wins, so there is one answer rather than two.
	 */
	public function testALayoutChosenOnThePageIsRespected() {
		$page_id = $this->make_registration_page();

		update_post_meta( $page_id, '_woodmart_main_layout', 'sidebar-left' );

		$this->go_to( get_permalink( $page_id ) );

		$this->assertSame( 'sidebar-right', ( new Registration() )->page_layout( 'sidebar-right' ) );
	}

	/**
	 * Every other page keeps whatever layout the theme gave it.
	 */
	public function testOtherPagesAreLeftAlone() {
		$this->make_registration_page();

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );

		$this->assertSame( 'sidebar-right', ( new Registration() )->page_layout( 'sidebar-right' ) );
	}

	/**
	 * The shortcode's honeypot is a field no real visitor fills in.
	 *
	 * It has to be named clear of WordPress's query variables like everything else here,
	 * and it has to be hidden without depending on the stylesheet loading.
	 */
	public function testTheHoneypotIsPrefixedLikeEverythingElse() {
		$this->assertStringStartsWith( 'woap_', Registration::HONEYPOT_FIELD );
	}

	/**
	 * An invitation email carries a link that actually redeems that invitation.
	 *
	 * The token is in the email and nowhere else — only its hash is stored — so a link
	 * that does not match is an invitation nobody can accept and nobody can debug.
	 */
	public function testTheInvitationLinkRedeemsTheInvitation() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$this->make_registration_page();

		$result = Invitations::create( $organization->get_id(), 'newcomer@acme.test', Member::ROLE_MEMBER );

		$this->assertNotWPError( $result );

		$token = $result['token'];

		$this->assertNull(
			InvitationRepository::find_by_token( 'not the token' ),
			'A token that was never issued must find nothing.'
		);

		$invitation = InvitationRepository::find_by_token( $token );

		$this->assertNotNull( $invitation );
		$this->assertSame( $organization->get_id(), $invitation->get( 'organization_id' ) );
		$this->assertStringContainsString( rawurlencode( $token ), Invitations::accept_url( $token ) );
	}

	/**
	 * The stored row never contains the token itself.
	 */
	public function testTheStoredRowCarriesOnlyAHash() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$result = Invitations::create( $organization->get_id(), 'newcomer@acme.test', Member::ROLE_MEMBER );
		$token  = $result['token'];

		$invitation = InvitationRepository::find_by_token( $token );

		$this->assertNotSame( $token, $invitation->get( 'token_hash' ) );
		$this->assertSame( InvitationRepository::hash_token( $token ), $invitation->get( 'token_hash' ) );
	}

	/**
	 * An outstanding invitation is found by the address it was sent to.
	 *
	 * This is what stops a second invitation being sent alongside a live one, and it
	 * has to be scoped to the organization asking.
	 */
	public function testAnOutstandingInvitationIsFoundByItsAddress() {
		$organization = $this->make_organization();
		$other        = $this->make_organization();

		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		Invitations::create( $organization->get_id(), 'newcomer@acme.test', Member::ROLE_MEMBER );

		$this->assertNotNull( InvitationRepository::find_pending_for_email( $organization->get_id(), 'newcomer@acme.test' ) );
		$this->assertNotNull(
			InvitationRepository::find_pending_for_email( $organization->get_id(), 'NEWCOMER@acme.test' ),
			'An address differing only in case is the same address.'
		);
		$this->assertNull(
			InvitationRepository::find_pending_for_email( $other->get_id(), 'newcomer@acme.test' ),
			'One organization must not see another organization\'s invitations.'
		);
	}

	/**
	 * Every email this plugin sends is registered with WooCommerce and has its texts.
	 *
	 * WooCommerce renders the subject and heading from these, and an empty one arrives
	 * as a blank subject line rather than as an error.
	 */
	public function testEveryEmailHasASubjectAndAHeading() {
		$ours = ( new Emails() )->add_classes( array() );

		$this->assertNotEmpty( $ours );

		$registered = WC()->mailer()->get_emails();

		foreach ( $ours as $key => $email ) {
			$this->assertArrayHasKey( $key, $registered, sprintf( '%s is not registered with WooCommerce.', $key ) );
			$this->assertNotSame( '', trim( (string) $email->get_default_subject() ), sprintf( '%s has no subject.', $key ) );
			$this->assertNotSame( '', trim( (string) $email->get_default_heading() ), sprintf( '%s has no heading.', $key ) );
			$this->assertNotSame( '', trim( (string) $email->get_subject() ), sprintf( '%s resolves to no subject.', $key ) );
		}
	}

	/**
	 * Every email action the plugin declares is one WooCommerce will actually fire on.
	 *
	 * WooCommerce only hooks the actions an email declares, so a trigger hooked to
	 * something absent from that list is an email that is never sent.
	 */
	public function testEveryEmailActionIsDeclared() {
		$declared = ( new Emails() )->add_actions( array() );

		$this->assertNotEmpty( $declared );

		foreach ( Emails::actions() as $action ) {
			$this->assertContains( $action, $declared, sprintf( '%s is not declared to WooCommerce.', $action ) );
		}
	}

	/**
	 * A location prints an address formatted for its own country.
	 *
	 * The plugin never composes an envelope itself — the postcode goes before the city
	 * in Germany and after it in the United States, and WooCommerce is what knows that.
	 */
	public function testALocationIsFormattedForItsCountry() {
		$organization = $this->make_organization();

		$german = $this->make_location(
			$organization,
			array(
				'city'     => 'Hamburg',
				'postcode' => '20095',
				'country'  => 'DE',
			)
		);

		$american = $this->make_location(
			$organization,
			array(
				'city'     => 'Columbus',
				'postcode' => '43004',
				'state'    => 'OH',
				'country'  => 'US',
			)
		);

		$this->assertMatchesRegularExpression( '/20095\s*(<br\/?>)?\s*Hamburg/i', $german->get_formatted_address() );
		$this->assertMatchesRegularExpression( '/Columbus[^0-9]*43004/i', $american->get_formatted_address() );
	}

	/**
	 * An organization's billing address is formatted the same way.
	 */
	public function testTheBillingAddressIsFormattedForItsCountry() {
		$organization = $this->make_organization();

		$this->assertStringContainsString( 'Berlin', $organization->get_formatted_billing_address() );
	}

	/**
	 * A location is a WooCommerce shipping address, column for column.
	 */
	public function testALocationIsAWooCommerceShippingAddress() {
		$organization = $this->make_organization();
		$location     = $this->make_location( $organization );

		$this->assertSame(
			array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ),
			array_keys( $location->get_shipping_address() )
		);
	}

	/**
	 * Deleting a location forgets every member's access to it.
	 *
	 * An access row pointing at a location that no longer exists is a member restricted
	 * to nothing, which is the one state the checkout has no answer for.
	 */
	public function testDeletingALocationForgetsWhoCouldUseIt() {
		$organization = $this->make_organization();
		$kept         = $this->make_location( $organization, array( 'name' => 'Depot Ost' ) );
		$removed      = $this->make_location( $organization, array( 'name' => 'Depot West' ) );

		$member = $this->make_member( $organization );

		MemberRepository::set_location_ids( $member->get_id(), array( $kept->get_id(), $removed->get_id() ) );

		LocationRepository::delete( $removed->get_id() );
		MemberRepository::forget_location( $removed->get_id() );

		$this->assertSame( array( $kept->get_id() ), MemberRepository::location_ids( $member->get_id() ) );
	}

	/**
	 * Access lists are read for many members at once, and answer for every one asked.
	 *
	 * "Has no locations" and "was not asked about" must not look alike, or the snapshot
	 * would report unrestricted access for a member it simply skipped.
	 */
	public function testABatchedAccessReadAnswersForEveryMemberAskedAbout() {
		$organization = $this->make_organization();
		$location     = $this->make_location( $organization );

		$restricted   = $this->make_member( $organization );
		$unrestricted = $this->make_member( $organization );

		MemberRepository::set_location_ids( $restricted->get_id(), array( $location->get_id() ) );

		$answers = MemberRepository::location_ids_for_members(
			array( $restricted->get_id(), $unrestricted->get_id() )
		);

		$this->assertArrayHasKey( $restricted->get_id(), $answers );
		$this->assertArrayHasKey( $unrestricted->get_id(), $answers );
		$this->assertSame( array( $location->get_id() ), $answers[ $restricted->get_id() ] );
		$this->assertSame( array(), $answers[ $unrestricted->get_id() ] );
	}

	/**
	 * The boolean and integer columns come back as booleans and integers.
	 *
	 * Everything arrives from the database as a string, and `'0'` is true in PHP until
	 * something casts it — which is how a switch that is off reads as on.
	 */
	public function testStoredColumnsComeBackAsTheirOwnTypes() {
		foreach ( array( Organization::class, Member::class, Location::class ) as $class ) {
			$casts = $class::casts();

			$this->assertNotEmpty( $casts, sprintf( '%s declares no casts.', $class ) );

			foreach ( $casts as $column => $type ) {
				$this->assertContains(
					$type,
					array( 'bool', 'int', 'array' ),
					sprintf( '%s casts %s to an unknown type.', $class, $column )
				);
			}
		}

		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );

		$this->assertIsBool( $organization->allows_custom_shipping() );
		$this->assertFalse( $organization->allows_custom_shipping() );
	}

	/**
	 * The two WordPress roles carry `read` and nothing else.
	 *
	 * Every capability comes from the membership row at runtime, so one on the role
	 * would be a second answer that outlives the membership it came from.
	 */
	public function testTheRolesCarryNothingButRead() {
		foreach ( array( Roles::ROLE_ORG_ADMIN, Roles::ROLE_MEMBER ) as $role_name ) {
			$role = get_role( $role_name );

			$this->assertNotNull( $role, sprintf( '%s does not exist.', $role_name ) );
			$this->assertSame(
				array( 'read' => true ),
				array_filter( $role->capabilities ),
				sprintf( '%s carries a capability of its own.', $role_name )
			);
		}
	}

	/**
	 * A pending registrant is told so, even when they are signed straight in.
	 *
	 * This is the *default* configuration — approval required to order, not to sign in —
	 * and it said nothing at all: a twenty-field form, then My Account, with no word that
	 * anything was pending. The screen that says so already existed and only the sign-in
	 * gate ever reached it, so nothing failed and nobody was told.
	 */
	public function testAPendingRegistrantIsToldWhileStillBeingSignedIn() {
		$this->make_registration_page();
		$this->set_setting( 'require_approval', true );
		$this->set_setting( LoginGate::SETTING, false );

		$landed = $this->register();

		$this->assertStringContainsString(
			Registration::PENDING_VAR . '=' . LoginGate::REASON_AWAITING,
			$landed,
			'A registration held for approval must say so, whatever the sign-in gate is set to.'
		);

		$this->assertTrue(
			is_user_logged_in(),
			'With the sign-in gate off they are signed in as before; only the silence is fixed.'
		);
	}

	/**
	 * That screen says the account is being reviewed, and names the company underneath.
	 *
	 * What the shop approves is a customer account. The company is what the account is
	 * *for* — the customer has just typed its name into a form and does not need telling
	 * that a second record exists and is under review.
	 */
	public function testTheWaitingScreenIsAboutTheAccountNotTheCompany() {
		$this->make_registration_page();
		$this->set_setting( 'require_approval', true );
		$this->set_setting( LoginGate::SETTING, false );

		$this->register();

		$_GET[ Registration::PENDING_VAR ] = LoginGate::REASON_AWAITING;

		$markup = ( new Registration() )->render();

		$this->assertStringContainsString( 'woap-registration--pending', $markup );
		$this->assertStringContainsString( 'your account has been created', $markup );
		$this->assertStringContainsString(
			'Acme Holdings AG',
			$markup,
			'The company is named as what the account is for.'
		);
		$this->assertStringNotContainsString(
			'cannot sign in',
			$markup,
			'They are signed in; this screen must not read as a refusal.'
		);
	}

	/**
	 * A shop that approves nothing says nothing.
	 */
	public function testAnUngatedRegistrationGoesStraightToTheAccount() {
		$this->make_registration_page();
		$this->set_setting( 'require_approval', false );
		$this->set_setting( LoginGate::SETTING, false );

		$this->assertStringNotContainsString(
			Registration::PENDING_VAR,
			$this->register(),
			'With approval switched off there is nothing to wait for and nothing to say.'
		);
	}

	/**
	 * The form warns about the review before it is filled in, not after.
	 *
	 * A shop that reviews registrations is asking for twenty fields and a password in
	 * exchange for an account that cannot buy anything yet. Finding that out on the
	 * confirmation screen is finding it out too late to have decided anything.
	 */
	public function testTheFormSaysNewAccountsAreReviewed() {
		$this->set_setting( 'require_approval', true );

		$this->assertStringContainsString(
			'New accounts are reviewed',
			( new Registration() )->render()
		);
	}

	/**
	 * And says nothing where a shop approves nothing.
	 */
	public function testTheFormIsSilentWhenNothingIsReviewed() {
		$this->set_setting( 'require_approval', false );

		$this->assertStringNotContainsString(
			'New accounts are reviewed',
			( new Registration() )->render()
		);
	}
}
