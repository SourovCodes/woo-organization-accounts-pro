<?php
/**
 * Order stop per brand.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Catalog\OrderStop;

/**
 * A stopped brand's products stay visible and cannot be ordered.
 */
class OrderStopTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var OrderStop
	 */
	private $stop;

	/**
	 * A fresh brand term for every test.
	 *
	 * @var int
	 */
	private $brand;

	/**
	 * Make sure the taxonomy exists and start from an empty cache.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! taxonomy_exists( OrderStop::TAXONOMY ) ) {
			register_taxonomy( OrderStop::TAXONOMY, array( 'product' ) );
		}

		OrderStop::flush();

		$this->stop  = new OrderStop();
		$this->brand = self::factory()->term->create( array( 'taxonomy' => OrderStop::TAXONOMY ) );
	}

	/**
	 * Leave no cached answer for the next file.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'woo_org_accounts_order_stopped' );
		OrderStop::flush();

		parent::tear_down();
	}

	/**
	 * A simple product in the test brand.
	 *
	 * @return \WC_Product_Simple
	 */
	private function branded_product() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Rainbow' );
		$product->set_regular_price( '40' );
		$product->save();

		wp_set_object_terms( $product->get_id(), array( $this->brand ), OrderStop::TAXONOMY );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Stop the test brand.
	 *
	 * @param string $note Optional note.
	 * @return void
	 */
	private function stop_brand( $note = '' ) {
		update_term_meta( $this->brand, OrderStop::META_STOPPED, '1' );

		if ( '' !== $note ) {
			update_term_meta( $this->brand, OrderStop::META_NOTE, $note );
		}

		OrderStop::flush();
	}

	/**
	 * Without a stop nothing changes.
	 *
	 * @return void
	 */
	public function testUnstoppedBrandIsPurchasable() {
		$product = $this->branded_product();

		$this->assertTrue( $this->stop->filter_purchasable( true, $product ) );
		$this->assertSame( '<a>button</a>', $this->stop->loop_label( '<a>button</a>', $product ) );
	}

	/**
	 * A stopped brand's product is not purchasable and shows the label in the grid.
	 *
	 * @return void
	 */
	public function testStoppedBrandIsNotPurchasable() {
		$product = $this->branded_product();
		$this->stop_brand( 'Back in 2028' );

		$this->assertFalse( $this->stop->filter_purchasable( true, $product ) );

		$html = $this->stop->loop_label( '<a>button</a>', $product );
		$this->assertStringNotContainsString( 'button', $html );
		$this->assertStringContainsString( 'Order stop', $html );
		$this->assertStringContainsString( 'Back in 2028', $html );
	}

	/**
	 * The registered filter is what WooCommerce asks.
	 *
	 * @return void
	 */
	public function testWooCommerceSeesTheStop() {
		$this->stop->register();
		$product = $this->branded_product();
		$this->stop_brand();

		$this->assertFalse( wc_get_product( $product->get_id() )->is_purchasable() );
	}

	/**
	 * A variation is judged by its parent's brand.
	 *
	 * @return void
	 */
	public function testVariationFollowsItsParent() {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Blocks' );
		$parent->save();
		wp_set_object_terms( $parent->get_id(), array( $this->brand ), OrderStop::TAXONOMY );

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->save();

		$this->stop_brand();

		$this->assertFalse( $this->stop->filter_purchasable( true, wc_get_product( $variation->get_id() ) ) );
	}

	/**
	 * Products of other brands are untouched.
	 *
	 * @return void
	 */
	public function testOtherBrandsAreUntouched() {
		$other   = self::factory()->term->create( array( 'taxonomy' => OrderStop::TAXONOMY ) );
		$product = $this->branded_product();
		wp_set_object_terms( $product->get_id(), array( $other ), OrderStop::TAXONOMY );
		$this->stop_brand();

		$this->assertTrue( $this->stop->filter_purchasable( true, wc_get_product( $product->get_id() ) ) );
	}

	/**
	 * The filter can let a product through despite the stop.
	 *
	 * @return void
	 */
	public function testFilterCanMakeAnException() {
		$product = $this->branded_product();
		$this->stop_brand();

		add_filter( 'woo_org_accounts_order_stopped', '__return_false' );

		$this->assertTrue( $this->stop->filter_purchasable( true, $product ) );
	}

	/**
	 * The brand form saves the stop and the note, and lifts them again.
	 *
	 * @return void
	 */
	public function testFormSavesAndLifts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array(
			OrderStop::NONCE_FIELD => wp_create_nonce( OrderStop::NONCE_ACTION ),
			'woap_order_stop'      => '1',
			'woap_order_stop_note' => 'Back in 2028',
		);
		$this->stop->save_fields( $this->brand );

		$this->assertSame( '1', get_term_meta( $this->brand, OrderStop::META_STOPPED, true ) );
		$this->assertSame( 'Back in 2028', get_term_meta( $this->brand, OrderStop::META_NOTE, true ) );

		$_POST = array(
			OrderStop::NONCE_FIELD => wp_create_nonce( OrderStop::NONCE_ACTION ),
		);
		$this->stop->save_fields( $this->brand );

		$this->assertSame( '', get_term_meta( $this->brand, OrderStop::META_STOPPED, true ) );
		$this->assertSame( '', get_term_meta( $this->brand, OrderStop::META_NOTE, true ) );
	}

	/**
	 * A term update without the form — the product sync — leaves the stop alone.
	 *
	 * @return void
	 */
	public function testUpdateWithoutTheFormKeepsTheStop() {
		$this->stop->register();
		$this->stop_brand( 'Back in 2028' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_update_term( $this->brand, OrderStop::TAXONOMY, array( 'name' => 'Renamed by sync' ) );

		$this->assertSame( '1', get_term_meta( $this->brand, OrderStop::META_STOPPED, true ) );
		$this->assertSame( 'Back in 2028', get_term_meta( $this->brand, OrderStop::META_NOTE, true ) );
	}

	/**
	 * Someone who may not manage brands cannot change the stop.
	 *
	 * @return void
	 */
	public function testCustomerCannotSave() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$_POST = array(
			OrderStop::NONCE_FIELD => wp_create_nonce( OrderStop::NONCE_ACTION ),
			'woap_order_stop'      => '1',
		);
		$this->stop->save_fields( $this->brand );

		$this->assertSame( '', get_term_meta( $this->brand, OrderStop::META_STOPPED, true ) );
	}
}
