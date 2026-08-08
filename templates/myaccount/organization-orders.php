<?php
/**
 * My Account: every order the organization has placed.
 *
 * Override in a theme at woo-organization-accounts/myaccount/organization-orders.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var \WC_Order[]                       $orders       Orders on this page.
 * @var int                               $page         Current page, one-based.
 * @var int                               $pages        Total number of pages.
 */

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_base_url = wc_get_account_endpoint_url( MyAccount::ENDPOINT_ORDERS );

?>
<div class="woap-account woap-account--orders">

	<?php if ( empty( $orders ) ) : ?>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'This %s has not placed any orders yet.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>

	<?php else : ?>

		<table class="woocommerce-orders-table woap-orders-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Date', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Placed by', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php echo esc_html( Labels::location() ); ?></th>
					<th><?php esc_html_e( 'Status', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Total', 'woo-organization-accounts-pro' ); ?></th>
					<th class="woap-actions"><?php esc_html_e( 'Actions', 'woo-organization-accounts-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $woap_order ) : ?>
					<?php $woap_buyer = get_user_by( 'id', OrderMeta::member_user_id( $woap_order ) ); ?>
					<tr>
						<td><?php echo esc_html( '#' . $woap_order->get_order_number() ); ?></td>
						<td>
							<time datetime="<?php echo esc_attr( $woap_order->get_date_created() ? $woap_order->get_date_created()->date( 'c' ) : '' ); ?>">
								<?php echo esc_html( $woap_order->get_date_created() ? wc_format_datetime( $woap_order->get_date_created() ) : '' ); ?>
							</time>
						</td>
						<td><?php echo esc_html( $woap_buyer instanceof WP_User ? $woap_buyer->display_name : __( 'Unknown', 'woo-organization-accounts-pro' ) ); ?></td>
						<td><?php echo esc_html( OrderMeta::location_name( $woap_order ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $woap_order->get_status() ) ); ?></td>
						<td><?php echo wp_kses_post( $woap_order->get_formatted_order_total() ); ?></td>
						<td class="woap-actions">
							<a href="<?php echo esc_url( $woap_order->get_view_order_url() ); ?>">
								<?php esc_html_e( 'View', 'woo-organization-accounts-pro' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<nav class="woocommerce-pagination woap-pagination">
				<?php if ( $page > 1 ) : ?>
					<a class="woocommerce-Button button" href="<?php echo esc_url( add_query_arg( 'woap_page', $page - 1, $woap_base_url ) ); ?>">
						<?php esc_html_e( 'Previous', 'woo-organization-accounts-pro' ); ?>
					</a>
				<?php endif; ?>

				<span class="woap-pagination__position">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'woo-organization-accounts-pro' ),
							$page,
							$pages
						)
					);
					?>
				</span>

				<?php if ( $page < $pages ) : ?>
					<a class="woocommerce-Button button" href="<?php echo esc_url( add_query_arg( 'woap_page', $page + 1, $woap_base_url ) ); ?>">
						<?php esc_html_e( 'Next', 'woo-organization-accounts-pro' ); ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>
</div>
