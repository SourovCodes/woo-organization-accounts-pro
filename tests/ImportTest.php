<?php
/**
 * Tests for the customer importer.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Import;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Import\Csv;
use WooOrgAccounts\Import\Importer;
use WooOrgAccounts\Import\Mapping;
use WooOrgAccounts\Import\OrganizationKey;
use WooOrgAccounts\Import\Record;
use WooOrgAccounts\Import\Report;
use WooOrgAccounts\Import\Result;
use WooOrgAccounts\Import\Run;
use WooOrgAccounts\Import\Storage;
use WooOrgAccounts\Roles;

/**
 * Covers reading an export, grouping it into organizations and writing the result.
 *
 * The fixture is a synthetic file, not a copy of anybody's customers, but every shape
 * in it was found in a real 647-row export: colleagues at one address, one company with
 * two branch addresses, a sole trader with no company name at all, a spelling that
 * differs only by an accent, an address with no postcode, two accounts sharing one
 * email address, one person with two logins, a delivery address in a different town,
 * and a row with no email address at all.
 */
class ImportTest extends TestCase {

	/**
	 * Files staged into the import directory by a test, to be removed afterwards.
	 *
	 * @var string[]
	 */
	private $staged = array();

	/**
	 * Remove anything a test left in the import directory.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->staged as $path ) {
			if ( file_exists( $path ) ) {
				Storage::delete( $path );
			}
		}

		$this->staged = array();

		delete_option( Run::OPTION );

		parent::tear_down();
	}

	/**
	 * Copy a fixture into the import directory, where a run expects to find it.
	 *
	 * @param string $name Fixture filename.
	 * @return string Absolute path to the copy.
	 */
	private function stage( $name = 'customers.csv' ) {
		$directory = Storage::directory();

		$this->assertNotWPError( $directory );

		$path = $directory . 'woap-import-' . wp_generate_password( 12, false, false ) . '.csv';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Staging a test fixture on the local filesystem.
		copy( __DIR__ . '/fixtures/' . $name, $path );

		$this->staged[] = $path;

		return $path;
	}

	/**
	 * Run a whole file through, batch by batch, as the screen does.
	 *
	 * @param array  $options Import options to override.
	 * @param string $name    Fixture filename.
	 * @return array The finished state.
	 */
	private function import( array $options = array(), $name = 'customers.csv' ) {
		$state = Run::start( $this->stage( $name ), $name );

		$this->assertNotWPError( $state );

		$state['options'] = wp_parse_args( $options, Run::default_options() );
		$state['started'] = time();

		$guard = 0;

		while ( empty( $state['finished'] ) && $guard < 50 ) {
			$state = Run::process_batch( $state );

			$this->assertNotWPError( $state );

			++$guard;
		}

		return $state;
	}

	/**
	 * The organization whose name matches, or null.
	 *
	 * @param string $name Name.
	 * @return Organization|null Organization.
	 */
	private function organization_named( $name ) {
		foreach ( OrganizationRepository::query() as $organization ) {
			if ( $organization->get_name() === $name ) {
				return $organization;
			}
		}

		return null;
	}

	/**
	 * The screen is hidden from the menu and still reachable by URL.
	 *
	 * The two halves have to be asserted together, because doing the second thing the
	 * obvious way breaks the first and nothing else notices. `remove_submenu_page()`
	 * during `admin_menu` — which is what this shipped as — left the page answering 403
	 * to everybody, including administrators: `user_can_access_admin_page()` runs from
	 * `wp-admin/includes/menu.php` as soon as `admin_menu` is done, and it does not ask
	 * the page who its parent is. `get_admin_page_parent()` finds one by searching
	 * `$submenu`, so a page taken out of `$submenu` resolves to no parent, its hook name
	 * comes out as `admin_page_<slug>` rather than `woocommerce_page_<slug>`, and the
	 * lookup misses the entry `add_submenu_page()` made under the real name.
	 *
	 * The assertion is therefore made at the two moments WordPress makes them: the
	 * access check after `admin_menu`, and the menu contents after `admin_head`.
	 *
	 * @return void
	 */
	public function testTheImportScreenIsHiddenFromTheMenuAndStillReachable() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->act_as_shop_manager();

		global $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages, $pagenow, $plugin_page, $parent_file, $typenow;

		$backup = array( $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages );

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$admin_page_hooks  = array();
		$_parent_pages     = array();
		$pagenow           = 'admin.php';
		$typenow           = '';
		$plugin_page       = Import::PAGE_SLUG;
		$parent_file       = '';

		add_menu_page( 'WooCommerce', 'WooCommerce', 'manage_woocommerce', 'woocommerce', '__return_null' );

		$screen = new Import();
		$screen->register_menu();

		$reachable = user_can_access_admin_page();

		$parent_file = '';

		$screen->hide_from_menu();

		$slugs = wp_list_pluck( (array) ( $submenu['woocommerce'] ?? array() ), 2 );

		list( $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages ) = $backup;

		$this->assertTrue( $reachable, 'An administrator was refused the import screen.' );
		$this->assertNotContains( Import::PAGE_SLUG, $slugs, 'The import screen should not sit in the menu.' );
	}

	/**
	 * The reader detects the delimiter, drops the byte order mark and counts the rows.
	 *
	 * @return void
	 */
	public function testTheFileIsReadWithItsHeadingsAndItsRowCount() {
		$csv = Csv::open( $this->stage() );

		$this->assertNotWPError( $csv );
		$this->assertSame( ',', $csv->delimiter() );
		$this->assertSame( 'id', $csv->headers()[0], 'A byte order mark would be glued to the first heading.' );
		$this->assertContains( 'billing_zipcode', $csv->headers() );
		$this->assertSame( 15, $csv->count_rows() );

		$csv->close();
	}

	/**
	 * Counting the rows leaves the cursor where it found it.
	 *
	 * @return void
	 */
	public function testCountingTheRowsDoesNotMoveTheCursor() {
		$csv = Csv::open( $this->stage() );

		$first = $csv->next();
		$csv->count_rows();
		$second = $csv->next();

		$this->assertSame( 'ada@brack.test', $first['email'] );
		$this->assertSame( 'catia@brack.test', $second['email'] );

		$csv->close();
	}

	/**
	 * A file written by Excel in German is read without mangling the columns.
	 *
	 * Semicolons, a byte order mark and Windows-1252 accents all at once, which is what
	 * "export to CSV" produces on a German copy of Excel and what a shop will actually
	 * upload.
	 *
	 * @return void
	 */
	public function testASemicolonFileWithABomAndWindowsEncodingIsRead() {
		$directory = Storage::directory();
		$path      = $directory . 'woap-import-' . wp_generate_password( 12, false, false ) . '.csv';

		$body  = "email;billing_company;billing_city\n";
		$body .= "a@example.test;M\xFCller AG;Z\xFCrich\n";

		file_put_contents( $path, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) . $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a fixture on the local filesystem.

		$this->staged[] = $path;

		$csv = Csv::open( $path );

		$this->assertSame( ';', $csv->delimiter() );
		$this->assertSame( array( 'email', 'billing_company', 'billing_city' ), $csv->headers() );

		$row = $csv->next();

		$this->assertSame( 'Müller AG', $row['billing_company'] );
		$this->assertSame( 'Zürich', $row['billing_city'] );

		$csv->close();
	}

	/**
	 * The columns are recognised from their headings, in each shop's own naming.
	 *
	 * @return void
	 */
	public function testColumnsAreRecognisedFromTheirHeadings() {
		$csv     = Csv::open( $this->stage() );
		$mapping = Mapping::detect( $csv->headers() );

		$csv->close();

		$this->assertSame( 'email', $mapping['email'] );
		$this->assertSame( 'first_name', $mapping['first_name'] );
		$this->assertSame( 'billing_street', $mapping['billing_address_1'] );
		$this->assertSame( 'billing_zipcode', $mapping['billing_postcode'] );
		$this->assertSame( 'billing_phone_number', $mapping['billing_phone'] );
		$this->assertSame( 'shipping_zipcode', $mapping['shipping_postcode'] );
		$this->assertSame( 'active', $mapping['active'] );
		$this->assertSame( '', $mapping['tax_id'], 'The file carries no tax ID column.' );
	}

	/**
	 * No column is handed to two fields.
	 *
	 * A heading matched twice is always a mistake, and the one it makes is silent: the
	 * second field reads a value that means something else.
	 *
	 * @return void
	 */
	public function testNoColumnFeedsTwoFields() {
		$csv     = Csv::open( $this->stage() );
		$mapping = array_filter( Mapping::detect( $csv->headers() ) );

		$csv->close();

		$this->assertSame( array_unique( $mapping ), $mapping );
	}

	/**
	 * Colleagues at one address are one organization, however many of them there are.
	 *
	 * This is the whole reason the person's name is not part of the key. Three people
	 * at one company with one address are the case this plugin exists for, and keying
	 * on the name would have made three organizations that cannot see each other's
	 * orders.
	 *
	 * @return void
	 */
	public function testColleaguesAtOneAddressBecomeOneOrganization() {
		$this->import();

		$organization = $this->organization_named( 'Brack GmbH' );

		$this->assertNotNull( $organization );
		$this->assertSame( 3, MemberRepository::count_for_organization( $organization->get_id() ) );
	}

	/**
	 * A company with two branch addresses arrives as two organizations.
	 *
	 * The safe direction: two records that should have been one are a merge away, and
	 * two customers wrongly merged can read each other's orders.
	 *
	 * @return void
	 */
	public function testABranchAtAnotherAddressIsItsOwnOrganization() {
		$this->import();

		$zurich    = $this->organization_named( 'Lüthy AG' );
		$solothurn = $this->organization_named( 'Luthy AG' );

		$this->assertNotNull( $zurich );
		$this->assertNotNull( $solothurn );
		$this->assertNotSame( $zurich->get_id(), $solothurn->get_id() );
	}

	/**
	 * A spelling that differs only by an accent or by case still groups.
	 *
	 * @return void
	 */
	public function testCaseAndAccentsDoNotSplitAnOrganization() {
		$this->assertSame(
			OrganizationKey::for_address(
				array(
					'company'   => 'Brack GmbH',
					'address_1' => 'Hintermättlistrasse 3',
					'postcode'  => '5506',
					'city'      => 'Mägenwil',
				)
			),
			OrganizationKey::for_address(
				array(
					'company'   => 'brack  gmbh',
					'address_1' => 'Hintermattlistrasse 3',
					'postcode'  => '5506',
					'city'      => 'MAGENWIL',
				)
			)
		);
	}

	/**
	 * A sole trader with no company name becomes an organization named after them.
	 *
	 * A shop with no guest checkout and no customers outside an organization has to give
	 * this person an account, and the alternative to naming it after them is not
	 * importing them at all.
	 *
	 * @return void
	 */
	public function testACustomerWithNoCompanyBecomesAnOrganizationOfTheirOwn() {
		$this->import();

		$organization = $this->organization_named( 'Roberta Bianda' );

		$this->assertNotNull( $organization );
		$this->assertSame( 1, MemberRepository::count_for_organization( $organization->get_id() ) );
		$this->assertSame( '', $organization->get( 'billing_company' ), 'A company name nobody gave is not invented to fill the column.' );
	}

	/**
	 * The key an organization answers with is the key its own row produced.
	 *
	 * This is what makes the import re-runnable without a column to store the key in,
	 * so it is worth asserting on the round trip rather than on either half.
	 *
	 * @return void
	 */
	public function testAStoredOrganizationAnswersWithTheKeyItWasCreatedFrom() {
		$this->import();

		$csv = Csv::open( $this->stage() );
		$map = Mapping::detect( $csv->headers() );

		$row    = $csv->next();
		$record = new Record( 1, $row, $map, Run::default_options() );

		$csv->close();

		$organization = $this->organization_named( 'Brack GmbH' );

		$this->assertSame( $record->organization_key(), OrganizationKey::for_organization( $organization ) );
	}

	/**
	 * An address WooCommerce would reject is imported, and reported.
	 *
	 * Losing a customer is worse than importing an address somebody has to fix. The
	 * warning is what makes it findable afterwards.
	 *
	 * @return void
	 */
	public function testAnAddressWithNoPostcodeIsImportedWithAWarning() {
		$state = $this->import();

		$organization = $this->organization_named( 'Holzspiel' );

		$this->assertNotNull( $organization );
		$this->assertSame( '', $organization->get( 'billing_postcode' ) );
		$this->assertGreaterThan( 0, (int) $state['flagged'] );

		$csv = Csv::open( $this->stage() );
		$map = Mapping::detect( $csv->headers() );

		$row = null;

		for ( $i = 0; $i < 7; $i++ ) {
			$row = $csv->next();
		}

		$csv->close();

		$record = new Record( 7, $row, $map, Run::default_options() );

		$this->assertNotEmpty( $record->warnings() );
		$this->assertTrue( $record->is_importable() );
	}

	/**
	 * A row with no email address cannot become an account, and says so.
	 *
	 * @return void
	 */
	public function testARowWithNoEmailAddressFails() {
		$state = $this->import();

		$this->assertSame( 1, (int) $state['counts'][ Result::FAILED ] );
		$this->assertNull( $this->organization_named( 'Ponpon' ) );
	}

	/**
	 * One email address cannot be two organizations, and the second row is left alone.
	 *
	 * WordPress will only let an address belong to one user, and this plugin will only
	 * let a user belong to one organization. The row is reported rather than guessed at.
	 *
	 * @return void
	 */
	public function testAnEmailAlreadyInAnotherOrganizationIsSkipped() {
		$state = $this->import();

		$this->assertSame( 1, (int) $state['counts'][ Result::SKIPPED ] );

		$user_id = email_exists( 'shared@example.test' );

		$this->assertNotFalse( $user_id );

		$membership   = MemberRepository::find_by_user( (int) $user_id );
		$organization = OrganizationRepository::find( $membership->get_organization_id() );

		$this->assertSame( 'Puracenter AG', $organization->get_name(), 'The first row keeps the account it created.' );
	}

	/**
	 * One person with two logins gets both, inside one organization.
	 *
	 * @return void
	 */
	public function testTwoLoginsForOnePersonJoinTheSameOrganization() {
		$this->import();

		$organization = $this->organization_named( 'Trottinette Toys AG' );

		$this->assertNotNull( $organization );
		$this->assertSame( 2, MemberRepository::count_for_organization( $organization->get_id() ) );
	}

	/**
	 * An organization any of whose rows is still active arrives active.
	 *
	 * The status arrives once per person and the copies disagree. Taking the first row's
	 * answer would suspend a working customer because a former colleague's login was
	 * closed — which is a lost customer, quietly.
	 *
	 * @return void
	 */
	public function testAnOrganizationIsActiveWhenAnyOfItsRowsIs() {
		$this->import();

		$trottinette = $this->organization_named( 'Trottinette Toys AG' );

		$this->assertSame(
			Organization::STATUS_ACTIVE,
			$trottinette->get_status(),
			'The first row was inactive and the second was not.'
		);

		$this->assertSame( Organization::STATUS_ACTIVE, $this->organization_named( 'Brack GmbH' )->get_status() );
	}

	/**
	 * The delivery address becomes a location, and one address is not stored twice.
	 *
	 * @return void
	 */
	public function testDeliveryAddressesBecomeLocationsWithoutRepeating() {
		$this->import();

		$brack     = $this->organization_named( 'Brack GmbH' );
		$locations = LocationRepository::for_organization( $brack->get_id() );

		$this->assertCount( 2, $locations, 'Two rows shared an address and the third had its own.' );
		$this->assertTrue( $locations[0]->is_default() );

		$names = array_map(
			static function ( $location ) {
				return $location->get_name();
			},
			$locations
		);

		$this->assertContains( 'Lager Nord – Zürich', $names );
	}

	/**
	 * An organization always ends up with somewhere to ship to.
	 *
	 * A row whose export carried no separate delivery address still has to be able to
	 * check out, and an organization with no location cannot.
	 *
	 * @return void
	 */
	public function testEveryImportedOrganizationHasAtLeastOneLocation() {
		$this->import();

		foreach ( OrganizationRepository::query() as $organization ) {
			$this->assertGreaterThan(
				0,
				LocationRepository::count_for_organization( $organization->get_id() ),
				$organization->get_name() . ' has nowhere to ship to.'
			);
		}
	}

	/**
	 * Every imported account can actually buy something.
	 *
	 * The end the whole import is for. A membership, an active organization and the
	 * capability, asserted together rather than one at a time.
	 *
	 * @return void
	 */
	public function testEveryImportedAccountOfAnActiveOrganizationCanPlaceOrders() {
		$this->import();

		$checked = 0;

		foreach ( OrganizationRepository::query( array( 'status' => Organization::STATUS_ACTIVE ) ) as $organization ) {
			foreach ( MemberRepository::for_organization( $organization->get_id() ) as $member ) {
				wp_set_current_user( $member->get_user_id() );
				\WooOrgAccounts\Membership\Context::flush();

				$this->assertTrue(
					\WooOrgAccounts\Membership\Context::can_purchase(),
					'An imported member of an active organization cannot check out.'
				);

				++$checked;
			}
		}

		$this->assertGreaterThan( 10, $checked );
	}

	/**
	 * Running the same file again changes nothing.
	 *
	 * The property the whole design of the key rests on: an organization is found by
	 * re-deriving its key from its own columns, so a second run recognises everything
	 * the first one made. Without it, a shop that reloads the page or re-uploads the
	 * file doubles its customer list.
	 *
	 * @return void
	 */
	public function testRunningTheSameFileTwiceCreatesNothingTheSecondTime() {
		$first = $this->import();

		$organizations = count( OrganizationRepository::query() );
		$users         = count( get_users( array( 'fields' => 'ID' ) ) );
		$locations     = $this->count_locations();

		delete_option( Run::OPTION );

		$second = $this->import();

		$this->assertSame( $organizations, count( OrganizationRepository::query() ) );
		$this->assertSame( $users, count( get_users( array( 'fields' => 'ID' ) ) ) );
		$this->assertSame( $locations, $this->count_locations() );

		$this->assertSame( 0, (int) $second['organizations'] );
		$this->assertSame( 0, (int) $second['counts'][ Result::CREATED ] );
		$this->assertGreaterThan( 0, (int) $first['counts'][ Result::CREATED ] );
	}

	/**
	 * Every location on the site.
	 *
	 * @return int Count.
	 */
	private function count_locations() {
		$total = 0;

		foreach ( OrganizationRepository::query() as $organization ) {
			$total += LocationRepository::count_for_organization( $organization->get_id() );
		}

		return $total;
	}

	/**
	 * The preview reports what the import goes on to do.
	 *
	 * The one assertion that makes the preview worth showing. A preview written as its
	 * own pass over the file would be written against the same assumptions as the
	 * importer, and would agree with it right up to the point where one of them was
	 * wrong.
	 *
	 * @return void
	 */
	public function testThePreviewAgreesWithTheImport() {
		$state = Run::start( $this->stage(), 'customers.csv' );

		$preview = Run::preview( $state );

		$this->assertNotWPError( $preview );

		$state['started'] = time();
		$guard            = 0;

		while ( empty( $state['finished'] ) && $guard < 50 ) {
			$state = Run::process_batch( $state );
			++$guard;
		}

		$this->assertSame( (int) $preview['organizations'], (int) $state['organizations'] );
		$this->assertSame( (int) $preview['locations'], (int) $state['locations'] );
		$this->assertSame( (int) $preview['flagged'], (int) $state['flagged'] );
		$this->assertSame( $preview['counts'], $state['counts'] );
		$this->assertSame( $preview['problems'], $state['problems'] );
	}

	/**
	 * What is wrong with a file is counted by problem, not by row.
	 *
	 * A real 647-row export had 581 rows with no phone number on a shop whose checkout
	 * requires one, so a screen listing the rows with something wrong listed almost
	 * every row and buried the handful of broken postcodes in the middle of it. The
	 * tally is what makes the same file readable, so a message must never carry the
	 * row's own data — that would be a tally of one, every time.
	 *
	 * @return void
	 */
	public function testProblemsAreCountedByProblemRatherThanByRow() {
		$state = $this->import();

		$this->assertNotEmpty( $state['problems'] );

		$postcode = 0;

		foreach ( $state['problems'] as $problem => $count ) {
			$this->assertIsInt( $count );
			$this->assertStringNotContainsString( '@', $problem, 'A message carrying a row\'s own address cannot be tallied.' );

			if ( false !== strpos( $problem, 'Postcode' ) ) {
				$postcode += $count;
			}
		}

		$this->assertGreaterThan( 0, $postcode, 'The row with no postcode should be counted.' );
		$this->assertSame( array_values( $state['problems'] ), wp_list_sort( array_values( $state['problems'] ), '', 'DESC' ), 'The commonest problem is listed first.' );
	}

	/**
	 * A preview leaves the database exactly as it found it.
	 *
	 * @return void
	 */
	public function testThePreviewWritesNothing() {
		$state = Run::start( $this->stage(), 'customers.csv' );

		$before = count( get_users( array( 'fields' => 'ID' ) ) );

		Run::preview( $state );

		$this->assertSame( array(), OrganizationRepository::query() );
		$this->assertSame( $before, count( get_users( array( 'fields' => 'ID' ) ) ) );
	}

	/**
	 * A WordPress account that already exists is joined up, not duplicated.
	 *
	 * A shop that has already sent some customers to the new site by hand must not end
	 * up with those customers unable to buy because the import could not create them
	 * again.
	 *
	 * @return void
	 */
	public function testAnExistingWordPressUserIsLinkedToTheirOrganization() {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'ada@brack.test',
				'role'       => 'customer',
			)
		);

		$state = $this->import();

		$this->assertSame( 1, (int) $state['counts'][ Result::LINKED ] );

		$membership = MemberRepository::find_by_user( $user_id );

		$this->assertNotNull( $membership );
		$this->assertSame( 'Brack GmbH', OrganizationRepository::find( $membership->get_organization_id() )->get_name() );
		$this->assertTrue( user_can( $user_id, Roles::PLACE_ORDERS ) );
		$this->assertContains( Roles::ROLE_ORG_ADMIN, get_userdata( $user_id )->roles );
	}

	/**
	 * Somebody who can manage the shop keeps the role that lets them.
	 *
	 * `set_role()` replaces every role a user holds, so an administrator who also buys
	 * on an organization's account is one call away from being locked out of wp-admin by
	 * an import.
	 *
	 * @return void
	 */
	public function testAnAdministratorKeepsTheirRole() {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'ada@brack.test',
				'role'       => 'administrator',
			)
		);

		$this->import();

		$this->assertContains( 'administrator', get_userdata( $user_id )->roles );
		$this->assertNotNull( MemberRepository::find_by_user( $user_id ) );
	}

	/**
	 * By default every imported account administers its own organization.
	 *
	 * @return void
	 */
	public function testEveryImportedAccountAdministersItsOrganizationByDefault() {
		$this->import();

		$organization = $this->organization_named( 'Brack GmbH' );

		foreach ( MemberRepository::for_organization( $organization->get_id() ) as $member ) {
			$this->assertSame( Member::ROLE_ADMIN, $member->get_role() );
		}
	}

	/**
	 * The other option makes only the first account of each organization an admin.
	 *
	 * @return void
	 */
	public function testTheFirstAdminOptionLeavesLaterAccountsAsMembers() {
		$this->import( array( 'role_mode' => 'first_admin' ) );

		$organization = $this->organization_named( 'Brack GmbH' );
		$roles        = array();

		foreach ( MemberRepository::for_organization( $organization->get_id() ) as $member ) {
			$roles[] = $member->get_role();
		}

		sort( $roles );

		$this->assertSame( array( Member::ROLE_ADMIN, Member::ROLE_MEMBER, Member::ROLE_MEMBER ), $roles );
	}

	/**
	 * The legal form is part of the name unless the shop says otherwise.
	 *
	 * @return void
	 */
	public function testTheLegalFormSplitsTwoSpellingsUnlessTheShopSaysOtherwise() {
		$this->import();

		$this->assertNotNull( $this->organization_named( 'Jouets au petit bois' ) );
		$this->assertNotNull( $this->organization_named( 'Jouets au petit bois sàrl' ) );
	}

	/**
	 * With the option on, they are one organization.
	 *
	 * @return void
	 */
	public function testStrippingTheLegalFormMergesTwoSpellingsAtOneAddress() {
		$this->import( array( 'ignore_legal_form' => true ) );

		$organization = $this->organization_named( 'Jouets au petit bois' );

		$this->assertNotNull( $organization );
		$this->assertNull( $this->organization_named( 'Jouets au petit bois sàrl' ) );
		$this->assertSame( 2, MemberRepository::count_for_organization( $organization->get_id() ) );
	}

	/**
	 * Importing six hundred customers does not send six hundred emails.
	 *
	 * @return void
	 */
	public function testNoEmailIsSentWhileImporting() {
		$sent = 0;

		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( &$sent ) {
				++$sent;

				return $short_circuit;
			},
			1
		);

		$this->import();

		$this->assertSame( 0, $sent );
	}

	/**
	 * The report carries a line per row, with the old shop's customer number on it.
	 *
	 * The customer number has nowhere to live in this plugin's tables, and inventing a
	 * column for it would be a field with no destination. The report is where the two
	 * systems are reconciled, so it has to be there.
	 *
	 * @return void
	 */
	public function testTheReportListsEveryRowAgainstItsCustomerNumber() {
		$state = Run::start( $this->stage(), 'customers.csv' );

		$report = Storage::report_path();

		$this->assertNotWPError( $report );
		$this->assertTrue( Report::start( $report ) );

		$this->staged[]   = $report;
		$state['report']  = $report;
		$state['started'] = time();
		$guard            = 0;

		while ( empty( $state['finished'] ) && $guard < 50 ) {
			$state = Run::process_batch( $state );
			++$guard;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this test wrote, on the local filesystem.
		$contents = file_get_contents( $report );

		$this->assertStringContainsString( 'ada@brack.test', $contents );
		$this->assertStringContainsString( '107', $contents, 'The customer number of the duplicated address.' );
		$this->assertSame( 16, substr_count( $contents, "\n" ), 'One heading row and fifteen rows.' );
	}

	/**
	 * A run is not allowed to leave the customer export sitting in the uploads folder.
	 *
	 * @return void
	 */
	public function testTheUploadedFileIsDeletedWhenTheRunFinishes() {
		$path  = $this->stage();
		$state = Run::start( $path, 'customers.csv' );

		$state['started'] = time();

		while ( empty( $state['finished'] ) ) {
			$state = Run::process_batch( $state );
		}

		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * The import directory refuses to hand out a path that is not inside it.
	 *
	 * @return void
	 */
	public function testStorageOnlyOwnsItsOwnFiles() {
		$this->assertTrue( Storage::owns( $this->stage() ) );
		$this->assertFalse( Storage::owns( ABSPATH . 'wp-config.php' ) );
		$this->assertFalse( Storage::owns( Storage::directory() . '../../wp-config.php' ) );
	}

	/**
	 * An organization already on the site is joined rather than duplicated.
	 *
	 * The same mechanism as a second run, from the other end: an organization that
	 * registered by hand is found by the import, because the key is derived from the
	 * columns rather than stamped on at import time.
	 *
	 * @return void
	 */
	public function testAnOrganizationThatRegisteredByHandIsFoundByTheImport() {
		$organization = $this->make_organization(
			array(
				'name'   => 'Brack GmbH',
				'status' => Organization::STATUS_ACTIVE,
			)
		);

		$organization->set_billing_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Byron',
				'company'    => 'Brack GmbH',
				'address_1'  => 'Hintermättlistrasse 3',
				'city'       => 'Mägenwil',
				'postcode'   => '5506',
				'country'    => 'CH',
				'email'      => 'ada@brack.test',
			)
		);

		OrganizationRepository::save( $organization );

		$this->import();

		$this->assertCount(
			1,
			array_filter(
				OrganizationRepository::query(),
				static function ( $candidate ) {
					return 'Brack GmbH' === $candidate->get_name();
				}
			)
		);

		$this->assertSame( 3, MemberRepository::count_for_organization( $organization->get_id() ) );
	}

	/**
	 * A row is refused only for the one thing that cannot be repaired later.
	 *
	 * An importer that refused rows for a bad postcode would drop customers a shop
	 * cannot get back, so the rule is asserted from the other side: nothing but a
	 * missing or malformed email address makes a row unimportable.
	 *
	 * @return void
	 */
	public function testOnlyAMissingEmailAddressMakesARowUnimportable() {
		$csv    = Csv::open( $this->stage() );
		$map    = Mapping::detect( $csv->headers() );
		$number = 0;

		$row = $csv->next();

		while ( null !== $row ) {
			++$number;

			$record = new Record( $number, $row, $map, Run::default_options() );

			$this->assertSame(
				'' !== $record->email() && is_email( $record->email() ),
				$record->is_importable(),
				'Row ' . $number . ' was judged on something other than its email address.'
			);

			$row = $csv->next();
		}

		$csv->close();
	}

	/**
	 * The importer never writes a member with capability overrides.
	 *
	 * Overrides are a diff against the role's defaults, so storing an empty set is not
	 * the same as storing every capability off — and an import that got that wrong would
	 * produce organization admins who could manage nothing.
	 *
	 * @return void
	 */
	public function testImportedMembersCarryNoCapabilityOverrides() {
		$this->import();

		foreach ( OrganizationRepository::query() as $organization ) {
			foreach ( MemberRepository::for_organization( $organization->get_id() ) as $member ) {
				$this->assertSame( array(), $member->get_capabilities() );
			}
		}
	}

	/**
	 * A location with no company of its own is labelled with the organization's.
	 *
	 * The same fallback the account screen applies: a parcel with no company on the
	 * label is one nobody at a loading bay recognises.
	 *
	 * @return void
	 */
	public function testALocationWithNoCompanyTakesTheOrganizationsName() {
		$this->import();

		$organization = $this->organization_named( 'Roberta Bianda' );
		$locations    = LocationRepository::for_organization( $organization->get_id() );

		$this->assertSame( 'Roberta Bianda', $locations[0]->get( 'company' ) );
	}

	/**
	 * An importer instance reports the organizations it made, not the rows that made them.
	 *
	 * @return void
	 */
	public function testTheImporterCountsOrganizationsRatherThanRows() {
		$csv      = Csv::open( $this->stage() );
		$map      = Mapping::detect( $csv->headers() );
		$importer = new Importer( Run::default_options(), true );
		$number   = 0;

		$row = $csv->next();

		while ( null !== $row ) {
			++$number;
			$importer->import( new Record( $number, $row, $map, Run::default_options() ) );

			$row = $csv->next();
		}

		$csv->close();

		$this->assertSame( 10, $importer->organizations_created() );
	}
}
