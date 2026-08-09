<?php
/**
 * My Account integration.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the organization screens to WooCommerce's My Account area.
 *
 * They are WooCommerce endpoints rather than pages of their own, so they inherit the
 * account navigation, the theme's account layout and the login requirement without
 * any of that being re-implemented. Each one is hidden from members who lack the
 * capability behind it — an ordinary member sees their own orders and nothing else,
 * and the endpoint refuses them even if they type the URL.
 */
class MyAccount {

	/**
	 * Endpoint showing the organization profile and its billing address.
	 */
	const ENDPOINT_PROFILE = 'organization';

	/**
	 * Endpoint listing the organization's members.
	 */
	const ENDPOINT_MEMBERS = 'organization-members';

	/**
	 * Endpoint listing the organization's locations.
	 */
	const ENDPOINT_LOCATIONS = 'organization-locations';

	/**
	 * Endpoint listing outstanding invitations.
	 */
	const ENDPOINT_INVITATIONS = 'organization-invitations';

	/**
	 * Endpoint listing every order the organization has placed.
	 */
	const ENDPOINT_ORDERS = 'organization-orders';

	/**
	 * Query variable naming the location being added or edited.
	 *
	 * Holds a location ID, or `new`. Its absence is what means "show the list".
	 */
	const LOCATION_VAR = 'woap_location';

	/**
	 * Query variable naming the member being managed.
	 *
	 * Holds a member ID. Its absence is what means "show the list".
	 */
	const MEMBER_VAR = 'woap_member';

	/**
	 * Query variable that asks for the invitation form.
	 *
	 * Holds `new`. Its absence is what means "show the list".
	 */
	const INVITE_VAR = 'woap_invite';

	/**
	 * Every endpoint, mapped to the capability that reveals it.
	 *
	 * @return array Map of endpoint to capability.
	 */
	public static function endpoints() {
		return array(
			self::ENDPOINT_PROFILE     => Roles::MANAGE_ORGANIZATION,
			self::ENDPOINT_MEMBERS     => Roles::MANAGE_MEMBERS,
			self::ENDPOINT_LOCATIONS   => Roles::MANAGE_LOCATIONS,
			self::ENDPOINT_INVITATIONS => Roles::INVITE_MEMBERS,
			self::ENDPOINT_ORDERS      => Roles::VIEW_ORGANIZATION_ORDERS,
		);
	}

	/**
	 * The menu label for each endpoint, following the site's organization mode.
	 *
	 * @return array Map of endpoint to translated label.
	 */
	public static function menu_labels() {
		return array(
			self::ENDPOINT_PROFILE     => Labels::organization(),
			self::ENDPOINT_MEMBERS     => Labels::members(),
			self::ENDPOINT_LOCATIONS   => Labels::locations(),
			self::ENDPOINT_INVITATIONS => __( 'Invitations', 'woo-organization-accounts-pro' ),
			self::ENDPOINT_ORDERS      => sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( '%s orders', 'woo-organization-accounts-pro' ),
				Labels::organization()
			),
		);
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_items' ) );

		/*
		 * Before WC_Form_Handler::save_address(), which is on the same action at the
		 * default priority. Refusing after it would mean refusing the screen and
		 * keeping what it submitted.
		 */
		add_action( 'template_redirect', array( $this, 'refuse_address_endpoint' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_account_content', array( $this, 'enqueue_theme_parts' ), 5 );

		foreach ( array_keys( self::endpoints() ) as $endpoint ) {
			add_action( 'woocommerce_account_' . $endpoint . '_endpoint', array( $this, 'render_' . str_replace( '-', '_', $endpoint ) ) );
			add_filter( 'woocommerce_endpoint_' . $endpoint . '_title', array( $this, 'endpoint_title' ), 10, 2 );
		}
	}

	/**
	 * The Woodmart icon-font glyph for each endpoint's navigation item.
	 *
	 * Woodmart tags every account menu item with `wd-my-acc-<endpoint>` and draws the
	 * item's icon from `--wd-my-acc-nav-icon` on that class. It defines the variable
	 * for its own endpoints only, so without this the plugin's five items all fall
	 * back to the theme's generic glyph and the menu reads as five copies of one item.
	 *
	 * **Every code point here has to be one the theme itself uses.** woodmart-font is
	 * not a complete range: an unassigned code point renders as nothing at all, and the
	 * item ends up with an empty square of space where the other four have an icon —
	 * which is exactly what `\f132` did to Invitations. There is no way to assert this
	 * in CI, because the font ships with the theme and the theme cannot be installed
	 * there, so check a new one against the theme's own stylesheet first:
	 *
	 *     grep -c '\\f157' wp-content/themes/woodmart/css/style.min.css
	 *
	 * Each of these is borrowed from the theme rule that already draws it: the shop
	 * toolbar icon, the my-account icon, `.wd-init-map`, `.social-email` and Woodmart's
	 * own Orders item.
	 *
	 * @return array Map of endpoint to woodmart-font code point.
	 */
	public static function nav_icons() {
		return array(
			self::ENDPOINT_PROFILE     => '\f146',
			self::ENDPOINT_MEMBERS     => '\f124',
			self::ENDPOINT_LOCATIONS   => '\f183',
			self::ENDPOINT_INVITATIONS => '\f157',
			self::ENDPOINT_ORDERS      => '\f138',
		);
	}

	/**
	 * Tell WordPress about the endpoints.
	 *
	 * Static because activation calls it directly, before flushing the rewrite rules.
	 *
	 * @return void
	 */
	public static function add_endpoints() {
		foreach ( array_keys( self::endpoints() ) as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * Tell WooCommerce the endpoints belong to My Account.
	 *
	 * @param array $vars WooCommerce query variables.
	 * @return array Variables, with ours added.
	 */
	public function add_query_vars( $vars ) {
		foreach ( array_keys( self::endpoints() ) as $endpoint ) {
			$vars[ $endpoint ] = $endpoint;
		}

		return $vars;
	}

	/**
	 * Add the organization screens to the account navigation.
	 *
	 * Inserted after Orders and before Addresses, which is where a customer looks for
	 * "things about my account" rather than "things I bought".
	 *
	 * @param array $items Menu items.
	 * @return array Items, with ours inserted.
	 */
	public function add_menu_items( $items ) {
		if ( null === Context::member() ) {
			return $items;
		}

		/*
		 * The organization's address is the organization's, so WooCommerce's own
		 * Addresses screen would offer to edit something the checkout ignores.
		 */
		unset( $items['edit-address'] );

		$ours = array();

		foreach ( self::endpoints() as $endpoint => $capability ) {
			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			$ours[ $endpoint ] = self::menu_labels()[ $endpoint ];
		}

		if ( empty( $ours ) ) {
			return $items;
		}

		$position = array_search( 'orders', array_keys( $items ), true );
		$position = ( false === $position ) ? 1 : $position + 1;

		return array_merge(
			array_slice( $items, 0, $position, true ),
			$ours,
			array_slice( $items, $position, null, true )
		);
	}

	/**
	 * Refuse WooCommerce's own address screen to anybody in an organization.
	 *
	 * A member has no billing address and no shipping address of their own. Billing is
	 * the organization's, written onto every order by `Checkout\BillingLock`; shipping
	 * is a location, resolved by `Checkout\ShippingSelector`. Both discard whatever is
	 * stored against the WordPress user, so WooCommerce's `edit-address` screen accepted
	 * a twelve-field address, reported "Address changed successfully" and changed
	 * nothing that would ever appear on an order.
	 *
	 * **Unsetting the menu item was never enough.** `add_menu_items()` removes
	 * `edit-address` from one array, which decides what the account sidebar prints and
	 * nothing else: the endpoint is a rewrite rule WooCommerce registers at `init`, and
	 * the URL still routed, still rendered and still saved. WooCommerce's own
	 * `dashboard.php` also prints a live link to it in its opening sentence, so the
	 * screen was reachable from the first page a member lands on without going near the
	 * menu.
	 *
	 * Refused at request time rather than by blanking the endpoint's slug option, which
	 * is how the plugin switches off registration. An endpoint slug is baked into the
	 * site's rewrite rules, and those are shared and cached: making one conditional on
	 * who is asking would leave the rules dependent on whoever happened to flush them.
	 *
	 * Non-members are left alone. An administrator, an author or a plain customer on a
	 * shop that also sells to individuals still has an address of their own.
	 *
	 * @return void
	 */
	public function refuse_address_endpoint() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		if ( null === Context::member() ) {
			return;
		}

		if ( current_user_can( Roles::MANAGE_BILLING ) ) {
			$destination = wc_get_account_endpoint_url( self::ENDPOINT_PROFILE );

			wc_add_notice(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					esc_html__( 'Addresses belong to your %s rather than to your own account, and are managed here.', 'woo-organization-accounts-pro' ),
					esc_html( Labels::organization() )
				),
				'notice'
			);
		} else {
			$destination = wc_get_page_permalink( 'myaccount' );

			wc_add_notice(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					esc_html__( 'Addresses belong to your %s rather than to your own account.', 'woo-organization-accounts-pro' ),
					esc_html( Labels::organization() )
				),
				'notice'
			);
		}

		if ( ! is_string( $destination ) || '' === $destination ) {
			return;
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Load WooCommerce's country and address scripts on the screens with an address.
	 *
	 * Hooked on the query variable rather than on `is_account_page()`, which asks
	 * WooCommerce a question it memoises the first time anything asks it. The endpoints
	 * only exist on the account page, so their presence is answer enough.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query ) {
			return;
		}

		$this->enqueue_nav_icons();

		$ours = array_intersect( array_keys( self::endpoints() ), array_keys( (array) $wp_query->query_vars ) );

		if ( empty( $ours ) ) {
			return;
		}

		wp_enqueue_script(
			'woap-account',
			WOAP_PLUGIN_URL . 'assets/js/account.js',
			array(),
			WOAP_VERSION,
			true
		);

		wp_enqueue_style( 'woap-account', WOAP_PLUGIN_URL . 'assets/css/account.css', array(), WOAP_VERSION );

		/*
		 * Only where an address form is actually rendered. The locations *list* has no
		 * form on it, so loading WooCommerce's country and state scripts there would be
		 * two requests and a JSON blob of every country's locale for a table.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deciding which assets a read-only screen needs.
		$editing_location = in_array( self::ENDPOINT_LOCATIONS, $ours, true ) && isset( $_GET[ self::LOCATION_VAR ] );

		if ( $editing_location || in_array( self::ENDPOINT_PROFILE, $ours, true ) ) {
			AddressFields::enqueue();
		}
	}

	/**
	 * Ask Woodmart for the parts these screens borrow.
	 *
	 * On `woocommerce_account_content` rather than `wp_enqueue_scripts`, because a part
	 * asked for that early lands ahead of the theme's own `base.css` and loses the ties
	 * that source order settles — see `Templates::enqueue_theme_parts()`. This action
	 * fires while the account content renders, just before the endpoint callbacks.
	 *
	 * @return void
	 */
	public function enqueue_theme_parts() {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query ) {
			return;
		}

		$ours = array_intersect( array_keys( self::endpoints() ), array_keys( (array) $wp_query->query_vars ) );

		if ( empty( $ours ) ) {
			return;
		}

		Templates::enqueue_theme_parts( 'woo-mod-shop-table', 'mod-notices-general' );
	}

	/**
	 * Give the plugin's navigation items their Woodmart icons.
	 *
	 * On every account screen, not only the plugin's own: the menu lists the
	 * organization items wherever it is drawn, so an icon that only appeared once the
	 * customer was already on one of those screens would be missing exactly where it
	 * is needed to get there.
	 *
	 * Emitted inline rather than shipped as a stylesheet so the endpoint slugs come
	 * from the constants above and cannot drift from a hand-maintained copy.
	 *
	 * @return void
	 */
	private function enqueue_nav_icons() {
		if ( ! is_page( wc_get_page_id( 'myaccount' ) ) ) {
			return;
		}

		$rules = '';

		foreach ( self::nav_icons() as $endpoint => $glyph ) {
			// Both halves are class constants, never a request value.
			$rules .= sprintf( '.wd-my-acc-%1$s{--wd-my-acc-nav-icon:"%2$s";}', $endpoint, $glyph );
		}

		wp_register_style( 'woap-account-nav', false, array(), WOAP_VERSION );
		wp_enqueue_style( 'woap-account-nav' );
		wp_add_inline_style( 'woap-account-nav', $rules );
	}

	/**
	 * The page title for one of our endpoints.
	 *
	 * @param string $title    Title so far.
	 * @param string $endpoint Endpoint being displayed.
	 * @return string Title.
	 */
	public function endpoint_title( $title, $endpoint ) {
		$labels = self::menu_labels();

		return isset( $labels[ $endpoint ] ) ? $labels[ $endpoint ] : $title;
	}

	/**
	 * Render the organization profile and billing screen.
	 *
	 * @return void
	 */
	public function render_organization() {
		$organization = self::guarded_organization( Roles::MANAGE_ORGANIZATION );

		if ( null === $organization ) {
			return;
		}

		Templates::render(
			'myaccount/organization.php',
			array(
				'organization' => $organization,
				'can_billing'  => current_user_can( Roles::MANAGE_BILLING ),
				'overview'     => self::overview( $organization ),
			)
		);
	}

	/**
	 * What the other organization screens hold, for the profile screen to report.
	 *
	 * The profile is the first item in the account menu and the one every organization
	 * admin lands on, so it doubles as the overview: how many people can order, how
	 * many places they can order to, and whether anything is waiting. Only the screens
	 * this member may open are counted — a tile leading to a refusal is worse than no
	 * tile, and the count itself would leak how many people are on an account somebody
	 * has no business administering.
	 *
	 * @param \WooOrgAccounts\Data\Organization $organization Organization to describe.
	 * @return array List of tiles, each with a label, a count and a URL.
	 */
	private static function overview( $organization ) {
		$tiles = array();

		if ( current_user_can( Roles::MANAGE_MEMBERS ) ) {
			$tiles[] = array(
				'label' => Labels::members(),
				'count' => MemberRepository::count_for_organization( $organization->get_id() ),
				'url'   => self::members_url(),
			);
		}

		if ( current_user_can( Roles::MANAGE_LOCATIONS ) ) {
			$tiles[] = array(
				'label' => Labels::locations(),
				'count' => LocationRepository::count_for_organization( $organization->get_id() ),
				'url'   => self::locations_url(),
			);
		}

		if ( current_user_can( Roles::INVITE_MEMBERS ) ) {
			$tiles[] = array(
				'label' => __( 'Invitations waiting', 'woo-organization-accounts-pro' ),
				'count' => InvitationRepository::count_for_organization( $organization->get_id(), Invitation::STATUS_PENDING ),
				'url'   => wc_get_account_endpoint_url( self::ENDPOINT_INVITATIONS ),
			);
		}

		if ( current_user_can( Roles::VIEW_ORGANIZATION_ORDERS ) ) {
			$tiles[] = array(
				'label' => __( 'Orders', 'woo-organization-accounts-pro' ),
				'count' => self::organization_orders( $organization->get_id(), 1, 1 )['total'],
				'url'   => wc_get_account_endpoint_url( self::ENDPOINT_ORDERS ),
			);
		}

		return $tiles;
	}

	/**
	 * Render the members screen.
	 *
	 * @return void
	 */
	public function render_organization_members() {
		$organization = self::guarded_organization( Roles::MANAGE_MEMBERS );

		if ( null === $organization ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view to render; the write itself is nonce-checked when submitted.
		$requested = isset( $_GET[ self::MEMBER_VAR ] ) ? absint( wp_unslash( $_GET[ self::MEMBER_VAR ] ) ) : 0;
		$locations = LocationRepository::for_organization( $organization->get_id() );

		/*
		 * One member is a screen of its own, for the reasons the location form is:
		 * everything about that person in one place, a rejected submission that comes
		 * back knowing who it was for, and a list that stays a list. It used to be a
		 * stack of accordions, every one of them holding a fifteen-control form, so a
		 * hundred-employee account shipped a hundred forms to say who works there.
		 */
		if ( $requested > 0 ) {
			$member = MemberRepository::find_for_organization( $requested, $organization->get_id() );

			if ( null !== $member ) {
				Templates::render(
					'myaccount/member-form.php',
					array(
						'organization' => $organization,
						'member'       => $member,
						'user'         => get_user_by( 'id', $member->get_user_id() ),
						'access'       => MemberRepository::location_ids( $member->get_id() ),
						'locations'    => $locations,
						'is_self'      => $member->get_user_id() === get_current_user_id(),
					)
				);

				return;
			}

			wc_print_notice( esc_html__( 'That member no longer exists.', 'woo-organization-accounts-pro' ), 'error' );
		}

		$members = MemberRepository::for_organization( $organization->get_id() );
		$users   = array();
		$access  = array();

		foreach ( $members as $member ) {
			$users[ $member->get_id() ]  = get_user_by( 'id', $member->get_user_id() );
			$access[ $member->get_id() ] = MemberRepository::location_ids( $member->get_id() );
		}

		Templates::render(
			'myaccount/members.php',
			array(
				'organization' => $organization,
				'members'      => $members,
				'users'        => $users,
				'access'       => $access,
				'locations'    => $locations,
				'current_user' => get_current_user_id(),
				'pending'      => current_user_can( Roles::INVITE_MEMBERS )
					? InvitationRepository::count_for_organization( $organization->get_id(), Invitation::STATUS_PENDING )
					: 0,
			)
		);
	}

	/**
	 * The URL of the member list.
	 *
	 * @return string URL.
	 */
	public static function members_url() {
		return wc_get_account_endpoint_url( self::ENDPOINT_MEMBERS );
	}

	/**
	 * The URL of the screen that manages one member.
	 *
	 * The member is named in the URL rather than only in a hidden field, so a rejected
	 * submission returns to the same screen still knowing whose it was.
	 *
	 * @param int $member_id Member to manage.
	 * @return string URL.
	 */
	public static function member_form_url( $member_id ) {
		return add_query_arg( self::MEMBER_VAR, absint( $member_id ), self::members_url() );
	}

	/**
	 * Render the locations screen.
	 *
	 * @return void
	 */
	public function render_organization_locations() {
		$organization = self::guarded_organization( Roles::MANAGE_LOCATIONS );

		if ( null === $organization ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view to render; the write itself is nonce-checked when submitted.
		$requested = isset( $_GET[ self::LOCATION_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::LOCATION_VAR ] ) ) : '';

		/*
		 * Two screens, not one. Editing used to happen in a form under the full list of
		 * locations, which meant scrolling past everything else to reach it and gave no
		 * sign of which one was open. It also lost the "which location am I editing?"
		 * context the moment a submission was rejected, because that lived in a query
		 * argument the form did not post back.
		 */
		if ( '' === $requested ) {
			Templates::render(
				'myaccount/locations.php',
				array(
					'organization' => $organization,
					'locations'    => LocationRepository::for_organization( $organization->get_id() ),
				)
			);

			return;
		}

		$editing = ( 'new' === $requested )
			? null
			: LocationRepository::find_for_organization( absint( $requested ), $organization->get_id() );

		if ( 'new' !== $requested && null === $editing ) {
			wc_print_notice( esc_html__( 'That entry no longer exists.', 'woo-organization-accounts-pro' ), 'error' );

			Templates::render(
				'myaccount/locations.php',
				array(
					'organization' => $organization,
					'locations'    => LocationRepository::for_organization( $organization->get_id() ),
				)
			);

			return;
		}

		Templates::render(
			'myaccount/location-form.php',
			array(
				'organization' => $organization,
				'editing'      => $editing,
			)
		);
	}

	/**
	 * The URL of the location list.
	 *
	 * @return string URL.
	 */
	public static function locations_url() {
		return wc_get_account_endpoint_url( self::ENDPOINT_LOCATIONS );
	}

	/**
	 * The URL of the form that adds or edits one location.
	 *
	 * The location is named in the URL rather than only in a hidden field, so a
	 * rejected submission comes back to the same screen still knowing what it was
	 * editing — and so the screen can be linked to, bookmarked and reloaded.
	 *
	 * @param int $location_id Location to edit, or 0 to add one.
	 * @return string URL.
	 */
	public static function location_form_url( $location_id = 0 ) {
		return add_query_arg(
			self::LOCATION_VAR,
			$location_id > 0 ? absint( $location_id ) : 'new',
			self::locations_url()
		);
	}

	/**
	 * Render the invitations screen.
	 *
	 * @return void
	 */
	public function render_organization_invitations() {
		$organization = self::guarded_organization( Roles::INVITE_MEMBERS );

		if ( null === $organization ) {
			return;
		}

		/*
		 * Sending one is a screen of its own, like adding a location or managing a
		 * member. It was a fold-away panel above the list, which meant the primary
		 * "Invite somebody" button on the members screen promised a form and delivered
		 * a list with the form shut — and a refused address came back to a form the
		 * visitor had to re-open to read the reason.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view to render; the write itself is nonce-checked when submitted.
		if ( isset( $_GET[ self::INVITE_VAR ] ) ) {
			Templates::render( 'myaccount/invitation-form.php', array( 'organization' => $organization ) );

			return;
		}

		Templates::render(
			'myaccount/invitations.php',
			array(
				'organization' => $organization,
				'invitations'  => InvitationRepository::for_organization( $organization->get_id() ),
			)
		);
	}

	/**
	 * The URL of the invitation list.
	 *
	 * @return string URL.
	 */
	public static function invitations_url() {
		return wc_get_account_endpoint_url( self::ENDPOINT_INVITATIONS );
	}

	/**
	 * The URL of the form that sends one invitation.
	 *
	 * @return string URL.
	 */
	public static function invite_form_url() {
		return add_query_arg( self::INVITE_VAR, 'new', self::invitations_url() );
	}

	/**
	 * Render the organization order history.
	 *
	 * @return void
	 */
	public function render_organization_orders() {
		$organization = self::guarded_organization( Roles::VIEW_ORGANIZATION_ORDERS );

		if ( null === $organization ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging a read-only list.
		$page = isset( $_GET['woap_page'] ) ? max( 1, absint( wp_unslash( $_GET['woap_page'] ) ) ) : 1;

		$per_page = 20;
		$orders   = self::organization_orders( $organization->get_id(), $per_page, $page );

		Templates::render(
			'myaccount/organization-orders.php',
			array(
				'organization' => $organization,
				'orders'       => $orders['orders'],
				'page'         => $page,
				'pages'        => $orders['pages'],
				'total'        => $orders['total'],
			)
		);
	}

	/**
	 * Every order placed by an organization, newest first.
	 *
	 * Uses wc_get_orders() with a meta query, which is native under High-Performance
	 * Order Storage. There is no WP_Query against a shop_order post type anywhere in
	 * this plugin, because under HPOS there are no such posts to query.
	 *
	 * @param int $organization_id Organization ID.
	 * @param int $per_page        Orders per page.
	 * @param int $page            Page number, one-based.
	 * @return array {
	 *     @type \WC_Order[] $orders The orders on this page.
	 *     @type int         $pages  Total number of pages.
	 *     @type int         $total  Total number of orders.
	 * }
	 */
	public static function organization_orders( $organization_id, $per_page = 20, $page = 1 ) {
		$query = wc_get_orders(
			array(
				'limit'      => $per_page,
				'page'       => max( 1, absint( $page ) ),
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The organization ID is the only way to scope an order list to an organization, and it is an indexed lookup in the HPOS meta table.
					array(
						'key'     => OrderMeta::ORGANIZATION_ID,
						'value'   => absint( $organization_id ),
						'compare' => '=',
					),
				),
			)
		);

		return array(
			'orders' => isset( $query->orders ) ? $query->orders : array(),
			'pages'  => isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1,
			'total'  => isset( $query->total ) ? (int) $query->total : 0,
		);
	}

	/**
	 * The current user's organization, if they may see the screen asking for it.
	 *
	 * Prints the refusal itself, so an endpoint callback can simply return when this
	 * gives back null.
	 *
	 * @param string $capability Capability the screen requires.
	 * @return \WooOrgAccounts\Data\Organization|null Organization, or null when refused.
	 */
	private static function guarded_organization( $capability ) {
		$organization = Context::organization();

		if ( null === $organization ) {
			wc_print_notice(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					esc_html__( 'Your account is not linked to a %s.', 'woo-organization-accounts-pro' ),
					esc_html( Labels::organization() )
				),
				'error'
			);

			return null;
		}

		if ( ! current_user_can( $capability ) ) {
			wc_print_notice( esc_html__( 'You do not have permission to view this.', 'woo-organization-accounts-pro' ), 'error' );

			return null;
		}

		return $organization;
	}
}
