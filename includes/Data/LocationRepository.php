<?php
/**
 * Location repository.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

use WooOrgAccounts\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the locations table.
 */
class LocationRepository extends Repository {

	/**
	 * The unprefixed table name.
	 *
	 * @return string Table name.
	 */
	protected static function table_name() {
		return Install::LOCATIONS;
	}

	/**
	 * The entity class rows are hydrated into.
	 *
	 * @return string Class name.
	 */
	protected static function entity_class() {
		return Location::class;
	}

	/**
	 * Every location belonging to an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return Location[] Locations, the default first and then alphabetically.
	 */
	public static function for_organization( $organization_id ) {
		global $wpdb;

		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return array();
		}

		$table = static::table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
			"SELECT * FROM {$table} WHERE organization_id = %d ORDER BY is_default DESC, name ASC",
			$organization_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * One location, but only if it belongs to the given organization.
	 *
	 * This is the only way the checkout resolves a submitted location ID. A location
	 * belonging to a different organization comes back as null, so a hand-edited form
	 * cannot ship an order to somebody else's warehouse.
	 *
	 * @param int $location_id     Location ID.
	 * @param int $organization_id Organization the location must belong to.
	 * @return Location|null Location, or null when it does not belong to that organization.
	 */
	public static function find_for_organization( $location_id, $organization_id ) {
		$location = self::find( $location_id );

		if ( null === $location || $location->get_organization_id() !== absint( $organization_id ) ) {
			return null;
		}

		return $location;
	}

	/**
	 * The locations a given member may ship to.
	 *
	 * A member with no explicit access list may use every location their organization
	 * has; one with a list is held to it.
	 *
	 * @param Member $member Membership.
	 * @return Location[] Locations the member may choose at checkout.
	 */
	public static function for_member( Member $member ) {
		$locations = self::for_organization( $member->get_organization_id() );
		$allowed   = MemberRepository::location_ids( $member->get_id() );

		if ( empty( $allowed ) ) {
			return $locations;
		}

		return array_values(
			array_filter(
				$locations,
				static function ( Location $location ) use ( $allowed ) {
					return in_array( $location->get_id(), $allowed, true );
				}
			)
		);
	}

	/**
	 * Persist a location, keeping at most one default per organization.
	 *
	 * @param Entity $entity Location to persist.
	 * @return int Location ID, or 0 when the write failed.
	 */
	public static function save( Entity $entity ) {
		$id = parent::save( $entity );

		if ( $id > 0 && $entity instanceof Location && $entity->is_default() ) {
			self::clear_other_defaults( $entity->get_organization_id(), $id );
		}

		return $id;
	}

	/**
	 * Delete a location and any member access pointing at it.
	 *
	 * @param int $id Location ID.
	 * @return bool True when a row was removed.
	 */
	public static function delete( $id ) {
		MemberRepository::forget_location( $id );

		return parent::delete( $id );
	}

	/**
	 * Delete every location belonging to an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Number of locations removed.
	 */
	public static function delete_for_organization( $organization_id ) {
		$removed = 0;

		foreach ( self::for_organization( $organization_id ) as $location ) {
			if ( self::delete( $location->get_id() ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Take the default flag off every other location of an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @param int $keep_id         The location that stays default.
	 * @return void
	 */
	private static function clear_other_defaults( $organization_id, $keep_id ) {
		global $wpdb;

		$table = static::table();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; every value is a placeholder.
				"UPDATE {$table} SET is_default = 0 WHERE organization_id = %d AND id != %d",
				absint( $organization_id ),
				absint( $keep_id )
			)
		);
	}
}
