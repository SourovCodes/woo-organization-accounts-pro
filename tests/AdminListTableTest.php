<?php
/**
 * Organizations list table tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Organizations;
use WooOrgAccounts\Admin\OrganizationsListTable;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;

/**
 * The list of every organization on the site.
 *
 * The screen the shop actually works from: it is where an account is approved, where a
 * pending registration is found, and the only place the whole customer base is visible.
 * It had no tests at all, which is how it came to render a form that submitted to
 * nothing.
 */
class AdminListTableTest extends TestCase {

	/**
	 * A prepared table, with the screen wp-admin would have set up.
	 *
	 * @return OrganizationsListTable The table.
	 */
	private function table() {
		set_current_screen( 'woocommerce_page_' . Organizations::PAGE_SLUG );

		$table = new OrganizationsListTable();
		$table->prepare_items();

		return $table;
	}

	/**
	 * The names of the organizations a prepared table is showing, in order.
	 *
	 * @param OrganizationsListTable $table The table.
	 * @return string[] Names.
	 */
	private function names( OrganizationsListTable $table ) {
		return array_map(
			static function ( Organization $item ) {
				return $item->get_name();
			},
			$table->items
		);
	}

	/**
	 * Every column has a heading and a way of being rendered.
	 *
	 * `column_default()` is the fallback and prints the raw column, so a column added to
	 * the list without a renderer is not a blank cell — it is whichever database column
	 * happens to share its key, or nothing at all.
	 */
	public function testEveryColumnCanRenderItself() {
		$organization = $this->make_organization();
		$table        = $this->table();

		foreach ( array_keys( $table->get_columns() ) as $column ) {
			if ( 'cb' === $column ) {
				continue;
			}

			$this->assertTrue(
				method_exists( $table, 'column_' . $column ),
				sprintf( 'The %s column falls through to column_default() and prints a raw value.', $column )
			);
		}

		unset( $organization );
	}

	/**
	 * Every sortable column is one the repository will actually sort by.
	 *
	 * The repository refuses a column it does not know and falls back, so a heading that
	 * sorts by nothing looks like a working control that quietly does nothing.
	 */
	public function testEverySortableColumnIsOneTheRepositorySorts() {
		// Distinct timestamps as well as distinct names, or a sort by date is a tie and
		// the rows come back in whatever order the database felt like.
		$this->make_organization(
			array(
				'name'         => 'Beta',
				'status'       => Organization::STATUS_PENDING,
				'date_created' => '2026-01-02 10:00:00',
			)
		);

		$this->make_organization(
			array(
				'name'         => 'Alpha',
				'status'       => Organization::STATUS_ACTIVE,
				'date_created' => '2026-01-01 10:00:00',
			)
		);

		foreach ( ( $this->table() )->get_sortable_columns() as $column => $definition ) {
			$orderby = is_array( $definition ) ? $definition[0] : $definition;

			$_GET['orderby'] = $orderby;
			$_GET['order']   = 'desc';

			$descending = $this->names( $this->table() );

			$_GET['order'] = 'asc';

			$ascending = $this->names( $this->table() );

			$this->assertSame(
				$ascending,
				array_reverse( $descending ),
				sprintf( 'Sorting by %s did not change the order, so the heading does nothing.', $column )
			);
		}
	}

	/**
	 * The status filters count what they filter to.
	 */
	public function testTheStatusViewsCountWhatTheyLinkTo() {
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$this->make_organization( array( 'status' => Organization::STATUS_ACTIVE ) );

		$views = ( $this->table() )->get_views();

		$this->assertStringContainsString( '(2)', $views[ Organization::STATUS_PENDING ] );
		$this->assertStringContainsString( '(1)', $views[ Organization::STATUS_ACTIVE ] );
		$this->assertStringContainsString( '(0)', $views[ Organization::STATUS_REJECTED ] );
		$this->assertStringContainsString( '(3)', $views['all'] );

		$_GET['status'] = Organization::STATUS_PENDING;

		$this->assertCount( 2, ( $this->table() )->items );
	}

	/**
	 * A status the plugin does not define shows everything rather than nothing.
	 */
	public function testAnUnknownStatusFilterIsIgnored() {
		$this->make_organization();
		$this->make_organization();

		$_GET['status'] = 'vip';

		$this->assertCount( 2, ( $this->table() )->items );
	}

	/**
	 * The search box finds an organization by name.
	 */
	public function testSearchingNarrowsTheList() {
		$this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$this->make_organization( array( 'name' => 'Nordwind Handel' ) );

		$_GET['s'] = 'Hafen';

		$this->assertSame( array( 'Hafen Logistik' ), $this->names( $this->table() ) );
	}

	/**
	 * The search reads the same place the search box submits to.
	 *
	 * The table reads `$_GET['s']`, so the form around it has to be a GET form. Posted
	 * instead, the term never reached the query and searching returned the whole list —
	 * and page two of a search was not a search either, because `WP_List_Table` builds
	 * its paging links out of the current URL.
	 */
	public function testTheListFormSubmitsWhereTheTableReadsFrom() {
		$this->act_as_shop_manager();
		$this->make_organization();

		set_current_screen( 'woocommerce_page_' . Organizations::PAGE_SLUG );

		ob_start();
		( new Organizations() )->render();
		$markup = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<form[^>]*method="get"/i',
			$markup,
			'The list form must submit by GET, which is where the table reads its search, sort and page from.'
		);
	}

	/**
	 * A filtered list keeps its filter through the search box.
	 */
	public function testTheFormCarriesTheStatusFilterItIsShowing() {
		$this->act_as_shop_manager();
		$this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$_GET['status'] = Organization::STATUS_PENDING;

		set_current_screen( 'woocommerce_page_' . Organizations::PAGE_SLUG );

		ob_start();
		( new Organizations() )->render();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString(
			'name="status" value="' . Organization::STATUS_PENDING . '"',
			$markup,
			'Searching from a filtered list must stay in that filter.'
		);
	}

	/**
	 * The list pages, and page two is the rest rather than the same rows again.
	 */
	public function testThePagesDoNotOverlap() {
		for ( $index = 0; $index < OrganizationsListTable::PER_PAGE + 3; $index++ ) {
			$this->make_organization( array( 'name' => sprintf( 'Organization %02d', $index ) ) );
		}

		$first = $this->names( $this->table() );

		// get_pagenum() reads $_REQUEST, which is what a browser would have populated.
		$_GET['paged']     = 2;
		$_REQUEST['paged'] = 2;

		$second = $this->names( $this->table() );

		$this->assertCount( OrganizationsListTable::PER_PAGE, $first );
		$this->assertCount( 3, $second );
		$this->assertSame( array(), array_intersect( $first, $second ) );
	}

	/**
	 * A row links to its own organization and offers the changes that make sense for it.
	 *
	 * An already-active account is not offered "Approve", because approving it is not a
	 * change — and a row action that does nothing is worse than one that is absent.
	 */
	public function testARowOffersOnlyTheChangesThatWouldChangeSomething() {
		$active = $this->make_organization( array( 'status' => Organization::STATUS_ACTIVE ) );
		$table  = $this->table();

		$markup = $table->column_name( $active );

		$this->assertStringContainsString( Organizations::edit_url( $active->get_id() ), html_entity_decode( $markup ) );
		$this->assertStringNotContainsString( '>Approve<', $markup, 'An active account cannot be approved again.' );
		$this->assertStringContainsString( '>Suspend<', $markup );
		$this->assertStringContainsString( '>Reject<', $markup );
		$this->assertStringContainsString( '>Delete<', $markup );

		$pending = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$this->assertStringContainsString( '>Approve<', $table->column_name( $pending ) );
	}

	/**
	 * Every state-changing row action is nonced.
	 */
	public function testEveryRowActionCarriesANonce() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$markup = ( $this->table() )->column_name( $organization );

		preg_match_all( '/href="([^"]*admin-post\.php[^"]*)"/', html_entity_decode( $markup ), $matches );

		$this->assertNotEmpty( $matches[1], 'The row offers no actions at all.' );

		foreach ( $matches[1] as $url ) {
			$this->assertStringContainsString( '_wpnonce', $url, 'A row action that changes something is not nonced.' );
		}
	}

	/**
	 * The checkbox column posts the field the bulk handler reads.
	 */
	public function testTheCheckboxPostsWhatTheBulkHandlerReads() {
		$organization = $this->make_organization();

		$this->assertStringContainsString(
			'name="organization_ids[]"',
			( $this->table() )->column_cb( $organization )
		);
	}

	/**
	 * The counts in the members and locations columns are that organization's own.
	 */
	public function testTheCountColumnsCountOnlyTheirOwnOrganization() {
		$mine  = $this->make_organization();
		$other = $this->make_organization();

		$this->make_member( $mine, Member::ROLE_ADMIN );
		$this->make_member( $mine );
		$this->make_member( $other );

		$this->make_location( $mine );
		$this->make_location( $other );
		$this->make_location( $other );

		$table = $this->table();

		$this->assertSame( '2', $table->column_members( $mine ) );
		$this->assertSame( '1', $table->column_members( $other ) );
		$this->assertSame( '1', $table->column_locations( $mine ) );
		$this->assertSame( '2', $table->column_locations( $other ) );
	}

	/**
	 * A column with nothing in it prints a dash rather than an empty cell.
	 */
	public function testEmptyColumnsPrintADash() {
		$organization = $this->make_organization( array( 'tax_id' => '' ) );

		$organization->set_billing_address(
			array(
				'email' => '',
				'phone' => '',
			)
		);

		$table = $this->table();

		$this->assertSame( '&mdash;', $table->column_contact( $organization ) );
		$this->assertSame( '&mdash;', $table->column_tax_id( $organization ) );
	}

	/**
	 * The contact column prints whichever of the two it has.
	 *
	 * The billing pair, because it is the pair every order carries. When the
	 * organization had an email and a phone of its own beside these, this column could
	 * show one address while the shop was sending to another.
	 */
	public function testTheContactColumnPrintsWhatItHas() {
		$organization = $this->make_organization();

		$organization->set_billing_address(
			array(
				'email' => 'buy@acme.test',
				'phone' => '+49 30 123456',
			)
		);

		$markup = ( $this->table() )->column_contact( $organization );

		$this->assertStringContainsString( 'mailto:buy@acme.test', $markup );
		$this->assertStringContainsString( '+49 30 123456', $markup );
	}

	/**
	 * The status column carries the class the stylesheet colours it by.
	 */
	public function testTheStatusColumnIsAPill() {
		$organization = $this->make_organization( array( 'status' => Organization::STATUS_SUSPENDED ) );

		$this->assertStringContainsString(
			'woap-status--' . Organization::STATUS_SUSPENDED,
			( $this->table() )->column_status( $organization )
		);
	}

	/**
	 * The empty state names what is missing, in the site's own vocabulary.
	 */
	public function testTheEmptyStateSpeaksTheSitesVocabulary() {
		$this->set_setting( 'organization_mode', 'education' );

		ob_start();
		( $this->table() )->no_items();
		$message = (string) ob_get_clean();

		$this->assertStringContainsString( Labels::organizations(), $message );
	}

	/**
	 * The bulk actions offered are the ones the handler knows how to apply.
	 *
	 * Offering one the handler does not map is a control that appears to work, reports
	 * nothing and changes nothing.
	 */
	public function testEveryBulkActionOfferedIsOneTheHandlerApplies() {
		$this->act_as_shop_manager();

		$table = $this->table();

		foreach ( array_keys( $table->get_bulk_actions() ) as $action ) {
			$organization = $this->make_organization( array( 'status' => Organization::STATUS_PENDING ) );

			$_GET = array(
				'action'           => $action,
				'organization_ids' => array( $organization->get_id() ),
				'_wpnonce'         => wp_create_nonce( 'bulk-' . OrganizationsListTable::PLURAL ),
			);

			$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is created on the line above.

			$caught = false;

			$throw = static function ( $location ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
				throw new RedirectException( $location );
			};

			add_filter( 'wp_redirect', $throw );

			try {
				( new Organizations() )->handle_bulk_status();
			} catch ( RedirectException $redirect ) {
				$caught = true;

				unset( $redirect );
			} finally {
				remove_filter( 'wp_redirect', $throw );
			}

			$this->assertTrue( $caught, sprintf( 'The %s bulk action was offered but not applied.', $action ) );
		}
	}

	/**
	 * The nonce the table prints is the one the handler checks.
	 *
	 * Both spell it out of `PLURAL`, and this is the assertion that keeps them together:
	 * `WP_List_Table` builds `bulk-<plural>` from the arguments it was constructed with,
	 * and nothing else would notice the two drifting apart until every bulk action
	 * started dying on a nonce failure.
	 */
	public function testTheBulkNonceTheTablePrintsIsTheOneTheHandlerChecks() {
		$this->act_as_shop_manager();
		$this->make_organization();

		ob_start();
		( $this->table() )->display();
		$markup = (string) ob_get_clean();

		preg_match( '/name="_wpnonce" value="([^"]+)"/', $markup, $matches );

		$this->assertNotEmpty( $matches[1] ?? '', 'The table printed no bulk nonce.' );
		$this->assertSame(
			1,
			wp_verify_nonce( $matches[1], 'bulk-' . OrganizationsListTable::PLURAL ),
			'The nonce the table prints is not the one Organizations::handle_bulk_status() verifies.'
		);
	}
}
