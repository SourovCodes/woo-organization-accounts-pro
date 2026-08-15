<?php
/**
 * The plugin's own place in the wp-admin menu.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * One top-level menu, and the parent every screen in this plugin registers against.
 *
 * The screens used to hang off WooCommerce's own menu, one submenu item each and no
 * relation between them: an *Organization Accounts* settings page next to a *Companies*
 * list, with the import reachable from a button on one of them and members, locations and
 * invitations reachable from nowhere at all. Six items into a menu that already has
 * fourteen is also where a shop stops finding any of them.
 *
 * **The top-level slug is the organizations list**, not a slug of its own. WordPress gives a
 * top-level menu a duplicate first submenu item pointing at the same slug, and re-registering
 * that slug as a submenu is how the duplicate gets its real name — the pattern WooCommerce
 * and WordPress core both use. Making the parent its own page instead would mean a landing
 * screen nobody asked for between the menu and the list.
 *
 * **Every page slug this plugin has ever had is kept**, because a slug is a URL. The order
 * column in wp-admin, the Woodmart theme panel, the organization detail screen's own edit,
 * status and delete links, and any bookmark a shop has made all address a screen by
 * `admin.php?page=<slug>`; changing a parent changes none of them, and changing a slug
 * breaks all of them at once and silently.
 *
 * The count bubble is the pending queue. It is the one number worth carrying into the menu:
 * a registration nobody has looked at is a customer who cannot buy anything, and the whole
 * reason the approvals screen exists is that a status filter on a list does not tell anybody
 * there is something waiting.
 */
class Menu {

	/**
	 * The top-level menu slug, which is also the organizations list.
	 */
	const PAGE_SLUG = Organizations::PAGE_SLUG;

	/**
	 * Capability required for every screen under it.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Where the menu sits.
	 *
	 * Just below WooCommerce, which is at 55.5, and above Products at 55.6 — this plugin
	 * describes who the shop sells to, so it belongs beside the shop rather than down among
	 * the site's own tools.
	 */
	const POSITION = 55.55;

	/**
	 * Register the hooks.
	 *
	 * Priority 9, ahead of every screen that registers a submenu against this parent. A
	 * submenu registered before its parent exists is dropped by WordPress without a word.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
	}

	/**
	 * Add the top-level menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			Labels::organizations(),
			$this->title(),
			self::CAPABILITY,
			self::PAGE_SLUG,
			'',
			'dashicons-groups',
			self::POSITION
		);
	}

	/**
	 * The menu title, with the pending count beside it.
	 *
	 * @return string Escaped title, possibly carrying a count bubble.
	 */
	private function title() {
		$pending = self::pending_count();

		if ( 0 === $pending ) {
			return Labels::organizations();
		}

		return sprintf(
			'%1$s <span class="awaiting-mod"><span class="pending-count">%2$s</span></span>',
			esc_html( Labels::organizations() ),
			esc_html( number_format_i18n( $pending ) )
		);
	}

	/**
	 * How many registrations are waiting to be reviewed.
	 *
	 * One `GROUP BY` over the whole table, which is why it is safe to ask on every admin
	 * request: the alternative — counting rows per status — is four queries to print one
	 * number.
	 *
	 * @return int The pending count.
	 */
	public static function pending_count() {
		$counts = OrganizationRepository::counts_by_status();

		return (int) ( $counts[ Organization::STATUS_PENDING ] ?? 0 );
	}
}
