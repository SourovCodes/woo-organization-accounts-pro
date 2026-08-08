<?php
/**
 * My Account: the organization's members.
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
 */

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

$woap_post_url = esc_url( wc_get_account_endpoint_url( MyAccount::ENDPOINT_MEMBERS ) );
$woap_labels   = Roles::labels();

?>
<div class="woap-account woap-account--members">

	<p class="woap-account__note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
				__( 'Everyone who can order on this account. To add somebody, send them an invitation.', 'woo-organization-accounts-pro' ),
				Labels::members()
			)
		);
		?>
	</p>

	<?php if ( empty( $members ) ) : ?>
		<p><?php esc_html_e( 'Nobody here yet.', 'woo-organization-accounts-pro' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $members as $woap_member ) : ?>
		<?php
		$woap_user       = isset( $users[ $woap_member->get_id() ] ) ? $users[ $woap_member->get_id() ] : null;
		$woap_access     = isset( $access[ $woap_member->get_id() ] ) ? $access[ $woap_member->get_id() ] : array();
		$woap_is_self    = $woap_member->get_user_id() === $current_user;
		$woap_overrides  = $woap_member->get_capabilities();
		$woap_defaults   = Roles::role_capabilities( $woap_member->get_role() );
		$woap_effective  = array_merge( $woap_defaults, $woap_overrides );
		$woap_field_base = 'woap-member-' . $woap_member->get_id();
		?>

		<details class="woap-member" <?php echo $woap_is_self ? 'open' : ''; ?>>
			<summary class="woap-member__summary">
				<strong><?php echo esc_html( $woap_user instanceof WP_User ? $woap_user->display_name : __( '(deleted account)', 'woo-organization-accounts-pro' ) ); ?></strong>
				<span class="woap-member__email"><?php echo esc_html( $woap_user instanceof WP_User ? $woap_user->user_email : '' ); ?></span>
				<span class="woap-member__role">
					<?php echo esc_html( $woap_member->is_admin() ? Labels::organization_admin() : Labels::member() ); ?>
				</span>
				<?php if ( ! $woap_member->is_active() ) : ?>
					<span class="woap-status woap-status--suspended"><?php esc_html_e( 'Inactive', 'woo-organization-accounts-pro' ); ?></span>
				<?php endif; ?>
			</summary>

			<form class="woocommerce-form woap-member__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
				<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="update_member">
				<input type="hidden" name="woap_member_id" value="<?php echo esc_attr( (string) $woap_member->get_id() ); ?>">
				<?php wp_nonce_field( 'woap_update_member' ); ?>

				<p class="woocommerce-form-row form-row-first">
					<label for="<?php echo esc_attr( $woap_field_base . '-role' ); ?>"><?php esc_html_e( 'Role', 'woo-organization-accounts-pro' ); ?></label>
					<select id="<?php echo esc_attr( $woap_field_base . '-role' ); ?>" name="woap_role">
						<option value="<?php echo esc_attr( Member::ROLE_MEMBER ); ?>" <?php selected( $woap_member->get_role(), Member::ROLE_MEMBER ); ?>>
							<?php echo esc_html( Labels::member() ); ?>
						</option>
						<option value="<?php echo esc_attr( Member::ROLE_ADMIN ); ?>" <?php selected( $woap_member->get_role(), Member::ROLE_ADMIN ); ?>>
							<?php echo esc_html( Labels::organization_admin() ); ?>
						</option>
					</select>
				</p>

				<p class="woocommerce-form-row form-row-last">
					<label for="<?php echo esc_attr( $woap_field_base . '-status' ); ?>"><?php esc_html_e( 'Status', 'woo-organization-accounts-pro' ); ?></label>
					<select id="<?php echo esc_attr( $woap_field_base . '-status' ); ?>" name="woap_status">
						<option value="<?php echo esc_attr( Member::STATUS_ACTIVE ); ?>" <?php selected( (string) $woap_member->get( 'status' ), Member::STATUS_ACTIVE ); ?>>
							<?php esc_html_e( 'Active', 'woo-organization-accounts-pro' ); ?>
						</option>
						<option value="<?php echo esc_attr( Member::STATUS_INACTIVE ); ?>" <?php selected( (string) $woap_member->get( 'status' ), Member::STATUS_INACTIVE ); ?>>
							<?php esc_html_e( 'Inactive', 'woo-organization-accounts-pro' ); ?>
						</option>
					</select>
				</p>

				<fieldset class="woap-member__permissions">
					<legend><?php esc_html_e( 'Permissions', 'woo-organization-accounts-pro' ); ?></legend>
					<?php foreach ( $woap_labels as $woap_capability => $woap_label ) : ?>
						<label>
							<input type="checkbox" name="woap_capabilities[]" value="<?php echo esc_attr( $woap_capability ); ?>" <?php checked( ! empty( $woap_effective[ $woap_capability ] ) ); ?>>
							<?php echo esc_html( $woap_label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>

				<?php if ( ! empty( $locations ) ) : ?>
					<fieldset class="woap-member__locations">
						<legend>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
									__( 'Limit to these %s', 'woo-organization-accounts-pro' ),
									Labels::locations()
								)
							);
							?>
						</legend>
						<p class="woap-account__note"><?php esc_html_e( 'Leave all unticked for access to every one.', 'woo-organization-accounts-pro' ); ?></p>
						<?php foreach ( $locations as $woap_location ) : ?>
							<label>
								<input type="checkbox" name="woap_location_access[]" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>" <?php checked( in_array( $woap_location->get_id(), $woap_access, true ) ); ?>>
								<?php echo esc_html( $woap_location->get_name() ); ?>
							</label><br>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<p>
					<button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Save', 'woo-organization-accounts-pro' ); ?></button>
				</p>
			</form>

			<?php if ( ! $woap_is_self ) : ?>
				<form class="woap-member__remove" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
					<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="remove_member">
					<input type="hidden" name="woap_member_id" value="<?php echo esc_attr( (string) $woap_member->get_id() ); ?>">
					<?php wp_nonce_field( 'woap_remove_member' ); ?>
					<button type="submit" class="woocommerce-Button button woap-button--danger">
						<?php esc_html_e( 'Remove from this account', 'woo-organization-accounts-pro' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</details>
	<?php endforeach; ?>
</div>
