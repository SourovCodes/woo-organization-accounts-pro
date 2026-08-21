<?php
/**
 * The organization on the orders screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Datasheet\Download as Datasheet;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Shows which organization an order belongs to, in the orders list and on the order.
 *
 * Both hooks are the High-Performance Order Storage ones. The plugin refuses to boot
 * without HPOS, so there is no legacy `manage_shop_order_posts_columns` path here and
 * no screen-ID branch — the orders screen is the orders screen.
 */
class OrderColumn {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Add the organization column after the order number.
	 *
	 * @param array $columns Existing columns.
	 * @return array Columns, with ours inserted.
	 */
	public function add_column( $columns ) {
		$inserted = array();

		foreach ( $columns as $key => $label ) {
			$inserted[ $key ] = $label;

			if ( 'order_number' === $key ) {
				$inserted['woap_organization'] = Labels::organization();
			}
		}

		if ( ! isset( $inserted['woap_organization'] ) ) {
			$inserted['woap_organization'] = Labels::organization();
		}

		return $inserted;
	}

	/**
	 * Print the organization column for one order.
	 *
	 * The name comes from the order's own snapshot rather than from a lookup, so a
	 * list of twenty orders is twenty rows and no extra queries. The link is only added
	 * when the organization still exists.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  The order.
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( 'woap_organization' !== $column || ! $order instanceof \WC_Order ) {
			return;
		}

		$name = OrderMeta::organization_name( $order );
		$id   = OrderMeta::organization_id( $order );

		if ( '' === $name && 0 === $id ) {
			echo '&mdash;';

			return;
		}

		if ( 0 === $id ) {
			echo esc_html( $name );

			return;
		}

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Organizations::edit_url( $id ) ),
			esc_html( '' !== $name ? $name : (string) $id )
		);
	}

	/**
	 * Add the organization panel to the order edit screen.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'woap-order-organization',
			Labels::organization(),
			array( $this, 'render_meta_box' ),
			OrderUtil::get_order_admin_screen(),
			'side',
			'default'
		);
	}

	/**
	 * Print the organization panel.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The order being edited.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$organization_id = OrderMeta::organization_id( $order );

		if ( 0 === $organization_id ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the organization noun for the site's mode, for example "Company". */
						__( 'This order is not linked to a %s.', 'woo-organization-accounts-pro' ),
						Labels::organization()
					)
				)
			);

			$this->render_datasheet_link( $order );

			return;
		}

		$organization = OrganizationRepository::find( $organization_id );
		$name         = OrderMeta::organization_name( $order );
		$location     = OrderMeta::location_name( $order );
		$buyer        = get_user_by( 'id', OrderMeta::member_user_id( $order ) );

		echo '<p><strong>';
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Organizations::edit_url( $organization_id ) ),
			esc_html( '' !== $name ? $name : (string) $organization_id )
		);
		echo '</strong></p>';

		if ( null === $organization ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'The account has since been deleted. The order keeps the details it was placed with.', 'woo-organization-accounts-pro' )
			);
		} elseif ( $organization->get_name() !== $name ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html(
					sprintf(
						/* translators: %s: the organization's current name. */
						__( 'Now called %s.', 'woo-organization-accounts-pro' ),
						$organization->get_name()
					)
				)
			);
		}

		if ( '' !== $location ) {
			printf(
				'<p>%1$s<br><strong>%2$s</strong></p>',
				esc_html( Labels::location() ),
				esc_html( $location )
			);
		}

		if ( $buyer instanceof \WP_User ) {
			printf(
				'<p>%1$s<br><a href="%2$s">%3$s</a></p>',
				esc_html__( 'Placed by', 'woo-organization-accounts-pro' ),
				esc_url( get_edit_user_link( $buyer->ID ) ),
				esc_html( $buyer->display_name )
			);
		}

		$this->render_datasheet_link( $order );
	}

	/**
	 * Offer the order's product data to shop staff.
	 *
	 * Printed for an order with no organization as well as for one with. The datasheet
	 * describes what was bought, which is a fact about the products and not about the
	 * account — the panel is only where the button fits, not what it is about.
	 *
	 * @param \WC_Order $order The order being edited.
	 * @return void
	 */
	private function render_datasheet_link( \WC_Order $order ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( Datasheet::admin_order_url( $order ) ),
			esc_html( Datasheet::label() )
		);
	}
}
