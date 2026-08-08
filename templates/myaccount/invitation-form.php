<?php
/**
 * My Account: send one invitation.
 *
 * A screen of its own, like adding a location or managing a member, so the "Invite
 * somebody" button leads to the form rather than to a list with the form shut, and a
 * refused address comes back on the form with the reason under the field.
 *
 * Override in a theme at woo-organization-accounts/myaccount/invitation-form.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 */

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_list_url = MyAccount::invitations_url();
$woap_post_url = esc_url( MyAccount::invite_form_url() );

$woap_errors = AccountHandlers::errors();
$woap_bad    = ( $woap_errors instanceof WP_Error ) && '' !== $woap_errors->get_error_message( 'woap_email' );

?>
<div class="woap-account woap-account--invitation-form">

	<p class="woap-account__back">
		<a href="<?php echo esc_url( $woap_list_url ); ?>">
			<?php esc_html_e( '← Back to invitations', 'woo-organization-accounts-pro' ); ?>
		</a>
	</p>

	<div class="woap-identity">
		<h3 class="woap-identity__name"><?php esc_html_e( 'Invite somebody', 'woo-organization-accounts-pro' ); ?></h3>

		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'They get a single-use link. It works only for the address you enter, only once, and only for this %s — so send it to an address you know is theirs.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
			?>
		</p>
	</div>

	<form class="woocommerce-form woap-account__form woap-panel" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="invite_member">
		<?php wp_nonce_field( 'woap_invite_member' ); ?>

		<p class="form-row form-row-wide validate-required<?php echo $woap_bad ? ' woocommerce-invalid woocommerce-invalid-required-field' : ''; ?>" id="woap_email_field">
			<label for="woap-invite-email" class="required_field">
				<?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span>
			</label>
			<span class="woocommerce-input-wrapper">
				<input type="email" class="woocommerce-Input input-text" id="woap-invite-email" name="woap_email" required aria-required="true" value="<?php echo esc_attr( AccountHandlers::value( 'woap_email', '' ) ); ?>">
				<?php if ( $woap_bad ) : ?>
					<span class="description"><?php echo esc_html( $woap_errors->get_error_message( 'woap_email' ) ); ?></span>
				<?php endif; ?>
			</span>
		</p>

		<p class="form-row form-row-wide">
			<label for="woap-invite-role"><?php esc_html_e( 'Role', 'woo-organization-accounts-pro' ); ?></label>
			<span class="woocommerce-input-wrapper">
				<select id="woap-invite-role" name="woap_role">
					<?php foreach ( Member::roles() as $woap_value => $woap_label ) : ?>
						<option value="<?php echo esc_attr( $woap_value ); ?>" <?php selected( AccountHandlers::value( 'woap_role', Member::ROLE_MEMBER ), $woap_value ); ?>>
							<?php echo esc_html( $woap_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
							__( 'You can change this, and everything else about them, from the %s screen once they have joined.', 'woo-organization-accounts-pro' ),
							Labels::members()
						)
					);
					?>
				</span>
			</span>
		</p>

		<p class="woap-account__actions">
			<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Send invitation', 'woo-organization-accounts-pro' ); ?></button>
			<a class="woap-account__cancel" href="<?php echo esc_url( $woap_list_url ); ?>"><?php esc_html_e( 'Cancel', 'woo-organization-accounts-pro' ); ?></a>
		</p>
	</form>
</div>
