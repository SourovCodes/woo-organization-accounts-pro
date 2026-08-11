<?php
/**
 * Invitation acceptance form.
 *
 * Override in a theme at woo-organization-accounts/registration/invitation-form.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WP_Error|null                        $errors       Errors from the last submission, if any.
 * @var array                                 $submitted    Sanitised values from the last submission.
 * @var \WooOrgAccounts\Data\Invitation       $invitation   The invitation being redeemed.
 * @var \WooOrgAccounts\Data\Organization     $organization The organization it is for.
 * @var bool                                  $has_account  Whether the invited address already has an account.
 * @var bool                                  $logged_in    Whether somebody is signed in.
 * @var string                                $token        The raw token from the link.
 * @var string                                $action       Nonce action for the form.
 * @var string                                $honeypot     Name of the honeypot field.
 */

use WooOrgAccounts\Members\Invitations;

defined( 'ABSPATH' ) || exit;

$woap_value = static function ( $key ) use ( $submitted ) {
	return isset( $submitted[ $key ] ) ? (string) $submitted[ $key ] : '';
};

?>
<?php
/*
 * Woodmart's registration container, with the narrow modifier this time: the join
 * form is a name and a password, so the theme's 450px single-column width is the
 * right shape for it. The organization registration form is not — see
 * registration/organization-form.php.
 */
?>
<div class="woocommerce woap-invitation wd-registration-page wd-no-registration">

	<h2>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: organization name. */
				__( 'Join %s', 'woo-organization-accounts-pro' ),
				$organization->get_name()
			)
		);
		?>
	</h2>

	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: organization name, 2: the invited email address. */
				__( 'You have been invited to join %1$s as %2$s.', 'woo-organization-accounts-pro' ),
				$organization->get_name(),
				$invitation->get_email()
			)
		);
		?>
	</p>

	<?php if ( $errors instanceof WP_Error && $errors->has_errors() ) : ?>
		<ul class="woocommerce-error" role="alert">
			<?php foreach ( $errors->get_error_messages() as $woap_message ) : ?>
				<li><?php echo wp_kses_post( $woap_message ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $has_account && ! $logged_in ) : ?>

		<p>
			<?php esc_html_e( 'An account already exists for that address. Sign in with it, then follow the invitation link again.', 'woo-organization-accounts-pro' ); ?>
		</p>
		<p>
			<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">
				<?php esc_html_e( 'Sign in', 'woo-organization-accounts-pro' ); ?>
			</a>
		</p>

	<?php else : ?>

		<form class="woocommerce-form register woap-invitation-form" method="post">
			<input type="hidden" name="woap_action" value="join">
			<?php
			/*
			 * The token is posted back under the name it already has in the link, so a
			 * rejected submission returns to the invitation it was about rather than to
			 * the organization registration form. One value, one name.
			 */
			?>
			<input type="hidden" name="<?php echo esc_attr( Invitations::QUERY_VAR ); ?>" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( $action ); ?>

			<?php
			/*
			 * The trap is hidden inline rather than from a stylesheet. A honeypot that
			 * only disappears once a stylesheet loads is one real customers fill in.
			 */
			?>
			<p class="woap-honeypot" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
				<label for="<?php echo esc_attr( $honeypot ); ?>"><?php esc_html_e( 'Leave this field empty', 'woo-organization-accounts-pro' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $honeypot ); ?>" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off">
			</p>

			<?php if ( ! $has_account ) : ?>

				<p class="woocommerce-form-row form-row-first">
					<label for="woap-join-first-name"><?php esc_html_e( 'First name', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
					<input type="text" class="woocommerce-Input input-text" id="woap-join-first-name" name="woap_first_name" required value="<?php echo esc_attr( $woap_value( 'woap_first_name' ) ); ?>">
				</p>

				<p class="woocommerce-form-row form-row-last">
					<label for="woap-join-last-name"><?php esc_html_e( 'Last name', 'woo-organization-accounts-pro' ); ?></label>
					<input type="text" class="woocommerce-Input input-text" id="woap-join-last-name" name="woap_last_name" value="<?php echo esc_attr( $woap_value( 'woap_last_name' ) ); ?>">
				</p>

				<p class="woocommerce-form-row form-row-first">
					<label for="woap-join-password"><?php esc_html_e( 'Choose a password', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
					<input type="password" class="woocommerce-Input input-text" id="woap-join-password" name="woap_password" required autocomplete="new-password" minlength="8">
				</p>

				<p class="woocommerce-form-row form-row-last">
					<label for="woap-join-password-confirm"><?php esc_html_e( 'Repeat password', 'woo-organization-accounts-pro' ); ?> <span class="required">*</span></label>
					<input type="password" class="woocommerce-Input input-text" id="woap-join-password-confirm" name="woap_password_confirm" required autocomplete="new-password" minlength="8">
				</p>

			<?php endif; ?>

			<p class="woocommerce-form-row form-row-wide">
				<button type="submit" class="woocommerce-Button button btn-color-primary">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: organization name. */
							__( 'Join %s', 'woo-organization-accounts-pro' ),
							$organization->get_name()
						)
					);
					?>
				</button>
			</p>
		</form>

	<?php endif; ?>
</div>
