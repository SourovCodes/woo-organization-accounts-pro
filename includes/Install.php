<?php
/**
 * Database schema.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's own tables.
 *
 * Organizations, members, locations and invitations are relational data with real
 * foreign keys between them, queried by status and by owner on every request that
 * touches the checkout. Post meta would turn each of those lookups into a join
 * against a table the whole site shares, and would give no way to make "one user
 * belongs to one organization" a constraint the database enforces rather than a rule
 * the code remembers.
 */
final class Install {

	/**
	 * Unprefixed name of the organizations table.
	 */
	const ORGANIZATIONS = 'woap_organizations';

	/**
	 * Unprefixed name of the members table.
	 */
	const MEMBERS = 'woap_members';

	/**
	 * Unprefixed name of the locations table.
	 */
	const LOCATIONS = 'woap_locations';

	/**
	 * Unprefixed name of the member-to-location access table.
	 */
	const MEMBER_LOCATIONS = 'woap_member_locations';

	/**
	 * Unprefixed name of the invitations table.
	 */
	const INVITATIONS = 'woap_invitations';

	/**
	 * Option holding the schema version the tables were last built at.
	 */
	const VERSION_OPTION = 'woo_org_accounts_db_version';

	/**
	 * Resolve a table name to its site-prefixed form.
	 *
	 * Every query in the plugin goes through this rather than interpolating
	 * `$wpdb->prefix` at the call site, so a table name is never assembled from
	 * anything but one of the constants above.
	 *
	 * @param string $table One of the class constants.
	 * @return string Prefixed table name.
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Every table the plugin owns, in the order they should be dropped.
	 *
	 * @return string[] Unprefixed table names.
	 */
	public static function tables() {
		return array(
			self::MEMBER_LOCATIONS,
			self::INVITATIONS,
			self::LOCATIONS,
			self::MEMBERS,
			self::ORGANIZATIONS,
		);
	}

	/**
	 * Create or update the tables and record the schema version.
	 *
	 * Idempotent: dbDelta() only issues the statements needed to reach the described
	 * schema, so running this against an up-to-date database does nothing.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}

		self::migrate_location_contacts();

		update_option( self::VERSION_OPTION, WOAP_DB_VERSION, false );
	}

	/**
	 * Move the old single contact name onto the WooCommerce address columns.
	 *
	 * Schema 1.0.0 gave a location one `contact_name`, which had to be split into a
	 * first and last name every time an order was shipped to it — and a contact called
	 * "Grace" arrived at the courier with no surname at all. The columns are now
	 * WooCommerce's own, so nothing is guessed at checkout; this splits each stored
	 * name once, here, where a person can see the result and correct it.
	 *
	 * The split takes the last whitespace-separated word as the surname, so
	 * "Mary Jane Watson" keeps "Mary Jane" together. A single word becomes a first name
	 * with no surname, which is the truthful reading of what was stored.
	 *
	 * dbDelta() adds the new columns but never removes the old ones, so the old ones
	 * are dropped here once their contents are safely moved.
	 *
	 * @return void
	 */
	private static function migrate_location_contacts() {
		global $wpdb;

		$table = self::table( self::LOCATIONS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; SHOW COLUMNS takes no placeholders.
		$legacy = $wpdb->get_col( "SHOW COLUMNS FROM {$table} LIKE 'contact_%'" );

		if ( empty( $legacy ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.
		$rows = $wpdb->get_results( "SELECT id, contact_name, contact_phone FROM {$table}", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$contact = trim( (string) $row['contact_name'] );
			$parts   = '' === $contact ? array() : preg_split( '/\s+/', $contact );
			$last    = count( $parts ) > 1 ? array_pop( $parts ) : '';

			$wpdb->update(
				$table,
				array(
					'first_name' => implode( ' ', $parts ),
					'last_name'  => $last,
					'phone'      => (string) $row['contact_phone'],
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		foreach ( $legacy as $column ) {
			$column = preg_replace( '/[^a-z_]/', '', (string) $column );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A column name cannot be a placeholder; this one came from SHOW COLUMNS and is stripped to [a-z_].
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
		}
	}

	/**
	 * Run the installer again when the shipped schema is newer than the database.
	 *
	 * Activation is not enough on its own: WordPress updates a plugin by unpacking
	 * the new files over the old ones without deactivating it, so a release that adds
	 * a column would otherwise reach a live site with the old tables still in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( WOAP_DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether every table the plugin needs exists.
	 *
	 * @return bool True when all tables are present.
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from a class constant, and SHOW TABLES takes no placeholders.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop every table the plugin owns.
	 *
	 * Only ever called from uninstall.php, and only when the site asked for its data
	 * to be removed.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder; this one is built from a class constant and $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * The CREATE TABLE statements, one per table.
	 *
	 * Written in the shape dbDelta() insists on — one column per line, two spaces
	 * after PRIMARY KEY, KEY rather than INDEX — because it parses these strings
	 * rather than executing them and quietly does nothing when the format slips.
	 *
	 * Every datetime is nullable rather than defaulting to the zero date, which MySQL
	 * 8 rejects under its default strict SQL mode.
	 *
	 * @return string[] SQL statements.
	 */
	public static function schema() {
		global $wpdb;

		$collate       = $wpdb->get_charset_collate();
		$organizations = self::table( self::ORGANIZATIONS );
		$members       = self::table( self::MEMBERS );
		$locations     = self::table( self::LOCATIONS );
		$access        = self::table( self::MEMBER_LOCATIONS );
		$invitations   = self::table( self::INVITATIONS );

		$schema = array();

		/*
		 * The billing address lives here and only here. It is the organization's, not
		 * any one member's, and the copy that ends up on an order is WooCommerce's own
		 * order billing snapshot, so a later edit here never rewrites history.
		 */
		$schema[] = "CREATE TABLE {$organizations} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(200) NOT NULL default '',
			email varchar(100) NOT NULL default '',
			phone varchar(50) NOT NULL default '',
			tax_id varchar(100) NOT NULL default '',
			status varchar(20) NOT NULL default 'pending',
			allow_custom_shipping tinyint(1) NOT NULL default 1,
			billing_first_name varchar(100) NOT NULL default '',
			billing_last_name varchar(100) NOT NULL default '',
			billing_company varchar(200) NOT NULL default '',
			billing_address_1 varchar(200) NOT NULL default '',
			billing_address_2 varchar(200) NOT NULL default '',
			billing_city varchar(100) NOT NULL default '',
			billing_state varchar(100) NOT NULL default '',
			billing_postcode varchar(20) NOT NULL default '',
			billing_country varchar(2) NOT NULL default '',
			billing_email varchar(100) NOT NULL default '',
			billing_phone varchar(50) NOT NULL default '',
			date_created datetime NULL default null,
			date_modified datetime NULL default null,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY name (name(100))
		) {$collate};";

		/*
		 * user_id is UNIQUE, which is how "every user belongs to exactly one
		 * organization" becomes a rule the database keeps rather than one every insert
		 * has to remember. A second membership fails the insert instead of quietly
		 * giving somebody two organizations and an ambiguous checkout.
		 */
		$schema[] = "CREATE TABLE {$members} (
			id bigint(20) unsigned NOT NULL auto_increment,
			organization_id bigint(20) unsigned NOT NULL default 0,
			user_id bigint(20) unsigned NOT NULL default 0,
			role varchar(20) NOT NULL default 'member',
			status varchar(20) NOT NULL default 'active',
			capabilities longtext NULL,
			date_created datetime NULL default null,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY organization_id (organization_id),
			KEY organization_role (organization_id,role)
		) {$collate};";

		/*
		 * The columns are WooCommerce's shipping address fields, named exactly as
		 * WooCommerce names them, so a location can be handed to an order without
		 * anything being reshaped on the way. The earlier schema had a single
		 * `contact_name` that had to be split into a first and last name at checkout,
		 * which produced an empty last name for every one-word contact.
		 */
		$schema[] = "CREATE TABLE {$locations} (
			id bigint(20) unsigned NOT NULL auto_increment,
			organization_id bigint(20) unsigned NOT NULL default 0,
			name varchar(200) NOT NULL default '',
			first_name varchar(100) NOT NULL default '',
			last_name varchar(100) NOT NULL default '',
			company varchar(200) NOT NULL default '',
			address_1 varchar(200) NOT NULL default '',
			address_2 varchar(200) NOT NULL default '',
			city varchar(100) NOT NULL default '',
			state varchar(100) NOT NULL default '',
			postcode varchar(20) NOT NULL default '',
			country varchar(2) NOT NULL default '',
			phone varchar(50) NOT NULL default '',
			is_default tinyint(1) NOT NULL default 0,
			date_created datetime NULL default null,
			PRIMARY KEY  (id),
			KEY organization_id (organization_id)
		) {$collate};";

		/*
		 * Which locations a member may ship to. An empty set means every location of
		 * their organization — the common case, so the common case costs no rows.
		 */
		$schema[] = "CREATE TABLE {$access} (
			member_id bigint(20) unsigned NOT NULL default 0,
			location_id bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY  (member_id,location_id),
			KEY location_id (location_id)
		) {$collate};";

		/*
		 * Only the SHA-256 of the invitation token is stored. The raw token exists in
		 * the email and nowhere else, so a database read cannot be turned into a way
		 * of joining somebody else's organization. char(64) with a UNIQUE key makes the
		 * lookup an exact index hit rather than a scan.
		 */
		$schema[] = "CREATE TABLE {$invitations} (
			id bigint(20) unsigned NOT NULL auto_increment,
			organization_id bigint(20) unsigned NOT NULL default 0,
			email varchar(100) NOT NULL default '',
			role varchar(20) NOT NULL default 'member',
			token_hash char(64) NOT NULL default '',
			status varchar(20) NOT NULL default 'pending',
			expires_at datetime NULL default null,
			invited_by bigint(20) unsigned NOT NULL default 0,
			date_created datetime NULL default null,
			date_accepted datetime NULL default null,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY organization_id (organization_id),
			KEY email (email)
		) {$collate};";

		return $schema;
	}
}
