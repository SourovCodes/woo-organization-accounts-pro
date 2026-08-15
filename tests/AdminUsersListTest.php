<?php
/**
 * The organization on WordPress's own users screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\UserColumn;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Labels;

/**
 * Finding somebody by the company they work for.
 *
 * This is the screen a shop looks people up on, and until now the plugin hooked it nowhere
 * at all: a search for a company name returned "no users found" on a site where every single
 * account belongs to a company. Correct, and useless.
 *
 * The tests here are written from the outside — a real `WP_User_Query` on a real users
 * screen, asserted by which users come back — because the bug was never in a function. Every
 * function involved was right; nothing joined them.
 */
class AdminUsersListTest extends TestCase {

	/**
	 * Put the request on the users screen and register the hooks.
	 *
	 * @return UserColumn The registered instance.
	 */
	private function on_users_screen() {
		set_current_screen( 'users' );

		$columns = new UserColumn();
		$columns->register();

		return $columns;
	}

	/**
	 * Run the query the users screen would run.
	 *
	 * @param array $args Query arguments, as `users.php` would set them.
	 * @return int[] The user IDs it returns.
	 */
	private function listed( array $args = array() ) {
		$query = new \WP_User_Query(
			array_merge(
				array(
					'fields' => 'ID',
					'number' => 0,
				),
				$args
			)
		);

		return array_map( 'absint', (array) $query->get_results() );
	}

	/**
	 * Searching a company name finds the people who work there.
	 *
	 * The reported bug, asserted the way it was reported: a name that appears nowhere in
	 * `wp_users` and only on the organization, typed into the search box.
	 *
	 * @return void
	 */
	public function testSearchingACompanyNameFindsItsMembers() {
		$organization = $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) );
		$member       = $this->make_member( $organization );

		$this->on_users_screen();

		$_GET['s'] = 'wurzel';

		$found = $this->listed( array( 'search' => '*wurzel*' ) );

		$this->assertContains(
			$member->get_user_id(),
			$found,
			'Searching the users list for a company name must find the people on that account.'
		);
	}

	/**
	 * Searching still finds somebody by their own name.
	 *
	 * The answer wanted is the union of two searches, not a replacement of one by the other.
	 * Resolving the organization match to an `include` list is exactly the shape that could
	 * drop WordPress's own answer, so it is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testSearchingAPersonStillFindsThatPerson() {
		$organization = $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) );

		$member = $this->make_member( $organization );

		wp_update_user(
			array(
				'ID'           => $member->get_user_id(),
				'display_name' => 'Gudrun Steiner',
				'user_email'   => 'gudrun@wurzel.test',
			)
		);

		$this->on_users_screen();

		$_GET['s'] = 'gudrun';

		$this->assertContains(
			$member->get_user_id(),
			$this->listed( array( 'search' => '*gudrun*' ) ),
			'A search that matches a person by their own address must still find them.'
		);
	}

	/**
	 * A search matching a company does not drag in everybody else.
	 *
	 * @return void
	 */
	public function testSearchingACompanyLeavesOtherPeopleOut() {
		$wanted = $this->make_member( $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) ) );
		$other  = $this->make_member( $this->make_organization( array( 'name' => 'Baumann KG' ) ) );

		$this->on_users_screen();

		$_GET['s'] = 'wurzel';

		$found = $this->listed( array( 'search' => '*wurzel*' ) );

		$this->assertContains( $wanted->get_user_id(), $found );
		$this->assertNotContains(
			$other->get_user_id(),
			$found,
			'Widening a search by organization must not widen it to every account.'
		);
	}

	/**
	 * The tax ID and the billing address are searchable too.
	 *
	 * The same three columns the organizations list searches, so the two screens answer the
	 * same question the same way.
	 *
	 * @return void
	 */
	public function testSearchingATaxIdFindsTheMembers() {
		$organization = $this->make_organization(
			array(
				'name'   => 'Baumann KG',
				'tax_id' => 'DE811234567',
			)
		);

		$member = $this->make_member( $organization );

		$this->on_users_screen();

		$_GET['s'] = 'DE811234567';

		$this->assertContains(
			$member->get_user_id(),
			$this->listed( array( 'search' => '*DE811234567*' ) ),
			'The users search must match the same organization columns the organizations list does.'
		);
	}

	/**
	 * Filtering to an organization with nobody on it lists nobody.
	 *
	 * An empty `include` means *no restriction* to WP_User_Query, so the obvious
	 * implementation answers "this company has no employees" by listing every user on the
	 * site — the same empty-means-the-opposite trap as an empty location-access list.
	 *
	 * @return void
	 */
	public function testFilteringToAnEmptyOrganizationListsNobody() {
		$empty = $this->make_organization( array( 'name' => 'Nobody Ltd' ) );

		$this->make_member( $this->make_organization( array( 'name' => 'Baumann KG' ) ) );

		$this->on_users_screen();

		$_GET[ UserColumn::FILTER ] = (string) $empty->get_id();

		$this->assertSame(
			array(),
			$this->listed(),
			'Filtering to an organization with no members must list nobody, not everybody.'
		);
	}

	/**
	 * Filtering to an organization lists exactly its people.
	 *
	 * @return void
	 */
	public function testFilteringToAnOrganizationListsItsPeople() {
		$organization = $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) );
		$mine         = $this->make_member( $organization );
		$theirs       = $this->make_member( $this->make_organization( array( 'name' => 'Baumann KG' ) ) );

		$this->on_users_screen();

		$_GET[ UserColumn::FILTER ] = (string) $organization->get_id();

		$found = $this->listed();

		$this->assertContains( $mine->get_user_id(), $found );
		$this->assertNotContains( $theirs->get_user_id(), $found );
	}

	/**
	 * The list carries a column naming the organization.
	 *
	 * @return void
	 */
	public function testTheListCarriesAnOrganizationColumn() {
		$columns = $this->on_users_screen();

		$this->assertArrayHasKey(
			UserColumn::COLUMN,
			$columns->add_column(
				array(
					'username' => 'Username',
					'role'     => 'Role',
				)
			)
		);
	}

	/**
	 * The column names the organization, follows the site's mode, and links to it.
	 *
	 * @return void
	 */
	public function testTheColumnNamesTheOrganizationAndLinksToIt() {
		$organization = $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$columns = $this->on_users_screen();
		$cell    = $columns->render_column( '', UserColumn::COLUMN, $member->get_user_id() );

		$this->assertStringContainsString( 'Wurzel Handels AG', $cell );
		$this->assertStringContainsString( 'organization_id=' . $organization->get_id(), $cell );
		$this->assertStringContainsString( Labels::organization_admin(), $cell );
	}

	/**
	 * Somebody with no membership is left alone.
	 *
	 * An administrator, an author, a plain customer. The column is not an assertion that
	 * everybody belongs to an organization.
	 *
	 * @return void
	 */
	public function testSomebodyWithNoMembershipHasNoOrganization() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$columns = $this->on_users_screen();

		$this->assertSame(
			'&mdash;',
			$columns->render_column( '', UserColumn::COLUMN, $user_id )
		);
	}

	/**
	 * The column heading follows the site's organization mode.
	 *
	 * @return void
	 */
	public function testTheColumnHeadingFollowsTheMode() {
		$this->set_setting( 'organization_mode', Labels::MODE_EDUCATION );

		$columns = $this->on_users_screen();
		$added   = $columns->add_column( array( 'role' => 'Role' ) );

		$this->assertSame( Labels::organization(), $added[ UserColumn::COLUMN ] );
		$this->assertSame( 'Institute', $added[ UserColumn::COLUMN ] );
	}

	/**
	 * A user query somewhere else on the site is left alone.
	 *
	 * `pre_get_users` fires for every `WP_User_Query` on the request, including ones other
	 * plugins run for their own reasons. Widening one of those would be this plugin
	 * answering a question it was not asked.
	 *
	 * @return void
	 */
	public function testAQueryOutsideTheUsersScreenIsUntouched() {
		$organization = $this->make_organization( array( 'name' => 'Wurzel Handels AG' ) );

		$this->make_member( $organization );

		$this->on_users_screen();

		set_current_screen( 'dashboard' );

		$_GET['s'] = 'wurzel';

		$this->assertSame(
			array(),
			$this->listed( array( 'search' => '*wurzel*' ) ),
			'Only the users screen widens its search; every other user query is left as it was.'
		);
	}
}
