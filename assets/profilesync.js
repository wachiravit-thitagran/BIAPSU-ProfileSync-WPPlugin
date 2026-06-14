/**
 * BIA PSU ProfileSync — choice page behaviour.
 *
 * Disables buttons on submit to prevent double posting and give feedback while
 * the server-to-server profile fetch runs.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.biapsu-form' );

		forms.forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				// Disable every action button so the user can't pick twice.
				document.querySelectorAll( '.biapsu-btn' ).forEach( function ( btn ) {
					btn.setAttribute( 'disabled', 'disabled' );
				} );

				var clicked = form.querySelector( 'button[type="submit"]' );
				if ( clicked && 'sync' === ( form.querySelector( 'input[name="decision"]' ) || {} ).value ) {
					clicked.textContent = clicked.getAttribute( 'data-busy' ) || 'กำลังซิงค์ข้อมูล…';
				}
			} );
		} );
	} );
}() );
