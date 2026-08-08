<?php
/**
 * Base class for the plugin's database entities.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A row from one of the plugin's tables, held as a typed property bag.
 *
 * The shape follows WooCommerce's own CRUD objects — a `$data` array behind `get()`
 * and `set()` rather than a declared property per column. Four entities across
 * roughly sixty columns would otherwise be several hundred lines of accessors that
 * say nothing, and this way the column list, its defaults and its types are stated
 * once, in one place, and the repository can derive the `$wpdb` format specifiers
 * from the same declaration.
 */
abstract class Entity {

	/**
	 * The row's primary key, or 0 for an entity that has never been saved.
	 *
	 * @var int
	 */
	protected $id = 0;

	/**
	 * Column values, keyed by column name.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Every storable column and the value a new row starts with.
	 *
	 * @return array Map of column name to default value.
	 */
	abstract public static function defaults();

	/**
	 * The type each column is cast to on the way in and out.
	 *
	 * Anything not listed is treated as a string. The repository reads this to build
	 * the `$wpdb` format specifiers, so a column typed here is a column that cannot
	 * be written with the wrong placeholder.
	 *
	 * @return array Map of column name to 'int', 'bool' or 'string'.
	 */
	public static function casts() {
		return array();
	}

	/**
	 * Build an entity, optionally from a database row.
	 *
	 * @param array|object|null $row Row as returned by $wpdb, or null for a new entity.
	 */
	public function __construct( $row = null ) {
		$this->data = static::defaults();

		if ( null === $row ) {
			return;
		}

		$row = (array) $row;

		if ( isset( $row['id'] ) ) {
			$this->id = (int) $row['id'];
		}

		foreach ( $this->data as $key => $unused ) {
			if ( array_key_exists( $key, $row ) ) {
				$this->data[ $key ] = self::cast( $key, $row[ $key ], static::casts() );
			}
		}
	}

	/**
	 * Cast a value to the type declared for its column.
	 *
	 * @param string $key   Column name.
	 * @param mixed  $value Raw value.
	 * @param array  $casts Cast map from casts().
	 * @return mixed Cast value.
	 */
	private static function cast( $key, $value, array $casts ) {
		$type = isset( $casts[ $key ] ) ? $casts[ $key ] : 'string';

		if ( 'int' === $type ) {
			return (int) $value;
		}

		if ( 'bool' === $type ) {
			return (bool) $value;
		}

		if ( null === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Retrieve the primary key.
	 *
	 * @return int Row ID, or 0 when the entity has not been saved.
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set the primary key. Used by the repository after an insert.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function set_id( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * Whether this entity corresponds to a stored row.
	 *
	 * @return bool True when it has an ID.
	 */
	public function exists() {
		return $this->id > 0;
	}

	/**
	 * Read a column.
	 *
	 * @param string $key      Column name.
	 * @param mixed  $fallback Returned when the column is not part of this entity.
	 * @return mixed Column value.
	 */
	public function get( $key, $fallback = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Write a column.
	 *
	 * Anything that is not a declared column is ignored rather than stored, so a
	 * stray form field can never reach the database as a column nobody expected.
	 *
	 * @param string $key   Column name.
	 * @param mixed  $value Value; cast to the column's declared type.
	 * @return $this
	 */
	public function set( $key, $value ) {
		if ( ! array_key_exists( $key, $this->data ) ) {
			return $this;
		}

		$this->data[ $key ] = self::cast( $key, $value, static::casts() );

		return $this;
	}

	/**
	 * Write several columns at once.
	 *
	 * @param array $props Map of column name to value.
	 * @return $this
	 */
	public function set_props( array $props ) {
		foreach ( $props as $key => $value ) {
			$this->set( $key, $value );
		}

		return $this;
	}

	/**
	 * The storable columns and their current values.
	 *
	 * @return array Map of column name to value.
	 */
	public function to_array() {
		return $this->data;
	}
}
