<?php
/**
 * My Account: the organization's members.
 *
 * A list, and nothing else. Changing somebody is a screen of its own — see
 * member-form.php — because everything worth knowing about one member does not fit in
 * a row, and because a stack of accordions each holding a fifteen-control form is a
 * hundred forms on a hundred-employee account.
 *
 * Override in a theme at woo-organization-accounts/myaccount/members.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var \WooOrgAccounts\Data\Member[]     $members      Its members.
 * @var array                             $users        WP_User objects, keyed by member ID.
 * @var array                             $access       Location IDs each member is limited to, keyed by member ID.
 * @var \WooOrgAccounts\Data\Location[]   $locations    The organization's locations.
 * @var int                               $current_user The viewer's user ID.
 * @var int                               $pending      Invitations sent and not yet accepted.
 */

use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

$woap_can_invite = current_user_can( Roles::INVITE_MEMBERS );

// Location names by ID, so the access column does not query once per row.
$woap_location_names = array();

foreach ( $locations as $woap_location ) {
	$woap_location_names[ $woap_location->get_id() ] = $woap_location->get_name();
}

/*
 * The header labels, reused as each cell's data-title. Woodmart stacks a
 * .shop_table_responsive on narrow screens, hides the header row and prints
 * `attr(data-title)` in front of every value instead, so a cell without one loses
 * its label on a phone. One array feeds both.
 */
$woap_columns = array(
	'member'      => Labels::member(),
	'role'        => __( 'Role', 'woo-organization-accounts-pro' ),
	'access'      => Labels::locations(),
	'permissions' => __( 'Permissions', 'woo-organization-accounts-pro' ),
	'status'      => __( 'Status', 'woo-organization-accounts-pro' ),
	'actions'     => __( 'Actions', 'woo-organization-accounts-pro' ),
);

?>
<div class="woap-account woap-account--members">

	<div class="woap-account__header">
		<p class="woap-account__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
					__( 'Everyone who can order on this account. %s join by invitation — there is no way to sign up to an account from outside it.', 'woo-organization-accounts-pro' ),
					Labels::members()
				)
			);
			?>
		</p>

		<?php if ( $woap_can_invite ) : ?>
			<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_INVITATIONS ) ); ?>">
				<?php esc_html_e( 'Invite somebody', 'woo-organization-accounts-pro' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $woap_can_invite && $pending > 0 ) : ?>
		<p class="woocommerce-info woap-account__info">
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_INVITATIONS ) ); ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of invitations that have been sent and not yet accepted. */
						_n(
							'%d invitation is waiting to be accepted.',
							'%d invitations are waiting to be accepted.',
							$pending,
							'woo-organization-accounts-pro'
						),
						$pending
					)
				);
				?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( empty( $members ) ) : ?>

		<div class="woap-empty">
			<p class="woap-empty__title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
						__( 'No %s yet.', 'woo-organization-accounts-pro' ),
						Labels::members()
					)
				);
				?>
			</p>
			<p class="woap-account__note"><?php esc_html_e( 'Send an invitation and whoever accepts it appears here.', 'woo-organization-accounts-pro' ); ?></p>
		</div>

	<?php else : ?>

		<table class="woocommerce-table shop_table shop_table_responsive woap-table woap-members-table">
			<thead>
				<tr>
					<?php foreach ( $woap_columns as $woap_column => $woap_label ) : ?>
						<th scope="col" class="woap-column--<?php echo esc_attr( $woap_column ); ?>"><?php echo esc_html( $woap_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $members as $woap_member ) : ?>
					<?php
					$woap_user     = isset( $users[ $woap_member->get_id() ] ) ? $users[ $woap_member->get_id() ] : null;
					$woap_access   = isset( $access[ $woap_member->get_id() ] ) ? $access[ $woap_member->get_id() ] : array();
					$woap_is_self  = $woap_member->get_user_id() === $current_user;
					$woap_manage   = MyAccount::member_form_url( $woap_member->get_id() );
					$woap_custom   = ! empty( $woap_member->get_capabilities() );
					$woap_restrict = array();

					foreach ( $woap_access as $woap_id ) {
						if ( isset( $woap_location_names[ $woap_id ] ) ) {
							$woap_restrict[] = $woap_location_names[ $woap_id ];
						}
					}
					?>
					<tr>
						<td data-title="<?php echo esc_attr( $woap_columns['member'] ); ?>">
							<a class="woap-table__title" href="<?php echo esc_url( $woap_manage ); ?>">
								<?php echo esc_html( $woap_user instanceof WP_User ? $woap_user->display_name : __( '(deleted account)', 'woo-organization-accounts-pro' ) ); ?>
							</a>
							<?php if ( $woap_is_self ) : ?>
								<span class="woap-status woap-status--neutral"><?php esc_html_e( 'You', 'woo-organization-accounts-pro' ); ?></span>
							<?php endif; ?>
							<?php if ( $woap_user instanceof WP_User ) : ?>
								<span class="woap-table__meta"><?php echo esc_html( $woap_user->user_email ); ?></span>
							<?php endif; ?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['role'] ); ?>">
							<?php echo esc_html( $woap_member->is_admin() ? Labels::organization_admin() : Labels::member() ); ?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['access'] ); ?>">
							<?php if ( empty( $woap_restrict ) ) : ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
										__( 'All %s', 'woo-organization-accounts-pro' ),
										Labels::locations()
									)
								);
								?>
							<?php else : ?>
								<?php echo esc_html( implode( ', ', $woap_restrict ) ); ?>
							<?php endif; ?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['permissions'] ); ?>">
							<?php
							echo esc_html(
								$woap_custom
									? __( 'Set individually', 'woo-organization-accounts-pro' )
									: __( 'Role defaults', 'woo-organization-accounts-pro' )
							);
							?>
						</td>
						<td data-title="<?php echo esc_attr( $woap_columns['status'] ); ?>">
							<?php if ( $woap_member->is_active() ) : ?>
								<span class="woap-status woap-status--active"><?php esc_html_e( 'Active', 'woo-organization-accounts-pro' ); ?></span>
							<?php else : ?>
								<span class="woap-status woap-status--suspended"><?php esc_html_e( 'Inactive', 'woo-organization-accounts-pro' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="woap-actions" data-title="<?php echo esc_attr( $woap_columns['actions'] ); ?>">
							<a href="<?php echo esc_url( $woap_manage ); ?>"><?php esc_html_e( 'Manage', 'woo-organization-accounts-pro' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
