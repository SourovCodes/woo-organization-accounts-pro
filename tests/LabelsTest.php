<?php
/**
 * Organization mode tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

/**
 * The site's single organization mode, and the vocabulary that follows from it.
 */
class LabelsTest extends TestCase {

	/**
	 * A fresh site is in business mode.
	 */
	public function testDefaultsToBusiness() {
		$this->assertSame( Labels::MODE_BUSINESS, Labels::mode() );
		$this->assertFalse( Labels::is_education() );
	}

	/**
	 * Business mode uses the company vocabulary.
	 */
	public function testBusinessVocabulary() {
		$this->set_setting( 'organization_mode', Labels::MODE_BUSINESS );

		$this->assertSame( 'Company', Labels::organization() );
		$this->assertSame( 'Companies', Labels::organizations() );
		$this->assertSame( 'Company Admin', Labels::organization_admin() );
		$this->assertSame( 'Employee', Labels::member() );
		$this->assertSame( 'Employees', Labels::members() );
		$this->assertSame( 'Branch', Labels::location() );
		$this->assertSame( 'Branches', Labels::locations() );
	}

	/**
	 * Educational mode uses the institute vocabulary.
	 */
	public function testEducationVocabulary() {
		$this->set_setting( 'organization_mode', Labels::MODE_EDUCATION );

		$this->assertTrue( Labels::is_education() );
		$this->assertSame( 'Institute', Labels::organization() );
		$this->assertSame( 'Institutes', Labels::organizations() );
		$this->assertSame( 'Institute Admin', Labels::organization_admin() );
		$this->assertSame( 'Staff Member', Labels::member() );
		$this->assertSame( 'Staff', Labels::members() );
		$this->assertSame( 'Campus', Labels::location() );
		$this->assertSame( 'Campuses', Labels::locations() );
	}

	/**
	 * An unknown mode falls back to business rather than showing nothing.
	 */
	public function testUnknownModeFallsBack() {
		$this->set_setting( 'organization_mode', 'charity' );

		$this->assertSame( Labels::MODE_BUSINESS, Labels::mode() );
		$this->assertSame( 'Company', Labels::organization() );
	}

	/**
	 * Only the two modes are offered.
	 */
	public function testOnlyTwoModesAreOffered() {
		$modes = Labels::modes();

		$this->assertCount( 2, $modes );
		$this->assertArrayHasKey( Labels::MODE_BUSINESS, $modes );
		$this->assertArrayHasKey( Labels::MODE_EDUCATION, $modes );
	}

	/**
	 * The mode is a site setting, not a column: nothing about an organization records it.
	 */
	public function testNoOrganizationTypeIsStored() {
		$columns = array_keys( Organization::defaults() );

		$this->assertNotContains( 'organization_type', $columns );
		$this->assertNotContains( 'type', $columns );
		$this->assertNotContains( 'mode', $columns );
	}

	/**
	 * Changing the mode changes every account menu label at once.
	 */
	public function testAccountMenuFollowsTheMode() {
		$this->set_setting( 'organization_mode', Labels::MODE_BUSINESS );

		$business = MyAccount::menu_labels();

		$this->assertSame( 'Company', $business[ MyAccount::ENDPOINT_PROFILE ] );
		$this->assertSame( 'Employees', $business[ MyAccount::ENDPOINT_MEMBERS ] );
		$this->assertSame( 'Company orders', $business[ MyAccount::ENDPOINT_ORDERS ] );

		$this->set_setting( 'organization_mode', Labels::MODE_EDUCATION );

		$education = MyAccount::menu_labels();

		$this->assertSame( 'Institute', $education[ MyAccount::ENDPOINT_PROFILE ] );
		$this->assertSame( 'Staff', $education[ MyAccount::ENDPOINT_MEMBERS ] );
		$this->assertSame( 'Campuses', $education[ MyAccount::ENDPOINT_LOCATIONS ] );
	}

	/**
	 * Switching the mode needs no migration: the data is untouched.
	 */
	public function testSwitchingModeLeavesDataAlone() {
		$organization = $this->make_organization();
		$before       = $organization->to_array();

		$this->set_setting( 'organization_mode', Labels::MODE_EDUCATION );

		$after = \WooOrgAccounts\Data\OrganizationRepository::find( $organization->get_id() )->to_array();

		$this->assertSame( $before['name'], $after['name'] );
		$this->assertSame( $before['status'], $after['status'] );
	}

	/**
	 * An invented mode cannot be saved.
	 */
	public function testSanitizeRefusesAnUnknownMode() {
		$settings = ( new Settings() )->sanitize( array( 'organization_mode' => 'charity' ) );

		$this->assertSame( Labels::MODE_BUSINESS, $settings['organization_mode'] );
	}
}
