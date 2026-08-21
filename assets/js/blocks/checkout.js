/**
 * Cart and Checkout blocks: organization billing notice and location selector.
 *
 * Plain JavaScript against the globals WooCommerce already enqueues — no JSX and no
 * bundler, so the plugin needs nothing but Composer to build.
 *
 * Nothing here enforces anything. The server discards the submitted billing address
 * and resolves the shipping address from the location ID, so this file only shows the
 * customer what is going to happen and sends the chosen location along with the order.
 * Every branch is guarded: if a WooCommerce global this relies on is missing or has
 * moved, the block checkout is left exactly as it was rather than broken.
 */
( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.plugins || ! wp.data || ! wc || ! wc.blocksCheckout ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};

	var NAMESPACE = 'woo-organization-accounts-pro';
	var CUSTOM = 'custom';

	var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages ||
		wc.blocksCheckout.ExperimentalOrderMeta;

	if ( ! Slot ) {
		return;
	}

	/**
	 * The organization data the Store API attached to the cart.
	 *
	 * @return {Object|null} Extension data, or null when it is not there.
	 */
	function organizationData() {
		var store = wp.data.select( 'wc/store/cart' );
		var cart;

		if ( ! store || typeof store.getCartData !== 'function' ) {
			return null;
		}

		cart = store.getCartData();

		if ( ! cart || ! cart.extensions || ! cart.extensions[ NAMESPACE ] ) {
			return null;
		}

		return cart.extensions[ NAMESPACE ];
	}

	/**
	 * Tell the checkout which location was chosen.
	 *
	 * @param {string} locationId Location ID, or the one-off address marker.
	 */
	function publishSelection( locationId ) {
		var checkout = wp.data.dispatch( 'wc/store/checkout' );

		if ( checkout && typeof checkout.setExtensionData === 'function' ) {
			checkout.setExtensionData( NAMESPACE, 'location_id', String( locationId ) );
		}
	}

	/**
	 * Copy a location's address into the shipping address the blocks display.
	 *
	 * @param {Object} location The chosen location.
	 */
	function applyShippingAddress( location ) {
		var cart = wp.data.dispatch( 'wc/store/cart' );

		if ( ! cart || typeof cart.updateCustomerData !== 'function' ) {
			return;
		}

		cart.updateCustomerData( {
			shipping_address: {
				first_name: location.first_name || '',
				last_name: location.last_name || '',
				company: location.company || '',
				address_1: location.address_1 || '',
				address_2: location.address_2 || '',
				city: location.city || '',
				state: location.state || '',
				postcode: location.postcode || '',
				country: location.country || '',
				phone: location.phone || ''
			}
		} );
	}

	/**
	 * The location selector and the billing notice.
	 *
	 * @return {Object|null} Element tree, or null when there is nothing to show.
	 */
	function OrganizationPanel() {
		var data = organizationData();
		var initial = '';
		var state;
		var selected;
		var setSelected;

		if ( data && data.locations && data.locations.length ) {
			initial = String( data.locations[ 0 ].id );
		} else if ( data && data.allow_custom_shipping ) {
			initial = CUSTOM;
		}

		state = useState( initial );
		selected = state[ 0 ];
		setSelected = state[ 1 ];

		useEffect( function () {
			if ( ! selected ) {
				return;
			}

			publishSelection( selected );

			if ( selected === CUSTOM || ! data ) {
				return;
			}

			data.locations.forEach( function ( location ) {
				if ( String( location.id ) === selected ) {
					applyShippingAddress( location );
				}
			} );
			// The address only has to follow the selection, so nothing else belongs here.
		}, [ selected ] );

		if ( ! data || ! data.has_organization ) {
			return null;
		}

		var options = data.locations.map( function ( location ) {
			return el( 'option', { key: location.id, value: String( location.id ) }, location.name );
		} );

		if ( data.allow_custom_shipping ) {
			options.push(
				el( 'option', { key: CUSTOM, value: CUSTOM }, data.custom_label || __( 'A different address', 'woo-organization-accounts-pro' ) )
			);
		}

		return el(
			'div',
			{ className: 'woap-blocks-panel' },
			data.billing_notice
				? el( 'p', { className: 'woap-blocks-panel__notice' }, data.billing_notice )
				: null,
			options.length
				? el(
					'p',
					{ className: 'woap-blocks-panel__field' },
					el( 'label', { htmlFor: 'woap-blocks-location' }, data.location_label ),
					el(
						'select',
						{
							id: 'woap-blocks-location',
							value: selected,
							onChange: function ( event ) {
								setSelected( event.target.value );
							}
						},
						options
					)
				)
				: null
		);
	}

	registerPlugin( 'woap-organization-checkout', {
		render: function () {
			return el( Slot, null, el( OrganizationPanel, null ) );
		},
		scope: 'woocommerce-checkout'
	} );

	/**
	 * The link that downloads the product data for everything in the cart.
	 *
	 * The Cart block only. One script is registered for both blocks, so without this
	 * test the button would also appear at the checkout, where the customer is paying
	 * rather than collecting data — and where the same file is one page away on the
	 * order they are about to place.
	 *
	 * @return {Object|null} Element tree, or null when there is nothing to offer.
	 */
	function DatasheetLink() {
		var data = organizationData();

		if ( ! data || ! data.datasheet_url ) {
			return null;
		}

		if ( ! document.querySelector( '.wp-block-woocommerce-cart' ) ) {
			return null;
		}

		return el(
			'p',
			{ className: 'woap-blocks-datasheet' },
			el(
				'a',
				{
					href: data.datasheet_url,
					className: 'wc-block-components-button wp-element-button outlined'
				},
				data.datasheet_label || __( 'Download datasheet', 'woo-organization-accounts-pro' )
			)
		);
	}

	if ( wc.blocksCheckout.ExperimentalOrderMeta ) {
		registerPlugin( 'woap-cart-datasheet', {
			render: function () {
				return el(
					wc.blocksCheckout.ExperimentalOrderMeta,
					null,
					el( DatasheetLink, null )
				);
			},
			scope: 'woocommerce-checkout'
		} );
	}
}( window.wp, window.wc ) );
