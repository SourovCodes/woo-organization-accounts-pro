<?php
/**
 * Organization repository tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

/**
 * Reading and writing organizations.
 */
class OrganizationRepositoryTest extends TestCase {

	/**
	 * A saved organization reads back with the values it was given.
	 */
	public function testRoundTrip() {
		$saved = $this->make_organization(
			array(
				'name'   => 'Beispiel & Söhne GmbH',
				'tax_id' => 'DE123456789',
			)
		);

		$this->assertGreaterThan( 0, $saved->get_id() );

		$loaded = OrganizationRepository::find( $saved->get_id() );

		$this->assertInstanceOf( Organization::class, $loaded );
		$this->assertSame( 'Beispiel & Söhne GmbH', $loaded->get_name() );
		$this->assertSame( 'DE123456789', $loaded->get( 'tax_id' ) );
		$this->assertSame( 'Berlin', $loaded->get_billing_address()['city'] );
	}

	/**
	 * A boolean column comes back as a boolean, not as the string MySQL stored.
	 */
	public function testBooleanColumnsAreCast() {
		$organization = $this->make_organization( array( 'allow_custom_shipping' => false ) );
		$loaded       = OrganizationRepository::find( $organization->get_id() );

		$this->assertIsBool( $loaded->allows_custom_shipping() );
		$this->assertFalse( $loaded->allows_custom_shipping() );
	}

	/**
	 * An unknown ID is null rather than an empty entity.
	 */
	public function testFindMissingReturnsNull() {
		$this->assertNull( OrganizationRepository::find( 999999 ) );
		$this->assertNull( OrganizationRepository::find( 0 ) );
	}

	/**
	 * Saving twice updates rather than inserting.
	 */
	public function testSaveUpdatesInPlace() {
		$organization = $this->make_organization();
		$id           = $organization->get_id();

		$organization->set( 'name', 'Renamed' );
		OrganizationRepository::save( $organization );

		$this->assertSame( $id, $organization->get_id() );
		$this->assertSame( 'Renamed', OrganizationRepository::find( $id )->get_name() );
		$this->assertSame( 1, OrganizationRepository::count() );
	}

	/**
	 * The status filter and the search both narrow the result.
	 */
	public function testQueryFilters() {
		$this->make_organization( array( 'name' => 'Alpha AG' ) );
		$this->make_organization(
			array(
				'name'   => 'Beta BV',
				'status' => Organization::STATUS_PENDING,
			)
		);
		$this->make_organization(
			array(
				'name'   => 'Gamma Oy',
				'tax_id' => 'FI999',
				'status' => Organization::STATUS_PENDING,
			)
		);

		$this->assertCount( 3, OrganizationRepository::query() );
		$this->assertCount( 2, OrganizationRepository::query( array( 'status' => Organization::STATUS_PENDING ) ) );
		$this->assertCount( 1, OrganizationRepository::query( array( 'search' => 'Alpha' ) ) );
		$this->assertCount( 1, OrganizationRepository::query( array( 'search' => 'FI999' ) ) );
		$this->assertSame( 2, OrganizationRepository::count( array( 'status' => Organization::STATUS_PENDING ) ) );
	}

	/**
	 * A search term is escaped rather than treated as a LIKE pattern.
	 */
	public function testSearchEscapesWildcards() {
		$this->make_organization( array( 'name' => 'Alpha AG' ) );

		$this->assertCount( 0, OrganizationRepository::query( array( 'search' => '%' ) ) );
	}

	/**
	 * Ordering is limited to an allow-list, so an arbitrary column cannot be injected.
	 */
	public function testOrderByFallsBackForUnknownColumns() {
		$this->make_organization( array( 'name' => 'Zeta' ) );
		$this->make_organization( array( 'name' => 'Alpha' ) );

		$results = OrganizationRepository::query( array( 'orderby' => 'name; DROP TABLE wptests_posts' ) );

		$this->assertCount( 2, $results );
		$this->assertSame( 'Alpha', $results[0]->get_name() );
	}

	/**
	 * Paging returns the slice that was asked for.
	 */
	public function testPaging() {
		foreach ( array( 'A Ltd', 'B Ltd', 'C Ltd' ) as $name ) {
			$this->make_organization( array( 'name' => $name ) );
		}

		$page_two = OrganizationRepository::query(
			array(
				'limit'  => 1,
				'offset' => 1,
			)
		);

		$this->assertCount( 1, $page_two );
		$this->assertSame( 'B Ltd', $page_two[0]->get_name() );
	}

	/**
	 * Every status is counted, including the ones with nothing in them.
	 */
	public function testCountsByStatus() {
		$this->make_organization();
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$counts = OrganizationRepository::counts_by_status();

		$this->assertSame( 1, $counts[ Organization::STATUS_ACTIVE ] );
		$this->assertSame( 1, $counts[ Organization::STATUS_PENDING ] );
		$this->assertSame( 0, $counts[ Organization::STATUS_REJECTED ] );
		$this->assertArrayHasKey( Organization::STATUS_SUSPENDED, $counts );
	}

	/**
	 * The organizations with nowhere to ship to are the ones with no location at all.
	 *
	 * The join is the whole method, and a join written the other way round — or one that
	 * counted rows rather than looking for their absence — would answer with every
	 * organization on the site, which the settings screen would then rewrite addresses for.
	 */
	public function testOrganizationsWithoutALocationAreFoundByTheirAbsence() {
		$stranded = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$supplied = $this->make_organization( array( 'name' => 'Nordwerk GmbH' ) );

		$this->make_location( $supplied );

		// Two locations, so the join cannot be answering with one row per location either.
		$this->make_location( $supplied, array( 'name' => 'Warehouse South' ) );

		$this->assertSame( 1, OrganizationRepository::count_without_locations() );

		$found = OrganizationRepository::without_locations();

		$this->assertCount( 1, $found );
		$this->assertSame( $stranded->get_id(), $found[0]->get_id() );
	}

	/**
	 * The backfill's batch takes the oldest first, so a second press resumes after it.
	 */
	public function testOrganizationsWithoutALocationComeBackOldestFirst() {
		$first  = $this->make_organization( array( 'name' => 'Erste GmbH' ) );
		$second = $this->make_organization( array( 'name' => 'Zweite GmbH' ) );

		$batch = OrganizationRepository::without_locations( 1 );

		$this->assertCount( 1, $batch );
		$this->assertSame( $first->get_id(), $batch[0]->get_id() );

		$this->make_location( $first );

		$next = OrganizationRepository::without_locations( 1 );

		$this->assertSame( $second->get_id(), $next[0]->get_id(), 'The second press did not resume where the first stopped.' );
	}

	/**
	 * A status change fires the hook the emails hang off, with the old status.
	 */
	public function testStatusChangeFiresHook() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$seen         = array();

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function ( $changed, $status, $previous ) use ( &$seen ) {
				$seen[] = array( $changed->get_id(), $status, $previous );
			},
			10,
			3
		);

		$this->assertTrue( OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_ACTIVE ) );

		$this->assertSame( array( array( $organization->get_id(), Organization::STATUS_ACTIVE, Organization::STATUS_PENDING ) ), $seen );
		$this->assertTrue( OrganizationRepository::find( $organization->get_id() )->is_active() );
	}

	/**
	 * Setting the status it already has changes nothing and fires nothing.
	 */
	public function testStatusChangeToSameStatusIsANoOp() {
		$organization = $this->make_organization();
		$fired        = 0;

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->assertFalse( OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_ACTIVE ) );
		$this->assertSame( 0, $fired );
	}

	/**
	 * An invented status is refused.
	 */
	public function testUnknownStatusIsRefused() {
		$organization = $this->make_organization();

		$this->assertFalse( OrganizationRepository::set_status( $organization->get_id(), 'vip' ) );
		$this->assertTrue( OrganizationRepository::find( $organization->get_id() )->is_active() );
	}

	/**
	 * Only an active organization may buy.
	 */
	public function testOnlyActiveIsActive() {
		foreach ( array( Organization::STATUS_PENDING, Organization::STATUS_SUSPENDED, Organization::STATUS_REJECTED ) as $status ) {
			$organization = $this->make_organization( array( 'status' => $status ) );

			$this->assertFalse( $organization->is_active(), $status . ' should not be able to buy.' );
		}
	}

	/**
	 * A column the entity does not declare is dropped rather than written.
	 */
	public function testUnknownPropertiesAreIgnored() {
		$organization = new Organization();
		$organization->set( 'is_secretly_an_admin', true );

		$this->assertArrayNotHasKey( 'is_secretly_an_admin', $organization->to_array() );
	}
}
