<?php
/**
 * New organization notification to the shop (HTML).
 *
 * Override in a theme at woocommerce/emails/new-organization.php.
 *
 * @package WooOrgAccounts
 *
 * @var string    $organization_name  Name of the organization.
 * @var string    $organization_noun  The organization noun for the site's mode.
 * @var string    $status_label       The status it was created with.
 * @var string    $contact_name       Name of the person who registered.
 * @var string    $contact_email      Their email address.
 * @var string    $review_url         Link to the organization in wp-admin.
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
		sprintf(
			/* translators: 1: the organization noun for the site's mode, 2: organization name. */
			__( 'A new %1$s has registered: %2$s', 'woo-organization-accounts-pro' ),
			$organization_noun,
			$organization_name
		)
	);
	?>
</p>

<ul>
	<li><?php echo esc_html__( 'Status:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $status_label ); ?></li>
	<li><?php echo esc_html__( 'Registered by:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $contact_name ); ?></li>
	<li><?php echo esc_html__( 'Email address:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $contact_email ); ?></li>
</ul>

<p>
	<a class="button" href="<?php echo esc_url( $review_url ); ?>" style="display:inline-block;padding:12px 20px;background:#7f54b3;color:#ffffff;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Review the account', 'woo-organization-accounts-pro' ); ?>
	</a>
</p>

<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
