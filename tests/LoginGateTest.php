<?php
/**
 * Tests for the sign-in gate.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\Registration;
use WooOrgAccounts\LoginGate;
use WooOrgAccounts\Membership\Context;

/**
 * The optional rule that only a member of an approved organization may sign in.
 *
 * The setting is off by default, so every test here switches it on first. What is
 * asserted is the shape of the rule rather than one screen: who it refuses, who it must
 * never refuse, and that a session already open is closed rather than left running.
 */
class LoginGateTest extends TestCase {

	/**
	 * Nobody is refused while the setting is off.
	 */
	public function testTheGateIsOffByDefault() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->assertFalse( LoginGate::is_enabled() );
		$this->assertSame( '', LoginGate::reason( $member->get_user_id() ) );
		$this->assertInstanceOf( \WP_User::class, $this->authenticate( $member->get_user_id() ) );
	}

	/**
	 * A member of an organization waiting for approval cannot sign in.
	 */
	public function testAPendingOrganizationCannotSignIn() {
		$this->set_setting( LoginGate::SETTING, true );

		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->assertSame( LoginGate::REASON_PENDING, LoginGate::reason( $member->get_user_id() ) );

		$result = $this->authenticate( $member->get_user_id() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woap_awaiting_approval', $result->get_error_code() );
		$this->assertSame( LoginGate::message( LoginGate::REASON_PENDING ), $result->get_error_message() );
	}

	/**
	 * A suspended or rejected organization is refused too, and told so differently.
	 *
	 * The rule is "approved", not "not yet looked at": an account that has been closed
	 * is as unapproved as one that has never been opened. The two messages differ
	 * because waiting is worth waiting for and a closed account is worth a phone call.
	 */
	public function testASuspendedOrganizationCannotSignIn() {
		$this->set_setting( LoginGate::SETTING, true );

		foreach ( array( Organization::STATUS_SUSPENDED, Organization::STATUS_REJECTED ) as $status ) {
			$organization = $this->make_organization( array( 'status' => $status ) );
			$member       = $this->make_member( $organization );

			$this->assertSame( LoginGate::REASON_INACTIVE, LoginGate::reason( $member->get_user_id() ), $status . ' was allowed in.' );
		}

		$this->assertNotSame(
			LoginGate::message( LoginGate::REASON_PENDING ),
			LoginGate::message( LoginGate::REASON_INACTIVE ),
			'A closed account is told the same thing as one still being reviewed.'
		);
	}

	/**
	 * An approved organization signs in as it always did.
	 */
	public function testAnApprovedOrganizationSignsIn() {
		$this->set_setting( LoginGate::SETTING, true );

		$member = $this->make_member( $this->make_organization(), Member::ROLE_ADMIN );

		$this->assertSame( '', LoginGate::reason( $member->get_user_id() ) );
		$this->assertInstanceOf( \WP_User::class, $this->authenticate( $member->get_user_id() ) );
	}

	/**
	 * A user who belongs to no organization is not this rule's business.
	 *
	 * The site owner, an author, a subscriber left over from before the plugin: none of
	 * them has an organization to be approved, and refusing everybody without a
	 * membership would lock the site's own administrator out of it.
	 */
	public function testSomebodyWithNoMembershipIsUnaffected() {
		$this->set_setting( LoginGate::SETTING, true );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( '', LoginGate::reason( $user_id ) );
		$this->assertInstanceOf( \WP_User::class, $this->authenticate( $user_id ) );
	}

	/**
	 * Shop staff are never locked out of their own shop.
	 *
	 * An administrator who joined an organization to see what a customer sees must not
	 * be able to lock themselves out by suspending it — the screen that would let them
	 * back in is behind the door that just shut.
	 */
	public function testShopStaffAreNeverRefused() {
		$this->set_setting( LoginGate::SETTING, true );

		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$user = get_user_by( 'id', $member->get_user_id() );
		$user->add_cap( 'manage_woocommerce' );

		$this->assertSame( '', LoginGate::reason( $user->ID ) );
	}

	/**
	 * A refusal by somebody else is passed through rather than replaced.
	 *
	 * The filter runs last so that nothing can answer after it, which also means it is
	 * handed every failed sign-in on the site. A wrong password must still report a
	 * wrong password.
	 */
	public function testAnEarlierRefusalIsLeftAlone() {
		$this->set_setting( LoginGate::SETTING, true );

		$error = new \WP_Error( 'incorrect_password', 'The password you entered is incorrect.' );

		$this->assertSame( $error, ( new LoginGate() )->refuse_authentication( $error ) );
		$this->assertNull( ( new LoginGate() )->refuse_authentication( null ) );
	}

	/**
	 * A session that is already open is ended when the organization stops being approved.
	 *
	 * An organization becomes unapproved by being suspended, which happens long after
	 * anybody signed in. A rule that only applied at the moment of sign-in would leave
	 * whoever was already signed in exactly where they were.
	 */
	public function testAnOpenSessionIsEnded() {
		/*
		 * wp_logout() takes WooCommerce's session with it, and clearing that session
		 * clears its cookie — which PHPUnit, having already sent its output, cannot do.
		 * The notice that follows belongs to the test environment rather than to the
		 * code under test.
		 */
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		$this->set_setting( LoginGate::SETTING, true );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->act_as( $member );

		$gate = new LoginGate();
		$gate->end_open_session();

		$this->assertSame( $member->get_user_id(), get_current_user_id(), 'An approved member was signed out.' );

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_SUSPENDED );
		Context::flush();

		$location = $this->catch_redirect(
			static function () use ( $gate ) {
				$gate->end_open_session();
			}
		);

		$this->assertSame( 0, get_current_user_id(), 'The suspended member kept their session.' );
		$this->assertStringContainsString( LoginGate::QUERY_VAR . '=' . LoginGate::REASON_INACTIVE, (string) $location );
	}

	/**
	 * The registration page explains itself when it is sent somebody's own reason.
	 */
	public function testTheRegistrationPageReportsAPendingAccount() {
		$this->set_setting( LoginGate::SETTING, true );

		$_GET[ Registration::PENDING_VAR ] = LoginGate::REASON_PENDING;

		$markup = ( new Registration() )->render();

		$this->assertStringContainsString( 'woap-registration--pending', $markup );
		$this->assertStringContainsString( esc_html( LoginGate::message( LoginGate::REASON_PENDING ) ), $markup );
		$this->assertStringNotContainsString( 'woap-registration-form', $markup, 'The form was shown again underneath the message.' );
	}

	/**
	 * A made-up reason in the URL renders nothing rather than an empty screen.
	 */
	public function testAnUnknownReasonIsIgnored() {
		$this->set_setting( LoginGate::SETTING, true );

		$this->assertSame( '', LoginGate::message( 'made-up' ) );

		$_GET[ Registration::PENDING_VAR ] = 'made-up';

		$this->assertStringContainsString( 'woap-registration-form', ( new Registration() )->render() );
	}

	/**
	 * Ask the gate what it would answer for a resolved user.
	 *
	 * The filter is asserted to be registered rather than fired: WordPress's own
	 * handlers are on it too, and firing them here would test core rather than this.
	 * Priority 100 is the point of it — nothing may answer after the gate.
	 *
	 * @param int $user_id User the credentials resolved to.
	 * @return \WP_User|\WP_Error|null Whatever the gate answered.
	 */
	private function authenticate( $user_id ) {
		$gate = new LoginGate();
		$gate->register();

		$this->assertSame(
			100,
			has_filter( 'authenticate', array( $gate, 'refuse_authentication' ) ),
			'The gate is not the last word on a sign-in.'
		);

		remove_filter( 'authenticate', array( $gate, 'refuse_authentication' ), 100 );
		remove_action( 'init', array( $gate, 'end_open_session' ), 20 );
		remove_action( 'template_redirect', array( $gate, 'explain_ended_session' ) );

		return $gate->refuse_authentication( get_user_by( 'id', $user_id ) );
	}

	/**
	 * Run something that ends in a redirect, and report where it went.
	 *
	 * @param callable $run What to run.
	 * @return string|null Redirect target, or null when nothing redirected.
	 */
	private function catch_redirect( callable $run ) {
		$catch = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $catch );

		try {
			$run();
		} catch ( RedirectException $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}

		return null;
	}
}
