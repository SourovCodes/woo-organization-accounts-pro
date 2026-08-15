<?php
/**
 * The list of everybody on every organization's account.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * People, across every account, in the shape wp-admin lists things.
 *
 * There was no screen like this at all. Members existed as a read-only block at the bottom
 * of one organization's detail page, so answering "which account is this person on" meant
 * knowing the answer first. It is the question a shop asks most often — somebody rings up
 * about an order — and the one the users list could not answer either.
 *
 * **Three columns name something that is not on the membership row** — the person, their
 * organization and their delivery access — so all three are loaded in one query each and
 * cached against the page, not asked for per row. That is the hundred-queries mistake the
 * REST snapshot is careful about, and it arrives here the same way: a list of twenty-five
 * people spans up to twenty-five accounts, and one `find()` per row looks like nothing until
 * the page is full.
 */
class MembersListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 25;

	/**
	 * The plural this table is keyed by.
	 *
	 * A constant because it forms the `bulk-members` nonce the handler checks, and the two
	 * cannot be allowed to drift.
	 */
	const PLURAL = 'members';

	/**
	 * Organizations for the rows on this page, keyed by ID.
	 *
	 * @var array
	 */
	private $organizations = array();

	/**
	 * Users for the rows on this page, keyed by user ID.
	 *
	 * @var array
	 */
	private $users = array();

	/**
	 * Location access for the rows on this page, keyed by member ID.
	 *
	 * @var array
	 */
	private $access = array();

	/**
	 * Set the table up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'member',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns, in order.
	 *
	 * @return array Map of column key to heading.
	 */
	public function get_columns() {
		return array(
			'name'         => __( 'Name', 'woo-organization-accounts-pro' ),
			'email'        => __( 'Email address', 'woo-organization-accounts-pro' ),
			'organization' => Labels::organization(),
			'role'         => __( 'Role', 'woo-organization-accounts-pro' ),
			'access'       => Labels::locations(),
			'status'       => __( 'Status', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * Which columns can be sorted on.
	 *
	 * Only the ones `MemberRepository::query()` allow-lists. A sortable column the
	 * repository would silently ignore is a control that does nothing.
	 *
	 * @return array Map of column key to [orderby, is_default].
	 */
	public function get_sortable_columns() {
		return array(
			'name'   => array( 'name', true ),
			'email'  => array( 'email', false ),
			'role'   => array( 'role', false ),
			'status' => array( 'status', false ),
		);
	}

	/**
	 * The role filter links above the table.
	 *
	 * @return array Map of key to link markup.
	 */
	public function get_views() {
		$counts  = MemberRepository::counts_by_role( $this->query_args( false ) );
		$current = self::current_role();
		$total   = array_sum( $counts );

		$views = array(
			'all' => $this->view_link( '', __( 'All', 'woo-organization-accounts-pro' ), $total, '' === $current ),
		);

		foreach ( Member::roles() as $role => $label ) {
			$views[ $role ] = $this->view_link( $role, $label, (int) $counts[ $role ], $role === $current );
		}

		return $views;
	}

	/**
	 * One filter link.
	 *
	 * @param string $role    Role to filter by, or an empty string for all.
	 * @param string $label   What to call it.
	 * @param int    $count   How many there are.
	 * @param bool   $current Whether this is the view being shown.
	 * @return string Markup.
	 */
	private function view_link( $role, $label, $count, $current ) {
		$args = array( 'page' => Members::PAGE_SLUG );

		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		$organization_id = self::current_organization();

		if ( $organization_id > 0 ) {
			$args['organization_id'] = $organization_id;
		}

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Load the rows for the current screen.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$args = $this->query_args( true );

		$this->items = MemberRepository::query( $args );

		$this->prime_caches();

		$this->set_pagination_args(
			array(
				'total_items' => MemberRepository::count( $args ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * The query the current screen describes.
	 *
	 * @param bool $paged Whether to include ordering and paging.
	 * @return array Arguments for MemberRepository::query().
	 */
	private function query_args( $paged ) {
		$args = array(
			'organization_id' => self::current_organization(),
			'role'            => self::current_role(),
			'search'          => self::current_search(),
		);

		if ( ! $paged ) {
			return $args;
		}

		return array_merge(
			$args,
			array(
				'orderby' => self::current_orderby(),
				'order'   => self::current_order(),
				'limit'   => self::PER_PAGE,
				'offset'  => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			)
		);
	}

	/**
	 * Load the organizations and users this page's rows name, in two queries.
	 *
	 * @return void
	 */
	private function prime_caches() {
		$organization_ids = array();
		$user_ids         = array();

		foreach ( $this->items as $member ) {
			$organization_ids[] = $member->get_organization_id();
			$user_ids[]         = $member->get_user_id();
		}

		$organization_ids = array_values( array_unique( array_filter( $organization_ids ) ) );

		if ( ! empty( $organization_ids ) ) {
			foreach ( OrganizationRepository::query( array( 'include' => $organization_ids ) ) as $organization ) {
				$this->organizations[ $organization->get_id() ] = $organization;
			}
		}

		// "All branches" and "two only" are different answers, so every row needs its list.
		$this->access = MemberRepository::location_ids_for_members(
			array_map(
				static function ( $member ) {
					return $member->get_id();
				},
				$this->items
			)
		);

		$user_ids = array_unique( array_filter( $user_ids ) );

		if ( empty( $user_ids ) ) {
			return;
		}

		// One query for every account on the page, rather than one per row.
		foreach ( get_users(
			array(
				'include' => $user_ids,
				'number'  => count( $user_ids ),
			)
		) as $user ) {
			$this->users[ $user->ID ] = $user;
		}
	}

	/**
	 * The name column, with its row actions.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_name( $item ) {
		$user = $this->users[ $item->get_user_id() ] ?? null;

		$name = $user instanceof \WP_User
			? sprintf(
				'<strong><a href="%1$s">%2$s</a></strong>',
				esc_url( Members::edit_url( $item->get_id() ) ),
				esc_html( $user->display_name )
			)
			: sprintf(
				'<strong>%s</strong>',
				esc_html__( '(deleted account)', 'woo-organization-accounts-pro' )
			);

		$actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Members::edit_url( $item->get_id() ) ),
				esc_html__( 'Edit', 'woo-organization-accounts-pro' )
			),
		);

		if ( $user instanceof \WP_User ) {
			$actions['user'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_user_link( $user->ID ) ),
				esc_html__( 'User account', 'woo-organization-accounts-pro' )
			);
		}

		$actions['remove'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( Members::remove_url( $item->get_id() ) ),
			esc_js(
				sprintf(
					/* translators: %s: the singular organization noun for the site's mode, for example "Company". */
					__( 'Take this person off the %s? Their login is kept, and so are their orders — they simply can no longer buy on this account.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			),
			esc_html__( 'Remove', 'woo-organization-accounts-pro' )
		);

		return $name . $this->row_actions( $actions );
	}

	/**
	 * The email column.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_email( $item ) {
		$user = $this->users[ $item->get_user_id() ] ?? null;

		if ( ! $user instanceof \WP_User ) {
			return '&mdash;';
		}

		return sprintf(
			'<a href="mailto:%1$s">%2$s</a>',
			esc_attr( $user->user_email ),
			esc_html( $user->user_email )
		);
	}

	/**
	 * The organization column.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_organization( $item ) {
		$organization = $this->organizations[ $item->get_organization_id() ] ?? null;

		if ( ! $organization instanceof Organization ) {
			return '&mdash;';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a> <span class="woap-status woap-status--%3$s">%4$s</span>',
			esc_url( Organizations::edit_url( $organization->get_id() ) ),
			esc_html( $organization->get_name() ),
			esc_attr( $organization->get_status() ),
			esc_html( $organization->get_status_label() )
		);
	}

	/**
	 * The role column.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_role( $item ) {
		$roles = Member::roles();

		return esc_html( $roles[ $item->get_role() ] ?? $item->get_role() );
	}

	/**
	 * The delivery-access column.
	 *
	 * An empty access list means every location, which is the opposite of what the stored
	 * form looks like, so it is reported as an answer rather than as a count of rows.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_access( $item ) {
		$ids = (array) ( $this->access[ $item->get_id() ] ?? array() );

		if ( empty( $ids ) ) {
			return esc_html(
				sprintf(
					/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
					__( 'All %s', 'woo-organization-accounts-pro' ),
					strtolower( Labels::locations() )
				)
			);
		}

		return esc_html(
			sprintf(
				/* translators: %d: how many locations this person may ship to. */
				_n( '%d only', '%d only', count( $ids ), 'woo-organization-accounts-pro' ),
				count( $ids )
			)
		);
	}

	/**
	 * The status column.
	 *
	 * @param Member $item The membership.
	 * @return string Markup.
	 */
	public function column_status( $item ) {
		$active = $item->is_active();

		return sprintf(
			'<span class="woap-status woap-status--%1$s">%2$s</span>',
			$active ? 'active' : 'suspended',
			esc_html(
				$active
					? __( 'Active', 'woo-organization-accounts-pro' )
					: __( 'Inactive', 'woo-organization-accounts-pro' )
			)
		);
	}

	/**
	 * Anything without a renderer of its own.
	 *
	 * @param Member $item        The membership.
	 * @param string $column_name Column key.
	 * @return string Markup.
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) $item->get( $column_name ) );
	}

	/**
	 * What to say when there is nobody to list.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nobody yet.', 'woo-organization-accounts-pro' );
	}

	/**
	 * The organization filter, printed beside the bulk actions.
	 *
	 * @param string $which Top or bottom of the table.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = self::current_organization();

		echo '<div class="alignleft actions">';

		printf(
			'<label class="screen-reader-text" for="woap-organization-filter">%s</label>',
			esc_html(
				sprintf(
					/* translators: %s: the singular organization noun for the site's mode, for example "Company". */
					__( 'Filter by %s', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			)
		);

		echo '<select name="organization_id" id="woap-organization-filter">';
		printf(
			'<option value="0">%s</option>',
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
					__( 'All %s', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		foreach ( OrganizationRepository::query( array( 'orderby' => 'name' ) ) as $organization ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $organization->get_id(),
				selected( $current, $organization->get_id(), false ),
				esc_html( $organization->get_name() )
			);
		}

		echo '</select>';

		submit_button( __( 'Filter', 'woo-organization-accounts-pro' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * The organization the screen is filtered to.
	 *
	 * @return int Organization ID, or 0.
	 */
	public static function current_organization() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view of the list to show.
		return isset( $_GET['organization_id'] ) ? absint( wp_unslash( $_GET['organization_id'] ) ) : 0;
	}

	/**
	 * The role the screen is filtered to.
	 *
	 * @return string A role, or an empty string.
	 */
	public static function current_role() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$role = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';

		return array_key_exists( $role, Member::roles() ) ? $role : '';
	}

	/**
	 * What the search box holds.
	 *
	 * @return string The term.
	 */
	public static function current_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	/**
	 * The column being sorted on.
	 *
	 * @return string A column name.
	 */
	public static function current_orderby() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		return isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
	}

	/**
	 * Which way it is being sorted.
	 *
	 * @return string ASC or DESC.
	 */
	public static function current_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'ASC';

		return ( 'DESC' === $order ) ? 'DESC' : 'ASC';
	}
}
