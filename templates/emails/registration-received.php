<?php
/**
 * Registration received email to the customer (HTML).
 *
 * Override in a theme at woocommerce/emails/registration-received.php.
 *
 * @package WooOrgAccounts
 *
 * @var string    $organization_name  Name of the organization.
 * @var string    $organization_noun  The organization noun for the site's mode.
 * @var string    $first_name         First name of the person who registered.
 * @var bool      $needs_approval     Whether the account still has to be approved.
 * @var string    $account_url        URL of the My Account page.
 * @var string    $shop_url           URL of the shop page.
 * @var string    $email_heading      Heading for the email.
 * @var string    $additional_content Extra content from the email settings.
 * @var \WC_Email $email              The email being sent.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

?>
<p>
	<?php
	echo esc_html(
		'' !== $first_name
			/* translators: %s: the customer's first name. */
			? sprintf( __( 'Hello %s,', 'woo-organization-accounts-pro' ), $first_name )
			: __( 'Hello,', 'woo-organization-accounts-pro' )
	);
	?>
</p>

<p>
	<?php esc_html_e( 'Thank you for registering. We have received your registration.', 'woo-organization-accounts-pro' ); ?>
</p>

<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: the organization noun for the site's mode, for example "Company", 2: organization name. */
			__( '%1$s: %2$s', 'woo-organization-accounts-pro' ),
			$organization_noun,
			$organization_name
		)
	);
	?>
</p>

<?php if ( $needs_approval ) : ?>
	<p>
		<?php esc_html_e( 'New accounts are reviewed before they can be used. We will email you as soon as yours has been approved, and you can sign in from then on.', 'woo-organization-accounts-pro' ); ?>
	</p>
<?php else : ?>
	<p>
		<?php esc_html_e( 'Your account is ready. Everyone on it can now place orders.', 'woo-organization-accounts-pro' ); ?>
	</p>

	<p>
		<a class="button" href="<?php echo esc_url( $shop_url ); ?>" style="display:inline-block;padding:12px 20px;background:#7f54b3;color:#ffffff;text-decoration:none;border-radius:3px;">
			<?php esc_html_e( 'Start shopping', 'woo-organization-accounts-pro' ); ?>
		</a>
	</p>
<?php endif; ?>

<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
