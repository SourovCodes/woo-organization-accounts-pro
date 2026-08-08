<?php
/**
 * Schema tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Install;

/**
 * The plugin's tables exist, and are built the way the rest of the code assumes.
 */
class InstallTest extends TestCase {

	/**
	 * Every table the plugin declares is present.
	 */
	public function testTablesExist() {
		$this->assertTrue( Install::tables_exist() );
	}

	/**
	 * The table name resolver uses the site's prefix.
	 */
	public function testTableNamesArePrefixed() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'woap_organizations', Install::table( Install::ORGANIZATIONS ) );
	}

	/**
	 * A user can only belong to one organization, and the database is what enforces it.
	 */
	public function testUserIdIsUnique() {
		global $wpdb;

		$table   = Install::table( Install::MEMBERS );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'user_id'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.

		$this->assertNotEmpty( $indexes, 'The members table has no user_id index.' );
		$this->assertSame( '0', (string) $indexes[0]['Non_unique'], 'The user_id index is not unique.' );
	}

	/**
	 * A second membership for the same user is refused by the database.
	 */
	public function testSecondMembershipForOneUserIsRefused() {
		global $wpdb;

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$suppressed = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			Install::table( Install::MEMBERS ),
			array(
				'organization_id' => $organization->get_id() + 1,
				'user_id'         => $member->get_user_id(),
			),
			array( '%d', '%d' )
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $inserted );
	}

	/**
	 * Running the installer again changes nothing and raises nothing.
	 */
	public function testInstallIsIdempotent() {
		Install::install();

		$this->assertTrue( Install::tables_exist() );
		$this->assertSame( WOAP_DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	/**
	 * The upgrade check is a no-op when the schema is current.
	 */
	public function testMaybeUpgradeDoesNothingWhenCurrent() {
		update_option( Install::VERSION_OPTION, WOAP_DB_VERSION, false );

		Install::maybe_upgrade();

		$this->assertSame( WOAP_DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	/**
	 * A stale schema version makes the installer run again.
	 */
	public function testMaybeUpgradeRunsWhenStale() {
		update_option( Install::VERSION_OPTION, '0.0.1', false );

		Install::maybe_upgrade();

		$this->assertSame( WOAP_DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	/**
	 * A location holds a WooCommerce shipping address, column for column.
	 *
	 * The names have to match WooCommerce's exactly, because a location is handed to
	 * an order without being reshaped. A rename here is an empty field on a parcel.
	 */
	public function testLocationColumnsMatchWooCommerceShippingFields() {
		global $wpdb;

		$table   = Install::table( Install::LOCATIONS );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.

		foreach ( Location::ADDRESS_FIELDS as $field ) {
			$this->assertContains( $field, $columns, $field . ' is missing from the locations table.' );
		}

		$this->assertNotContains( 'contact_name', $columns, 'The single contact name column should be gone.' );
	}

	/**
	 * Upgrading from schema 1.0.0 moves each contact name onto the address columns.
	 *
	 * The old column stored one name that had to be split at checkout, which gave a
	 * one-word contact no surname at all. The split happens once, here, where somebody
	 * can see the result — and the old columns go with it, because dbDelta never
	 * removes a column on its own.
	 */
	public function testUpgradeFromTheSingleContactNameColumn() {
		global $wpdb;

		$table = Install::table( Install::LOCATIONS );

		/*
		 * This test cannot rely on the transaction the test case rolls back for it.
		 * MySQL commits implicitly on DDL, and the migration under test is DDL, so
		 * every row written before it survives the rollback and would be counted by the
		 * next test. Hence a made-up organization ID rather than a real organization —
		 * locations carry no foreign key, and this way nothing else has to be created —
		 * and an explicit COMMIT after the cleanup, which the suite's `autocommit = 0`
		 * would otherwise roll back along with everything else.
		 */
		$organization_id = 987654;

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN contact_name varchar(200) NOT NULL DEFAULT '', ADD COLUMN contact_phone varchar(50) NOT NULL DEFAULT '', ADD COLUMN contact_email varchar(100) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.

		$rows = array(
			array( 'Grace Hopper', '+49 40 111' ),
			array( 'Grace', '+49 40 222' ),
			array( 'Mary Jane Watson', '' ),
			array( '', '' ),
		);

		foreach ( $rows as $index => $row ) {
			$wpdb->insert(
				$table,
				array(
					'organization_id' => $organization_id,
					'name'            => 'Depot ' . $index,
					'first_name'      => '',
					'last_name'       => '',
					'phone'           => '',
					'country'         => 'DE',
					'contact_name'    => $row[0],
					'contact_phone'   => $row[1],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		Install::install();

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant.

		$this->assertNotContains( 'contact_name', $columns );
		$this->assertNotContains( 'contact_phone', $columns );
		$this->assertNotContains( 'contact_email', $columns );

		$migrated = array();

		foreach ( LocationRepository::for_organization( $organization_id ) as $location ) {
			$migrated[ $location->get_name() ] = $location;
		}

		$this->assertSame( 'Grace', $migrated['Depot 0']->get( 'first_name' ) );
		$this->assertSame( 'Hopper', $migrated['Depot 0']->get( 'last_name' ) );
		$this->assertSame( '+49 40 111', $migrated['Depot 0']->get( 'phone' ) );

		// One word is a first name with no surname, which is what was actually stored.
		$this->assertSame( 'Grace', $migrated['Depot 1']->get( 'first_name' ) );
		$this->assertSame( '', $migrated['Depot 1']->get( 'last_name' ) );

		// Three words keep the given names together rather than splitting on the first space.
		$this->assertSame( 'Mary Jane', $migrated['Depot 2']->get( 'first_name' ) );
		$this->assertSame( 'Watson', $migrated['Depot 2']->get( 'last_name' ) );

		$this->assertSame( '', $migrated['Depot 3']->get( 'first_name' ) );
		$this->assertSame( '', $migrated['Depot 3']->get( 'last_name' ) );

		LocationRepository::delete_for_organization( $organization_id );
		$wpdb->query( 'COMMIT' );

		$this->assertCount( 0, LocationRepository::for_organization( $organization_id ) );
	}
}
