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
	 *     @type int[]  $include Restrict to these IDs. An empty array matches nothing.
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
	 * The organizations that have nowhere to ship to.
	 *
	 * An organization with no location cannot check out at all, whatever else is right about
	 * it, so this is the one question the settings screen's backfill asks — both to report how
	 * many there are and to work through them.
	 *
	 * A `LEFT JOIN` rather than a count per organization, because the answer is wanted for
	 * every row on the site at once and a shop that has just imported one has hundreds. It is
	 * ordered by ID and takes a limit so the backfill can work in batches without a row moving
	 * between one press and the next: every organization the batch fixes leaves the result set
	 * for good, so the next press resumes where this one stopped without carrying an offset.
	 *
	 * @param int $limit Maximum rows to return. 0 for all of them.
	 * @return Organization[] Organizations with no location, oldest first.
	 */
	public static function without_locations( $limit = 0 ) {
		global $wpdb;

		$table     = static::table();
		$locations = LocationRepository::table();
		$limit     = absint( $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names come from class constants; the limit is a placeholder below.
		$sql = "SELECT o.* FROM {$table} o LEFT JOIN {$locations} l ON l.organization_id = o.id WHERE l.id IS NULL ORDER BY o.id ASC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only the placeholder for the limit; prepare() binds it here.
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above when it carries a limit; otherwise it takes no values.
		return static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * How many organizations have nowhere to ship to.
	 *
	 * @return int Number of organizations with no location.
	 */
	public static function count_without_locations() {
		global $wpdb;

		$table     = static::table();
		$locations = LocationRepository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both table names come from class constants; the query takes no values.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} o LEFT JOIN {$locations} l ON l.organization_id = o.id WHERE l.id IS NULL" );
	}

	/**
	 * Persist an organization, with its billing company derived from its name.
	 *
	 * The billing company is not collected anywhere — see
	 * `Frontend\AddressFields::strip_company()` — because an organization already is
	 * the company, and two fields for one answer produced an invoice whose company
	 * line disagreed with the account it was billed to.
	 *
	 * Applied on every save rather than only when the column is empty, which is the
	 * difference between a copy and a derived value: a blank-only fallback fills the
	 * column once and then lets it rot, so renaming an account leaves its old name on
	 * every invoice it is billed for afterwards. Set here, at the one door every write
	 * goes through — the admin screens, all three REST routes, registration and the
	 * importer — rather than at each of them, because a rule enforced in five places
	 * is a rule with five chances to be forgotten.
	 *
	 * Rows written before this existed are corrected the next time they are saved.
	 *
	 * @param Entity $entity Organization to persist.
	 * @return int The row ID, or 0 when the write failed.
	 */
	public static function save( Entity $entity ) {
		if ( $entity instanceof Organization ) {
			$entity->set( 'billing_company', $entity->get_name() );
		}

		return parent::save( $entity );
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

		/*
		 * Named IDs, for a screen that already knows which rows it wants — a list showing an
		 * organization name per row, which would otherwise ask for them one at a time. An
		 * empty array is *not* "no restriction" here: a caller asking for none must get
		 * none, or the shape becomes another empty-means-the-opposite trap.
		 */
		if ( isset( $args['include'] ) && is_array( $args['include'] ) ) {
			$ids = self::clean_ids( $args['include'] );

			if ( empty( $ids ) ) {
				return ' WHERE 1=0';
			}

			$clauses[] = 'id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )';
			$params    = array_merge( $params, $ids );
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
