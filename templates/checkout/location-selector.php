<?php
/**
 * Delivery location selector on the classic checkout.
 *
 * Override in a theme at woo-organization-accounts/checkout/location-selector.php.
 *
 * @package WooOrgAccounts
 *
 * @var string                             $field         Name of the select field.
 * @var string                             $custom        Value meaning "a one-off address".
 * @var \WooOrgAccounts\Data\Location[]     $locations     Locations the member may ship to.
 * @var string                             $selected      Currently selected value.
 * @var bool                               $allow_custom  Whether a one-off address is allowed.
 * @var string                             $location_noun Singular location noun for the site's mode.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $locations ) && ! $allow_custom ) {
	return;
}

$woap_default = $selected;

if ( '' === $woap_default ) {
	foreach ( $locations as $woap_location ) {
		if ( $woap_location->is_default() ) {
			$woap_default = (string) $woap_location->get_id();
			break;
		}
	}
}

if ( '' === $woap_default && ! empty( $locations ) ) {
	$woap_default = (string) $locations[0]->get_id();
}

?>
<div class="woap-location-selector">
	<p class="form-row form-row-wide">
		<label for="woap-location">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Deliver to which %s?', 'woo-organization-accounts-pro' ),
					$location_noun
				)
			);
			?>
		</label>
		<select id="woap-location" name="<?php echo esc_attr( $field ); ?>" class="woap-location-select">
			<?php foreach ( $locations as $woap_location ) : ?>
				<option value="<?php echo esc_attr( (string) $woap_location->get_id() ); ?>" <?php selected( $woap_default, (string) $woap_location->get_id() ); ?>>
					<?php echo esc_html( $woap_location->get_name() ); ?>
				</option>
			<?php endforeach; ?>

			<?php if ( $allow_custom ) : ?>
				<option value="<?php echo esc_attr( $custom ); ?>" <?php selected( $woap_default, $custom ); ?>>
					<?php esc_html_e( 'A different address (enter it below)', 'woo-organization-accounts-pro' ); ?>
				</option>
			<?php endif; ?>
		</select>
	</p>
</div>
