<?php
/**
 * Organizations list table.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The organizations screen in wp-admin.
 *
 * A WP_List_Table rather than a hand-rolled table so the status views, the search box,
 * the sortable headers, the pagination and the bulk actions all behave the way every
 * other list in wp-admin behaves.
 */
class OrganizationsListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Set the screen up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'organization',
				'plural'   => 'organizations',
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
			'cb'        => '<input type="checkbox">',
			'name'      => Labels::organization(),
			'status'    => __( 'Status', 'woo-organization-accounts-pro' ),
			'contact'   => __( 'Contact', 'woo-organization-accounts-pro' ),
			'tax_id'    => __( 'VAT / tax ID', 'woo-organization-accounts-pro' ),
			'members'   => Labels::members(),
			'locations' => Labels::locations(),
			'created'   => __( 'Registered', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * The columns a click on the heading sorts by.
	 *
	 * @return array Map of column key to sort definition.
	 */
	public function get_sortable_columns() {
		return array(
			'name'    => array( 'name', true ),
			'status'  => array( 'status', false ),
			'created' => array( 'date_created', false ),
		);
	}

	/**
	 * The status filters above the table.
	 *
	 * @return array Map of view key to link markup.
	 */
	public function get_views() {
		$counts  = OrganizationRepository::counts_by_status();
		$total   = array_sum( $counts );
		$current = self::current_status();
		$base    = admin_url( 'admin.php?page=' . Organizations::PAGE_SLUG );

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'woo-organization-accounts-pro' ),
				$total
			),
		);

		foreach ( Organization::statuses() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				isset( $counts[ $status ] ) ? $counts[ $status ] : 0
			);
		}

		return $views;
	}

	/**
	 * The bulk actions offered.
	 *
	 * @return array Map of action to label.
	 */
	public function get_bulk_actions() {
		return array(
			'approve' => __( 'Approve', 'woo-organization-accounts-pro' ),
			'suspend' => __( 'Suspend', 'woo-organization-accounts-pro' ),
			'reject'  => __( 'Reject', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * Load the rows for the current screen.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$args = array(
			'status'  => self::current_status(),
			'search'  => self::current_search(),
			'orderby' => self::current_orderby(),
			'order'   => self::current_order(),
			'limit'   => self::PER_PAGE,
			'offset'  => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
		);

		$this->items = OrganizationRepository::query( $args );

		$this->set_pagination_args(
			array(
				'total_items' => OrganizationRepository::count( $args ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * The checkbox column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="organization_ids[]" value="%d">', $item->get_id() );
	}

	/**
	 * The name column, with its row actions.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_name( $item ) {
		$edit_url = Organizations::edit_url( $item->get_id() );

		$actions = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'woo-organization-accounts-pro' ) ),
		);

		foreach ( self::status_actions( $item ) as $status => $label ) {
			$actions[ $status ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Organizations::status_url( $item->get_id(), $status ) ),
				esc_html( $label )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( Organizations::delete_url( $item->get_id() ) ),
			esc_js( __( 'Delete this account and every member, location and invitation belonging to it? Orders are kept.', 'woo-organization-accounts-pro' ) ),
			esc_html__( 'Delete', 'woo-organization-accounts-pro' )
		);

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $edit_url ),
			esc_html( $item->get_name() ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * The status column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_status( $item ) {
		return sprintf(
			'<span class="woap-status woap-status--%1$s">%2$s</span>',
			esc_attr( $item->get_status() ),
			esc_html( $item->get_status_label() )
		);
	}

	/**
	 * The contact column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_contact( $item ) {
		$email = (string) $item->get( 'email' );
		$phone = (string) $item->get( 'phone' );

		$lines = array();

		if ( '' !== $email ) {
			$lines[] = sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $email ) );
		}

		if ( '' !== $phone ) {
			$lines[] = esc_html( $phone );
		}

		return empty( $lines ) ? '&mdash;' : implode( '<br>', $lines );
	}

	/**
	 * The tax ID column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_tax_id( $item ) {
		$tax_id = (string) $item->get( 'tax_id' );

		return '' === $tax_id ? '&mdash;' : esc_html( $tax_id );
	}

	/**
	 * The member count column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_members( $item ) {
		return esc_html( (string) MemberRepository::count_for_organization( $item->get_id() ) );
	}

	/**
	 * The location count column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_locations( $item ) {
		return esc_html( (string) count( LocationRepository::for_organization( $item->get_id() ) ) );
	}

	/**
	 * The registration date column.
	 *
	 * @param Organization $item The organization.
	 * @return string Markup.
	 */
	public function column_created( $item ) {
		$created = $item->get( 'date_created' );

		if ( empty( $created ) ) {
			return '&mdash;';
		}

		return esc_html( date_i18n( get_option( 'date_format' ), strtotime( $created . ' UTC' ) ) );
	}

	/**
	 * What to print when a column has no handler.
	 *
	 * @param Organization $item        The organization.
	 * @param string       $column_name Column key.
	 * @return string Markup.
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) $item->get( $column_name, '' ) );
	}

	/**
	 * The message shown when nothing matches.
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html(
			sprintf(
				/* translators: %s: the plural organization noun for the site's mode. */
				__( 'No %s found.', 'woo-organization-accounts-pro' ),
				Labels::organizations()
			)
		);
	}

	/**
	 * The status changes offered for one organization.
	 *
	 * @param Organization $organization The organization.
	 * @return array Map of status to action label.
	 */
	private static function status_actions( Organization $organization ) {
		$actions = array();

		if ( ! $organization->is_active() ) {
			$actions[ Organization::STATUS_ACTIVE ] = __( 'Approve', 'woo-organization-accounts-pro' );
		}

		if ( Organization::STATUS_SUSPENDED !== $organization->get_status() ) {
			$actions[ Organization::STATUS_SUSPENDED ] = __( 'Suspend', 'woo-organization-accounts-pro' );
		}

		if ( Organization::STATUS_REJECTED !== $organization->get_status() ) {
			$actions[ Organization::STATUS_REJECTED ] = __( 'Reject', 'woo-organization-accounts-pro' );
		}

		return $actions;
	}

	/**
	 * The status filter the screen is showing.
	 *
	 * @return string A status, or an empty string for all.
	 */
	private static function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtering a read-only list.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return array_key_exists( $status, Organization::statuses() ) ? $status : '';
	}

	/**
	 * The search term the screen is showing.
	 *
	 * @return string Search term.
	 */
	private static function current_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Searching a read-only list. The search box submits by GET.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	/**
	 * The column the screen is sorted by.
	 *
	 * @return string Column name.
	 */
	private static function current_orderby() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sorting a read-only list; the repository refuses any column not on its allow-list.
		return isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
	}

	/**
	 * The direction the screen is sorted in.
	 *
	 * @return string ASC or DESC.
	 */
	private static function current_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sorting a read-only list.
		return isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC';
	}
}
