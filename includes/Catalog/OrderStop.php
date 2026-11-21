<?php
/**
 * Order stop per brand.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the shop stop orders for a whole brand while its products stay on show.
 *
 * A supplier that is sold out for a year is not a reason to take its range off the
 * site: customers still look for it, and the catalogue is how they find out it exists.
 * So the products keep their pages and their prices, and only the ability to order goes:
 * where the basket button was, the shop shows an "Order stop" label and, if the shop
 * wrote one, a note such as when the brand is expected back.
 *
 * **It is a setting on the brand, not a list of products.** A brand is what the
 * supplier's stop is about, and every product the sync adds to that brand later is
 * stopped with it. It lives on the brand's own edit screen (Products → Brands) as two
 * term meta values, and switching it off again restores everything.
 *
 * **This is the one place `woocommerce_is_purchasable` is the right tool.** Customer
 * view refuses additions and leaves the basket alone, because it is a presentation mode
 * and emptying a retailer's basket would destroy real work. An order stop is the
 * opposite case: a stopped product must not be ordered at all, including when it is
 * already in a basket. Not purchasable is the one answer WooCommerce enforces everywhere
 * — adding to the basket, restoring a saved basket (it removes the item and says why),
 * the classic checkout and the Store API — so one filter covers all of them.
 *
 * Orders the shop enters itself are untouched: wp-admin adds order items without asking
 * whether a product is purchasable. That is deliberate — a stop is about customers, and
 * the shop may still have a reason to book one.
 *
 * `woo_org_accounts_order_stopped` decides the final answer, so exceptions — a
 * customer allowed to order a fixed quantity despite the stop — can be added without
 * touching this class.
 */
class OrderStop {

	/**
	 * The taxonomy an order stop is set on.
	 */
	const TAXONOMY = 'product_brand';

	/**
	 * Term meta: whether the brand is stopped ('1' or absent).
	 */
	const META_STOPPED = 'woap_order_stop';

	/**
	 * Term meta: the note shown under the label.
	 */
	const META_NOTE = 'woap_order_stop_note';

	/**
	 * Nonce action for the brand form fields.
	 */
	const NONCE_ACTION = 'woap_order_stop_term';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'woap_order_stop_nonce';

	/**
	 * Stopped brand IDs for this request.
	 *
	 * @var int[]|null
	 */
	private static $stopped = null;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_label' ), 20, 2 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'single_label' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );

		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'render_edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_fields' ) );
	}

	/**
	 * The IDs of every stopped brand.
	 *
	 * @return int[] Term IDs.
	 */
	public static function stopped_brand_ids() {
		if ( null === self::$stopped ) {
			$ids = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_key'   => self::META_STOPPED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			self::$stopped = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}

		return self::$stopped;
	}

	/**
	 * Forget the request cache, after a brand was saved.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$stopped = null;
	}

	/**
	 * The stopped brand a product belongs to, if any.
	 *
	 * A variation is judged by its parent, which is what carries the brand.
	 *
	 * @param \WC_Product $product The product.
	 * @return int Brand term ID, or 0 when the product is not stopped.
	 */
	public static function stopped_brand( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}

		$stopped = self::stopped_brand_ids();

		if ( empty( $stopped ) ) {
			return 0;
		}

		$id     = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$brands = array_intersect( $stopped, wc_get_product_term_ids( $id, self::TAXONOMY ) );

		return $brands ? (int) reset( $brands ) : 0;
	}

	/**
	 * Whether a product may not be ordered because of an order stop.
	 *
	 * @param \WC_Product $product The product.
	 * @return bool True when stopped.
	 */
	public static function is_stopped( $product ) {
		$brand = self::stopped_brand( $product );

		/**
		 * Filters whether a product is under an order stop.
		 *
		 * The hook for exceptions: return false to let the current customer order a
		 * product whose brand is stopped.
		 *
		 * @since 0.18.0
		 *
		 * @param bool        $stopped Whether the product is stopped.
		 * @param \WC_Product $product The product.
		 * @param int         $brand   The stopped brand's term ID, or 0.
		 */
		return (bool) apply_filters( 'woo_org_accounts_order_stopped', $brand > 0, $product, $brand );
	}

	/**
	 * The note shown under the label for a product, or an empty string.
	 *
	 * @param \WC_Product $product The product.
	 * @return string Note.
	 */
	public static function note( $product ) {
		$brand = self::stopped_brand( $product );

		return $brand ? (string) get_term_meta( $brand, self::META_NOTE, true ) : '';
	}

	/**
	 * A stopped product is not purchasable.
	 *
	 * @param bool        $purchasable Whether WooCommerce considers it purchasable.
	 * @param \WC_Product $product     The product.
	 * @return bool False for a stopped product.
	 */
	public function filter_purchasable( $purchasable, $product ) {
		if ( ! $purchasable ) {
			return $purchasable;
		}

		return self::is_stopped( $product ) ? false : $purchasable;
	}

	/**
	 * The label in a product grid, where the basket button would be.
	 *
	 * @param string      $link    The button markup.
	 * @param \WC_Product $product The product.
	 * @return string Markup.
	 */
	public function loop_label( $link, $product = null ) {
		if ( ! $product instanceof \WC_Product || ! self::is_stopped( $product ) ) {
			return $link;
		}

		return self::label_html( $product, 'woap-order-stop--loop' );
	}

	/**
	 * The label on a product page, where the add-to-cart form would be.
	 *
	 * @return void
	 */
	public function single_label() {
		global $product;

		if ( ! $product instanceof \WC_Product || ! self::is_stopped( $product ) ) {
			return;
		}

		echo self::label_html( $product, 'woap-order-stop--single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in label_html().
	}

	/**
	 * The label markup.
	 *
	 * @param \WC_Product $product  The product.
	 * @param string      $modifier Extra class.
	 * @return string Markup.
	 */
	public static function label_html( $product, $modifier = '' ) {
		$note = self::note( $product );

		return sprintf(
			'<div class="woap-order-stop %1$s"><span class="woap-order-stop__label">%2$s</span>%3$s</div>',
			esc_attr( $modifier ),
			esc_html__( 'Order stop', 'woo-organization-accounts-pro' ),
			'' !== $note ? '<span class="woap-order-stop__note">' . esc_html( $note ) . '</span>' : ''
		);
	}

	/**
	 * Load the label's stylesheet while any brand is stopped.
	 *
	 * @return void
	 */
	public function enqueue_style() {
		if ( empty( self::stopped_brand_ids() ) ) {
			return;
		}

		wp_enqueue_style( 'woap-order-stop', WOAP_PLUGIN_URL . 'assets/css/order-stop.css', array(), WOAP_VERSION );
	}

	/**
	 * The fields on the "Add brand" form.
	 *
	 * @return void
	 */
	public function render_add_fields() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label><input type="checkbox" name="woap_order_stop" value="1"> <?php esc_html_e( 'Order stop', 'woo-organization-accounts-pro' ); ?></label>
			<p class="description"><?php esc_html_e( 'Products of this brand stay visible with their prices, but cannot be ordered.', 'woo-organization-accounts-pro' ); ?></p>
		</div>
		<div class="form-field">
			<label for="woap-order-stop-note"><?php esc_html_e( 'Order stop note', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" id="woap-order-stop-note" name="woap_order_stop_note" value="">
			<p class="description"><?php esc_html_e( 'Optional. Shown under the label, for example when the brand is expected back.', 'woo-organization-accounts-pro' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The fields on a brand's edit form.
	 *
	 * @param \WP_Term $term The brand.
	 * @return void
	 */
	public function render_edit_fields( $term ) {
		$stopped = '1' === get_term_meta( $term->term_id, self::META_STOPPED, true );
		$note    = (string) get_term_meta( $term->term_id, self::META_NOTE, true );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Order stop', 'woo-organization-accounts-pro' ); ?></th>
			<td>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<label><input type="checkbox" name="woap_order_stop" value="1"<?php checked( $stopped ); ?>> <?php esc_html_e( 'Stop orders for this brand', 'woo-organization-accounts-pro' ); ?></label>
				<p class="description"><?php esc_html_e( 'Products of this brand stay visible with their prices, but cannot be ordered.', 'woo-organization-accounts-pro' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="woap-order-stop-note"><?php esc_html_e( 'Order stop note', 'woo-organization-accounts-pro' ); ?></label></th>
			<td>
				<input type="text" id="woap-order-stop-note" name="woap_order_stop_note" value="<?php echo esc_attr( $note ); ?>">
				<p class="description"><?php esc_html_e( 'Optional. Shown under the label, for example when the brand is expected back.', 'woo-organization-accounts-pro' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the fields.
	 *
	 * Only when the request carries this form's own nonce: the product sync renames and
	 * creates brands through the same `created_`/`edited_` hooks, and a save that read a
	 * missing checkbox as "off" would lift a stop every time the sync ran.
	 *
	 * @param int $term_id The brand.
	 * @return void
	 */
	public function save_fields( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's own capability.
			return;
		}

		if ( ! empty( $_POST['woap_order_stop'] ) ) {
			update_term_meta( $term_id, self::META_STOPPED, '1' );
		} else {
			delete_term_meta( $term_id, self::META_STOPPED );
		}

		$note = isset( $_POST['woap_order_stop_note'] ) ? sanitize_text_field( wp_unslash( $_POST['woap_order_stop_note'] ) ) : '';

		if ( '' !== $note ) {
			update_term_meta( $term_id, self::META_NOTE, $note );
		} else {
			delete_term_meta( $term_id, self::META_NOTE );
		}

		self::flush();
	}
}
