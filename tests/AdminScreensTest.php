<?php
/**
 * The rest of what this plugin adds to wp-admin.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\OrderColumn;
use WooOrgAccounts\Admin\Organizations;
use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

/**
 * The settings screen, the order column and the theme panel.
 *
 * `SettingsTest` covers the shape of the stored option; this covers the screens that
 * read and write it, which is where a setting that exists but cannot be changed, or a
 * column that shows the wrong account, would actually show up.
 */
class AdminScreensTest extends TestCase {

	/**
	 * An order belonging to an organization.
	 *
	 * @param Organization $organization The organization.
	 * @return \WC_Order The order.
	 */
	private function make_order( Organization $organization ) {
		$order = wc_create_order();

		$order->update_meta_data( '_woap_organization_id', $organization->get_id() );
		$order->update_meta_data( '_woap_organization_name', $organization->get_name() );
		$order->save();

		return $order;
	}

	/**
	 * The organization column is offered on the orders list, under its own name.
	 */
	public function testTheOrdersListGainsAnOrganizationColumn() {
		$columns = ( new OrderColumn() )->add_column(
			array(
				'order_number' => 'Order',
				'order_date'   => 'Date',
			)
		);

		$this->assertArrayHasKey( 'woap_organization', $columns );
		$this->assertSame( Labels::organization(), $columns['woap_organization'] );
		$this->assertSame(
			array( 'order_number', 'woap_organization', 'order_date' ),
			array_keys( $columns ),
			'The column belongs beside the order number, not at the end of the row.'
		);
	}

	/**
	 * A list with no order number column still gets the column.
	 */
	public function testTheColumnIsAddedEvenWithNothingToSitBeside() {
		$columns = ( new OrderColumn() )->add_column( array( 'order_date' => 'Date' ) );

		$this->assertArrayHasKey( 'woap_organization', $columns );
	}

	/**
	 * The column reads the order's own snapshot, and links to the live record.
	 */
	public function testTheColumnNamesTheOrganizationAndLinksToIt() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$order        = $this->make_order( $organization );

		ob_start();
		( new OrderColumn() )->render_column( 'woap_organization', $order );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Hafen Logistik', $markup );
		$this->assertStringContainsString( Organizations::edit_url( $organization->get_id() ), html_entity_decode( $markup ) );
	}

	/**
	 * The name on an order is a snapshot, so a rename does not rewrite history.
	 */
	public function testTheColumnKeepsTheNameTheOrderWasPlacedUnder() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$order        = $this->make_order( $organization );

		$organization->set( 'name', 'Hafen Logistik AG' );
		OrganizationRepository::save( $organization );

		ob_start();
		( new OrderColumn() )->render_column( 'woap_organization', wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Hafen Logistik', $markup );
		$this->assertStringNotContainsString( 'Hafen Logistik AG', $markup );
	}

	/**
	 * An order belonging to no organization prints a dash rather than a broken link.
	 */
	public function testAnOrderWithNoOrganizationPrintsADash() {
		$order = wc_create_order();

		ob_start();
		( new OrderColumn() )->render_column( 'woap_organization', $order );
		$markup = (string) ob_get_clean();

		$this->assertSame( '&mdash;', $markup );
	}

	/**
	 * A deleted organization leaves the name it was, without a link to nothing.
	 */
	public function testADeletedOrganizationStillNamesItself() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$order        = $this->make_order( $organization );

		$order->update_meta_data( '_woap_organization_id', 0 );
		$order->save();

		ob_start();
		( new OrderColumn() )->render_column( 'woap_organization', wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Hafen Logistik', $markup );
		$this->assertStringNotContainsString( '<a ', $markup );
	}

	/**
	 * Another column's turn is not ours to print into.
	 */
	public function testTheColumnPrintsNothingForOtherColumns() {
		$organization = $this->make_organization();
		$order        = $this->make_order( $organization );

		ob_start();
		( new OrderColumn() )->render_column( 'order_total', $order );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * The order panel reports the organization, the member and the delivery location.
	 */
	public function testTheOrderPanelReportsWhoTheOrderWasFor() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$location     = $this->make_location( $organization, array( 'name' => 'Depot Ost' ) );

		$order = $this->make_order( $organization );

		$order->update_meta_data( '_woap_location_id', $location->get_id() );
		$order->update_meta_data( '_woap_location_name', $location->get_name() );
		$order->update_meta_data( '_woap_member_user_id', $member->get_user_id() );
		$order->save();

		ob_start();
		( new OrderColumn() )->render_meta_box( wc_get_order( $order->get_id() ) );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'Hafen Logistik', $markup );
		$this->assertStringContainsString( 'Depot Ost', $markup );
	}

	/**
	 * The panel says so plainly when an order belongs to no organization.
	 */
	public function testTheOrderPanelSaysWhenThereIsNoOrganization() {
		$order = wc_create_order();

		ob_start();
		( new OrderColumn() )->render_meta_box( $order );
		$markup = (string) ob_get_clean();

		$this->assertNotSame( '', trim( $markup ), 'An unlinked order must be reported, not left blank.' );
		$this->assertStringContainsString( Labels::organization(), $markup );
	}

	/**
	 * Every setting the plugin defines has a field on the screen that changes it.
	 *
	 * A default with no control is a setting only a developer can reach, and one that
	 * silently keeps whatever the last import left in the option.
	 */
	public function testEverySettingHasAControlOnTheScreen() {
		$this->act_as_shop_manager();

		// The registration page control is a list of the site's pages, and the plugin
		// creates one on activation — a site with none has nothing to offer.
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		set_current_screen( 'woocommerce_page_' . Settings::PAGE_SLUG );

		$settings = new Settings();
		$settings->register_settings();

		ob_start();
		$settings->render_page();
		$markup = (string) ob_get_clean();

		foreach ( array_keys( Settings::get_settings() ) as $key ) {
			$this->assertStringContainsString(
				Settings::OPTION_KEY . '[' . $key . ']',
				$markup,
				sprintf( 'The %s setting has no control on the settings screen.', $key )
			);
		}
	}

	/**
	 * The screen writes back to the option the plugin reads.
	 */
	public function testTheScreenWritesToTheOptionThePluginReads() {
		$this->act_as_shop_manager();

		set_current_screen( 'woocommerce_page_' . Settings::PAGE_SLUG );

		$settings = new Settings();
		$settings->register_settings();

		ob_start();
		$settings->render_page();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'option_page', $markup );
		$this->assertStringContainsString( 'options.php', $markup );
	}

	/**
	 * Sanitising is what the screen saves through, and it is the same rules as the API.
	 */
	public function testTheScreenSavesThroughTheSameSanitiser() {
		$clean = ( new Settings() )->sanitize(
			array(
				'organization_mode'      => 'education',
				'require_approval'       => '1',
				'invitation_expiry_days' => '9999',
				'not_a_setting'          => 'ignored',
			)
		);

		$this->assertSame( 'education', $clean['organization_mode'] );
		$this->assertTrue( $clean['require_approval'] );
		$this->assertArrayNotHasKey( 'not_a_setting', $clean );
		$this->assertSame( 365, $clean['invitation_expiry_days'], 'The expiry is capped rather than stored as typed.' );
	}

	/**
	 * A save that carried no page control keeps the page that is configured.
	 *
	 * The control is a list of the site's pages, so on a site with none — every page
	 * trashed, or the registration page left as a draft — the screen renders the row
	 * with no field in it and the submission carries nothing for that setting. Read as
	 * "not chosen" it would reset to zero on the next save of any other setting, and
	 * the registration page the whole signup flow links to would quietly detach.
	 */
	public function testSavingWithNoPageControlKeepsTheConfiguredPage() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->set_setting( 'registration_page_id', $page_id );

		$clean = ( new Settings() )->sanitize( array( 'organization_mode' => 'business' ) );

		$this->assertSame( $page_id, $clean['registration_page_id'] );
	}

	/**
	 * Something that is not a page cannot be made the registration page.
	 */
	public function testTheRegistrationPageMustBeAPage() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->set_setting( 'registration_page_id', $page_id );

		$clean = ( new Settings() )->sanitize(
			array( 'registration_page_id' => self::factory()->post->create( array( 'post_type' => 'post' ) ) )
		);

		$this->assertSame( $page_id, $clean['registration_page_id'] );
	}

	/**
	 * The update check is nonced, and refuses somebody who may not update plugins.
	 */
	public function testTheUpdateCheckNeedsPermission() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_GET     = array( '_wpnonce' => wp_create_nonce( 'woap_check_updates' ) );
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is created on the line above.

		$this->expectException( \WPDieException::class );

		( new Settings() )->handle_check_updates();
	}

	/**
	 * Without its nonce the update check is refused too.
	 */
	public function testTheUpdateCheckNeedsItsNonce() {
		$this->act_as_shop_manager();

		$_GET     = array( '_wpnonce' => 'not the nonce' );
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Deliberately wrong; the handler is expected to refuse it.

		$this->expectException( \WPDieException::class );

		( new Settings() )->handle_check_updates();
	}

	/**
	 * The order meta the column reads is the meta the checkout writes.
	 *
	 * Two halves again: `OrderMeta` names the keys and the column reads them back. A
	 * renamed key would leave every order in the list reporting no organization.
	 */
	public function testTheColumnReadsTheKeysTheCheckoutWrites() {
		$organization = $this->make_organization( array( 'name' => 'Hafen Logistik' ) );
		$order        = $this->make_order( $organization );

		$this->assertSame( $organization->get_id(), OrderMeta::organization_id( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 'Hafen Logistik', OrderMeta::organization_name( wc_get_order( $order->get_id() ) ) );
	}
}
