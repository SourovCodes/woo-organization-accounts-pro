<?php
/**
 * My Account: outstanding and past invitations.
 *
 * A list, and nothing else. Sending one is its own screen — see invitation-form.php —
 * because the question this screen is usually opened to answer is "did they get it
 * yet?", and because a primary button called "Invite somebody" should lead to the form
 * rather than to a list with the form folded shut inside it.
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
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( MyAccount::invitations_url() );

/*
 * The header labels, reused as each cell's data-title. Woodmart stacks a
 * .shop_table_responsive on narrow screens, hides the header row and prints
 * `attr(data-title)` in front of every value instead, so a cell without one loses
 * its label on a phone. One array feeds both.
 */
$woap_columns = array(
	'email'   => __( 'Sent to', 'woo-organization-accounts-pro' ),
	'role'    => __( 'Role', 'woo-organization-accounts-pro' ),
	'status'  => __( 'Status', 'woo-organization-accounts-pro' ),
	'expires' => __( 'Expires', 'woo-organization-accounts-pro' ),
	'actions' => __( 'Actions', 'woo-organization-accounts-pro' ),
);

/*
 * Which pill a status wears. Every state the list can show has one, so a status added
 * later shows up as plainly styled rather than as no pill at all.
 */
$woap_pills = array(
	Invitation::STATUS_PENDING  => 'pending',
	Invitation::STATUS_ACCEPTED => 'active',
	Invitation::STATUS_REVOKED  => 'rejected',
);

?>
<div class="woap-account woap-account--invitations">

	<div class="woap-account__header">
		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
					__( 'An invitation is the only way to add %s. Each one is a single-use link, good for the address it was sent to and nothing else.', 'woo-organization-accounts-pro' ),
					Labels::members()
				)
			);
			?>
		</p>

		<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( MyAccount::invite_form_url() ); ?>">
			<?php esc_html_e( 'Invite somebody', 'woo-organization-accounts-pro' ); ?>
		</a>
	</div>

	<?php if ( empty( $invitations ) ) : ?>

		<div class="woap-empty">
			<p class="woap-empty__title"><?php esc_html_e( 'No invitations sent yet.', 'woo-organization-accounts-pro' ); ?></p>
			<p class="woap-account__note"><?php esc_html_e( 'Whoever you invite appears here until they accept.', 'woo-organization-accounts-pro' ); ?></p>
			<p>
				<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( MyAccount::invite_form_url() ); ?>">
					<?php esc_html_e( 'Invite somebody', 'woo-organization-accounts-pro' ); ?>
				</a>
			</p>
		</div>

	<?php else : ?>

		<table class="woocommerce-table shop_table shop_table_responsive woap-table woap-invitations-table">
			<thead>
				<tr>
					<?php foreach ( $woap_columns as $woap_column => $woap_label ) : ?>
						<th scope="col" class="woap-column--<?php echo esc_attr( $woap_column ); ?>"><?php echo esc_html( $woap_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $invitations as $woap_invitation ) : ?>
					<?php
					$woap_status  = (string) $woap_invitation->get( 'status' );
					$woap_pending = Invitation::STATUS_PENDING === $woap_status;
					$woap_pill    = isset( $woap_pills[ $woap_status ] ) ? $woap_pills[ $woap_status ] : 'neutral';
					$woap_sent    = $woap_invitation->get( 'date_created' );
					$woap_expires = $woap_invitation->get( 'expires_at' );

					if ( $woap_pending && $woap_invitation->is_expired() ) {
						$woap_pill = 'rejected';
					}
					?>
					<tr>
						<td data-title="<?php echo esc_attr( $woap_columns['email'] ); ?>">
							<span class="woap-table__title"><?php echo esc_html( $woap_invitation->get_email() ); ?></span>
							<?php if ( ! empty( $woap_sent ) ) : ?>
								<span class="woap-table__meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: the date an invitation was sent. */
											__( 'Sent %s', 'woo-organization-accounts-pro' ),
											date_i18n( get_option( 'date_format' ), strtotime( $woap_sent . ' UTC' ) )
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['role'] ); ?>"><?php echo esc_html( Member::ROLE_ADMIN === $woap_invitation->get_role() ? Labels::organization_admin() : Labels::member() ); ?></td>
						<td data-title="<?php echo esc_attr( $woap_columns['status'] ); ?>">
							<span class="woap-status woap-status--<?php echo esc_attr( $woap_pill ); ?>"><?php echo esc_html( $woap_invitation->get_status_label() ); ?></span>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['expires'] ); ?>">
							<?php
							echo esc_html(
								empty( $woap_expires )
									? __( 'Never', 'woo-organization-accounts-pro' )
									: date_i18n( get_option( 'date_format' ), strtotime( $woap_expires . ' UTC' ) )
							);
							?>
						</td>
						<td class="woap-actions" data-title="<?php echo esc_attr( $woap_columns['actions'] ); ?>">
							<?php if ( $woap_pending ) : ?>
								<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
									<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="resend_invitation">
									<input type="hidden" name="woap_invitation_id" value="<?php echo esc_attr( (string) $woap_invitation->get_id() ); ?>">
									<?php wp_nonce_field( 'woap_resend_invitation' ); ?>
									<button type="submit" class="woap-link-button"><?php esc_html_e( 'Send again', 'woo-organization-accounts-pro' ); ?></button>
								</form>
								<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
									<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="revoke_invitation">
									<input type="hidden" name="woap_invitation_id" value="<?php echo esc_attr( (string) $woap_invitation->get_id() ); ?>">
									<?php wp_nonce_field( 'woap_revoke_invitation' ); ?>
									<button type="submit" class="woap-link-button woap-link-button--danger" data-woap-confirm="
										<?php
										echo esc_attr(
											sprintf(
												/* translators: %s: the invited email address. */
												__( 'Withdraw the invitation to %s? Their link stops working immediately.', 'woo-organization-accounts-pro' ),
												$woap_invitation->get_email()
											)
										);
										?>
									"><?php esc_html_e( 'Withdraw', 'woo-organization-accounts-pro' ); ?></button>
								</form>
							<?php else : ?>
								<span aria-hidden="true">&mdash;</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
