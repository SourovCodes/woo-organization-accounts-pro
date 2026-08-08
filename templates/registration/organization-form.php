<?php
/**
 * Organization registration form.
 *
 * Override in a theme at woo-organization-accounts/registration/organization-form.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WP_Error|null $errors    Errors from the last submission, if any.
 * @var array          $submitted Sanitised values from the last submission.
 * @var array          $billing   Billing address values to prefill.
 * @var string         $action    Nonce action for the form.
 * @var string         $honeypot  Name of the honeypot field.
 */

use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_value = static function ( $key ) use ( $submitted ) {
	return isset( $submitted[ $key ] ) ? (string) $submitted[ $key ] : '';
};

?>
<div class="woocommerce woap-registration">

	<?php if ( $errors instanceof WP_Error && $errors->has_errors() ) : ?>
		<ul class="woocommerce-error" role="alert">
			<?php foreach ( $errors->get_error_messages() as $woap_message ) : ?>
				<li><?php echo wp_kses_post( $woap_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<form class="woocommerce-form woap-registration-form" method="post">
		<input type="hidden" name="woap_action" value="register">
		<?php wp_nonce_field( $action ); ?>

		<p class="woap-honeypot" aria-hidden="true">
			<label for="<?php echo esc_attr( $honeypot ); ?>"><?php esc_html_e( 'Leave this field empty', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $honeypot ); ?>" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off">
		</p>

		<h2>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( '%s details', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</h2>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-organization-name">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the organization noun for the site's mode, for example "Company". */
						__( '%s name', 'woo-organization-accounts-pro' ),
						Labels::organization()
					)
				);
				?>
				<span class="required">*</span>
			</label>
			<input type="text" class="woocommerce-Input input-text" id="woap-organization-name" name="organization_name" required value="<?php echo esc_attr( $woap_value( 'organization_name' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-organization-email"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-organization-email" name="organization_email" required value="<?php echo esc_attr( $woap_value( 'organization_email' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-organization-phone"><?php esc_html_e( 'Phone', 'woo-organization-accounts-pro' ); ?></label>
			<input type="tel" class="woocommerce-Input input-text" id="woap-organization-phone" name="organization_phone" value="<?php echo esc_attr( $woap_value( 'organization_phone' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-wide">
			<label for="woap-tax-id"><?php esc_html_e( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-tax-id" name="tax_id" value="<?php echo esc_attr( $woap_value( 'tax_id' ) ); ?>">
		</p>

		<h2><?php esc_html_e( 'Billing address', 'woo-organization-accounts-pro' ); ?></h2>
		<p class="woap-form-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'Every order is billed to this address. It belongs to the %s, so individual members cannot change it at checkout.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>

		<?php
		/*
		 * WooCommerce's own billing fields for the chosen country. The registration form
		 * has to collect exactly what the checkout will later require, or an organization
		 * registers happily and then cannot buy anything.
		 */
		AddressFields::render( AddressFields::BILLING, $billing );
		?>

		<h2>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization admin noun for the site's mode, for example "Company Admin". */
					__( 'Your account (%s)', 'woo-organization-accounts-pro' ),
					Labels::organization_admin()
				)
			);
			?>
		</h2>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-admin-first-name"><?php esc_html_e( 'First name', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-admin-first-name" name="admin_first_name" required value="<?php echo esc_attr( $woap_value( 'admin_first_name' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-admin-last-name"><?php esc_html_e( 'Last name', 'woo-organization-accounts-pro' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="woap-admin-last-name" name="admin_last_name" value="<?php echo esc_attr( $woap_value( 'admin_last_name' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-admin-email"><?php esc_html_e( 'Your email address', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-admin-email" name="admin_email" required autocomplete="username" value="<?php echo esc_attr( $woap_value( 'admin_email' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-admin-phone"><?php esc_html_e( 'Your phone (optional)', 'woo-organization-accounts-pro' ); ?></label>
			<input type="tel" class="woocommerce-Input input-text" id="woap-admin-phone" name="admin_phone" value="<?php echo esc_attr( $woap_value( 'admin_phone' ) ); ?>">
		</p>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-password"><?php esc_html_e( 'Password', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
			<input type="password" class="woocommerce-Input input-text" id="woap-password" name="password" required autocomplete="new-password" minlength="8">
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-password-confirm"><?php esc_html_e( 'Repeat password', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
			<input type="password" class="woocommerce-Input input-text" id="woap-password-confirm" name="password_confirm" required autocomplete="new-password" minlength="8">
		</p>

		<?php
		/**
		 * Fires at the end of the organization registration form.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_org_accounts_registration_form' );
		?>

		<p class="woocommerce-form-row form-row-wide">
			<button type="submit" class="woocommerce-Button button">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the organization noun for the site's mode, for example "Company". */
						__( 'Register %s', 'woo-organization-accounts-pro' ),
						Labels::organization()
					)
				);
				?>
			</button>
		</p>
	</form>
</div>
