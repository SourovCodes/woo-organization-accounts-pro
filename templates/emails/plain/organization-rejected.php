<?php
/**
 * Organization rejected email (plain text).
 *
 * Override in a theme at woocommerce/emails/plain/organization-rejected.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $organization_name  Name of the organization.
 * @var string $organization_noun  The organization noun for the site's mode.
 * @var string $account_url        URL of the My Account page.
 * @var string $shop_url           URL of the shop page.
 * @var string $email_heading      Heading for the email.
 * @var string $additional_content Extra content from the email settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( $email_heading ) . " =\n\n";

echo esc_html__( 'We are not able to open your account at the moment.', 'woo-organization-accounts-pro' ) . "\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: the organization noun for the site's mode, for example "Company", 2: organization name. */
		__( '%1$s: %2$s', 'woo-organization-accounts-pro' ),
		$organization_noun,
		$organization_name
	)
) . "\n\n";

echo esc_html__( 'If you think this is a mistake, or you would like to know more, please get in touch with us and we will look at it again.', 'woo-organization-accounts-pro' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
