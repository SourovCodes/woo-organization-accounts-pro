<?php
/**
 * The customer view switch, on the retailer's account dashboard.
 *
 * Override in a theme at woo-organization-accounts/customer-view/toggle.php.
 *
 * @package WooOrgAccounts
 *
 * @var bool   $active   Whether customer view is currently on.
 * @var string $fields   The hidden fields and nonce that switch it the other way.
 * @var string $headline The state, said plainly.
 * @var string $note     What it does, or what it is doing.
 * @var string $button   The switch.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="woap-customer-view <?php echo $active ? 'woap-customer-view--on' : 'woap-customer-view--off'; ?>">
	<span class="woap-customer-view__title"><?php echo esc_html( $headline ); ?></span>

	<p class="woap-customer-view__note"><?php echo esc_html( $note ); ?></p>

	<?php /* Empty action, so the form posts back to the page it is on, query arguments included. */ ?>
	<form method="post" action="">
		<?php echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by CustomerView::form_fields() from esc_attr() and wp_nonce_field(). ?>
		<button type="submit" class="btn btn-style-bordered woap-customer-view__button">
			<?php echo esc_html( $button ); ?>
		</button>
	</form>
</div>
