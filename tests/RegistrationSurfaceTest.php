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
}
