<?php
/**
 * Schema tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

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
}
