<?php
/**
 * New organization notification to the shop (plain text).
 *
 * Override in a theme at woocommerce/emails/plain/new-organization.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $organization_name  Name of the organization.
 * @var string $organization_noun  The organization noun for the site's mode.
 * @var string $status_label       The status it was created with.
 * @var string $contact_name       Name of the person who registered.
 * @var string $contact_email      Their email address.
 * @var string $review_url         Link to the organization in wp-admin.
 * @var string $email_heading      Heading for the email.
 * @var string $additional_content Extra content from the email settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( $email_heading ) . " =\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: the organization noun for the site's mode, 2: organization name. */
		__( 'A new %1$s has registered: %2$s', 'woo-organization-accounts-pro' ),
		$organization_noun,
		$organization_name
	)
) . "\n\n";

echo esc_html__( 'Status:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $status_label ) . "\n";
echo esc_html__( 'Registered by:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $contact_name ) . "\n";
echo esc_html__( 'Email address:', 'woo-organization-accounts-pro' ) . ' ' . esc_html( $contact_email ) . "\n\n";

echo esc_html__( 'Review the account:', 'woo-organization-accounts-pro' ) . ' ' . esc_url_raw( $review_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
