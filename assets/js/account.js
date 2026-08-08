/**
 * My Account: confirm before anything destructive.
 *
 * Removing a member or deleting a location cannot be undone from the frontend, and
 * both buttons sit next to an ordinary "Edit" link. The message comes from the
 * element's own data attribute so it is written, and translated, in PHP.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-woap-confirm]' );

		if ( ! trigger ) {
			return;
		}

		var message = trigger.getAttribute( 'data-woap-confirm' );

		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	} );
}() );
