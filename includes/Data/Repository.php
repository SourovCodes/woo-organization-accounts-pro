<?php
/**
 * Base class for the plugin's repositories.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

use WooOrgAccounts\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes one of the plugin's tables.
 *
 * Every line of SQL in this plugin lives in `includes/Data/`, and every statement
 * with a variable in it goes through `$wpdb->prepare()` or through `$wpdb->insert()`
 * and `$wpdb->update()`, which prepare for themselves. Keeping the queries in one
 * directory is what makes "no cross-organization access" something that can be read
 * and tested rather than hoped for: a method that returns rows belonging to an
 * organization takes that organization's ID, and there is no method that returns them
 * without one.
 */
abstract class Repository {

	/**
	 * The unprefixed table name, as an Install class constant.
	 *
	 * @return string Table name.
	 */
	abstract protected static function table_name();

	/**
	 * The entity class rows are hydrated into.
	 *
	 * @return string Fully qualified class name.
	 */
	abstract protected static function entity_class();

	/**
	 * The prefixed table name.
	 *
	 * @return string Table name.
	 */
	public static function table() {
		return Install::table( static::table_name() );
	}

	/**
	 * Turn a database row into an entity.
	 *
	 * @param array|object|null $row Row from $wpdb.
	 * @return Entity|null Entity, or null when there was no row.
	 */
	protected static function hydrate( $row ) {
		if ( empty( $row ) ) {
			return null;
		}

		$class = static::entity_class();

		return new $class( $row );
	}

	/**
	 * Turn a set of database rows into entities.
	 *
	 * @param array $rows Rows from $wpdb.
	 * @return Entity[] Entities.
	 */
	protected static function hydrate_all( $rows ) {
		$entities = array();

		foreach ( (array) $rows as $row ) {
			$entity = static::hydrate( $row );

			if ( null !== $entity ) {
				$entities[] = $entity;
			}
		}

		return $entities;
	}

	/**
	 * The `$wpdb` format specifiers for a set of column values.
	 *
	 * Derived from the entity's cast map, so the placeholder a column is written with
	 * always matches the type it is read back as.
	 *
	 * @param array $values Map of column name to value.
	 * @return string[] Format specifiers in the same order.
	 */
	protected static function formats( array $values ) {
		$class   = static::entity_class();
		$casts   = $class::casts();
		$formats = array();

		foreach ( array_keys( $values ) as $key ) {
			$type = isset( $casts[ $key ] ) ? $casts[ $key ] : 'string';

			$formats[] = ( 'int' === $type || 'bool' === $type ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Reduce a list of IDs to the unique, positive integers in it.
	 *
	 * Every batched read builds its `IN` list from this, so an empty or hand-supplied
	 * list cannot reach the query as a zero, a string or a duplicate.
	 *
	 * @param int[] $ids IDs.
	 * @return int[] Unique positive IDs, renumbered from zero.
	 */
	protected static function clean_ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Fetch one row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return Entity|null Entity, or null when there is no such row.
	 */
	public static function find( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( 0 === $id ) {
			return null;
		}

		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return static::hydrate( $row );
	}

	/**
	 * Insert or update an entity, and stamp its dates.
	 *
	 * @param Entity $entity Entity to persist.
	 * @return int The row ID, or 0 when the write failed.
	 */
	public static function save( Entity $entity ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $entity->to_array();

		if ( array_key_exists( 'date_modified', $data ) ) {
			$entity->set( 'date_modified', $now );
		}

		if ( $entity->exists() ) {
			$data = $entity->to_array();

			$updated = $wpdb->update(
				static::table(),
				$data,
				array( 'id' => $entity->get_id() ),
				static::formats( $data ),
				array( '%d' )
			);

			return false === $updated ? 0 : $entity->get_id();
		}

		if ( array_key_exists( 'date_created', $data ) && empty( $entity->get( 'date_created' ) ) ) {
			$entity->set( 'date_created', $now );
		}

		$data     = $entity->to_array();
		$inserted = $wpdb->insert( static::table(), $data, static::formats( $data ) );

		if ( ! $inserted ) {
			return 0;
		}

		$entity->set_id( $wpdb->insert_id );

		return $entity->get_id();
	}

	/**
	 * Delete one row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return bool True when a row was removed.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( 0 === $id ) {
			return false;
		}

		return (bool) $wpdb->delete( static::table(), array( 'id' => $id ), array( '%d' ) );
	}
}
