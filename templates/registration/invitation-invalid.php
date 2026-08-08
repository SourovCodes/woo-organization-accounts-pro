<?php
/**
 * Shown when an invitation link cannot be used.
 *
 * Override in a theme at woo-organization-accounts/registration/invitation-invalid.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $message Why the link was refused.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="woocommerce woap-invitation woap-invitation--invalid">
	<ul class="woocommerce-error" role="alert">
		<li><?php echo esc_html( $message ); ?></li>
	</ul>
</div>
