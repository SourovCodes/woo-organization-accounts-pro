<?php
/**
 * Invitation email (plain text).
 *
 * Override in a theme at woocommerce/emails/plain/invitation.php.
 *
 * @package WooOrgAccounts
 *
 * @var string $organization_name  Name of the organization.
 * @var string $accept_url         The one-time join link.
 * @var string $organization_noun  The organization noun for the site's mode.
 * @var string $expires            Formatted expiry date, or an empty string.
 * @var string $email_heading      Heading for the email.
 * @var string $additional_content Extra content from the email settings.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( $email_heading ) . " =\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: organization name, 2: the organization noun for the site's mode. */
		__( 'You have been invited to join %1$s on our shop, so you can order on the %2$s account.', 'woo-organization-accounts-pro' ),
		$organization_name,
		$organization_noun
	)
) . "\n\n";

echo esc_html__( 'Open this link to accept:', 'woo-organization-accounts-pro' ) . "\n";
echo esc_url_raw( $accept_url ) . "\n\n";

if ( '' !== $expires ) {
	echo esc_html(
		sprintf(
			/* translators: %s: the date the invitation expires. */
			__( 'The link stops working on %s.', 'woo-organization-accounts-pro' ),
			$expires
		)
	) . "\n\n";
}

echo esc_html__( 'It works only once, and only for this email address. If you were not expecting it, you can ignore this message.', 'woo-organization-accounts-pro' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
