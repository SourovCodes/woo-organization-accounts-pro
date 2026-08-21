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
 * @var int                               $total        Total number of orders.
 */

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Datasheet\Download as Datasheet;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_base_url = wc_get_account_endpoint_url( MyAccount::ENDPOINT_ORDERS );

/*
 * One list of columns for both the header and each cell's data-title. Woodmart
 * stacks this table on narrow screens, hides the header and prints `attr(data-title)`
 * in front of every value instead — so a cell without one becomes an unlabelled
 * fragment on a phone. Deriving both from the same array is what stops the header and
 * those labels drifting apart.
 *
 * The column ids are WooCommerce's own wherever the column means the same thing,
 * because Woodmart styles several of them by name.
 */
$woap_columns = array(
	'order-number'  => __( 'Order', 'woo-organization-accounts-pro' ),
	'order-date'    => __( 'Date', 'woo-organization-accounts-pro' ),
	'woap-buyer'    => __( 'Placed by', 'woo-organization-accounts-pro' ),
	'woap-location' => Labels::location(),
	'order-status'  => __( 'Status', 'woo-organization-accounts-pro' ),
	'order-total'   => __( 'Total', 'woo-organization-accounts-pro' ),
	'order-actions' => __( 'Actions', 'woo-organization-accounts-pro' ),
);

/**
 * Open a cell carrying the classes and data-title Woodmart's stacked layout needs.
 *
 * @param string $column Column id.
 * @return string Opening tag.
 */
$woap_cell = static function ( $column ) use ( $woap_columns ) {
	// The order number is the row's header cell, as it is in WooCommerce's own table.
	$tag = 'order-number' === $column ? 'th scope="row"' : 'td';

	return sprintf(
		'<%1$s class="woocommerce-orders-table__cell woocommerce-orders-table__cell-%2$s" data-title="%3$s">',
		$tag,
		esc_attr( $column ),
		esc_attr( $woap_columns[ $column ] )
	);
};

?>
<div class="woap-account woap-account--orders">

	<?php if ( empty( $orders ) ) : ?>

		<div class="woap-empty">
			<p class="woap-empty__title">
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
			<p class="woap-account__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
						__( 'Every order any of your %s places appears here, whoever placed it.', 'woo-organization-accounts-pro' ),
						Labels::members()
					)
				);
				?>
			</p>
		</div>

	<?php else : ?>

		<div class="woap-account__header">
			<p class="woap-account__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: total number of orders the organization has placed. */
						_n(
							'%d order, newest first.',
							'%d orders, newest first.',
							$total,
							'woo-organization-accounts-pro'
						),
						$total
					)
				);
				?>
			</p>
		</div>

		<table class="woocommerce-orders-table shop_table shop_table_responsive woap-table woap-orders-table">
			<thead>
				<tr>
					<?php foreach ( $woap_columns as $woap_column => $woap_label ) : ?>
						<th scope="col" class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr( $woap_column ); ?>">
							<span class="nobr"><?php echo esc_html( $woap_label ); ?></span>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $woap_order ) : ?>
					<?php
					$woap_buyer   = get_user_by( 'id', OrderMeta::member_user_id( $woap_order ) );
					$woap_created = $woap_order->get_date_created();
					?>
					<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $woap_order->get_status() ); ?> order">
						<?php
						// The cell helper escapes the class and the label it builds the tag from.
						echo $woap_cell( 'order-number' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
							<a href="<?php echo esc_url( $woap_order->get_view_order_url() ); ?>">
								<?php echo esc_html( '#' . $woap_order->get_order_number() ); ?>
							</a>
						</th>
						<?php echo $woap_cell( 'order-date' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<time datetime="<?php echo esc_attr( $woap_created ? $woap_created->date( 'c' ) : '' ); ?>">
								<?php echo esc_html( $woap_created ? wc_format_datetime( $woap_created ) : '' ); ?>
							</time>
						</td>
						<?php echo $woap_cell( 'woap-buyer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( $woap_buyer instanceof WP_User ? $woap_buyer->display_name : __( 'Unknown', 'woo-organization-accounts-pro' ) ); ?>
						</td>
						<?php echo $woap_cell( 'woap-location' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( OrderMeta::location_name( $woap_order ) ); ?>
						</td>
						<?php echo $woap_cell( 'order-status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( wc_get_order_status_name( $woap_order->get_status() ) ); ?>
						</td>
						<?php echo $woap_cell( 'order-total' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo wp_kses_post( $woap_order->get_formatted_order_total() ); ?>
						</td>
						<?php echo $woap_cell( 'order-actions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<a href="<?php echo esc_url( $woap_order->get_view_order_url() ); ?>" class="woocommerce-button button view">
								<?php esc_html_e( 'View', 'woo-organization-accounts-pro' ); ?>
							</a>
							<?php if ( Datasheet::may_download_order( $woap_order ) ) : ?>
								<a href="<?php echo esc_url( Datasheet::order_url( $woap_order ) ); ?>" class="woocommerce-button button">
									<?php echo esc_html( Datasheet::short_label() ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<nav class="woocommerce-pagination woap-pagination">
				<?php if ( $page > 1 ) : ?>
					<a class="woocommerce-Button button btn-style-bordered" href="<?php echo esc_url( add_query_arg( 'woap_page', $page - 1, $woap_base_url ) ); ?>">
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
					<a class="woocommerce-Button button btn-style-bordered" href="<?php echo esc_url( add_query_arg( 'woap_page', $page + 1, $woap_base_url ) ); ?>">
						<?php esc_html_e( 'Next', 'woo-organization-accounts-pro' ); ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>
</div>
