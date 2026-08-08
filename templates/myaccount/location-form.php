<?php
/**
 * My Account: add or edit one delivery location.
 *
 * A screen of its own rather than a form beneath the list, so what is being edited is
 * unmistakable and a rejected submission comes back to the same place with everything
 * still filled in.
 *
 * Override in a theme at woo-organization-accounts/myaccount/location-form.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization  $organization The organization.
 * @var \WooOrgAccounts\Data\Location|null $editing      The location being edited, or null to add one.
 */

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_editing  = $editing instanceof Location ? $editing : new Location();
$woap_list_url = MyAccount::locations_url();
$woap_post_url = esc_url( MyAccount::location_form_url( $woap_editing->get_id() ) );

$woap_address = $woap_editing->get_shipping_address();
$woap_name    = $woap_editing->get_name();
$woap_default = $woap_editing->is_default();

// A rejected submission is handed straight back, so the form shows what was typed.
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

$woap_errors = AccountHandlers::errors();
$woap_bad    = ( $woap_errors instanceof WP_Error ) && '' !== $woap_errors->get_error_message( 'woap_name' );

?>
<div class="woap-account woap-account--location-form">

	<p class="woap-account__back">
		<a href="<?php echo esc_url( $woap_list_url ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
					__( '← Back to %s', 'woo-organization-accounts-pro' ),
					Labels::locations()
				)
			);
			?>
		</a>
	</p>

	<div class="woap-identity">
		<h3 class="woap-identity__name">
			<?php
			echo esc_html(
				$woap_editing->exists()
					? sprintf(
						/* translators: %s: the name of the location being edited. */
						__( 'Edit “%s”', 'woo-organization-accounts-pro' ),
						$woap_editing->get_name()
					)
					: sprintf(
						/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
						__( 'Add a %s', 'woo-organization-accounts-pro' ),
						Labels::location()
					)
			);
			?>
		</h3>

		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'This is a delivery address, and the checkout asks for exactly these fields. A blank company is filled in with the %s name.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>
	</div>

	<form class="woocommerce-form woap-account__form woap-panel" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_location">
		<input type="hidden" name="woap_location_id" value="<?php echo esc_attr( (string) $woap_editing->get_id() ); ?>">
		<?php wp_nonce_field( 'woap_save_location' ); ?>

		<p class="form-row form-row-wide validate-required<?php echo $woap_bad ? ' woocommerce-invalid woocommerce-invalid-required-field' : ''; ?>" id="woap_name_field">
			<label for="woap_name" class="required_field">
				<?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
			</label>
			<span class="woocommerce-input-wrapper">
				<input type="text" class="input-text" id="woap_name" name="woap_name" required aria-required="true" value="<?php echo esc_attr( $woap_name ); ?>">
				<span class="description">
					<?php
					echo esc_html(
						$woap_bad
							? $woap_errors->get_error_message( 'woap_name' )
							: sprintf(
								/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
								__( 'What members will see when they choose this %s at checkout.', 'woo-organization-accounts-pro' ),
								Labels::location()
							)
					);
					?>
				</span>
			</span>
		</p>

		<?php
		/*
		 * WooCommerce's own shipping fields for the chosen country, rendered by
		 * WooCommerce. Which fields exist, what they are called and which are required
		 * all come from it, so this form asks a German customer for exactly what the
		 * checkout will ask them for, and a Canadian one for a province from the list.
		 */
		AddressFields::render(
			AddressFields::SHIPPING,
			$woap_address,
			array( 'errors' => $woap_errors )
		);
		?>

		<p class="form-row form-row-wide">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="woap_is_default" value="1" <?php checked( $woap_default ); ?>>
				<span><?php esc_html_e( 'Use this as the default at checkout', 'woo-organization-accounts-pro' ); ?></span>
			</label>
		</p>

		<p class="woap-account__actions">
			<button type="submit" class="woocommerce-Button button btn-color-primary">
				<?php
				echo esc_html(
					$woap_editing->exists()
						? __( 'Save changes', 'woo-organization-accounts-pro' )
						: sprintf(
							/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
							__( 'Add %s', 'woo-organization-accounts-pro' ),
							Labels::location()
						)
				);
				?>
			</button>
			<a class="woap-account__cancel" href="<?php echo esc_url( $woap_list_url ); ?>">
				<?php esc_html_e( 'Cancel', 'woo-organization-accounts-pro' ); ?>
			</a>
		</p>
	</form>
</div>
