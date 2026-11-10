/*
 * WP NTK — личный кабинет наставника.
 * Формы работают и без этого скрипта: он лишь добавляет кнопку «Показать»
 * у паролей и не даёт отправить форму дважды двойным нажатием.
 */
( function () {
	'use strict';

	function each( list, callback ) {
		Array.prototype.forEach.call( list, callback );
	}

	function initPasswordToggles() {
		each( document.querySelectorAll( '.wp-ntk-toggle' ), function ( button ) {
			var input = document.getElementById( button.getAttribute( 'aria-controls' ) );
			if ( ! input ) {
				return;
			}

			button.hidden = false;
			button.addEventListener( 'click', function () {
				var show = 'password' === input.type;

				input.type = show ? 'text' : 'password';
				button.textContent = button.getAttribute( show ? 'data-hide' : 'data-show' );
				button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			} );
		} );
	}

	function initSubmitGuard() {
		var forms = document.querySelectorAll( '.wp-ntk-form' );

		each( forms, function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( form.hasAttribute( 'data-submitting' ) ) {
					event.preventDefault();
					return;
				}
				form.setAttribute( 'data-submitting', '' );
			} );
		} );

		// Страница могла быть показана заново кнопкой «Назад».
		window.addEventListener( 'pageshow', function () {
			each( forms, function ( form ) {
				form.removeAttribute( 'data-submitting' );
			} );
		} );
	}

	function init() {
		initPasswordToggles();
		initSubmitGuard();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
