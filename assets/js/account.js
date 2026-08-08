/**
 * My Account: the two bits of behaviour the organization screens need.
 *
 * Both are enhancements. Nothing here decides anything — the server reads the same
 * radio these scripts follow, so the screens work unchanged with JavaScript off.
 */
( function () {
	'use strict';

	/**
	 * Confirm before anything destructive.
	 *
	 * Removing a member or deleting a location cannot be undone from the frontend, and
	 * both buttons sit next to an ordinary "Edit" link. The message comes from the
	 * element's own data attribute so it is written, and translated, in PHP.
	 */
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

	/**
	 * Follow the radio at the top of a choice panel.
	 *
	 * Each panel holds one group of radios and one detail block per answer. The block
	 * belonging to the chosen answer is enabled; the rest are disabled, so a list left
	 * behind by a previous answer cannot be submitted by accident. A block marked
	 * `data-woap-choice-keep` stays on screen while disabled rather than disappearing,
	 * which is how the permission list can go on answering "what does this role allow?"
	 * while the role is the thing deciding.
	 *
	 * @param {Element} panel The choice panel.
	 * @return {void}
	 */
	function syncPanel( panel ) {
		var chosen = panel.querySelector( 'input[type="radio"]:checked' );
		var answer = chosen ? chosen.value : '';

		panel.querySelectorAll( '[data-woap-choice-detail]' ).forEach( function ( detail ) {
			var active = detail.getAttribute( 'data-woap-choice-detail' ) === answer;
			var keep = detail.hasAttribute( 'data-woap-choice-keep' );

			detail.hidden = ! active && ! keep;
			detail.classList.toggle( 'woap-choice__detail--disabled', ! active );

			detail.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.disabled = ! active;
			} );
		} );
	}

	/**
	 * Show what the chosen role allows, while the role is what decides.
	 *
	 * Every permission carries its own answer for each role, so changing the role
	 * re-ticks the list from the role that was just picked. Without it the list keeps
	 * showing the permissions of the role the member used to hold, which reads as a
	 * promise the server has no intention of keeping.
	 *
	 * @param {Element} form The member form.
	 * @return {void}
	 */
	function syncRoleDefaults( form ) {
		var role = form.querySelector( '.woap-role-select' );
		var panel = form.querySelector( '[data-woap-choice="permissions"]' );

		if ( ! role || ! panel ) {
			return;
		}

		var chosen = panel.querySelector( 'input[type="radio"]:checked' );

		if ( ! chosen || 'role' !== chosen.value ) {
			return;
		}

		panel.querySelectorAll( '[data-woap-default-' + role.value + ']' ).forEach( function ( input ) {
			input.checked = '1' === input.getAttribute( 'data-woap-default-' + role.value );
		} );
	}

	/**
	 * Bring every panel in line with what is currently chosen.
	 *
	 * @return {void}
	 */
	function sync() {
		document.querySelectorAll( '.woap-choice' ).forEach( syncPanel );

		document.querySelectorAll( '.woap-account__form' ).forEach( syncRoleDefaults );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( ! event.target.closest( '.woap-choice, .woap-account__form' ) ) {
			return;
		}

		sync();
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync );
	} else {
		sync();
	}
}() );
