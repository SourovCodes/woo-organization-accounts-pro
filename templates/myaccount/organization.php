<?php
/**
 * My Account: the organization profile and its billing address.
 *
 * Override in a theme at woo-organization-accounts/myaccount/organization.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var bool                              $can_billing  Whether the viewer may edit billing.
 */

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_PROFILE ) );
$woap_billing  = $organization->get_billing_address();

if ( AccountHandlers::has_submission() ) {
	foreach ( array_keys( $woap_billing ) as $woap_field ) {
		$woap_billing[ $woap_field ] = AccountHandlers::value( 'billing_' . $woap_field, $woap_billing[ $woap_field ] );
	}
}

?>
<div class="woap-account woap-account--organization">

	<p class="woap-account__status">
		<?php esc_html_e( 'Status:', 'woo-organization-accounts-pro' ); ?>
		<span class="woap-status woap-status--<?php echo esc_attr( $organization->get_status() ); ?>">
			<?php echo esc_html( $organization->get_status_label() ); ?>
		</span>
	</p>

	<?php if ( Organization::STATUS_PENDING === $organization->get_status() ) : ?>
		<p class="woocommerce-info">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'This %s is awaiting approval. Orders can be placed once it is approved.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>
	<?php endif; ?>

	<form class="woocommerce-form woap-account__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_organization">
		<?php wp_nonce_field( 'woap_save_organization' ); ?>

		<h3>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( '%s details', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</h3>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-name"><?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-name" name="woap_name" required value="<?php echo esc_attr( $organization->get_name() ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-email"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-email" name="woap_email" value="<?php echo esc_attr( (string) $organization->get( 'email' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-phone"><?php esc_html_e( 'Phone', 'woo-organization-accounts-pro' ); ?></label>
			<input type="tel" class="woocommerce-Input input-text" id="woap-phone" name="woap_phone" value="<?php echo esc_attr( (string) $organization->get( 'phone' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-tax-id"><?php esc_html_e( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-tax-id" name="woap_tax_id" value="<?php echo esc_attr( (string) $organization->get( 'tax_id' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label>
				<input type="checkbox" name="woap_allow_custom_shipping" value="1" <?php checked( $organization->allows_custom_shipping() ); ?>>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
						__( 'Allow one-off shipping addresses at checkout, as well as the saved %s', 'woo-organization-accounts-pro' ),
						Labels::locations()
					)
				);
				?>
			</label>
		</p>

		<p>
			<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Save details', 'woo-organization-accounts-pro' ); ?></button>
		</p>
	</form>

	<h3><?php esc_html_e( 'Billing address', 'woo-organization-accounts-pro' ); ?></h3>

	<?php if ( ! $can_billing ) : ?>

		<address><?php echo wp_kses_post( $organization->get_formatted_billing_address() ); ?></address>
		<p class="woap-account__note"><?php esc_html_e( 'You do not have permission to change the billing address.', 'woo-organization-accounts-pro' ); ?></p>

	<?php else : ?>

		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'Every order placed by this %s is billed here. Orders already placed keep the address they were placed with.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>

		<form class="woocommerce-form woap-account__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
			<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_billing">
			<?php wp_nonce_field( 'woap_save_billing' ); ?>

			<?php
			/*
			 * WooCommerce's own billing fields for the chosen country, rendered by
			 * WooCommerce, so this form asks for exactly what the checkout asks for — a
			 * state where the country has states, none where it does not, and the label
			 * that country uses for its postcode.
			 */
			AddressFields::render( AddressFields::BILLING, $woap_billing, array( 'errors' => AccountHandlers::errors() ) );
			?>

			<p>
				<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Save billing address', 'woo-organization-accounts-pro' ); ?></button>
			</p>
		</form>

	<?php endif; ?>
</div>
