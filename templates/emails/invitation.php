<?php
/**
 * Invitation email (HTML).
 *
 * Override in a theme at woocommerce/emails/invitation.php.
 *
 * @package WooOrgAccounts
 *
 * @var string    $organization_name Name of the organization.
 * @var string    $accept_url        The one-time join link.
 * @var string    $organization_noun The organization noun for the site's mode.
 * @var string    $expires           Formatted expiry date, or an empty string.
 * @var string    $email_heading     Heading for the email.
 * @var string    $additional_content Extra content from the email settings.
 * @var \WC_Email $email             The email being sent.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

?>
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: organization name, 2: the organization noun for the site's mode. */
			__( 'You have been invited to join %1$s on our shop, so you can order on the %2$s account.', 'woo-organization-accounts-pro' ),
			$organization_name,
			$organization_noun
		)
	);
	?>
</p>

<p>
	<a class="button" href="<?php echo esc_url( $accept_url ); ?>" style="display:inline-block;padding:12px 20px;background:#7f54b3;color:#ffffff;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Accept the invitation', 'woo-organization-accounts-pro' ); ?>
	</a>
</p>

<p style="font-size:0.9em;color:#555;">
	<?php esc_html_e( 'If the button does not work, copy this link into your browser:', 'woo-organization-accounts-pro' ); ?><br>
	<?php echo esc_html( $accept_url ); ?>
</p>

<?php if ( '' !== $expires ) : ?>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the date the invitation expires. */
				__( 'The link stops working on %s.', 'woo-organization-accounts-pro' ),
				$expires
			)
		);
		?>
	</p>
<?php endif; ?>

<p style="font-size:0.9em;color:#555;">
	<?php esc_html_e( 'It works only once, and only for this email address. If you were not expecting it, you can ignore this message.', 'woo-organization-accounts-pro' ); ?>
</p>

<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
