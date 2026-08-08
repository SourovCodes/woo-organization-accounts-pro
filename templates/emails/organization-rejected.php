<?php
/**
 * Organization rejected email (HTML).
 *
 * Override in a theme at woocommerce/emails/organization-rejected.php.
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
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: organization name. */
			__( 'We are not able to open a trade account for %s at the moment.', 'woo-organization-accounts-pro' ),
			$organization_name
		)
	);
	?>
</p>

<p>
	<?php esc_html_e( 'If you think this is a mistake, or you would like to know more, please get in touch with us and we will look at it again.', 'woo-organization-accounts-pro' ); ?>
</p>

<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
