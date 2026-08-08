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
 * @var array                             $countries    Countries the shop sells to.
 */

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_PROFILE ) );
$woap_billing  = $organization->get_billing_address();

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
			<input type="text" class="woocommerce-Input input-text" id="woap-name" name="name" required value="<?php echo esc_attr( $organization->get_name() ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-email"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-email" name="email" value="<?php echo esc_attr( (string) $organization->get( 'email' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-phone"><?php esc_html_e( 'Phone', 'woo-organization-accounts-pro' ); ?></label>
			<input type="tel" class="woocommerce-Input input-text" id="woap-phone" name="phone" value="<?php echo esc_attr( (string) $organization->get( 'phone' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-tax-id"><?php esc_html_e( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-tax-id" name="tax_id" value="<?php echo esc_attr( (string) $organization->get( 'tax_id' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label>
				<input type="checkbox" name="allow_custom_shipping" value="1" <?php checked( $organization->allows_custom_shipping() ); ?>>
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
			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Save details', 'woo-organization-accounts-pro' ); ?></button>
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

			<p class="woocommerce-form-row form-row-first">
				<label for="woap-billing-first-name"><?php esc_html_e( 'First name', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-first-name" name="billing_first_name" value="<?php echo esc_attr( $woap_billing['first_name'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-last">
				<label for="woap-billing-last-name"><?php esc_html_e( 'Last name', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-last-name" name="billing_last_name" value="<?php echo esc_attr( $woap_billing['last_name'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-wide">
				<label for="woap-billing-company"><?php esc_html_e( 'Company or organization name', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-company" name="billing_company" value="<?php echo esc_attr( $woap_billing['company'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-wide">
				<label for="woap-billing-country"><?php esc_html_e( 'Country or region', 'woo-organization-accounts-pro' ); ?></label>
				<select class="woocommerce-Input" id="woap-billing-country" name="billing_country">
					<?php foreach ( $countries as $woap_code => $woap_country_name ) : ?>
						<option value="<?php echo esc_attr( $woap_code ); ?>" <?php selected( $woap_billing['country'], $woap_code ); ?>>
							<?php echo esc_html( $woap_country_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="woocommerce-form-row form-row-wide">
				<label for="woap-billing-address-1"><?php esc_html_e( 'Street address', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-address-1" name="billing_address_1" value="<?php echo esc_attr( $woap_billing['address_1'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-wide">
				<label for="woap-billing-address-2"><?php esc_html_e( 'Apartment, suite, unit (optional)', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-address-2" name="billing_address_2" value="<?php echo esc_attr( $woap_billing['address_2'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-first">
				<label for="woap-billing-city"><?php esc_html_e( 'Town or city', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-city" name="billing_city" value="<?php echo esc_attr( $woap_billing['city'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-last">
				<label for="woap-billing-postcode"><?php esc_html_e( 'Postcode or ZIP', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-postcode" name="billing_postcode" value="<?php echo esc_attr( $woap_billing['postcode'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-first">
				<label for="woap-billing-state"><?php esc_html_e( 'State, county or province', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" class="woocommerce-Input input-text" id="woap-billing-state" name="billing_state" value="<?php echo esc_attr( $woap_billing['state'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-last">
				<label for="woap-billing-email"><?php esc_html_e( 'Billing email address', 'woo-organization-accounts-pro' ); ?></label>
				<input type="email" class="woocommerce-Input input-text" id="woap-billing-email" name="billing_email" value="<?php echo esc_attr( $woap_billing['email'] ); ?>">
			</p>

			<p class="woocommerce-form-row form-row-wide">
				<label for="woap-billing-phone"><?php esc_html_e( 'Billing phone', 'woo-organization-accounts-pro' ); ?></label>
				<input type="tel" class="woocommerce-Input input-text" id="woap-billing-phone" name="billing_phone" value="<?php echo esc_attr( $woap_billing['phone'] ); ?>">
			</p>

			<p>
				<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Save billing address', 'woo-organization-accounts-pro' ); ?></button>
			</p>
		</form>

	<?php endif; ?>
</div>
