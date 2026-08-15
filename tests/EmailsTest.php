<?php
/**
 * Email tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Emails\Emails;
use WooOrgAccounts\Members\Invitations;

/**
 * The messages the plugin sends, and the one detail that cannot be recovered if it
 * goes missing: the invitation link.
 */
class EmailsTest extends TestCase {

	/**
	 * Messages captured instead of sent.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Intercept wp_mail, and put the notification hooks back.
	 *
	 * WooCommerce instantiates its email classes once per request and each constructor
	 * hooks its own `…_notification` action. The test case restores `$wp_filter` after
	 * every test, so those hooks are stripped again while the WC_Emails singleton
	 * survives — and a singleton that already exists does not re-register anything.
	 * Rebuilding one clean set here is what makes an email test independent of which
	 * test happened to run first.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->sent = array();

		WC()->mailer();

		foreach ( Emails::actions() as $action ) {
			remove_all_actions( $action . '_notification' );
		}

		( new Emails() )->add_classes( array() );

		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 1, 2 );
	}

	/**
	 * Record a message and stop it going anywhere.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $args          The wp_mail() arguments.
	 * @return bool Always true, so nothing is delivered.
	 */
	public function capture( $short_circuit, $args ) {
		unset( $short_circuit );

		$this->sent[] = $args;

		return true;
	}

	/**
	 * The plugin's emails are handed to WooCommerce.
	 */
	public function testEmailClassesAreRegistered() {
		$emails = ( new Emails() )->add_classes( array() );

		$this->assertArrayHasKey( 'WooOrgAccounts_Invitation', $emails );
		$this->assertArrayHasKey( 'WooOrgAccounts_OrganizationApproved', $emails );
		$this->assertArrayHasKey( 'WooOrgAccounts_OrganizationRejected', $emails );
		$this->assertArrayHasKey( 'WooOrgAccounts_NewOrganization', $emails );

		foreach ( $emails as $email ) {
			$this->assertInstanceOf( \WC_Email::class, $email );
		}
	}

	/**
	 * No email class name needs URL-encoding to survive a query string.
	 *
	 * WooCommerce identifies an email to preview by `get_class()`, and its settings
	 * bundle interpolates that into the preview iframe's `src` without encoding it — so
	 * a class name is URL surface, and a namespaced one puts raw backslashes in a query
	 * string. The WordPress-hardening `if ($args ~ ...) { return 403; }` snippets many
	 * hosts run reject those before PHP is reached, which no hook here could answer:
	 * the request never arrives. Hence the four concrete email classes living in the
	 * global namespace, against this plugin's PSR-4 rule everywhere else.
	 *
	 * Asserted over `get_class()` of everything actually registered rather than as a
	 * list of expected names, because the failure is a property of any future email
	 * added to this plugin — and one written back inside `WooOrgAccounts\Emails` would
	 * pass a test that only named today's four.
	 */
	public function testNoRegisteredEmailClassNameNeedsUrlEncoding() {
		$emails = ( new Emails() )->add_classes( array() );

		$this->assertNotEmpty( $emails );

		foreach ( $emails as $email ) {
			$class = get_class( $email );

			$this->assertSame(
				$class,
				rawurlencode( $class ),
				sprintf( '%s has to survive a query string unencoded.', $class )
			);
		}
	}

	/**
	 * The plugin's actions are added to WooCommerce's transactional list.
	 *
	 * Without this WooCommerce never loads its email classes on the request that fires
	 * one of them, and the message is silently not sent.
	 */
	public function testActionsAreRegisteredWithWooCommerce() {
		$actions = ( new Emails() )->add_actions( array( 'woocommerce_order_status_pending_to_processing' ) );

		foreach ( Emails::actions() as $action ) {
			$this->assertContains( $action, $actions );
		}

		$this->assertContains( 'woocommerce_order_status_pending_to_processing', $actions );
	}

	/**
	 * Issuing an invitation emails the link to the address it was issued for.
	 */
	public function testInvitationEmailCarriesTheLink() {
		$organization = $this->make_organization();
		$result       = Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'bob@acme.test', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Acme GmbH', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'woap_invite=' . $result['token'], $this->sent[0]['message'] );
	}

	/**
	 * Approving an organization tells its admins, and only its admins.
	 */
	public function testApprovalEmailGoesToTheAdmins() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->make_member( $organization );

		$this->sent = array();

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_ACTIVE );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( get_user_by( 'id', $admin->get_user_id() )->user_email, $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Acme GmbH', $this->sent[0]['subject'] );
	}

	/**
	 * The approval email is about the customer's account, with the company as context.
	 *
	 * The screens say *account* and the emails said *company*, which is the one place the
	 * framing could still disagree with itself — and the email is what the customer keeps.
	 * The company is still named, because "your account has been approved" on a shop where
	 * one person may hold one account is not, on its own, enough to place.
	 */
	public function testTheApprovalEmailIsAboutTheAccount() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$this->make_member( $organization, Member::ROLE_ADMIN );

		$this->sent = array();

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_ACTIVE );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'Your account', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'Acme GmbH', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'Your account has been approved', $this->sent[0]['message'] );
		$this->assertStringContainsString(
			'Acme GmbH',
			$this->sent[0]['message'],
			'The company is named as what the account is for.'
		);
	}

	/**
	 * The rejection email is off by default: telling somebody no is the shop's call.
	 */
	public function testRejectionEmailIsOffByDefault() {
		$emails = ( new Emails() )->add_classes( array() );

		$this->assertFalse( $emails['WooOrgAccounts_OrganizationRejected']->is_enabled() );
		$this->assertTrue( $emails['WooOrgAccounts_OrganizationApproved']->is_enabled() );
	}

	/**
	 * An organization with no admins produces no email rather than an error.
	 */
	public function testNoAdminsMeansNoEmail() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$this->sent = array();

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_ACTIVE );

		$this->assertCount( 0, $this->sent );
	}

	/**
	 * A new registration is reported to the shop, with a link to review it.
	 */
	public function testNewRegistrationNotifiesTheShop() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$this->sent = array();

		/**
		 * Fired here rather than by registering an organization for real, because the
		 * registration form is the thing under test elsewhere and this test is about
		 * what the shop is told once it has happened.
		 *
		 * @since 0.1.0
		 *
		 * @param \WooOrgAccounts\Data\Organization $organization The new organization.
		 * @param \WooOrgAccounts\Data\Member       $member       Its first admin.
		 */
		do_action( 'woo_org_accounts_organization_registered', $organization, $member );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'Acme GmbH', $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'organization_id=' . $organization->get_id(), $this->sent[0]['message'] );
	}

	/**
	 * Suspending fires no customer email — there is nothing useful to say
	 * automatically, and the two emails that do exist are for approval and rejection.
	 */
	public function testSuspensionSendsNothing() {
		$organization = $this->make_organization();
		$this->make_member( $organization, Member::ROLE_ADMIN );

		$this->sent = array();

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_SUSPENDED );

		$this->assertCount( 0, $this->sent );
	}
}
