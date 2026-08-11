<?php
/**
 * Organization repository.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

use WooOrgAccounts\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the organizations table.
 */
class OrganizationRepository extends Repository {

	/**
	 * The unprefixed table name.
	 *
	 * @return string Table name.
	 */
	protected static function table_name() {
		return Install::ORGANIZATIONS;
	}

	/**
	 * The entity class rows are hydrated into.
	 *
	 * @return string Class name.
	 */
	protected static function entity_class() {
		return Organization::class;
	}

	/**
	 * Query organizations.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $status  Restrict to one status.
	 *     @type string $search  Match against name, email or tax ID.
	 *     @type string $orderby Column to sort on. One of name, status, date_created, id.
	 *     @type string $order   ASC or DESC.
	 *     @type int    $limit   Maximum rows. 0 for no limit.
	 *     @type int    $offset  Rows to skip.
	 * }
	 * @return Organization[] Matching organizations.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'  => '',
				'search'  => '',
				'orderby' => 'name',
				'order'   => 'ASC',
				'limit'   => 0,
				'offset'  => 0,
			)
		);

		$table  = static::table();
		$where  = self::where_clause( $args, $params );
		$order  = self::order_clause( $args['orderby'], $args['order'] );
		$limit  = '';
		$limits = array();

		if ( $args['limit'] > 0 ) {
			$limit    = ' LIMIT %d OFFSET %d';
			$limits[] = absint( $args['limit'] );
			$limits[] = absint( $args['offset'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table, WHERE and ORDER BY are built from validated identifiers; every value is a placeholder bound below.
		$sql = "SELECT * FROM {$table}{$where}{$order}{$limit}";

		$values = array_merge( $params, $limits );

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only placeholders for the values; prepare() binds them here.
			$sql = $wpdb->prepare( $sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above when it carries any values.
		return static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Count organizations matching a query.
	 *
	 * @param array $args Same arguments as query(), minus ordering and paging.
	 * @return int Number of matching rows.
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'search' => '',
			)
		);

		$table = static::table();
		$where = self::where_clause( $args, $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and WHERE are built from validated identifiers; every value is a placeholder bound below.
		$sql = "SELECT COUNT(*) FROM {$table}{$where}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only placeholders for the values; prepare() binds them here.
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above when it carries any values.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * How many organizations sit in each status.
	 *
	 * One query rather than one per status, because the admin list table shows all
	 * four counts above every screen.
	 *
	 * @return array Map of status to count, including the statuses with none.
	 */
	public static function counts_by_status() {
		global $wpdb;

		$counts = array_fill_keys( array_keys( Organization::statuses() ), 0 );
		$table  = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the query takes no values.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Move an organization to a new status.
	 *
	 * @param int    $organization_id Organization ID.
	 * @param string $status          One of the Organization::STATUS_* constants.
	 * @return bool True when the status changed.
	 */
	public static function set_status( $organization_id, $status ) {
		if ( ! array_key_exists( $status, Organization::statuses() ) ) {
			return false;
		}

		$organization = self::find( $organization_id );

		if ( null === $organization || $organization->get_status() === $status ) {
			return false;
		}

		$previous = $organization->get_status();
		$organization->set( 'status', $status );

		if ( 0 === self::save( $organization ) ) {
			return false;
		}

		/**
		 * Fires after an organization's status has changed.
		 *
		 * @since 0.1.0
		 *
		 * @param Organization $organization The organization, with its new status.
		 * @param string       $status       The new status.
		 * @param string       $previous     The status it held before.
		 */
		do_action( 'woo_org_accounts_organization_status_changed', $organization, $status, $previous );

		return true;
	}

	/**
	 * Build the WHERE clause for a query, collecting its bound values.
	 *
	 * @param array $args   Query arguments.
	 * @param array $params Receives the values to bind, in order.
	 * @return string SQL fragment, beginning with a space, or an empty string.
	 */
	private static function where_clause( array $args, &$params ) {
		$clauses = array();
		$params  = array();

		if ( ! empty( $args['status'] ) && array_key_exists( $args['status'], Organization::statuses() ) ) {
			$clauses[] = 'status = %s';
			$params[]  = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			/*
			 * The billing email rather than an email of the organization's own, which
			 * this table no longer has: it is the address every order carries, so it is
			 * the one somebody searching from an order will have in front of them.
			 */
			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$clauses[] = '( name LIKE %s OR billing_email LIKE %s OR tax_id LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		return empty( $clauses ) ? '' : ' WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Build the ORDER BY clause from an allow-list.
	 *
	 * A column name cannot be a placeholder, so the only safe way to sort on a
	 * caller-supplied column is to refuse everything that is not on this list.
	 *
	 * @param string $orderby Requested column.
	 * @param string $order   Requested direction.
	 * @return string SQL fragment, beginning with a space.
	 */
	private static function order_clause( $orderby, $order ) {
		$allowed = array( 'id', 'name', 'status', 'date_created', 'date_modified' );

		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'name';
		}

		$order = ( 'DESC' === strtoupper( (string) $order ) ) ? 'DESC' : 'ASC';

		return ' ORDER BY ' . $orderby . ' ' . $order;
	}
}
