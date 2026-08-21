<?php
/**
 * The product datasheet a customer downloads from a cart or an order.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Datasheet\Download;
use WooOrgAccounts\Datasheet\Sheet;

/**
 * The file's shape, its mapping onto WooCommerce, and who may ask for it.
 *
 * The mapping tests are worth having one by one, but the one that catches the class of
 * bug this file is most likely to grow is `testEveryRowIsAsWideAsTheHeader`: a ragged
 * row is invisible on the screen of whoever generated it and turns into the *next*
 * column's data on the screen of whoever consumes it.
 */
class DatasheetTest extends TestCase {

	/**
	 * Build a simple product.
	 *
	 * @param array $props Properties to set, keyed by setter suffix.
	 * @return \WC_Product_Simple The saved product.
	 */
	private function make_product( array $props = array() ) {
		$product = new \WC_Product_Simple();
		$product->set_name( $props['name'] ?? 'Widget' );
		$product->set_regular_price( $props['price'] ?? '10' );

		if ( isset( $props['sku'] ) ) {
			$product->set_sku( $props['sku'] );
		}

		if ( isset( $props['gtin'] ) ) {
			$product->set_global_unique_id( $props['gtin'] );
		}

		if ( isset( $props['description'] ) ) {
			$product->set_description( $props['description'] );
		}

		if ( isset( $props['msrp'] ) ) {
			$product->update_meta_data( Sheet::MSRP_META, $props['msrp'] );
		}

		$product->save();

		return $product;
	}

	/**
	 * Create an attachment standing in for a product image.
	 *
	 * @param string $name File name.
	 * @return int Attachment ID.
	 */
	private function make_image( $name ) {
		return $this->factory->attachment->create_object(
			array(
				'file'           => $name . '.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
	}

	/**
	 * The position of a column in the file.
	 *
	 * @param string $heading The heading.
	 * @return int Index.
	 */
	private function column( $heading ) {
		return (int) array_search( $heading, Sheet::columns(), true );
	}

	/**
	 * A fully described product produces the row the feed expects.
	 *
	 * @return void
	 */
	public function testAProductMapsOntoTheFeedsColumns() {
		$product = $this->make_product(
			array(
				'name'        => 'Intelligente Knete Crystal Clear',
				'sku'         => 'IK-111010',
				'gtin'        => '8594164760334',
				'description' => '<p>Dehnt sich wie Kaugummi.</p>',
				'price'       => '9.90',
				'msrp'        => '19.80',
			)
		);

		$row = Sheet::row( $product );

		$this->assertSame( 'IK-111010', $row[ $this->column( 'Artnr' ) ] );
		$this->assertSame( '8594164760334', $row[ $this->column( 'Artean' ) ] );
		$this->assertSame( 'Intelligente Knete Crystal Clear', $row[ $this->column( 'Bez1' ) ] );
		$this->assertSame( '9.9000', $row[ $this->column( 'EK / VK1' ) ] );
		$this->assertSame( '19.8000', $row[ $this->column( 'UVP / VK3' ) ] );
	}

	/**
	 * The long description keeps its markup.
	 *
	 * @return void
	 */
	public function testTheDescriptionIsCarriedWithItsMarkup() {
		$product = $this->make_product( array( 'description' => '<ul><li>Springt wie ein Ball</li></ul>' ) );

		$this->assertSame(
			'<ul><li>Springt wie ein Ball</li></ul>',
			Sheet::row( $product )[ $this->column( 'Langtext' ) ]
		);
	}

	/**
	 * The description's entities are decoded.
	 *
	 * The shop's product sync stores numeric character references, so a description
	 * arrives holding `&#xFC;` rather than `ü`. Passed through, every umlaut in the file
	 * reads as an escape sequence to anything treating the column as text.
	 *
	 * @return void
	 */
	public function testTheDescriptionsEntitiesAreDecoded() {
		$product = $this->make_product(
			array( 'description' => 'Trendige Accessoires f&#xFC;r Kinder &amp; Jugendliche &#x2013; hochwertig.' ),
		);

		$this->assertSame(
			'Trendige Accessoires für Kinder & Jugendliche – hochwertig.',
			Sheet::row( $product )[ $this->column( 'Langtext' ) ]
		);
	}

	/**
	 * The description never spans a physical line.
	 *
	 * A quoted field holding a line break is valid RFC 4180 and every real parser reads
	 * it — but it turns one record into several lines for anything that splits the file
	 * by line first, and the feed this has to look like runs its bullet lines together
	 * for exactly that reason. The markup still carries the breaks.
	 *
	 * @return void
	 */
	public function testTheDescriptionIsPutOnOneLine() {
		$product = $this->make_product(
			array( 'description' => "<ul>\r\n<li>Springt wie ein Ball</li>\n\n<li>Trocknet nie aus</li>\n</ul>" ),
		);

		$row = Sheet::row( $product );

		$this->assertSame(
			'<ul> <li>Springt wie ein Ball</li> <li>Trocknet nie aus</li> </ul>',
			$row[ $this->column( 'Langtext' ) ]
		);

		$csv   = Sheet::render( array( $product ) );
		$lines = explode( "\r\n", rtrim( $csv, "\r\n" ) );

		$this->assertCount( 2, $lines, 'One product is one header line and one record line.' );
	}

	/**
	 * A product with no recommended retail price leaves the column empty.
	 *
	 * Empty, and never `0`: the column is a fact about the product, and a shop that has
	 * never recorded an RRP must not be made to look as though it were free.
	 *
	 * @return void
	 */
	public function testAMissingRecommendedPriceIsAnEmptyColumn() {
		$product = $this->make_product();

		$this->assertSame( '', Sheet::row( $product )[ $this->column( 'UVP / VK3' ) ] );
	}

	/**
	 * The manufacturer comes from WooCommerce's own brands taxonomy.
	 *
	 * @return void
	 */
	public function testTheManufacturerComesFromTheBrandsTaxonomy() {
		if ( ! taxonomy_exists( Sheet::BRAND_TAXONOMY ) ) {
			$this->markTestSkipped( 'This WooCommerce has no brands taxonomy.' );
		}

		$product = $this->make_product();
		$term    = wp_insert_term( 'Intelligente Knete', Sheet::BRAND_TAXONOMY );
		wp_set_object_terms( $product->get_id(), array( (int) $term['term_id'] ), Sheet::BRAND_TAXONOMY );

		$this->assertSame(
			'Intelligente Knete',
			Sheet::row( wc_get_product( $product->get_id() ) )[ $this->column( 'Hersteller' ) ]
		);
	}

	/**
	 * Every row is exactly as wide as the header, whatever the product carries.
	 *
	 * This is the invariant rather than a case, because the gallery is the one column
	 * group whose width depends on the data: a product with two images and one with
	 * twelve have to produce the same number of fields or the file is ragged.
	 *
	 * @return void
	 */
	public function testEveryRowIsAsWideAsTheHeader() {
		$bare = $this->make_product( array( 'price' => '' ) );

		$many = $this->make_product();
		$ids  = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$ids[] = $this->make_image( 'gallery-' . $index );
		}

		$many->set_image_id( $this->make_image( 'main' ) );
		$many->set_gallery_image_ids( $ids );
		$many->save();

		$width = count( Sheet::columns() );

		$this->assertCount( $width, Sheet::row( $bare ) );
		$this->assertCount( $width, Sheet::row( wc_get_product( $many->get_id() ) ) );
	}

	/**
	 * The gallery fills its columns in order and stops at the ninth.
	 *
	 * @return void
	 */
	public function testTheGalleryIsTruncatedAndPadded() {
		$product = $this->make_product();
		$ids     = array();

		for ( $index = 1; $index <= 12; $index++ ) {
			$ids[] = $this->make_image( 'gallery-' . $index );
		}

		$product->set_gallery_image_ids( $ids );
		$product->save();

		$row = Sheet::row( wc_get_product( $product->get_id() ) );

		$this->assertNotSame( '', $row[ $this->column( 'ImageURL_9' ) ] );
		$this->assertSame(
			wp_get_attachment_url( $ids[8] ),
			$row[ $this->column( 'ImageURL_9' ) ],
			'The ninth gallery image belongs in the ninth column, not the twelfth.'
		);

		$short = $this->make_product();
		$short->set_gallery_image_ids( array( $this->make_image( 'only-one' ) ) );
		$short->save();

		$this->assertSame( '', Sheet::row( wc_get_product( $short->get_id() ) )[ $this->column( 'ImageURL_9' ) ] );
	}

	/**
	 * A variation inherits what it does not carry itself.
	 *
	 * The brand is the one that cannot work any other way — the terms are on the parent
	 * post and a variation has none of its own, ever.
	 *
	 * @return void
	 */
	public function testAVariationFallsBackToItsParent() {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Knete' );
		$parent->set_sku( 'IK-PARENT' );
		$parent->set_description( 'Die Beschreibung steht am Elternprodukt.' );
		$parent->update_meta_data( Sheet::MSRP_META, '19.80' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '9.90' );
		$variation->save();

		$row = Sheet::row( wc_get_product( $variation->get_id() ) );

		$this->assertSame( 'Die Beschreibung steht am Elternprodukt.', $row[ $this->column( 'Langtext' ) ] );
		$this->assertSame( '19.8000', $row[ $this->column( 'UVP / VK3' ) ] );
		$this->assertSame( '9.9000', $row[ $this->column( 'EK / VK1' ) ] );
	}

	/**
	 * The same product on two lines is one row.
	 *
	 * @return void
	 */
	public function testAProductIsDescribedOnce() {
		$product = $this->make_product();

		$this->assertCount( 1, Sheet::rows( array( $product, $product ) ) );
	}

	/**
	 * The header is the feed's own, verbatim, whatever the site's language.
	 *
	 * The consuming system matches on these names, so translating them would hand a
	 * German shop's customer a file their own software cannot read.
	 *
	 * @return void
	 */
	public function testTheHeaderIsTheFeedsOwnAndUntranslated() {
		$csv    = Sheet::render( array() );
		$header = strtok( ltrim( $csv, "\xEF\xBB\xBF" ), "\r\n" );

		$this->assertSame(
			'Artnr;Artean;Hersteller;Bez1;Langtext;EK / VK1;UVP / VK3;MainImageURL;'
			. 'ImageURL_1;ImageURL_2;ImageURL_3;ImageURL_4;ImageURL_5;ImageURL_6;ImageURL_7;ImageURL_8;ImageURL_9',
			$header
		);
	}

	/**
	 * The file is written the way the feed is: BOM, semicolons and CRLF.
	 *
	 * @return void
	 */
	public function testTheFileIsWrittenTheWayTheFeedIs() {
		$csv = Sheet::render( array( $this->make_product( array( 'sku' => 'IK-1' ) ) ) );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'Excel reads a BOM-less UTF-8 CSV as Windows-1252.' );
		$this->assertStringContainsString( "\r\n", $csv );
		$this->assertStringNotContainsString( "\r\r", $csv );
		$this->assertStringEndsWith( "\r\n", $csv );
		$this->assertStringContainsString( 'IK-1;', $csv );
	}

	/**
	 * A field is quoted when the standard says so and not otherwise.
	 *
	 * The feed quotes nothing it does not have to, and PHP's own `fputcsv()` quotes any
	 * field holding a space — which put the header out as `"EK / VK1"` and quoted every
	 * product name on the site. Both files parse to the same values, but a consumer
	 * matching a column name as a literal string sees a different one.
	 *
	 * @return void
	 */
	public function testOnlyTheFieldsThatHaveToBeQuotedAre() {
		$csv = Sheet::render(
			array(
				$this->make_product(
					array(
						'name'        => 'Knete Lolly Pop',
						'description' => 'Dehnt sich; springt wie ein "Ball".',
					)
				),
			)
		);

		$this->assertStringContainsString( 'Knete Lolly Pop;', $csv, 'A space is not a reason to quote.' );
		$this->assertStringContainsString(
			'"Dehnt sich; springt wie ein ""Ball""."',
			$csv,
			'A field holding the delimiter or a quote has to be quoted, with its quotes doubled.'
		);
	}

	/**
	 * An order's datasheet describes the products that were bought.
	 *
	 * @return void
	 */
	public function testAnOrdersDatasheetDescribesItsProducts() {
		$product = $this->make_product( array( 'sku' => 'IK-ORDER' ) );
		$order   = wc_create_order();
		$order->add_product( $product, 2 );
		$order->save();

		$rows = Sheet::rows( Sheet::from_order( $order ) );

		$this->assertCount( 1, $rows, 'Quantity is not a column, so two of a thing is one row.' );
		$this->assertSame( 'IK-ORDER', $rows[0][ $this->column( 'Artnr' ) ] );
	}

	/**
	 * A member of another organization may not download an order's product data.
	 *
	 * The cross-tenant question, asked of the check the button and the handler both
	 * use — not of `Capabilities` alone, which is the side that always agrees.
	 *
	 * @return void
	 */
	public function testAnOutsiderMayNotDownloadAnOrdersDatasheet() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );

		$order = wc_create_order( array( 'customer_id' => $buyer->get_user_id() ) );
		$order->add_product( $this->make_product(), 1 );
		$order->update_meta_data( OrderMeta::ORGANIZATION_ID, $organization->get_id() );
		$order->save();

		$stranger = $this->make_member( $this->make_organization(), Member::ROLE_ADMIN );
		$this->act_as( $stranger );

		$this->assertFalse( Download::may_download_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * An organization admin may download a colleague's order.
	 *
	 * @return void
	 */
	public function testAnOrganizationAdminMayDownloadAColleaguesOrder() {
		$organization = $this->make_organization();
		$buyer        = $this->make_member( $organization );
		$admin        = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = wc_create_order( array( 'customer_id' => $buyer->get_user_id() ) );
		$order->add_product( $this->make_product(), 1 );
		$order->update_meta_data( OrderMeta::ORGANIZATION_ID, $organization->get_id() );
		$order->save();

		$this->act_as( $admin );

		$this->assertTrue( Download::may_download_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * The order screens get exactly one button, from the actions filter.
	 *
	 * WooCommerce's own `order/order-details.php` renders
	 * `wc_get_account_orders_actions()` in an "Actions:" row of its footer, and that
	 * template is what the view-order screen and order-received both print. So a second
	 * hook on `woocommerce_order_details_after_order_table` — which is what this had at
	 * first — puts two identical buttons on the same page, ninety pixels apart.
	 *
	 * @return void
	 */
	public function testAnOrderScreenOffersTheDatasheetOnce() {
		$download = new Download();
		$download->register();

		$this->assertFalse(
			has_action( 'woocommerce_order_details_after_order_table', array( $download, 'render_order_button' ) ),
			'The order details template already renders the actions filter.'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_my_account_my_orders_actions', array( $download, 'add_orders_list_action' ) )
		);

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );

		$order = wc_create_order( array( 'customer_id' => $member->get_user_id() ) );
		$order->add_product( $this->make_product(), 1 );
		$order->update_meta_data( OrderMeta::ORGANIZATION_ID, $organization->get_id() );
		$order->save();

		$this->act_as( $member );

		$actions = wc_get_account_orders_actions( wc_get_order( $order->get_id() ) );
		$ours    = array_filter(
			$actions,
			static function ( $action ) {
				return false !== strpos( $action['url'], 'woap_datasheet' );
			}
		);

		$this->assertCount( 1, $ours, 'The order carries exactly one datasheet action.' );
	}

	/**
	 * The button asks for no theme variant it will not get.
	 *
	 * Woodmart's selector is `.btn.btn-style-bordered`, so on a `.button` the class
	 * matches nothing — it looked like it was choosing a variant and was choosing
	 * nothing, leaving the button to take whatever the surrounding context gave it:
	 * grey in the cart, solid primary in an order's footer.
	 *
	 * @return void
	 */
	public function testTheButtonDoesNotAskForAVariantItCannotGet() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.
		$source = (string) file_get_contents( WOAP_PLUGIN_DIR . 'includes/Datasheet/Download.php' );

		$this->assertStringNotContainsString(
			'class="button btn-style-bordered',
			$source,
			'btn-style-bordered needs .btn, not .button.'
		);
	}

	/**
	 * The frontend download is handled on `template_redirect`.
	 *
	 * Structural, in the shape `AccountHandlersTest` uses: a handler that drifts onto
	 * `admin-post.php` is on a request where WooCommerce has loaded no cart at all, so
	 * the cart's datasheet would be empty rather than refused.
	 *
	 * @return void
	 */
	public function testTheFrontendDownloadIsHandledOnTemplateRedirect() {
		$download = new Download();
		$download->register();

		$this->assertNotFalse(
			has_action( 'template_redirect', array( $download, 'maybe_download' ) )
		);
		$this->assertFalse(
			has_action( 'admin_post_' . Download::ADMIN_ACTION, array( $download, 'maybe_download' ) )
		);
	}
}
