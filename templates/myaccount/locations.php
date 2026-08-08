<?php
/**
 * My Account: the organization's delivery locations.
 *
 * Override in a theme at woo-organization-accounts/myaccount/locations.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization  $organization The organization.
 * @var \WooOrgAccounts\Data\Location[]    $locations    Its locations.
 * @var \WooOrgAccounts\Data\Location|null $editing      The location being edited, if any.
 */

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_LOCATIONS ) );
$woap_editing  = $editing instanceof Location ? $editing : new Location();
$woap_list_url = wc_get_account_endpoint_url( MyAccount::ENDPOINT_LOCATIONS );

/*
 * A rejected submission is handed straight back rather than redirected away, so the
 * form shows what was typed. Everything else falls back to the stored location.
 */
$woap_address = $woap_editing->get_shipping_address();
$woap_name    = $woap_editing->get_name();
$woap_default = $woap_editing->is_default();

if ( AccountHandlers::has_submission() ) {
	foreach ( array_keys( $woap_address ) as $woap_field ) {
		$woap_address[ $woap_field ] = AccountHandlers::value( 'shipping_' . $woap_field, $woap_address[ $woap_field ] );
	}

	$woap_name    = AccountHandlers::value( 'woap_name', $woap_name );
	$woap_default = (bool) AccountHandlers::value( 'woap_is_default', $woap_default );
}

if ( '' === $woap_address['country'] ) {
	$woap_address['country'] = WC()->countries->get_base_country();
}

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
					<th><?php esc_html_e( 'Delivery address', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Delivery contact', 'woo-organization-accounts-pro' ); ?></th>
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
							<?php
							$woap_contact = $woap_location->get_contact_name();
							$woap_tel     = (string) $woap_location->get( 'phone' );

							echo '' !== $woap_contact ? esc_html( $woap_contact ) : '<span aria-hidden="true">&mdash;</span>';

							if ( '' !== $woap_tel ) {
								echo '<br>' . esc_html( $woap_tel );
							}
							?>
						</td>
						<td class="woap-actions">
							<a href="<?php echo esc_url( add_query_arg( 'woap_location', $woap_location->get_id(), $woap_list_url ) ); ?>">
								<?php esc_html_e( 'Edit', 'woo-organization-accounts-pro' ); ?>
							</a>
							<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
								<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="delete_location">
								<input type="hidden" name="woap_location_id" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>">
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
		<input type="hidden" name="woap_location_id" value="<?php echo esc_attr( (string) $woap_editing->get_id() ); ?>">
		<?php wp_nonce_field( 'woap_save_location' ); ?>

		<p class="woocommerce-form-row form-row form-row-wide validate-required">
			<label for="woap-location-name">
				<?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'woo-organization-accounts-pro' ); ?>">*</abbr>
			</label>
			<span class="woocommerce-input-wrapper">
				<input type="text" class="input-text" id="woap-location-name" name="woap_name" required value="<?php echo esc_attr( $woap_name ); ?>">
			</span>
			<span class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
						__( 'What members will see when they choose this %s at checkout.', 'woo-organization-accounts-pro' ),
						Labels::location()
					)
				);
				?>
			</span>
		</p>

		<?php
		/*
		 * WooCommerce's own shipping fields for the chosen country, rendered by
		 * WooCommerce. Which fields exist, what they are called and which are required
		 * all come from it, so this form asks a German customer for exactly what the
		 * checkout will ask them for, and a Canadian one for a province from the list.
		 */
		AddressFields::render( AddressFields::SHIPPING, $woap_address );
		?>

		<p class="woocommerce-form-row form-row form-row-wide">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="woap_is_default" value="1" <?php checked( $woap_default ); ?>>
				<span><?php esc_html_e( 'Use this as the default at checkout', 'woo-organization-accounts-pro' ); ?></span>
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
