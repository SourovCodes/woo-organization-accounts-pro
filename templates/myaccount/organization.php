<?php
/**
 * My Account: the organization profile and its billing address.
 *
 * The first screen in the account menu and the one every organization admin lands on,
 * so it opens with what the account *is* — status, address, what the other screens
 * hold — before it offers to change any of it.
 *
 * Override in a theme at woo-organization-accounts/myaccount/organization.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var bool                              $can_billing  Whether the viewer may edit billing.
 * @var array                             $overview     Tiles describing the other screens.
 */

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_PROFILE ) );
$woap_billing  = $organization->get_billing_address();

$woap_details = array(
	'name'   => $organization->get_name(),
	'tax_id' => (string) $organization->get( 'tax_id' ),
);

$woap_tax_id_required = Organization::tax_id_required();

// A rejected submission is handed straight back, so the form shows what was typed.
if ( AccountHandlers::has_submission() ) {
	foreach ( array_keys( $woap_billing ) as $woap_field ) {
		$woap_billing[ $woap_field ] = AccountHandlers::value( 'billing_' . $woap_field, $woap_billing[ $woap_field ] );
	}

	foreach ( array_keys( $woap_details ) as $woap_field ) {
		$woap_details[ $woap_field ] = AccountHandlers::value( 'woap_' . $woap_field, $woap_details[ $woap_field ] );
	}
}

$woap_errors = AccountHandlers::errors();

/**
 * The message rejecting one detail field, or an empty string.
 *
 * A notice at the top says something is wrong without saying where, which on a form
 * this long is most of the problem — so each field carries its own reason too.
 *
 * @param string $woap_field Field name, without its prefix.
 * @return string Message, or an empty string when the field was accepted.
 */
$woap_detail_error = static function ( $woap_field ) use ( $woap_errors ) {
	return $woap_errors instanceof WP_Error ? $woap_errors->get_error_message( 'woap_' . $woap_field ) : '';
};

?>
<div class="woap-account woap-account--organization">

	<div class="woap-identity">
		<h3 class="woap-identity__name">
			<?php echo esc_html( $organization->get_name() ); ?>
			<span class="woap-status woap-status--<?php echo esc_attr( $organization->get_status() ); ?>">
				<?php echo esc_html( $organization->get_status_label() ); ?>
			</span>
		</h3>

		<ul class="woap-meta">
			<?php
			/*
			 * The contact details here are the billing ones, because they are the pair
			 * that reaches an order — an organization has no separate email or phone of
			 * its own any more, and having both meant the screen could show one address
			 * while every order carried the other.
			 */
			?>
			<?php if ( '' !== (string) $organization->get( 'billing_email' ) ) : ?>
				<li class="woap-meta__item">
					<span class="woap-meta__label"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></span>
					<span class="woap-meta__value"><?php echo esc_html( (string) $organization->get( 'billing_email' ) ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( '' !== (string) $organization->get( 'billing_phone' ) ) : ?>
				<li class="woap-meta__item">
					<span class="woap-meta__label"><?php esc_html_e( 'Phone', 'woo-organization-accounts-pro' ); ?></span>
					<span class="woap-meta__value"><?php echo esc_html( (string) $organization->get( 'billing_phone' ) ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( '' !== (string) $organization->get( 'tax_id' ) ) : ?>
				<li class="woap-meta__item">
					<span class="woap-meta__label"><?php esc_html_e( 'VAT or tax ID', 'woo-organization-accounts-pro' ); ?></span>
					<span class="woap-meta__value"><?php echo esc_html( (string) $organization->get( 'tax_id' ) ); ?></span>
				</li>
			<?php endif; ?>
		</ul>
	</div>

	<?php if ( Organization::STATUS_PENDING === $organization->get_status() ) : ?>
		<p class="woocommerce-info woap-account__info">
			<?php
			echo esc_html__( 'This account is awaiting approval. Orders can be placed once it has been approved.', 'woo-organization-accounts-pro' );
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $overview ) ) : ?>
		<ul class="woap-tiles">
			<?php foreach ( $overview as $woap_tile ) : ?>
				<li class="woap-tiles__tile">
					<a class="woap-tiles__link" href="<?php echo esc_url( $woap_tile['url'] ); ?>">
						<span class="woap-tiles__count"><?php echo esc_html( number_format_i18n( $woap_tile['count'] ) ); ?></span>
						<span class="woap-tiles__label"><?php echo esc_html( $woap_tile['label'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<form class="woocommerce-form woap-account__form woap-panel" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_organization">
		<?php wp_nonce_field( 'woap_save_organization' ); ?>

		<h4 class="woap-panel__title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( '%s details', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</h4>

		<?php $woap_bad = $woap_detail_error( 'name' ); ?>
		<p class="woocommerce-form-row form-row-wide validate-required<?php echo '' !== $woap_bad ? ' woocommerce-invalid woocommerce-invalid-required-field' : ''; ?>">
			<label for="woap-name" class="required_field"><?php esc_html_e( 'Name', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-name" name="woap_name" required aria-required="true" value="<?php echo esc_attr( $woap_details['name'] ); ?>">
			<?php if ( '' !== $woap_bad ) : ?>
				<span class="description"><?php echo esc_html( wp_strip_all_tags( $woap_bad ) ); ?></span>
			<?php endif; ?>
		</p>

		<?php
		/*
		 * The email address and the phone number are edited in the billing address
		 * below, where WooCommerce defines and validates them for the country. They
		 * used to be here too, in a second pair of columns that reached no order.
		 */
		?>
		<?php $woap_bad = $woap_detail_error( 'tax_id' ); ?>
		<p class="woocommerce-form-row form-row-wide<?php echo '' !== $woap_bad ? ' woocommerce-invalid woocommerce-invalid-required-field' : ''; ?><?php echo $woap_tax_id_required ? ' validate-required' : ''; ?>">
			<label for="woap-tax-id"<?php echo $woap_tax_id_required ? ' class="required_field"' : ''; ?>>
				<?php esc_html_e( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ); ?>
			</label>
			<input type="text" class="woocommerce-Input input-text" id="woap-tax-id" name="woap_tax_id" <?php echo $woap_tax_id_required ? 'required aria-required="true"' : ''; ?> value="<?php echo esc_attr( $woap_details['tax_id'] ); ?>">
			<?php if ( '' !== $woap_bad ) : ?>
				<span class="description"><?php echo esc_html( wp_strip_all_tags( $woap_bad ) ); ?></span>
			<?php endif; ?>
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="woap_allow_custom_shipping" value="1" <?php checked( $organization->allows_custom_shipping() ); ?>>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
							__( 'Allow one-off shipping addresses at checkout, as well as the saved %s', 'woo-organization-accounts-pro' ),
							Labels::locations()
						)
					);
					?>
				</span>
			</label>
		</p>

		<p class="woap-account__actions">
			<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Save details', 'woo-organization-accounts-pro' ); ?></button>
		</p>
	</form>

	<?php if ( ! $can_billing ) : ?>

		<div class="woap-panel">
			<h4 class="woap-panel__title"><?php esc_html_e( 'Billing address', 'woo-organization-accounts-pro' ); ?></h4>
			<address class="woap-address"><?php echo wp_kses_post( $organization->get_formatted_billing_address() ); ?></address>
			<p class="woap-account__note"><?php esc_html_e( 'You do not have permission to change the billing address.', 'woo-organization-accounts-pro' ); ?></p>
		</div>

	<?php else : ?>

		<form class="woocommerce-form woap-account__form woap-panel" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
			<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="save_billing">
			<?php wp_nonce_field( 'woap_save_billing' ); ?>

			<h4 class="woap-panel__title"><?php esc_html_e( 'Billing address', 'woo-organization-accounts-pro' ); ?></h4>

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

			<?php
			/*
			 * WooCommerce's own billing fields for the chosen country, rendered by
			 * WooCommerce, so this form asks for exactly what the checkout asks for — a
			 * state where the country has states, none where it does not, and the label
			 * that country uses for its postcode.
			 */
			AddressFields::render( AddressFields::BILLING, $woap_billing, array( 'errors' => AccountHandlers::errors() ) );
			?>

			<p class="woap-account__actions">
				<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Save billing address', 'woo-organization-accounts-pro' ); ?></button>
			</p>
		</form>

	<?php endif; ?>
</div>
