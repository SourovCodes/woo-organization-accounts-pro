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
use WooOrgAccounts\Frontend\AccountHandlers;
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
	 * The list shows every location and no form.
	 */
	public function testLocationsListShowsNoForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$this->make_location( $organization );
		$this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		$markup = $this->render_locations();

		$this->assertStringContainsString( 'Warehouse North', $markup );
		$this->assertStringContainsString( 'Warehouse South', $markup );
		$this->assertStringNotContainsString( 'shipping_address_1', $markup, 'The list should not carry an address form.' );
		$this->assertStringContainsString( MyAccount::LOCATION_VAR . '=new', $markup, 'The list needs a way to add one.' );
	}

	/**
	 * Editing one location shows that one, and not the others.
	 *
	 * The form used to sit underneath the whole list, so editing meant scrolling past
	 * every other location with nothing to say which one was open.
	 */
	public function testEditingOneLocationHidesTheRest() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$north = $this->make_location( $organization );
		$this->make_location( $organization, array( 'name' => 'Warehouse South' ) );

		$_GET[ MyAccount::LOCATION_VAR ] = (string) $north->get_id();

		$markup = $this->render_locations();

		$this->assertStringContainsString( 'Warehouse North', $markup );
		$this->assertStringNotContainsString( 'Warehouse South', $markup, 'The other locations should not be on the edit screen.' );
		$this->assertStringContainsString( 'shipping_address_1', $markup );
		$this->assertStringContainsString( 'value="' . $north->get_id() . '"', $markup );
	}

	/**
	 * The add screen is the same form with nothing in it.
	 */
	public function testAddingALocationShowsAnEmptyForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$this->make_location( $organization );

		$_GET[ MyAccount::LOCATION_VAR ] = 'new';

		$markup = $this->render_locations();

		$this->assertStringContainsString( 'shipping_address_1', $markup );
		$this->assertStringNotContainsString( 'Warehouse North', $markup );
		$this->assertStringContainsString( 'name="woap_location_id" value="0"', $markup );
	}

	/**
	 * Another organization's location cannot be opened by guessing its ID.
	 */
	public function testEditingAForeignLocationIsRefused() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->act_as( $this->make_member( $ours, Member::ROLE_ADMIN ) );
		$foreign = $this->make_location( $theirs, array( 'name' => 'Rival Depot' ) );

		$_GET[ MyAccount::LOCATION_VAR ] = (string) $foreign->get_id();

		$markup = $this->render_locations();

		$this->assertStringNotContainsString( 'Rival Depot', $markup );
		$this->assertStringNotContainsString( 'shipping_address_1', $markup, 'A foreign location must not open its form.' );
	}

	/**
	 * The form posts back to itself, so a rejected edit stays an edit.
	 *
	 * Posting to the plain endpoint instead loses which location was being changed, and
	 * saving again would add a second one rather than correcting the first.
	 */
	public function testTheFormPostsBackToItself() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$north = $this->make_location( $organization );

		$_GET[ MyAccount::LOCATION_VAR ] = (string) $north->get_id();

		$markup = $this->render_locations();

		$this->assertStringContainsString(
			esc_url( MyAccount::location_form_url( $north->get_id() ) ),
			$markup
		);
	}

	/**
	 * A rejected submission comes back on the form, filled in, with the bad field flagged.
	 */
	public function testRejectedSubmissionReturnsToTheFormWithTheFieldFlagged() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );
		$north = $this->make_location( $organization );

		$_GET[ MyAccount::LOCATION_VAR ] = (string) $north->get_id();

		$_POST = array(
			AccountHandlers::ACTION_FIELD => 'save_location',
			'_wpnonce'                    => wp_create_nonce( 'woap_save_location' ),
			'woap_location_id'            => $north->get_id(),
			'woap_name'                   => 'Renamed Depot',
			'shipping_first_name'         => 'Grace',
			'shipping_last_name'          => 'Hopper',
			'shipping_country'            => 'DE',
			'shipping_city'               => 'Hamburg',
			'shipping_phone'              => '+49 40 1',
		);

		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building the request the handler verifies; the nonce is set above.

		( new AccountHandlers() )->dispatch();

		$markup = $this->render_locations();

		// Still the edit screen, still that location, still the other one hidden.
		$this->assertStringContainsString( 'name="woap_location_id" value="' . $north->get_id() . '"', $markup );

		// What was typed survived.
		$this->assertStringContainsString( 'value="Renamed Depot"', $markup );
		$this->assertStringContainsString( 'value="Grace"', $markup );

		// And the offending field is flagged where the eye is, not only at the top.
		$this->assertStringContainsString( 'woocommerce-invalid', $markup );

		wc_clear_notices();
	}

	/**
	 * Render the locations endpoint and give back its markup.
	 *
	 * @return string Markup.
	 */
	private function render_locations() {
		ob_start();
		( new MyAccount() )->render_organization_locations();

		return (string) ob_get_clean();
	}

	/**
	 * Render the members endpoint and give back its markup.
	 *
	 * @return string Markup.
	 */
	private function render_members() {
		ob_start();
		( new MyAccount() )->render_organization_members();

		return (string) ob_get_clean();
	}

	/**
	 * The member list is a list, with no form on it.
	 *
	 * It used to be one `<details>` per member, each holding a form of a role, a
	 * status, seven permission checkboxes and a checkbox per location — so an account
	 * with fifty employees shipped fifty forms to answer "who works here?", and the
	 * answer itself was hidden inside them.
	 */
	public function testMemberListShowsNoForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$other = $this->make_member( $organization, Member::ROLE_MEMBER );

		$markup = $this->render_members();

		$this->assertStringNotContainsString( 'name="woap_capabilities[]"', $markup, 'The list should not carry a permissions form.' );
		$this->assertStringNotContainsString( 'value="update_member"', $markup );
		$this->assertStringContainsString( MyAccount::MEMBER_VAR . '=' . $other->get_id(), $markup, 'The list needs a way to open one member.' );
	}

	/**
	 * Managing one member shows that one, and the form that changes them.
	 */
	public function testManagingOneMemberOpensTheirForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		$other = $this->make_member( $organization, Member::ROLE_MEMBER );

		$_GET[ MyAccount::MEMBER_VAR ] = (string) $other->get_id();

		$markup = $this->render_members();

		$this->assertStringContainsString( 'value="update_member"', $markup );
		$this->assertStringContainsString( 'name="woap_capabilities[]"', $markup );
		$this->assertStringContainsString( 'name="woap_member_id" value="' . $other->get_id() . '"', $markup );
		$this->assertStringContainsString( esc_url( MyAccount::member_form_url( $other->get_id() ) ), $markup );
	}

	/**
	 * The screen never offers to remove the person using it.
	 *
	 * The handler refuses it too, but an account admin should not be given a button
	 * whose only outcome is a refusal.
	 */
	public function testYouCannotRemoveYourself() {
		$organization = $this->make_organization();
		$self         = $this->make_member( $organization, Member::ROLE_ADMIN );
		$this->act_as( $self );

		$_GET[ MyAccount::MEMBER_VAR ] = (string) $self->get_id();

		$markup = $this->render_members();

		$this->assertStringContainsString( 'value="update_member"', $markup );
		$this->assertStringNotContainsString( 'value="remove_member"', $markup );
	}

	/**
	 * Another organization's member cannot be opened by guessing their ID.
	 */
	public function testManagingAForeignMemberIsRefused() {
		$ours   = $this->make_organization();
		$theirs = $this->make_organization( array( 'name' => 'Rival Ltd' ) );

		$this->act_as( $this->make_member( $ours, Member::ROLE_ADMIN ) );
		$foreign = $this->make_member( $theirs, Member::ROLE_MEMBER );

		$_GET[ MyAccount::MEMBER_VAR ] = (string) $foreign->get_id();

		$markup = $this->render_members();

		$this->assertStringNotContainsString( 'name="woap_member_id" value="' . $foreign->get_id() . '"', $markup );
		$this->assertStringNotContainsString( 'value="update_member"', $markup, 'A foreign member must not open their form.' );

		wc_clear_notices();
	}

	/**
	 * Render the invitations endpoint and give back its markup.
	 *
	 * @return string Markup.
	 */
	private function render_invitations() {
		ob_start();
		( new MyAccount() )->render_organization_invitations();

		return (string) ob_get_clean();
	}

	/**
	 * The invitation list is a list, with the form on a screen of its own.
	 *
	 * The form used to fold away above the list, which made the primary "Invite
	 * somebody" button on the members screen promise a form and deliver a list with
	 * that form shut.
	 */
	public function testInvitationListShowsNoForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		\WooOrgAccounts\Members\Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$markup = $this->render_invitations();

		$this->assertStringContainsString( 'bob@acme.test', $markup );
		$this->assertStringNotContainsString( 'value="invite_member"', $markup, 'The list should not carry the invite form.' );
		$this->assertStringContainsString( MyAccount::INVITE_VAR . '=new', $markup, 'The list needs a way to send one.' );
	}

	/**
	 * Asking for the invitation form gets the form, and not the list.
	 */
	public function testInviteScreenShowsTheForm() {
		$organization = $this->make_organization();
		$this->act_as( $this->make_member( $organization, Member::ROLE_ADMIN ) );

		\WooOrgAccounts\Members\Invitations::create( $organization->get_id(), 'bob@acme.test', Member::ROLE_MEMBER );

		$_GET[ MyAccount::INVITE_VAR ] = 'new';

		$markup = $this->render_invitations();

		$this->assertStringContainsString( 'value="invite_member"', $markup );
		$this->assertStringContainsString( 'name="woap_email"', $markup );
		$this->assertStringNotContainsString( 'bob@acme.test', $markup, 'The list should not be on the form screen.' );
		$this->assertStringContainsString( esc_url( MyAccount::invite_form_url() ), $markup, 'The form must post back to itself.' );
	}

	/**
	 * Every navigation icon is a code point woodmart-font actually assigns.
	 *
	 * The font is not a complete range, and an unassigned code point renders as
	 * nothing at all — the item keeps its space in the menu and shows no icon, which
	 * is what `\f132` did to Invitations. The font ships with the theme and cannot be
	 * installed in CI, so this asserts the weaker thing that can be checked anywhere:
	 * every icon is one of the code points the theme is known to assign.
	 */
	public function testNavigationIconsAreGlyphsTheThemeAssigns() {
		/*
		 * Read off Woodmart 8.5's own stylesheet — the toolbar shop icon, the
		 * my-account icon, `.wd-init-map`, `.social-email` and the theme's own Orders
		 * item. Re-check with `grep -c '\\fXXX' woodmart/css/style.min.css` before
		 * adding to this list.
		 */
		$assigned = array( '\f146', '\f124', '\f183', '\f157', '\f138' );

		foreach ( MyAccount::nav_icons() as $endpoint => $glyph ) {
			$this->assertContains(
				$glyph,
				$assigned,
				$endpoint . ' uses ' . $glyph . ', which woodmart-font may not assign — it would render as no icon at all.'
			);
		}
	}

	/**
	 * `?action=register` on My Account goes to the registration page.
	 *
	 * It is Woodmart's own signal for "show me the register side" — the header
	 * dropdown's *Create an Account* link and the login page's button both use it — and
	 * WooCommerce's registration is switched off while this plugin is active. Without
	 * the redirect the visitor asks to sign up and the site answers by asking them to
	 * sign in.
	 */
	public function testRegisterActionGoesToTheRegistrationPage() {
		wp_set_current_user( 0 );

		$page_id = Registration::create_page();

		$_GET['action']            = 'register';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertSame(
			get_permalink( $page_id ),
			$this->account_redirect(),
			'?action=register still lands on the login form.'
		);
	}

	/**
	 * Somebody already signed in is sent to their account rather than to a sign-up form.
	 */
	public function testRegisterActionForASignedInVisitor() {
		$this->act_as( $this->make_member( $this->make_organization() ) );

		Registration::create_page();

		$_GET['action']            = 'register';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertSame( wc_get_page_permalink( 'myaccount' ), $this->account_redirect() );
	}

	/**
	 * Any other account screen is left alone.
	 */
	public function testAccountPagesWithoutTheArgumentAreLeftAlone() {
		wp_set_current_user( 0 );

		Registration::create_page();

		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertSame( '', $this->account_redirect() );
	}

	/**
	 * Where an account-page request is sent, if anywhere.
	 *
	 * `is_account_page()` asks the main query a question a unit test has no page to
	 * answer with, so the filter WooCommerce provides for exactly that stands in.
	 *
	 * @return string Redirect target, or an empty string when the request was left alone.
	 */
	private function account_redirect() {
		$catch = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'woocommerce_is_account_page', '__return_true' );
		add_filter( 'wp_redirect', $catch );

		try {
			( new Registration() )->redirect_register_action();
		} catch ( RedirectException $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $catch );
			remove_filter( 'woocommerce_is_account_page', '__return_true' );
		}

		return '';
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
	 * The honeypot is hidden by the markup itself, not by a stylesheet.
	 *
	 * It used to be hidden only by a rule in account.css, which is enqueued on the My
	 * Account endpoints and nowhere else — so on the registration page, the one page
	 * the trap is actually on, it rendered as a visible field labelled "Leave this
	 * field empty". A honeypot real customers can see is one they fill in, and every
	 * submission that fills it is discarded.
	 */
	public function testTheHoneypotIsHiddenWithoutAStylesheet() {
		wp_set_current_user( 0 );

		$markup = do_shortcode( '[' . Registration::SHORTCODE . ']' );

		$this->assertMatchesRegularExpression(
			'/class="woap-honeypot"[^>]*style="[^"]*position:\s*absolute/',
			$markup,
			'The honeypot is not hidden by an inline style.'
		);

		$css = file_get_contents( WOAP_PLUGIN_DIR . 'assets/css/account.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.

		$this->assertStringNotContainsString(
			'.woap-honeypot',
			$css,
			'Hiding the honeypot has two homes again; the stylesheet copy is the one that does not load where the trap is.'
		);
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
