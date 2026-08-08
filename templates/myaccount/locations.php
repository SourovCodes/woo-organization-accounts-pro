<?php
/**
 * My Account: the organization's delivery locations.
 *
 * Override in a theme at woo-organization-accounts/myaccount/locations.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var \WooOrgAccounts\Data\Location[]   $locations    Its locations.
 */

use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( MyAccount::locations_url() );

/*
 * The header labels, reused as each cell's data-title. Woodmart stacks a
 * .shop_table_responsive on narrow screens, hides the header row and prints
 * `attr(data-title)` in front of every value instead, so a cell without one loses
 * its label on a phone. One array feeds both.
 */
$woap_columns = array(
	'name'    => __( 'Name', 'woo-organization-accounts-pro' ),
	'address' => __( 'Delivery address', 'woo-organization-accounts-pro' ),
	'contact' => __( 'Delivery contact', 'woo-organization-accounts-pro' ),
	'actions' => __( 'Actions', 'woo-organization-accounts-pro' ),
);

?>
<div class="woap-account woap-account--locations">

	<div class="woap-account__header">
		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
					__( 'Orders are delivered to one of these %s. Members choose one at checkout, starting from whichever is the default.', 'woo-organization-accounts-pro' ),
					Labels::locations()
				)
			);
			?>
		</p>

		<a class="woocommerce-Button button btn-color-primary woap-button--add" href="<?php echo esc_url( MyAccount::location_form_url() ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Add a %s', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
			?>
		</a>
	</div>

	<?php if ( empty( $locations ) ) : ?>

		<div class="woap-empty">
			<p class="woap-empty__title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
						__( 'No %s yet.', 'woo-organization-accounts-pro' ),
						Labels::locations()
					)
				);
				?>
			</p>
			<p class="woap-account__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
						__( 'Nobody can check out until there is at least one, because there is nowhere for an order to go. Add the first %s to open the account for ordering.', 'woo-organization-accounts-pro' ),
						Labels::location()
					)
				);
				?>
			</p>
			<p>
				<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( MyAccount::location_form_url() ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
							__( 'Add a %s', 'woo-organization-accounts-pro' ),
							Labels::location()
						)
					);
					?>
				</a>
			</p>
		</div>

	<?php else : ?>

		<table class="woocommerce-table shop_table shop_table_responsive woap-table woap-locations-table">
			<thead>
				<tr>
					<?php foreach ( $woap_columns as $woap_column => $woap_label ) : ?>
						<th scope="col" class="woap-column--<?php echo esc_attr( $woap_column ); ?>"><?php echo esc_html( $woap_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $locations as $woap_location ) : ?>
					<?php
					$woap_missing = AddressFields::missing( AddressFields::SHIPPING, $woap_location->get_shipping_address() );
					$woap_edit    = MyAccount::location_form_url( $woap_location->get_id() );
					$woap_contact = $woap_location->get_contact_name();
					$woap_tel     = (string) $woap_location->get( 'phone' );
					?>
					<tr>
						<td data-title="<?php echo esc_attr( $woap_columns['name'] ); ?>">
							<a class="woap-table__title" href="<?php echo esc_url( $woap_edit ); ?>">
								<?php echo esc_html( $woap_location->get_name() ); ?>
							</a>
							<?php if ( $woap_location->is_default() ) : ?>
								<span class="woap-status woap-status--active"><?php esc_html_e( 'Default', 'woo-organization-accounts-pro' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $woap_missing ) ) : ?>
								<span class="woap-status woap-status--suspended"><?php esc_html_e( 'Incomplete', 'woo-organization-accounts-pro' ); ?></span>
								<span class="woap-table__meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: comma-separated list of the address fields that are empty. */
											__( 'Cannot be delivered to until %s is filled in.', 'woo-organization-accounts-pro' ),
											implode( ', ', $woap_missing )
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['address'] ); ?>"><?php echo wp_kses_post( $woap_location->get_formatted_address() ); ?></td>
						<td data-title="<?php echo esc_attr( $woap_columns['contact'] ); ?>">
							<?php
							echo '' !== $woap_contact ? esc_html( $woap_contact ) : '<span aria-hidden="true">&mdash;</span>';

							if ( '' !== $woap_tel ) {
								echo '<span class="woap-table__meta">' . esc_html( $woap_tel ) . '</span>';
							}
							?>
						</td>
						<td class="woap-actions" data-title="<?php echo esc_attr( $woap_columns['actions'] ); ?>">
							<a href="<?php echo esc_url( $woap_edit ); ?>">
								<?php esc_html_e( 'Edit', 'woo-organization-accounts-pro' ); ?>
							</a>
							<?php if ( ! $woap_location->is_default() ) : ?>
								<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
									<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="default_location">
									<input type="hidden" name="woap_location_id" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>">
									<?php wp_nonce_field( 'woap_default_location' ); ?>
									<button type="submit" class="woap-link-button"><?php esc_html_e( 'Make default', 'woo-organization-accounts-pro' ); ?></button>
								</form>
							<?php endif; ?>
							<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
								<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="delete_location">
								<input type="hidden" name="woap_location_id" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>">
								<?php wp_nonce_field( 'woap_delete_location' ); ?>
								<button type="submit" class="woap-link-button woap-link-button--danger" data-woap-confirm="
									<?php
									echo esc_attr(
										sprintf(
											/* translators: %s: the name of the location being deleted. */
											__( 'Delete “%s”? Orders already sent there keep their address.', 'woo-organization-accounts-pro' ),
											$woap_location->get_name()
										)
									);
									?>
								">
									<?php esc_html_e( 'Delete', 'woo-organization-accounts-pro' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
