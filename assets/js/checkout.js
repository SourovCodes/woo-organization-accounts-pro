/**
 * Classic checkout: fill the shipping fields from the chosen organization location.
 *
 * Presentation only. The server resolves the location by ID and writes the address
 * onto the order itself, so what these fields end up containing changes nothing about
 * where the order goes — it only shows the customer where that is.
 */
( function ( $, settings ) {
	'use strict';

	if ( ! settings || ! settings.field || ! $ ) {
		return;
	}

	var ADDRESS_KEYS = [
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
		'phone'
	];

	/**
	 * Find a location in the localised list by its ID.
	 *
	 * @param {string} id Location ID as submitted by the select.
	 * @return {Object|null} The location, or null when the value is not one.
	 */
	function findLocation( id ) {
		var locations = settings.locations || [];
		var i;

		for ( i = 0; i < locations.length; i++ ) {
			if ( String( locations[ i ].id ) === String( id ) ) {
				return locations[ i ];
			}
		}

		return null;
	}

	/**
	 * Write a value into a shipping field, telling WooCommerce it changed.
	 *
	 * Country and state are enhanced selects; setting .val() alone leaves the visible
	 * control showing the previous choice and leaves the state field unrebuilt.
	 *
	 * @param {string} key   Field key without the shipping_ prefix.
	 * @param {string} value Value to set.
	 */
	function setField( key, value ) {
		var $field = $( '#shipping_' + key );

		if ( ! $field.length ) {
			return;
		}

		$field.val( value ).trigger( 'change' );
	}

	/**
	 * Show the shipping address fields, or hide them behind the chosen location.
	 *
	 * @param {boolean} editable Whether the customer is entering their own address.
	 */
	function setEditable( editable ) {
		var $fields = $( '.woocommerce-shipping-fields__field-wrapper' );

		$fields.toggleClass( 'woap-fields-locked', ! editable );
		$fields.find( 'input, select' ).each( function () {
			var $input = $( this );

			if ( $input.attr( 'name' ) === settings.field ) {
				return;
			}

			if ( editable ) {
				$input.removeAttr( 'readonly' );
			} else {
				$input.attr( 'readonly', 'readonly' );
			}
		} );
	}

	/**
	 * Apply whatever the select currently says.
	 */
	function apply() {
		var value = $( '#woap-location' ).val();
		var location;
		var i;

		if ( value === settings.custom ) {
			setEditable( true );
			return;
		}

		location = findLocation( value );

		if ( ! location ) {
			setEditable( true );
			return;
		}

		setEditable( false );

		for ( i = 0; i < ADDRESS_KEYS.length; i++ ) {
			setField( ADDRESS_KEYS[ i ], location[ ADDRESS_KEYS[ i ] ] || '' );
		}

		$( document.body ).trigger( 'update_checkout' );
	}

	$( document ).on( 'change', '#woap-location', apply );

	// The checkout form is re-rendered by WooCommerce after every totals refresh.
	$( document.body ).on( 'updated_checkout', function () {
		var value = $( '#woap-location' ).val();

		setEditable( value === settings.custom || ! findLocation( value ) );
	} );

	$( apply );
}( window.jQuery, window.woapCheckout ) );
