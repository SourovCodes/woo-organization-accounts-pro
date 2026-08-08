<?php
/**
 * My Account: manage one member.
 *
 * A screen of its own rather than an accordion under the list, so what is being changed
 * is unmistakable, a rejected submission comes back knowing whose it was, and the list
 * costs one row per person instead of one form per person.
 *
 * Override in a theme at woo-organization-accounts/myaccount/member-form.php.
 *
 * @package WooOrgAccounts
 *
 * @var \WooOrgAccounts\Data\Organization $organization The organization.
 * @var \WooOrgAccounts\Data\Member       $member       The member being managed.
 * @var \WP_User|false                    $user         Their WordPress account.
 * @var int[]                             $access       Location IDs they are limited to; empty means all.
 * @var \WooOrgAccounts\Data\Location[]   $locations    The organization's locations.
 * @var bool                              $is_self      Whether the viewer is managing their own membership.
 */

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Frontend\AccountHandlers;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

$woap_list_url = MyAccount::members_url();
$woap_post_url = esc_url( MyAccount::member_form_url( $member->get_id() ) );

$woap_role       = $member->get_role();
$woap_status     = (string) $member->get( 'status' );
$woap_overrides  = $member->get_capabilities();
$woap_perm_scope = empty( $woap_overrides ) ? 'role' : 'custom';
$woap_effective  = array_merge( Roles::role_capabilities( $woap_role ), $woap_overrides );
$woap_loc_scope  = empty( $access ) ? 'all' : 'selected';
$woap_access     = array_map( 'absint', (array) $access );

// A rejected submission is handed straight back, so the form shows what was chosen.
if ( AccountHandlers::has_submission() ) {
	$woap_role       = AccountHandlers::value( 'woap_role', $woap_role );
	$woap_status     = AccountHandlers::value( 'woap_status', $woap_status );
	$woap_perm_scope = AccountHandlers::value( 'woap_permissions_scope', $woap_perm_scope );
	$woap_loc_scope  = AccountHandlers::value( 'woap_location_scope', $woap_loc_scope );
	$woap_effective  = array_merge(
		array_fill_keys( Roles::capabilities(), false ),
		array_fill_keys( (array) AccountHandlers::value( 'woap_capabilities', array() ), true )
	);
	$woap_access     = array_map( 'absint', (array) AccountHandlers::value( 'woap_location_access', $woap_access ) );
}

$woap_admin_defaults  = Roles::role_capabilities( Member::ROLE_ADMIN );
$woap_member_defaults = Roles::role_capabilities( Member::ROLE_MEMBER );
$woap_name            = $user instanceof WP_User ? $user->display_name : __( '(deleted account)', 'woo-organization-accounts-pro' );
$woap_joined          = $member->get( 'date_created' );

?>
<div class="woap-account woap-account--member-form">

	<p class="woap-account__back">
		<a href="<?php echo esc_url( $woap_list_url ); ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
					__( '← Back to %s', 'woo-organization-accounts-pro' ),
					Labels::members()
				)
			);
			?>
		</a>
	</p>

	<div class="woap-identity">
		<h3 class="woap-identity__name">
			<?php echo esc_html( $woap_name ); ?>
			<?php if ( $is_self ) : ?>
				<span class="woap-status woap-status--neutral"><?php esc_html_e( 'You', 'woo-organization-accounts-pro' ); ?></span>
			<?php endif; ?>
		</h3>

		<ul class="woap-meta">
			<?php if ( $user instanceof WP_User ) : ?>
				<li class="woap-meta__item">
					<span class="woap-meta__label"><?php esc_html_e( 'Email address', 'woo-organization-accounts-pro' ); ?></span>
					<span class="woap-meta__value"><?php echo esc_html( $user->user_email ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( ! empty( $woap_joined ) ) : ?>
				<li class="woap-meta__item">
					<span class="woap-meta__label"><?php esc_html_e( 'Joined', 'woo-organization-accounts-pro' ); ?></span>
					<span class="woap-meta__value"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $woap_joined . ' UTC' ) ) ); ?></span>
				</li>
			<?php endif; ?>
		</ul>
	</div>

	<form class="woocommerce-form woap-account__form" method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
		<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="update_member">
		<input type="hidden" name="woap_member_id" value="<?php echo esc_attr( (string) $member->get_id() ); ?>">
		<?php wp_nonce_field( 'woap_update_member' ); ?>

		<fieldset class="woap-panel">
			<legend class="woap-panel__title"><?php esc_html_e( 'Role and status', 'woo-organization-accounts-pro' ); ?></legend>

			<p class="form-row form-row-first">
				<label for="woap-member-role"><?php esc_html_e( 'Role', 'woo-organization-accounts-pro' ); ?></label>
				<span class="woocommerce-input-wrapper">
					<select id="woap-member-role" class="woap-role-select" name="woap_role">
						<?php foreach ( Member::roles() as $woap_value => $woap_label ) : ?>
							<option value="<?php echo esc_attr( $woap_value ); ?>" <?php selected( $woap_role, $woap_value ); ?>>
								<?php echo esc_html( $woap_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: the organization admin noun, 2: the singular member noun. */
								__( 'The %1$s role manages everything on these screens. The %2$s role places orders and nothing more, unless you choose permissions one by one below.', 'woo-organization-accounts-pro' ),
								Labels::organization_admin(),
								Labels::member()
							)
						);
						?>
					</span>
				</span>
			</p>

			<p class="form-row form-row-last">
				<label for="woap-member-status"><?php esc_html_e( 'Status', 'woo-organization-accounts-pro' ); ?></label>
				<span class="woocommerce-input-wrapper">
					<select id="woap-member-status" name="woap_status">
						<option value="<?php echo esc_attr( Member::STATUS_ACTIVE ); ?>" <?php selected( $woap_status, Member::STATUS_ACTIVE ); ?>>
							<?php esc_html_e( 'Active', 'woo-organization-accounts-pro' ); ?>
						</option>
						<option value="<?php echo esc_attr( Member::STATUS_INACTIVE ); ?>" <?php selected( $woap_status, Member::STATUS_INACTIVE ); ?>>
							<?php esc_html_e( 'Inactive', 'woo-organization-accounts-pro' ); ?>
						</option>
					</select>
					<span class="description">
						<?php esc_html_e( 'An inactive member keeps their sign-in but cannot order until you make them active again.', 'woo-organization-accounts-pro' ); ?>
					</span>
				</span>
			</p>
		</fieldset>

		<fieldset class="woap-panel woap-choice" data-woap-choice="permissions">
			<legend class="woap-panel__title"><?php esc_html_e( 'Permissions', 'woo-organization-accounts-pro' ); ?></legend>

			<label class="woap-choice__option">
				<input type="radio" name="woap_permissions_scope" value="role" <?php checked( 'role', $woap_perm_scope ); ?>>
				<span><?php esc_html_e( 'Whatever the role allows', 'woo-organization-accounts-pro' ); ?></span>
			</label>

			<label class="woap-choice__option">
				<input type="radio" name="woap_permissions_scope" value="custom" <?php checked( 'custom', $woap_perm_scope ); ?>>
				<span><?php esc_html_e( 'Choose them one by one', 'woo-organization-accounts-pro' ); ?></span>
			</label>

			<div class="woap-choice__detail woap-checklist" data-woap-choice-detail="custom" data-woap-choice-keep>
				<?php foreach ( Roles::labels() as $woap_capability => $woap_label ) : ?>
					<label class="woap-checklist__item">
						<input
							type="checkbox"
							name="woap_capabilities[]"
							value="<?php echo esc_attr( $woap_capability ); ?>"
							data-woap-default-admin="<?php echo empty( $woap_admin_defaults[ $woap_capability ] ) ? '0' : '1'; ?>"
							data-woap-default-member="<?php echo empty( $woap_member_defaults[ $woap_capability ] ) ? '0' : '1'; ?>"
							<?php checked( ! empty( $woap_effective[ $woap_capability ] ) ); ?>
						>
						<span><?php echo esc_html( $woap_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<?php if ( ! empty( $locations ) ) : ?>
			<fieldset class="woap-panel woap-choice" data-woap-choice="locations">
				<legend class="woap-panel__title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
							__( 'Which %s they can deliver to', 'woo-organization-accounts-pro' ),
							Labels::locations()
						)
					);
					?>
				</legend>

				<label class="woap-choice__option">
					<input type="radio" name="woap_location_scope" value="all" <?php checked( 'all', $woap_loc_scope ); ?>>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
								__( 'All %s, including any added later', 'woo-organization-accounts-pro' ),
								Labels::locations()
							)
						);
						?>
					</span>
				</label>

				<label class="woap-choice__option">
					<input type="radio" name="woap_location_scope" value="selected" <?php checked( 'selected', $woap_loc_scope ); ?>>
					<span><?php esc_html_e( 'Only the ones I tick', 'woo-organization-accounts-pro' ); ?></span>
				</label>

				<div class="woap-choice__detail woap-checklist" data-woap-choice-detail="selected">
					<?php foreach ( $locations as $woap_location ) : ?>
						<label class="woap-checklist__item">
							<input type="checkbox" name="woap_location_access[]" value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>" <?php checked( in_array( $woap_location->get_id(), $woap_access, true ) ); ?>>
							<span><?php echo esc_html( $woap_location->get_name() ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endif; ?>

		<p class="woap-account__actions">
			<button type="submit" class="woocommerce-Button button btn-color-primary"><?php esc_html_e( 'Save changes', 'woo-organization-accounts-pro' ); ?></button>
			<a class="woap-account__cancel" href="<?php echo esc_url( $woap_list_url ); ?>"><?php esc_html_e( 'Cancel', 'woo-organization-accounts-pro' ); ?></a>
		</p>
	</form>

	<?php if ( ! $is_self ) : ?>
		<div class="woap-panel woap-panel--danger">
			<h4 class="woap-panel__title"><?php esc_html_e( 'Remove from this account', 'woo-organization-accounts-pro' ); ?></h4>

			<p class="woap-account__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the organization noun for the site's mode, for example "Company". */
						__( 'They keep their own sign-in and their past orders stay on the %s. They can no longer order on it.', 'woo-organization-accounts-pro' ),
						Labels::organization()
					)
				);
				?>
			</p>

			<form method="post" action="<?php echo $woap_post_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above with esc_url(). ?>">
				<input type="hidden" name="<?php echo esc_attr( AccountHandlers::ACTION_FIELD ); ?>" value="remove_member">
				<input type="hidden" name="woap_member_id" value="<?php echo esc_attr( (string) $member->get_id() ); ?>">
				<?php wp_nonce_field( 'woap_remove_member' ); ?>
				<button type="submit" class="woocommerce-Button button btn-style-bordered woap-button--danger" data-woap-confirm="
					<?php
					echo esc_attr(
						sprintf(
							/* translators: %s: the name of the member being removed. */
							__( 'Remove %s? They keep their sign-in but can no longer order on this account.', 'woo-organization-accounts-pro' ),
							$woap_name
						)
					);
					?>
				">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the name of the member being removed. */
							__( 'Remove %s', 'woo-organization-accounts-pro' ),
							$woap_name
						)
					);
					?>
				</button>
			</form>
		</div>
	<?php endif; ?>
</div>
