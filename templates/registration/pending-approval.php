<?php
/**
 * Shown after registering, when the account may not sign in until it is approved.
 *
 * Override in a theme at woo-organization-accounts/registration/pending-approval.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $message     Why the account cannot sign in yet.
 * @var string $account_url URL of the My Account page.
 * @var string $shop_url    URL of the shop page.
 */

use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

?>
<?php
/*
 * Woodmart's registration container with the narrow modifier, as the invitation form
 * uses: this screen is a sentence and two links, and the theme's 450px column is the
 * right shape for it.
 */
?>
<div class="woocommerce woap-registration woap-registration--pending wd-registration-page wd-no-registration">

	<h2>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'Thank you — your %s has been registered', 'woo-organization-accounts-pro' ),
				Labels::organization()
			)
		);
		?>
	</h2>

	<ul class="woocommerce-info" role="status">
		<li><?php echo esc_html( $message ); ?></li>
	</ul>

	<p>
		<?php esc_html_e( 'We will email you at the address you registered with as soon as the account has been reviewed.', 'woo-organization-accounts-pro' ); ?>
	</p>

	<p>
		<?php if ( '' !== (string) $shop_url ) : ?>
			<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( $shop_url ); ?>">
				<?php esc_html_e( 'Continue to the shop', 'woo-organization-accounts-pro' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( '' !== (string) $account_url ) : ?>
			<a class="woocommerce-Button button btn-style-bordered" href="<?php echo esc_url( $account_url ); ?>">
				<?php esc_html_e( 'Go to sign in', 'woo-organization-accounts-pro' ); ?>
			</a>
		<?php endif; ?>
	</p>
</div>
