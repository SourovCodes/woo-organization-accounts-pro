<?php
/**
 * My Account: the organization's delivery locations.
 *
 * Override in a theme at woo-organization-accounts/myaccount/locations.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization    $organization The organization.
 * @var \WooOrgAccounts\Data\Location[]      $locations    Its locations.
 * @var \WooOrgAccounts\Data\Location|null   $editing      The location being edited, if any.
 * @var array                                $countries    Countries the shop sells to.
 */

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_LOCATIONS ) );
$woap_editing  = $editing instanceof Location ? $editing : new Location();
$woap_list_url = wc_get_account_endpoint_url( MyAccount::ENDPOINT_LOCATIONS );

?>
<div class="woap-account woap-account--locations">

	<p class="woap-account__note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
				__( 'Orders are delivered to one of these %s. Members choose one at checkout.', 'woo-organization-accounts-pro' ),
				Labels::locations()
			)
		);
		?>
	</p>

	<?php if ( empty( $locations ) ) : ?>
		<p><?php esc_html_e( 'None yet. Add the first one below.', 'woo-organization-accounts-pro' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-table woap-locations-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Address', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Contact', 'woo-organization-accounts-pro' ); ?></th>
					<th class="woap-actions"><?php esc_html_e( 'Actions', 'woo-organization-accounts-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $locations as $woap_location ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $woap_location->get_name() ); ?>
							<?php if ( $woap_location->is_default() ) : ?>
								<span class="woap-status woap-status--active"><?php esc_html_e( 'Default', 'woo-organization-accounts-pro' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( $woap_location->get_formatted_address() ); ?></td>
						<td>
							<?php echo esc_html( (string) $woap_location->get( 'contact_name' ) ); ?><br>
							<?php echo esc_html( (string) $woap_location->get( 'contact_phone' ) ); ?>
						</td>
						<td class="woap-actions">
							<a href="<?php echo esc_url( add_query_arg( 'woap_location', $woap_location->get_id(), $woap_list_url ) ); ?>">
								<?php esc_html_e( 'Edit', 'woo-organization-accounts-pro' ); ?>
							</a>
							<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
								<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="delete_location">
								<input type="hidden" name="location_id" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>">
								<?php wp_nonce_field( 'woap_delete_location' ); ?>
								<button type="submit" class="woap-link-button"><?php esc_html_e( 'Delete', 'woo-organization-accounts-pro' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3>
		<?php
		echo esc_html(
			$woap_editing->exists()
				? sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Edit %s', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
				: sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Add a %s', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
		);
		?>
	</h3>

	<form class="woocommerce-form woap-account__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_location">
		<input type="hidden" name="location_id" value="<?php echo esc_attr( (string) $woap_editing->get_id() ); ?>">
		<?php wp_nonce_field( 'woap_save_location' ); ?>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-name"><?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-name" name="name" required value="<?php echo esc_attr( $woap_editing->get_name() ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-country"><?php esc_html_e( 'Country or region', 'woo-organization-accounts-pro' ); ?></label>
			<select class="woocommerce-Input" id="woap-location-country" name="country">
				<?php
				$woap_country = (string) $woap_editing->get( 'country' );

				if ( '' === $woap_country ) {
					$woap_country = WC()->countries->get_base_country();
				}

				foreach ( $countries as $woap_code => $woap_country_name ) :
					?>
					<option value="<?php echo esc_attr( $woap_code ); ?>" <?php selected( $woap_country, $woap_code ); ?>>
						<?php echo esc_html( $woap_country_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-address-1"><?php esc_html_e( 'Street address', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-address-1" name="address_1" value="<?php echo esc_attr( (string) $woap_editing->get( 'address_1' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-address-2"><?php esc_html_e( 'Apartment, suite, unit (optional)', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-address-2" name="address_2" value="<?php echo esc_attr( (string) $woap_editing->get( 'address_2' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-location-city"><?php esc_html_e( 'Town or city', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-city" name="city" value="<?php echo esc_attr( (string) $woap_editing->get( 'city' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-location-postcode"><?php esc_html_e( 'Postcode or ZIP', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-postcode" name="postcode" value="<?php echo esc_attr( (string) $woap_editing->get( 'postcode' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-state"><?php esc_html_e( 'State, county or province', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-state" name="state" value="<?php echo esc_attr( (string) $woap_editing->get( 'state' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-location-contact-name"><?php esc_html_e( 'Contact name', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-location-contact-name" name="contact_name" value="<?php echo esc_attr( (string) $woap_editing->get( 'contact_name' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-location-contact-phone"><?php esc_html_e( 'Contact phone', 'woo-organization-accounts-pro' ); ?></label>
			<input type="tel" class="woocommerce-Input input-text" id="woap-location-contact-phone" name="contact_phone" value="<?php echo esc_attr( (string) $woap_editing->get( 'contact_phone' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-location-contact-email"><?php esc_html_e( 'Contact email', 'woo-organization-accounts-pro' ); ?></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-location-contact-email" name="contact_email" value="<?php echo esc_attr( (string) $woap_editing->get( 'contact_email' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label>
				<input type="checkbox" name="is_default" value="1" <?php checked( $woap_editing->is_default() ); ?>>
				<?php esc_html_e( 'Use this as the default at checkout', 'woo-organization-accounts-pro' ); ?>
			</label>
		</p>

		<p>
			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Save', 'woo-organization-accounts-pro' ); ?></button>
			<?php if ( $woap_editing->exists() ) : ?>
				<a class="woocommerce-Button button" href="<?php echo esc_url( $woap_list_url ); ?>"><?php esc_html_e( 'Cancel', 'woo-organization-accounts-pro' ); ?></a>
			<?php endif; ?>
		</p>
	</form>
</div>
