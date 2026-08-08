<?php
/**
 * Shown to a signed-in visitor who lands on the registration page.
 *
 * Override in a theme at woo-organization-accounts/registration/already-signed-in.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $account_url URL of the My Account page.
 */

use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

?>
<div class="woocommerce woap-registration woap-registration--signed-in">
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'You are already signed in. To register another %s, sign out first.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			)
		);
		?>
	</p>
	<p>
		<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( $account_url ); ?>">
			<?php esc_html_e( 'Go to my account', 'woo-organization-accounts-pro' ); ?>
		</a>
	</p>
</div>
