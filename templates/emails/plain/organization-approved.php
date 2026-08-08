<?php
/**
 * Organization approved email (plain text).
 *
 * Override in a theme at woocommerce/emails/plain/organization-approved.php.
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

echo esc_html(
	sprintf(
		/* translators: 1: organization name, 2: the organization noun for the site's mode. */
		__( '%1$s has been approved. Everyone on the %2$s account can now place orders.', 'woo-organization-accounts-pro' ),
		$organization_name,
		$organization_noun
	)
) . "\n\n";

echo esc_html__( 'Shop:', 'woo-organization-accounts-pro' ) . ' ' . esc_url_raw( $shop_url ) . "\n";
echo esc_html__( 'Your account:', 'woo-organization-accounts-pro' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
