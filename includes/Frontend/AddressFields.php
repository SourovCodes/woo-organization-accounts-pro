<?php
/**
 * Address fields, rendered and validated the way WooCommerce does.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The one place this plugin builds an address form.
 *
 * Every address the plugin collects — the organization's billing address, and each
 * location's shipping address — is defined, rendered and validated from WooCommerce's
 * own `WC()->countries->get_address_fields()`. Nothing here decides what an address
 * looks like.
 *
 * That matters more than it sounds. An address form written by hand is wrong in a
 * different way in every country: it asks a German customer for a state they do not
 * have, offers a Canadian a free-text province instead of the list their courier
 * expects, insists on a postcode in Ireland, calls a ZIP a postcode in Ohio, and
 * accepts "Californa" without blinking. WooCommerce already knows all of that, per
 * country, and keeps knowing it as countries change. Asking it is the only way these
 * forms stay consistent with the checkout the customer will see next.
 *
 * The prefixes are `billing_` and `shipping_` on purpose, and the wrapper classes are
 * WooCommerce's own: `country-select.js` looks for `#billing_state` / `#shipping_state`
 * inside `.woocommerce-billing-fields` / `.woocommerce-shipping-fields` by those exact
 * names. Rename either and the state field silently stops following the country.
 */
final class AddressFields {

	/**
	 * The billing address of an organization.
	 */
	const BILLING = 'billing';

	/**
	 * The shipping address of a location.
	 */
	const SHIPPING = 'shipping';

	/**
	 * WooCommerce's field definitions for one address, for one country.
	 *
	 * @param string $type    self::BILLING or self::SHIPPING.
	 * @param string $country Two-letter country code, or an empty string for the shop's base.
	 * @return array Field definitions keyed by prefixed field name, in display order.
	 */
	public static function fields( $type, $country = '' ) {
		$country = '' !== $country ? $country : WC()->countries->get_base_country();
		$fields  = WC()->countries->get_address_fields( $country, $type . '_' );
		$fields  = self::delivery_fields( $type, $fields );

		/**
		 * Filters the address fields this plugin collects.
		 *
		 * Applied on top of WooCommerce's own definitions, so a shop that has already
		 * customised its checkout fields sees the same customisation here.
		 *
		 * @since 0.2.0
		 *
		 * @param array  $fields  Field definitions, keyed by prefixed field name.
		 * @param string $type    'billing' or 'shipping'.
		 * @param string $country Two-letter country code.
		 */
		$fields = apply_filters( 'woo_org_accounts_address_fields', $fields, $type, $country );

		uasort( $fields, 'wc_checkout_fields_uasort_comparison' );

		return $fields;
	}

	/**
	 * The two corrections a delivery address needs that a personal one does not.
	 *
	 * Applied before the plugin's own filter, so a shop can still overrule either.
	 * Billing is left exactly as WooCommerce defines it.
	 *
	 * Both are about the same difference: WooCommerce's shipping fields describe an
	 * address somebody is typing at a checkout, and these describe one the shop has
	 * kept on file. A field that is fair to insist on in the first case is not
	 * necessarily fair to insist on in the second, because in the second it is applied
	 * retroactively to records that already exist.
	 *
	 * **The surname stops being required**, because a delivery address here belongs to
	 * a place at least as often as to a person: "Warehouse North" has no surname, and
	 * demanding one produces a made-up one. The first name is still required, so the
	 * parcel is always addressed to something — see location-form.php, which is where
	 * the person filling it in is told which of the two they are doing.
	 *
	 * **The phone stops being required.** WooCommerce marks it required when the shop
	 * sets `woocommerce_checkout_phone_field` to `required`, which is a rule about the
	 * person buying. `missing()` decides whether a location is complete enough to ship
	 * to at all and `Checkout\ShippingSelector` refuses one it says is not, so
	 * inheriting that rule would mean a shop turning the setting on made every location
	 * it had already saved undeliverable — a refusal at somebody's checkout, about a
	 * record only an admin can edit. The field is still offered, and still validated as
	 * a phone number when it is filled in.
	 *
	 * A shop that hides the phone entirely is left alone: WooCommerce removes the field
	 * before this sees it, and nothing here puts one back.
	 *
	 * @param string $type   self::BILLING or self::SHIPPING.
	 * @param array  $fields WooCommerce's definitions, keyed by prefixed field name.
	 * @return array The definitions, corrected for a delivery address.
	 */
	private static function delivery_fields( $type, array $fields ) {
		if ( self::SHIPPING !== $type ) {
			return $fields;
		}

		foreach ( array( 'shipping_last_name', 'shipping_phone' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$fields[ $key ]['required'] = false;
			}
		}

		return $fields;
	}

	/**
	 * The unprefixed names of the fields in an address.
	 *
	 * @param string $type    self::BILLING or self::SHIPPING.
	 * @param string $country Two-letter country code.
	 * @return string[] Field names without their prefix.
	 */
	public static function keys( $type, $country = '' ) {
		$keys   = array();
		$length = strlen( $type ) + 1;

		foreach ( array_keys( self::fields( $type, $country ) ) as $key ) {
			$keys[] = substr( $key, $length );
		}

		return $keys;
	}

	/**
	 * Render an address form.
	 *
	 * @param string $type    self::BILLING or self::SHIPPING.
	 * @param array  $values  Current values, keyed without the prefix.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type bool           $readonly Render every field readonly.
	 *     @type string[]       $exclude  Unprefixed field names to leave out.
	 *     @type \WP_Error|null $errors   Errors from a rejected submission, keyed by prefixed field name.
	 * }
	 * @return void
	 */
	public static function render( $type, array $values, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'readonly' => false,
				'exclude'  => array(),
				'errors'   => null,
			)
		);

		$country = isset( $values['country'] ) && '' !== $values['country']
			? $values['country']
			: WC()->countries->get_base_country();

		printf( '<div class="woocommerce-%s-fields woap-address-fields">', esc_attr( $type ) );

		foreach ( self::fields( $type, $country ) as $key => $field ) {
			$name = substr( $key, strlen( $type ) + 1 );

			if ( in_array( $name, $args['exclude'], true ) ) {
				continue;
			}

			if ( $args['readonly'] ) {
				$field['custom_attributes'] = array_merge(
					isset( $field['custom_attributes'] ) ? (array) $field['custom_attributes'] : array(),
					array( 'readonly' => 'readonly' )
				);
			}

			$field = self::mark_invalid( $key, $field, $args['errors'] );

			woocommerce_form_field( $key, $field, isset( $values[ $name ] ) ? $values[ $name ] : '' );
		}

		echo '</div>';
	}

	/**
	 * The required fields an address has left empty, by their labels.
	 *
	 * An address can be stored complete for one country and incomplete for another —
	 * a US address needs a state and a German one does not — so this is asked of the
	 * country the address is actually in. It is how a location saved before this
	 * plugin validated anything, or edited to a stricter country, is caught on a
	 * screen somebody can fix rather than at a customer's checkout.
	 *
	 * @param string $type   self::BILLING or self::SHIPPING.
	 * @param array  $values Values keyed without the prefix.
	 * @return string[] Labels of the required fields that are empty.
	 */
	public static function missing( $type, array $values ) {
		$country = isset( $values['country'] ) ? (string) $values['country'] : '';
		$missing = array();

		if ( '' === $country ) {
			return array( __( 'Country / Region', 'woo-organization-accounts-pro' ) );
		}

		foreach ( self::fields( $type, $country ) as $key => $field ) {
			$name = substr( $key, strlen( $type ) + 1 );

			if ( empty( $field['required'] ) ) {
				continue;
			}

			if ( '' === trim( (string) ( isset( $values[ $name ] ) ? $values[ $name ] : '' ) ) ) {
				$missing[] = isset( $field['label'] ) ? $field['label'] : $name;
			}
		}

		return $missing;
	}

	/**
	 * Flag a field the last submission was rejected over.
	 *
	 * A notice at the top of a fourteen-field form tells somebody that *something* is
	 * wrong; it does not tell them where. WooCommerce marks the offending row with
	 * `woocommerce-invalid`, which every WooCommerce theme already styles, and the
	 * reason goes underneath the input that caused it.
	 *
	 * @param string         $key    Prefixed field name.
	 * @param array          $field  Field definition.
	 * @param \WP_Error|null $errors Errors from the rejected submission.
	 * @return array The field definition, marked up if it was rejected.
	 */
	private static function mark_invalid( $key, array $field, $errors ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $field;
		}

		$message = $errors->get_error_message( $key );

		if ( '' === $message ) {
			return $field;
		}

		$field['class'] = array_merge(
			isset( $field['class'] ) ? (array) $field['class'] : array(),
			array( 'woocommerce-invalid', 'woocommerce-invalid-required-field' )
		);

		$field['description'] = trim(
			( isset( $field['description'] ) ? $field['description'] . ' ' : '' ) . $message
		);

		return $field;
	}

	/**
	 * Read an address out of a submission.
	 *
	 * The country is resolved first, because which other fields exist at all depends
	 * on it. Anything not in that country's field set is dropped rather than stored:
	 * a state posted for a country that has no states is not data, it is noise.
	 *
	 * @param string $type self::BILLING or self::SHIPPING.
	 * @return array Values keyed without the prefix.
	 */
	public static function posted( $type ) {
		$prefix  = $type . '_';
		$country = self::posted_value( $prefix . 'country' );
		$country = '' !== $country ? strtoupper( $country ) : WC()->countries->get_base_country();

		$values = array();

		foreach ( self::fields( $type, $country ) as $key => $field ) {
			$name = substr( $key, strlen( $prefix ) );

			if ( 'country' === $name ) {
				$values[ $name ] = $country;
				continue;
			}

			$raw = self::posted_value( $key );

			$values[ $name ] = ( isset( $field['type'] ) && 'email' === $field['type'] )
				? sanitize_email( $raw )
				: $raw;
		}

		return $values;
	}

	/**
	 * Check an address the way WooCommerce checks one at checkout.
	 *
	 * Deliberately the same rules in the same order as
	 * `WC_Checkout::validate_posted_data()`: a country that exists, a postcode
	 * formatted and validated for that country, a plausible phone, a real email, a
	 * state from that country's list, and every field the country marks required. An
	 * address accepted here is one the checkout will accept too — which is the whole
	 * point of collecting it in advance.
	 *
	 * Values are normalised in place, so the caller stores what WooCommerce would:
	 * postcodes formatted, states upper-cased to their key.
	 *
	 * @param string    $type   self::BILLING or self::SHIPPING.
	 * @param array     $values Values keyed without the prefix. Normalised in place.
	 * @param \WP_Error $errors Errors to add to.
	 * @return void
	 */
	public static function validate( $type, array &$values, \WP_Error $errors ) {
		$country = isset( $values['country'] ) ? (string) $values['country'] : '';

		if ( '' !== $country && ! WC()->countries->country_exists( $country ) ) {
			$errors->add(
				$type . '_country',
				sprintf(
					/* translators: %s: the two-letter country code that was submitted. */
					__( '"%s" is not a country we recognise.', 'woo-organization-accounts-pro' ),
					$country
				)
			);

			return;
		}

		foreach ( self::fields( $type, $country ) as $key => $field ) {
			$name = substr( $key, strlen( $type ) + 1 );

			if ( ! array_key_exists( $name, $values ) ) {
				continue;
			}

			$label  = isset( $field['label'] ) ? $field['label'] : $name;
			$format = array_filter( isset( $field['validate'] ) ? (array) $field['validate'] : array() );

			if ( in_array( 'postcode', $format, true ) ) {
				$values[ $name ] = wc_format_postcode( $values[ $name ], $country );

				if ( '' !== $values[ $name ] && ! \WC_Validation::is_postcode( $values[ $name ], $country ) ) {
					/* translators: %s: name of the field, in bold. */
					$errors->add( $key, self::invalid( __( '%s is not a valid postcode or ZIP.', 'woo-organization-accounts-pro' ), $label ) );
				}
			}

			if ( in_array( 'phone', $format, true ) ) {
				$values[ $name ] = wc_remove_non_displayable_chars( $values[ $name ] );

				if ( '' !== $values[ $name ] && ! \WC_Validation::is_phone( $values[ $name ] ) ) {
					/* translators: %s: name of the field, in bold. */
					$errors->add( $key, self::invalid( __( '%s is not a valid phone number.', 'woo-organization-accounts-pro' ), $label ) );
				}
			}

			if ( in_array( 'email', $format, true ) && '' !== $values[ $name ] && ! is_email( $values[ $name ] ) ) {
				/* translators: %s: name of the field, in bold. */
				$errors->add( $key, self::invalid( __( '%s is not a valid email address.', 'woo-organization-accounts-pro' ), $label ) );
			}

			if ( in_array( 'state', $format, true ) && '' !== $values[ $name ] ) {
				self::validate_state( $key, $label, $country, $values[ $name ], $errors );
			}

			if ( ! empty( $field['required'] ) && '' === trim( (string) $values[ $name ] ) ) {
				$errors->add(
					$key,
					sprintf(
						/* translators: %s: name of the field, in bold. */
						__( '%s is a required field.', 'woo-organization-accounts-pro' ),
						'<strong>' . esc_html( $label ) . '</strong>'
					)
				);
			}
		}
	}

	/**
	 * Check a state against the list its country actually has.
	 *
	 * A country with no state list accepts anything, which is why this is skipped
	 * rather than refused for those. Where there is a list, the full name is accepted
	 * as well as the code and normalised to the code — a customer typing "California"
	 * has not made a mistake.
	 *
	 * @param string    $key     Prefixed field name, used as the error code.
	 * @param string    $label   Field label.
	 * @param string    $country Two-letter country code.
	 * @param string    $state   Submitted state; replaced with its key when it matches.
	 * @param \WP_Error $errors  Errors to add to.
	 * @return void
	 */
	private static function validate_state( $key, $label, $country, &$state, \WP_Error $errors ) {
		$valid = WC()->countries->get_states( $country );

		if ( empty( $valid ) || ! is_array( $valid ) ) {
			return;
		}

		$by_name = array_map( 'wc_strtoupper', array_flip( array_map( 'wc_strtoupper', $valid ) ) );
		$state   = wc_strtoupper( $state );

		if ( isset( $by_name[ $state ] ) ) {
			$state = $by_name[ $state ];
		}

		if ( in_array( $state, $by_name, true ) ) {
			return;
		}

		$errors->add(
			$key,
			sprintf(
				/* translators: 1: name of the field, in bold. 2: comma-separated list of the values it accepts. */
				__( '%1$s is not valid. Please choose one of: %2$s', 'woo-organization-accounts-pro' ),
				'<strong>' . esc_html( $label ) . '</strong>',
				implode( ', ', $valid )
			)
		);
	}

	/**
	 * Build an "X is not valid" message with the field name emphasised.
	 *
	 * @param string $template Translated message with one %s placeholder.
	 * @param string $label    Field label.
	 * @return string Message.
	 */
	private static function invalid( $template, $label ) {
		return sprintf( $template, '<strong>' . esc_html( $label ) . '</strong>' );
	}

	/**
	 * Read one posted field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller verifies a nonce before reading a submission; this is only the reader.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Load the scripts that make the state field follow the country.
	 *
	 * These are WooCommerce's own, so the behaviour is the checkout's behaviour rather
	 * than an imitation of it. On the frontend they are already registered; in the
	 * admin they are not, so they are registered here against WooCommerce's own files.
	 * Both scripts return early when their parameters are missing, so the worst a
	 * future WooCommerce rename can do is leave the server-rendered control in place.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! wp_script_is( 'wc-country-select', 'registered' ) ) {
			wp_register_script(
				'wc-country-select',
				WC()->plugin_url() . '/assets/js/frontend/country-select.min.js',
				array( 'jquery', 'selectWoo' ),
				WC_VERSION,
				true
			);

			wp_localize_script( 'wc-country-select', 'wc_country_select_params', self::country_select_params() );
		}

		if ( ! wp_script_is( 'wc-address-i18n', 'registered' ) ) {
			wp_register_script(
				'wc-address-i18n',
				WC()->plugin_url() . '/assets/js/frontend/address-i18n.min.js',
				array( 'jquery', 'wc-country-select' ),
				WC_VERSION,
				true
			);

			wp_localize_script( 'wc-address-i18n', 'wc_address_i18n_params', self::address_i18n_params() );
		}

		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );

		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
	}

	/**
	 * The parameters `country-select.js` reads.
	 *
	 * @return array Script parameters.
	 */
	private static function country_select_params() {
		return array(
			'countries'              => wp_json_encode(
				array_merge(
					WC()->countries->get_allowed_country_states(),
					WC()->countries->get_shipping_country_states()
				),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			),
			'i18n_select_state_text' => esc_attr__( 'Select an option…', 'woo-organization-accounts-pro' ),
			'i18n_no_matches'        => esc_attr__( 'No matches found', 'woo-organization-accounts-pro' ),
			'i18n_searching'         => esc_attr__( 'Searching…', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * The parameters `address-i18n.js` reads.
	 *
	 * @return array Script parameters.
	 */
	private static function address_i18n_params() {
		return array(
			'locale'             => wp_json_encode( WC()->countries->get_country_locale() ),
			'locale_fields'      => wp_json_encode( WC()->countries->get_country_locale_field_selectors() ),
			'i18n_required_text' => esc_attr__( 'required', 'woo-organization-accounts-pro' ),
			'i18n_optional_text' => esc_attr__( 'optional', 'woo-organization-accounts-pro' ),
		);
	}
}
