<?php
/**
 * The plugin's place in the wp-admin menu, and the approvals queue.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Approvals;
use WooOrgAccounts\Admin\Import;
use WooOrgAccounts\Admin\Members;
use WooOrgAccounts\Admin\Menu;
use WooOrgAccounts\Admin\Organizations;
use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

/**
 * One menu, and every screen that used to hang off WooCommerce's.
 *
 * The assertion worth having here is not that the menu exists but that **nothing moved**:
 * a page slug is a URL, and the order column, the Woodmart theme panel and anybody's
 * bookmarks all address a screen by `admin.php?page=<slug>`. Reparenting is invisible;
 * renaming would break every one of them silently, because a bad `page` argument renders
 * an empty screen rather than a 404.
 */
class AdminMenuTest extends TestCase {

	/**
	 * Build the menu the way wp-admin does.
	 *
	 * @return void
	 */
	private function build_menu() {
		global $menu, $submenu, $_registered_pages;

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();

		$this->act_as_shop_manager();

		/*
		 * Registered the way the plugin registers them, then fired through the real hook,
		 * because **the order is the thing most likely to be wrong** and calling the
		 * methods in a convenient order would hide exactly that. The parent goes on at 8
		 * and the organizations list at 9 so its same-slug entry lands before any other;
		 * with Settings first, WordPress auto-inserts a second copy of the parent above it
		 * and the menu carries two entries for one screen.
		 */
		( new Menu() )->register();
		( new Settings() )->register();
		( new Organizations() )->register();
		( new Approvals() )->register();
		( new Members() )->register();
		( new Import() )->register();

		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing WordPress's own hook to build the menu exactly as wp-admin does.
	}

	/**
	 * The slugs registered under the plugin's own menu.
	 *
	 * @return string[] Page slugs.
	 */
	private function submenu_slugs() {
		global $submenu;

		return array_map(
			static function ( $item ) {
				return $item[2];
			},
			(array) ( $submenu[ Menu::PAGE_SLUG ] ?? array() )
		);
	}

	/**
	 * There is exactly one top-level menu, and it is the organizations list.
	 *
	 * @return void
	 */
	public function testThePluginAddsOneTopLevelMenu() {
		global $menu;

		$this->build_menu();

		$ours = array_filter(
			(array) $menu,
			static function ( $item ) {
				return isset( $item[2] ) && false !== strpos( (string) $item[2], 'woo-organization-accounts' );
			}
		);

		$this->assertCount( 1, $ours, 'The plugin must add one top-level menu, not one per screen.' );
		$this->assertSame( Menu::PAGE_SLUG, reset( $ours )[2] );
	}

	/**
	 * Every screen this plugin has ever had keeps its slug.
	 *
	 * The list is written out rather than derived, deliberately: this is the one test that
	 * should fail loudly when somebody renames a page, and deriving it from the constants
	 * would make it agree with any rename.
	 *
	 * @return void
	 */
	public function testTheOrganizationsListAppearsOnceInTheMenu() {
		$this->build_menu();

		$slugs = $this->submenu_slugs();

		$this->assertSame(
			1,
			count( array_keys( $slugs, Organizations::PAGE_SLUG, true ) ),
			'WordPress copies the parent into its own submenu — bubble and all — the first time a submenu with a different slug arrives. Two entries for one screen is what that looks like.'
		);

		$this->assertSame(
			Organizations::PAGE_SLUG,
			reset( $slugs ),
			'The list has to be the first submenu, or the copy happens before it lands.'
		);
	}

	/**
	 * Every screen this plugin has ever had keeps its slug.
	 *
	 * The list is written out rather than derived, deliberately: this is the one test that
	 * should fail loudly when somebody renames a page, and deriving it from the constants
	 * would make it agree with any rename.
	 *
	 * @return void
	 */
	public function testEveryScreenKeepsTheSlugItHadUnderWooCommerce() {
		$this->build_menu();

		$slugs = $this->submenu_slugs();

		foreach (
			array(
				'woo-organization-accounts-list',
				'woo-organization-accounts',
				'woo-organization-accounts-import',
			) as $slug
		) {
			$this->assertContains( $slug, $slugs, 'A page slug is a URL. Reparenting a screen must not move it.' );
		}
	}

	/**
	 * Nothing of ours is left under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function testNothingIsLeftUnderTheWooCommerceMenu() {
		global $submenu;

		$this->build_menu();

		$stragglers = array_filter(
			(array) ( $submenu['woocommerce'] ?? array() ),
			static function ( $item ) {
				return isset( $item[2] ) && false !== strpos( (string) $item[2], 'woo-organization-accounts' );
			}
		);

		$this->assertSame( array(), $stragglers );
	}

	/**
	 * The import stays registered and stays out of the menu.
	 *
	 * A permanent menu item for something a shop does once is clutter on every other day,
	 * and taking it out with `remove_submenu_page()` during `admin_menu` is what made the
	 * screen answer 403 to everybody. It is unset on `admin_head` instead — so the entry is
	 * present when the menu is built and gone by the time it is printed.
	 *
	 * @return void
	 */
	public function testTheImportIsRegisteredButHidden() {
		global $submenu;

		$this->build_menu();

		$this->assertContains( Import::PAGE_SLUG, $this->submenu_slugs() );

		set_current_screen( 'toplevel_page_' . Menu::PAGE_SLUG );

		( new Import() )->hide_from_menu();

		$remaining = array_map(
			static function ( $item ) {
				return $item[2];
			},
			(array) ( $submenu[ Menu::PAGE_SLUG ] ?? array() )
		);

		$this->assertNotContains( Import::PAGE_SLUG, $remaining );
	}

	/**
	 * The menu is named for the site's organization mode.
	 *
	 * @return void
	 */
	public function testTheMenuFollowsTheOrganizationMode() {
		global $menu;

		$this->set_setting( 'organization_mode', Labels::MODE_EDUCATION );

		$this->build_menu();

		$ours = array_filter(
			(array) $menu,
			static function ( $item ) {
				return isset( $item[2] ) && Menu::PAGE_SLUG === $item[2];
			}
		);

		$this->assertStringContainsString( 'Institutes', reset( $ours )[0] );
	}

	/**
	 * The pending count is what the bubble reports.
	 *
	 * @return void
	 */
	public function testThePendingCountIsWhatIsWaiting() {
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->make_organization( array( 'status' => Organization::STATUS_ACTIVE ) );

		$this->assertSame( 2, Menu::pending_count() );
	}

	/**
	 * The approvals screen lists exactly what is pending.
	 *
	 * @return void
	 */
	public function testTheQueueListsExactlyWhatIsPending() {
		$this->act_as_shop_manager();

		$waiting = $this->make_organization(
			array(
				'name'   => 'Wurzel Handels AG',
				'status' => Organization::STATUS_PENDING,
			)
		);

		$this->make_organization(
			array(
				'name'   => 'Baumann KG',
				'status' => Organization::STATUS_ACTIVE,
			)
		);

		$this->make_member( $waiting, Member::ROLE_ADMIN );

		ob_start();
		( new Approvals() )->render();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Wurzel Handels AG', $markup );
		$this->assertStringNotContainsString( 'Baumann KG', $markup );
	}

	/**
	 * The queue names the person who registered, not only the company.
	 *
	 * The decision is about a customer, and the screen exists because a row showing a name
	 * and a status pill is not enough to make it on.
	 *
	 * @return void
	 */
	public function testTheQueueNamesTheCustomerWhoRegistered() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		wp_update_user(
			array(
				'ID'           => $member->get_user_id(),
				'display_name' => 'Gudrun Steiner',
				'user_email'   => 'gudrun@wurzel.test',
			)
		);

		ob_start();
		( new Approvals() )->render();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Gudrun Steiner', $markup );
		$this->assertStringContainsString( 'gudrun@wurzel.test', $markup );
		$this->assertStringContainsString(
			'1 Hauptstrasse',
			$markup,
			'The billing address is what a review is checking; it has to be on the card.'
		);
	}

	/**
	 * An empty queue says so, rather than printing a failed search.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueIsGoodNews() {
		$this->act_as_shop_manager();

		$this->make_organization( array( 'status' => Organization::STATUS_ACTIVE ) );

		ob_start();
		( new Approvals() )->render();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nothing waiting', $markup );
		$this->assertStringContainsString( 'woap-empty', $markup );
	}

	/**
	 * Approving from the queue goes through the one door that sends the email.
	 *
	 * Both buttons are the existing nonced status URL, so there is still exactly one thing
	 * approving means — and exactly one place the approval mail fires from.
	 *
	 * @return void
	 */
	public function testApprovingFromTheQueueFiresTheStatusHookOnce() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$fired = 0;

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$_GET = array(
			'organization_id' => $organization->get_id(),
			'status'          => Organization::STATUS_ACTIVE,
			'woap_return'     => Approvals::PAGE_SLUG,
			'_wpnonce'        => wp_create_nonce( 'woap_admin_set_status_' . $organization->get_id() ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler will verify, nonce included.
		$_REQUEST = $_GET;

		$throw = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $throw );

		$landed = '';

		try {
			( new Organizations() )->handle_set_status();
		} catch ( RedirectException $redirect ) {
			$landed = $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}

		$this->assertSame( 1, $fired );
		$this->assertSame(
			Organization::STATUS_ACTIVE,
			OrganizationRepository::find( $organization->get_id() )->get_status()
		);
		$this->assertStringContainsString(
			Approvals::PAGE_SLUG,
			$landed,
			'A reviewer working the queue wants the next one, not the record they just finished with.'
		);
	}
}
