<?php
/**
 * Adding and editing an organization's delivery addresses, from wp-admin.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Locations\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * One location, on a screen of its own.
 *
 * Reached by `?woap_location=<id>` or `new` from the organization's Locations tab — never a
 * form underneath the list, and never one folded away above it either. The account screens
 * carried all three of the bugs that shape says: you scroll past everything else to reach
 * it, nothing says which row is open, and the row being edited lives only in a query
 * argument the form does not post back, so a rejected submission returns as a blank *add*
 * form and saving again creates a duplicate instead of correcting the original. The ID posts
 * back with the form.
 *
 * **The form is `AddressFields`, not a hand-written address.** That is the founding rule of
 * this plugin's address handling and it is not tidiness: a hand-written form asks a German
 * customer for a state they do not have, gives a Canadian free text where their courier
 * expects a province from a list, and calls a ZIP a postcode in Ohio. The delivery fields
 * are also the relaxed ones — `last_name` and `phone` are never required, because a delivery
 * address belongs to a place at least as often as to a person and "Warehouse North" has no
 * surname.
 */
class LocationScreen {

	/**
	 * Capability required to use it.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_woap_admin_location_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_woap_admin_location_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * The URL of one location's own screen.
	 *
	 * @param int $organization_id Organization the location belongs to.
	 * @param int $location_id     Location ID, or 0 to add one.
	 * @return string URL.
	 */
	public static function edit_url( $organization_id, $location_id = 0 ) {
		return add_query_arg(
			array( 'woap_location' => $location_id > 0 ? absint( $location_id ) : 'new' ),
			Organizations::tab_url( $organization_id, 'locations' )
		);
	}

	/**
	 * A nonced URL that deletes a location.
	 *
	 * @param int $location_id Location ID.
	 * @return string URL.
	 */
	public static function delete_url( $location_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'woap_admin_location_delete',
					'location_id' => absint( $location_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'woap_admin_location_delete_' . absint( $location_id )
		);
	}

	/**
	 * Which location the request is asking to edit.
	 *
	 * @return string A location ID, "new", or an empty string for the list.
	 */
	public static function requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only screen to show.
		return isset( $_GET['woap_location'] ) ? sanitize_text_field( wp_unslash( $_GET['woap_location'] ) ) : '';
	}

	/**
	 * Render the add or edit form.
	 *
	 * @param Organization $organization The organization it belongs to.
	 * @param string       $requested    A location ID, or "new".
	 * @return void
	 */
	public function render_form( Organization $organization, $requested ) {
		$location = ( 'new' === $requested )
			? new Location()
			: LocationRepository::find_for_organization( absint( $requested ), $organization->get_id() );

		if ( ! $location instanceof Location ) {
			printf( '<p>%s</p>', esc_html__( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );

			return;
		}

		list( $errors, $submitted ) = Notices::consume();

		$address = $location->get_shipping_address();

		/*
		 * What a rejected save tried to store wins over what is stored: correcting one
		 * mistyped postcode must not mean retyping the other eleven fields.
		 */
		foreach ( array_keys( $address ) as $field ) {
			if ( array_key_exists( AddressFields::SHIPPING . '_' . $field, $submitted ) ) {
				$address[ $field ] = $submitted[ AddressFields::SHIPPING . '_' . $field ];
			}
		}

		$name       = (string) ( $submitted['woap_name'] ?? $location->get_name() );
		$is_default = array_key_exists( 'woap_is_default', $submitted )
			? (bool) $submitted['woap_is_default']
			: $location->is_default();

		printf(
			'<h2>%s</h2>',
			esc_html(
				0 === $location->get_id()
					? sprintf(
						/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
						__( 'Add a %s', 'woo-organization-accounts-pro' ),
						strtolower( Labels::location() )
					)
					: $location->get_name()
			)
		);

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woap_admin_location_save">';
		printf( '<input type="hidden" name="woap_organization_id" value="%d">', (int) $organization->get_id() );
		printf( '<input type="hidden" name="woap_location_id" value="%d">', (int) $location->get_id() );
		wp_nonce_field( 'woap_admin_location_save_' . $organization->get_id() );

		echo '<table class="form-table" role="presentation"><tbody>';

		$invalid = ( $errors instanceof \WP_Error ) && '' !== $errors->get_error_message( 'woap_name' );

		printf(
			'<tr class="%1$s"><th scope="row"><label for="woap_name">%2$s</label></th><td>',
			$invalid ? 'woap-row--invalid' : '',
			esc_html__( 'Name', 'woo-organization-accounts-pro' )
		);
		printf(
			'<input type="text" class="regular-text" name="woap_name" id="woap_name" value="%s">',
			esc_attr( $name )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'What this address is called in the checkout selector — "Head office", "Warehouse North". Only the label; nothing on a parcel.', 'woo-organization-accounts-pro' )
		);

		if ( $invalid ) {
			printf( '<p class="woap-field-error">%s</p>', wp_kses_post( $errors->get_error_message( 'woap_name' ) ) );
		}

		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="woap_is_default" value="1"%2$s> %3$s</label></td></tr>',
			esc_html__( 'Default', 'woo-organization-accounts-pro' ),
			checked( $is_default, true, false ),
			esc_html__( 'Offer this one first at the checkout', 'woo-organization-accounts-pro' )
		);

		echo '</tbody></table>';

		printf( '<h3>%s</h3>', esc_html__( 'Delivery address', 'woo-organization-accounts-pro' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A surname and a phone number are optional here: a delivery address belongs to a place at least as often as to a person. Everything else is what the checkout asks for in that country.', 'woo-organization-accounts-pro' )
		);

		AddressFields::render( AddressFields::SHIPPING, $address, array( 'errors' => $errors ) );

		submit_button( __( 'Save', 'woo-organization-accounts-pro' ) );

		printf(
			'<a href="%1$s" class="button button-link">%2$s</a>',
			esc_url( Organizations::tab_url( $organization->get_id(), 'locations' ) ),
			esc_html__( 'Cancel', 'woo-organization-accounts-pro' )
		);

		echo '</form>';
	}

	/**
	 * Save an added or edited location.
	 *
	 * @return void
	 */
	public function handle_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$organization_id = isset( $_POST['woap_organization_id'] ) ? absint( wp_unslash( $_POST['woap_organization_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_location_save_' . $organization_id );
		self::require_capability();

		$organization = OrganizationRepository::find( $organization_id );

		if ( ! $organization instanceof Organization ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			self::go_to( Organizations::list_url() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$location_id = isset( $_POST['woap_location_id'] ) ? absint( wp_unslash( $_POST['woap_location_id'] ) ) : 0;

		$location = $location_id > 0
			? LocationRepository::find_for_organization( $location_id, $organization->get_id() )
			: new Location();

		if ( ! $location instanceof Location ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			self::go_to( Organizations::tab_url( $organization_id, 'locations' ) );
		}

		$address = AddressFields::posted( AddressFields::SHIPPING );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$name = isset( $_POST['woap_name'] ) ? sanitize_text_field( wp_unslash( $_POST['woap_name'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$is_default = ! empty( $_POST['woap_is_default'] );

		$saved = Locations::save(
			$organization,
			$location,
			array_merge(
				$address,
				array(
					'name'       => $name,
					'is_default' => $is_default,
				)
			)
		);

		if ( is_wp_error( $saved ) ) {
			Notices::hold(
				self::form_errors( $saved ),
				array_merge(
					self::prefixed_address( $address ),
					array(
						'woap_name'       => $name,
						'woap_is_default' => $is_default,
					)
				)
			);

			self::go_to( self::edit_url( $organization_id, $location_id ) );
		}

		Notices::success(
			sprintf(
				/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
				__( '%s saved.', 'woo-organization-accounts-pro' ),
				Labels::location()
			)
		);

		self::go_to( Organizations::tab_url( $organization_id, 'locations' ) );
	}

	/**
	 * Delete a location.
	 *
	 * @return void
	 */
	public function handle_delete() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$location_id = isset( $_GET['location_id'] ) ? absint( wp_unslash( $_GET['location_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_location_delete_' . $location_id );
		self::require_capability();

		$location = LocationRepository::find( $location_id );

		if ( ! $location instanceof Location ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			self::go_to( Organizations::list_url() );
		}

		$organization_id = $location->get_organization_id();
		$deleted         = Locations::delete( $location );

		if ( is_wp_error( $deleted ) ) {
			Notices::error( $deleted->get_error_message() );
			self::go_to( Organizations::tab_url( $organization_id, 'locations' ) );
		}

		Notices::success(
			Locations::can_ship( $organization_id )
				? sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( '%s deleted.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
				: sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( '%s deleted. This account now has nowhere to ship to, so nobody on it can check out until another is added.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
		);

		self::go_to( Organizations::tab_url( $organization_id, 'locations' ) );
	}

	/**
	 * Re-key a service refusal the way this form's fields are named.
	 *
	 * The service reports against the column — `postcode`, `name` — because that is what the
	 * record calls it. `AddressFields::render()` marks a field by its prefixed input name,
	 * and the name field has no prefix to get.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return \WP_Error The same messages, keyed the way this form names its fields.
	 */
	private static function form_errors( \WP_Error $error ) {
		$rekeyed = new \WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$code = (string) $code;

			if ( in_array( $code, Location::ADDRESS_FIELDS, true ) ) {
				$rekeyed->add( AddressFields::SHIPPING . '_' . $code, $error->get_error_message( $code ) );

				continue;
			}

			$rekeyed->add( 0 === strpos( $code, 'woap_' ) ? $code : 'woap_' . $code, $error->get_error_message( $code ) );
		}

		return $rekeyed;
	}

	/**
	 * Re-key an address by its prefixed field names, as the form posts them.
	 *
	 * @param array $address Values keyed without the prefix.
	 * @return array Values keyed with it.
	 */
	private static function prefixed_address( array $address ) {
		$prefixed = array();

		foreach ( $address as $field => $value ) {
			$prefixed[ AddressFields::SHIPPING . '_' . $field ] = $value;
		}

		return $prefixed;
	}

	/**
	 * Refuse anybody without the capability.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do that.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Finish a handler by going somewhere.
	 *
	 * @param string $url Where to go.
	 * @return void
	 */
	private static function go_to( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
