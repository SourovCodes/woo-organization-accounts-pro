<?php
/**
 * Membership repository.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

use WooOrgAccounts\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the members table and the location access it owns.
 */
class MemberRepository extends Repository {

	/**
	 * Memoised user-to-membership lookups, keyed by user ID.
	 *
	 * The user-to-membership lookup runs on nearly every authenticated request — the
	 * checkout gate, the My Account menu, every capability check — and the answer
	 * cannot change within a request except through this class, which clears the
	 * memo when it does.
	 *
	 * @var array
	 */
	private static $by_user = array();

	/**
	 * The unprefixed table name.
	 *
	 * @return string Table name.
	 */
	protected static function table_name() {
		return Install::MEMBERS;
	}

	/**
	 * The entity class rows are hydrated into.
	 *
	 * @return string Class name.
	 */
	protected static function entity_class() {
		return Member::class;
	}

	/**
	 * The membership belonging to a user.
	 *
	 * A user has at most one, enforced by a UNIQUE key on the column.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return Member|null Membership, or null when the user belongs to no organization.
	 */
	public static function find_by_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( 0 === $user_id ) {
			return null;
		}

		if ( array_key_exists( $user_id, self::$by_user ) ) {
			return self::$by_user[ $user_id ];
		}

		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );

		self::$by_user[ $user_id ] = static::hydrate( $row );

		return self::$by_user[ $user_id ];
	}

	/**
	 * One membership, but only if it belongs to the given organization.
	 *
	 * Every screen that acts on a member reaches it through this rather than through
	 * find(), so a member ID from someone else's organization pasted into a form
	 * resolves to nothing instead of to a member.
	 *
	 * @param int $member_id       Member ID.
	 * @param int $organization_id Organization the member must belong to.
	 * @return Member|null Membership, or null when it does not belong to that organization.
	 */
	public static function find_for_organization( $member_id, $organization_id ) {
		$member = self::find( $member_id );

		if ( null === $member || $member->get_organization_id() !== absint( $organization_id ) ) {
			return null;
		}

		return $member;
	}

	/**
	 * Every membership in an organization.
	 *
	 * @param int   $organization_id Organization ID.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $role   Restrict to one role.
	 *     @type string $status Restrict to one status.
	 * }
	 * @return Member[] Memberships, admins first and then by join date.
	 */
	public static function for_organization( $organization_id, array $args = array() ) {
		global $wpdb;

		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'role'   => '',
				'status' => '',
			)
		);

		$table   = static::table();
		$clauses = array( 'organization_id = %d' );
		$params  = array( $organization_id );

		if ( ! empty( $args['role'] ) ) {
			$clauses[] = 'role = %s';
			$params[]  = (string) $args['role'];
		}

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$params[]  = (string) $args['status'];
		}

		$where = implode( ' AND ', $clauses );
		$query = "SELECT * FROM {$table} WHERE {$where} ORDER BY role ASC, date_created ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $query is assembled from class constants and literal clauses above; its placeholders are bound here.
		$sql = $wpdb->prepare( $query, $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Every membership belonging to each of several organizations.
	 *
	 * The batched form of for_organization(), for the one caller that needs a whole
	 * page of organizations at once: asking per organization turns a hundred-row page
	 * into a hundred queries. Every organization asked about is present in the result,
	 * with an empty array when it has no members, so a caller never has to test for a
	 * missing key.
	 *
	 * @param int[] $organization_ids Organization IDs.
	 * @return array Map of organization ID to Member[], admins first and then by join date.
	 */
	public static function for_organizations( array $organization_ids ) {
		global $wpdb;

		$organization_ids = self::clean_ids( $organization_ids );
		$members          = array_fill_keys( $organization_ids, array() );

		if ( empty( $organization_ids ) ) {
			return $members;
		}

		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $organization_ids ), '%d' ) );

		$query = "SELECT * FROM {$table} WHERE organization_id IN ({$placeholders}) ORDER BY organization_id ASC, role ASC, date_created ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $query is a class constant table name and an IN list of %d placeholders, one per ID, bound here.
		$sql = $wpdb->prepare( $query, $organization_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		foreach ( static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) ) as $member ) {
			$members[ $member->get_organization_id() ][] = $member;
		}

		return $members;
	}

	/**
	 * How many members an organization has.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Member count.
	 */
	public static function count_for_organization( $organization_id ) {
		global $wpdb;

		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organization_id = %d", absint( $organization_id ) ) );
	}

	/**
	 * Whether an organization would still have an active admin without this member.
	 *
	 * Checked before an admin is removed or demoted. An organization with no admin
	 * left is one nobody can administer, and only a site administrator could repair.
	 *
	 * @param int $organization_id Organization ID.
	 * @param int $except_member_id Member being removed or demoted.
	 * @return bool True when another active admin remains.
	 */
	public static function has_other_admin( $organization_id, $except_member_id ) {
		global $wpdb;

		$table = static::table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; every value is a placeholder.
				"SELECT COUNT(*) FROM {$table} WHERE organization_id = %d AND role = %s AND status = %s AND id != %d",
				absint( $organization_id ),
				Member::ROLE_ADMIN,
				Member::STATUS_ACTIVE,
				absint( $except_member_id )
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Persist a membership and drop its memoised lookup.
	 *
	 * @param Entity $entity Membership to persist.
	 * @return int Member ID, or 0 when the write failed.
	 */
	public static function save( Entity $entity ) {
		$id = parent::save( $entity );

		self::$by_user = array();

		return $id;
	}

	/**
	 * Delete a membership, its location access and its memoised lookup.
	 *
	 * @param int $id Member ID.
	 * @return bool True when a row was removed.
	 */
	public static function delete( $id ) {
		self::set_location_ids( $id, array() );

		$deleted = parent::delete( $id );

		self::$by_user = array();

		return $deleted;
	}

	/**
	 * Delete every membership in an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Number of memberships removed.
	 */
	public static function delete_for_organization( $organization_id ) {
		$removed = 0;

		foreach ( self::for_organization( $organization_id ) as $member ) {
			if ( self::delete( $member->get_id() ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * The locations a member is restricted to.
	 *
	 * An empty result means no restriction — every location of their organization —
	 * rather than no access, so the ordinary case stores no rows at all.
	 *
	 * @param int $member_id Member ID.
	 * @return int[] Location IDs.
	 */
	public static function location_ids( $member_id ) {
		global $wpdb;

		$member_id = absint( $member_id );

		if ( 0 === $member_id ) {
			return array();
		}

		$table = Install::table( Install::MEMBER_LOCATIONS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT location_id FROM {$table} WHERE member_id = %d", $member_id ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * The locations each of several members is restricted to.
	 *
	 * The batched form of location_ids(). An empty array against a member carries the
	 * same meaning it does there — no restriction, so every location their organization
	 * has — and a member with no rows is present in the result rather than missing from
	 * it, because "not restricted" and "not asked about" must not look alike.
	 *
	 * @param int[] $member_ids Member IDs.
	 * @return array Map of member ID to location ID list.
	 */
	public static function location_ids_for_members( array $member_ids ) {
		global $wpdb;

		$member_ids = self::clean_ids( $member_ids );
		$access     = array_fill_keys( $member_ids, array() );

		if ( empty( $member_ids ) ) {
			return $access;
		}

		$table        = Install::table( Install::MEMBER_LOCATIONS );
		$placeholders = implode( ', ', array_fill( 0, count( $member_ids ), '%d' ) );
		$query        = "SELECT member_id, location_id FROM {$table} WHERE member_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $query is a class constant table name and an IN list of %d placeholders, one per ID, bound here.
		$sql = $wpdb->prepare( $query, $member_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$access[ absint( $row['member_id'] ) ][] = absint( $row['location_id'] );
		}

		return $access;
	}

	/**
	 * Replace the locations a member is restricted to.
	 *
	 * @param int   $member_id    Member ID.
	 * @param int[] $location_ids Location IDs. An empty array lifts the restriction.
	 * @return void
	 */
	public static function set_location_ids( $member_id, array $location_ids ) {
		global $wpdb;

		$member_id = absint( $member_id );

		if ( 0 === $member_id ) {
			return;
		}

		$table = Install::table( Install::MEMBER_LOCATIONS );

		$wpdb->delete( $table, array( 'member_id' => $member_id ), array( '%d' ) );

		foreach ( array_unique( array_map( 'absint', $location_ids ) ) as $location_id ) {
			if ( 0 === $location_id ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'member_id'   => $member_id,
					'location_id' => $location_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Remove one location from every member's access list.
	 *
	 * Called when a location is deleted, so no membership is left pointing at a
	 * location that no longer exists.
	 *
	 * @param int $location_id Location ID.
	 * @return void
	 */
	public static function forget_location( $location_id ) {
		global $wpdb;

		$wpdb->delete(
			Install::table( Install::MEMBER_LOCATIONS ),
			array( 'location_id' => absint( $location_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Forget every memoised lookup.
	 *
	 * Only the test suite needs this; within a request the writes above keep the memo
	 * honest on their own.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$by_user = array();
	}
}
