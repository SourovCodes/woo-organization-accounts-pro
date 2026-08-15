<?php
/**
 * Writing the places an organization has goods delivered to.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Locations;

use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * The one expression of what saving and deleting a location does.
 *
 * A location is a WooCommerce shipping address column for column, plus a `name` that is only
 * the label in the checkout selector. Nothing about it is derived at checkout, with one
 * exception that has to happen in exactly one place: **a blank company becomes the
 * organization's name**, stored rather than resolved later, because a parcel with no company
 * on the label is one nobody at a loading bay recognises. Three screens write a location and
 * each deriving that for itself is how the courier comes to get something different from
 * what the screen showed.
 *
 * The address is merged onto the stored one and then validated whole, because which fields
 * an address needs depends on its country: validating only what was sent would let an edit
 * changing `country` alone leave a US address with no state.
 *
 * Errors are keyed by the **unprefixed** field name — `name`, `address_1`, `postcode` — so a
 * caller can mark the field it came from. `Rest\LocationsController` passes them through
 * `Writes::refuse()` with the `shipping_` prefix already stripped; a screen prefixes them
 * back to reach `AddressFields::render()`, which keys its `errors` argument by the prefixed
 * name.
 */
final class Locations {

	/**
	 * Validate a submission and store it.
	 *
	 * On an edit the address is only checked when the caller carried one. A record stored
	 * before this plugin validated anything, or one whose country has grown stricter since,
	 * must not make renaming it impossible — the same retroactive-rule objection the relaxed
	 * delivery fields exist for.
	 *
	 * @param Organization  $organization The organization it belongs to.
	 * @param Location|null $location     The location being written, or null to create one.
	 * @param array         $values       `name`, `is_default`, and any of Location::ADDRESS_FIELDS.
	 *                                    A key that is absent is left as it is.
	 * @return Location|\WP_Error The location, or the errors that stopped it.
	 */
	public static function save( Organization $organization, $location, array $values ) {
		$location = $location instanceof Location ? $location : new Location();
		$creating = 0 === $location->get_id();
		$errors   = new \WP_Error();

		$name = array_key_exists( 'name', $values )
			? sanitize_text_field( trim( (string) $values['name'] ) )
			: $location->get_name();

		if ( '' === $name ) {
			$errors->add(
				'name',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Please give the %s a name.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		$submitted = self::submitted_address( $values );

		$address = AddressFields::merge(
			AddressFields::SHIPPING,
			$submitted,
			$creating ? array_fill_keys( Location::ADDRESS_FIELDS, '' ) : $location->get_shipping_address()
		);

		if ( $creating || ! empty( $submitted ) ) {
			$address_errors = new \WP_Error();

			AddressFields::validate( AddressFields::SHIPPING, $address, $address_errors );

			foreach ( $address_errors->get_error_codes() as $code ) {
				$errors->add(
					self::unprefixed( (string) $code ),
					$address_errors->get_error_message( $code )
				);
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		if ( '' === trim( (string) $address['company'] ) ) {
			$address['company'] = $organization->get_name();
		}

		$location->set_props(
			array(
				'organization_id' => $organization->get_id(),
				'name'            => $name,
				'is_default'      => array_key_exists( 'is_default', $values )
					? (bool) $values['is_default']
					: $location->is_default(),
			)
		);

		$location->set_shipping_address( $address );

		if ( 0 === LocationRepository::save( $location ) ) {
			return new \WP_Error(
				'woap_not_saved',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		return $location;
	}

	/**
	 * Delete a location.
	 *
	 * Deleting the last one is allowed. An organization with no location cannot check out,
	 * which is a state the checkout already reports clearly; refusing here would mean a shop
	 * correcting a wrong address had to add the right one first, in that order, for no
	 * reason the screen could explain.
	 *
	 * `LocationRepository::delete()` takes it off every member's access list first, so
	 * nobody is left restricted to a location that is not there — which reads as "all of
	 * them" and would silently widen their access.
	 *
	 * @param Location $location The location to delete.
	 * @return true|\WP_Error True, or the reason it could not be.
	 */
	public static function delete( Location $location ) {
		if ( ! LocationRepository::delete( $location->get_id() ) ) {
			return new \WP_Error(
				'woap_not_saved',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'That %s could not be deleted.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		return true;
	}

	/**
	 * Whether an organization still has somewhere to ship to.
	 *
	 * @param int $organization_id The organization.
	 * @return bool True when at least one location remains.
	 */
	public static function can_ship( $organization_id ) {
		return LocationRepository::count_for_organization( $organization_id ) > 0;
	}

	/**
	 * The address fields a submission carried.
	 *
	 * A location is flat — the address fields sit beside the name rather than under an
	 * `address` key — in the snapshot, over REST and on every form. One shape per noun,
	 * whichever direction it is travelling in.
	 *
	 * @param array $values Whatever the caller passed.
	 * @return array Submitted address fields, keyed without a prefix.
	 */
	private static function submitted_address( array $values ) {
		$submitted = array();

		foreach ( Location::ADDRESS_FIELDS as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$submitted[ $field ] = $values[ $field ];
			}
		}

		return $submitted;
	}

	/**
	 * A field name with the shipping prefix taken off.
	 *
	 * `AddressFields::validate()` keys its errors by the prefixed name because that is what
	 * a rendered form calls the input. A location's own fields carry no prefix, so the
	 * errors are reported the way the record names them and each caller puts back whichever
	 * prefix its own surface uses.
	 *
	 * @param string $field The field name an error was keyed by.
	 * @return string The name without the prefix.
	 */
	private static function unprefixed( $field ) {
		$prefix = AddressFields::SHIPPING . '_';

		return 0 === strpos( $field, $prefix ) ? substr( $field, strlen( $prefix ) ) : $field;
	}
}
