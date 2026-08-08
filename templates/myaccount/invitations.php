<?php
/**
 * My Account: outstanding and past invitations.
 *
 * Override in a theme at woo-organization-accounts/myaccount/invitations.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization  $organization The organization.
 * @var \WooOrgAccounts\Data\Invitation[]  $invitations  Its invitations, newest first.
 */

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( admin_url( 'admin-post.php' ) );

?>
<div class="woap-account woap-account--invitations">

	<h3><?php esc_html_e( 'Invite somebody', 'woo-organization-accounts-pro' ); ?></h3>

	<p class="woap-account__note">
		<?php esc_html_e( 'They receive a one-time link. It works only for the address you enter, and only once.', 'woo-organization-accounts-pro' ); ?>
	</p>

	<form class="woocommerce-form woap-account__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="action" value="woap_invite_member">
		<?php wp_nonce_field( 'woap_invite_member' ); ?>

		<p class="woocommerce-form-row form-row-first">
			<label for="woap-invite-email"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></label>
			<input type="email" class="woocommerce-Input input-text" id="woap-invite-email" name="email" required>
		</p>

		<p class="woocommerce-form-row form-row-last">
			<label for="woap-invite-role"><?php esc_html_e( 'Role', 'woo-organization-accounts-pro' ); ?></label>
			<select id="woap-invite-role" name="role">
				<option value="<?php echo esc_attr( Member::ROLE_MEMBER ); ?>"><?php echo esc_html( Labels::member() ); ?></option>
				<option value="<?php echo esc_attr( Member::ROLE_ADMIN ); ?>"><?php echo esc_html( Labels::organization_admin() ); ?></option>
			</select>
		</p>

		<p>
			<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Send invitation', 'woo-organization-accounts-pro' ); ?></button>
		</p>
	</form>

	<h3><?php esc_html_e( 'Invitations', 'woo-organization-accounts-pro' ); ?></h3>

	<?php if ( empty( $invitations ) ) : ?>
		<p><?php esc_html_e( 'None sent yet.', 'woo-organization-accounts-pro' ); ?></p>
	<?php else : ?>
		<table class="woocommerce-table woap-invitations-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Role', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'woo-organization-accounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'woo-organization-accounts-pro' ); ?></th>
					<th class="woap-actions"><?php esc_html_e( 'Actions', 'woo-organization-accounts-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $invitations as $woap_invitation ) : ?>
					<tr>
						<td><?php echo esc_html( $woap_invitation->get_email() ); ?></td>
						<td><?php echo esc_html( Member::ROLE_ADMIN === $woap_invitation->get_role() ? Labels::organization_admin() : Labels::member() ); ?></td>
						<td><?php echo esc_html( $woap_invitation->get_status_label() ); ?></td>
						<td>
							<?php
							$woap_expires = $woap_invitation->get( 'expires_at' );

							echo esc_html(
								empty( $woap_expires )
									? __( 'Never', 'woo-organization-accounts-pro' )
									: date_i18n( get_option( 'date_format' ), strtotime( $woap_expires . ' UTC' ) )
							);
							?>
						</td>
						<td class="woap-actions">
							<?php if ( Invitation::STATUS_PENDING === (string) $woap_invitation->get( 'status' ) ) : ?>
								<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
									<input type="hidden" name="action" value="woap_resend_invitation">
									<input type="hidden" name="invitation_id" value="<?php echo esc_attr( (string) $woap_invitation->get_id() ); ?>">
									<?php wp_nonce_field( 'woap_resend_invitation' ); ?>
									<button type="submit" class="woap-link-button"><?php esc_html_e( 'Send again', 'woo-organization-accounts-pro' ); ?></button>
								</form>
								<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
									<input type="hidden" name="action" value="woap_revoke_invitation">
									<input type="hidden" name="invitation_id" value="<?php echo esc_attr( (string) $woap_invitation->get_id() ); ?>">
									<?php wp_nonce_field( 'woap_revoke_invitation' ); ?>
									<button type="submit" class="woap-link-button"><?php esc_html_e( 'Withdraw', 'woo-organization-accounts-pro' ); ?></button>
								</form>
							<?php else : ?>
								<span aria-hidden="true">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
