/**
 * wp-admin behaviour for the organization account screens.
 *
 * Progressive enhancement only, and deliberately so. Every setting these screens hold is
 * decided by a radio the server reads; this file disables the block belonging to the answer
 * that was not chosen, so a form cannot submit two answers at once. With JavaScript off the
 * blocks are simply both enabled, the radio still decides, and every screen still works.
 *
 * Plain JavaScript against no library, so the plugin builds with nothing but Composer and
 * CI's `node --check assets/js` stays meaningful.
 */
( function () {
	'use strict';

	/**
	 * Enable the detail block belonging to the chosen answer, and disable the rest.
	 *
	 * Disabled rather than hidden: a hidden checkbox still submits, so hiding alone would
	 * let a form send the locations somebody ticked before changing their mind back to
	 * "all of them" — and the two are stored identically, which is exactly the ambiguity
	 * the radio exists to remove.
	 *
	 * @param {HTMLElement} choice The block holding one radio group.
	 */
	function sync( choice ) {
		var chosen = choice.querySelector( 'input[type="radio"]:checked' );
		var value = chosen ? chosen.value : '';

		choice
			.querySelectorAll( '[data-woap-choice-detail]' )
			.forEach( function ( detail ) {
				var active = detail.getAttribute( 'data-woap-choice-detail' ) === value;

				detail.hidden = ! active;

				detail
					.querySelectorAll( 'input, select, textarea' )
					.forEach( function ( field ) {
						field.disabled = ! active;
					} );
			} );
	}

	function init() {
		document
			.querySelectorAll( '[data-woap-choice]' )
			.forEach( function ( choice ) {
				sync( choice );

				choice
					.querySelectorAll( 'input[type="radio"]' )
					.forEach( function ( radio ) {
						radio.addEventListener( 'change', function () {
							sync( choice );
						} );
					} );
			} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
