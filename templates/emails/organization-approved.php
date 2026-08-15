<?php
/**
 * Organization approved email (HTML).
 *
 * Override in a theme at woocommerce/emails/organization-approved.php.
 *
 * @package WooOrgAccounts
 *
 * @var string    $organization_name  Name of the organization.
 * @var string    $organization_noun  The organization noun for the site's mode.
 * @var string    $account_url        URL of the My Account page.
 * @var string    $shop_url           URL of the shop page.
 * @var string    $email_heading      Heading for the email.
 * @var string    $additional_content Extra content from the email settings.
 * @var \WC_Email $email              The email being sent.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

?>
<?php
/*
 * The account is what was approved, and the company is what it is for. The recipient is a
 * person who signed up and has been waiting to buy something; leading with the company name
 * answers a question they did not ask, about a record they may not know exists.
 */
?>
<p>
	<?php esc_html_e( 'Your account has been approved. Everyone on it can now place orders.', 'woo-organization-accounts-pro' ); ?>
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

<p>
	<a class="button" href="<?php echo esc_url( $shop_url ); ?>" style="display:inline-block;padding:12px 20px;background:#7f54b3;color:#ffffff;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Start shopping', 'woo-organization-accounts-pro' ); ?>
	</a>
</p>

<p>
	<?php
	printf(
		/* translators: 1: opening link tag, 2: closing link tag. */
		esc_html__( 'You can invite colleagues and manage delivery addresses from %1$syour account%2$s.', 'woo-organization-accounts-pro' ),
		'<a href="' . esc_url( $account_url ) . '">',
		'</a>'
	);
	?>
</p>

<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
