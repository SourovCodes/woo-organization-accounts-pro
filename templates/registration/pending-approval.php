<?php
/**
 * Shown after registering, when the account may not sign in until it is approved.
 *
 * Override in a theme at woo-organization-accounts/registration/pending-approval.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $message           Why the account cannot sign in yet.
 * @var string $account_url       URL of the My Account page.
 * @var string $shop_url          URL of the shop page.
 * @var string $organization_name What the account is for.
 * @var bool   $signed_in         Whether they are signed in while they wait.
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

	<?php
	/*
	 * What was created is a customer account, and that is what is being reviewed. The
	 * company is named underneath as what the account is *for* — a customer who has just
	 * filled in a twenty-field form knows perfectly well which company they typed, and
	 * telling them their company is under review reads as a second, unexplained step.
	 */
	?>
	<h2>
		<?php esc_html_e( 'Thank you — your account has been created', 'woo-organization-accounts-pro' ); ?>
	</h2>

	<?php if ( ! empty( $organization_name ) ) : ?>
		<p class="woap-registration__for">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: the organization noun for the site's mode, for example "Company", 2: the organization's name. */
					__( '%1$s: %2$s', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					(string) $organization_name
				)
			);
			?>
		</p>
	<?php endif; ?>

	<ul class="woocommerce-info" role="status">
		<li><?php echo esc_html( $message ); ?></li>
	</ul>

	<p>
		<?php esc_html_e( 'We will email you at the address you registered with as soon as it has been reviewed.', 'woo-organization-accounts-pro' ); ?>
	</p>

	<p>
		<?php if ( '' !== (string) $shop_url ) : ?>
			<a class="woocommerce-Button button btn-color-primary" href="<?php echo esc_url( $shop_url ); ?>">
				<?php esc_html_e( 'Continue to the shop', 'woo-organization-accounts-pro' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( '' !== (string) $account_url ) : ?>
			<a class="woocommerce-Button button btn-style-bordered" href="<?php echo esc_url( $account_url ); ?>">
				<?php
				echo esc_html(
					! empty( $signed_in )
						? __( 'Go to my account', 'woo-organization-accounts-pro' )
						: __( 'Go to sign in', 'woo-organization-accounts-pro' )
				);
				?>
			</a>
		<?php endif; ?>
	</p>
</div>
