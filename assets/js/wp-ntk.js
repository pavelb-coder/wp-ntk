/*
 * WP NTK — личный кабинет наставника.
 * Формы работают и без этого скрипта: он лишь добавляет кнопку «Показать»
 * у паролей, проверку расширений файлов и защиту от двойной отправки.
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

	function initProjectTimers() {
		each( document.querySelectorAll( '.wp-ntk-countdown' ), function ( element ) {
			var serverNow = Number( element.getAttribute( 'data-now' ) );
			var startedAt = Date.now();
			function update() {
				var seconds = Math.max( 0, Number( element.getAttribute( 'data-end' ) ) - serverNow - Math.floor( ( Date.now() - startedAt ) / 1000 ) );
				if ( ! seconds ) { element.textContent = 'Срок завершился; обновите страницу.'; return; }
				var days = Math.floor( seconds / 86400 ); seconds %= 86400;
				var hours = Math.floor( seconds / 3600 ); seconds %= 3600;
				var minutes = Math.floor( seconds / 60 );
				element.textContent = 'До окончания: ' + days + ' д. ' + hours + ' ч. ' + minutes + ' мин. ' + ( seconds % 60 ) + ' сек.';
			}
			update(); window.setInterval( update, 1000 );
		} );
	}

	// Быстрая проверка расширения; окончательный формат проверяется сервером.
	function initProjectFiles() {
		each( document.querySelectorAll( '.wp-ntk-project-file' ), function ( input ) {
			var output = document.getElementById( input.getAttribute( 'aria-describedby' ) );
			function validate() {
				var extensions = ( input.getAttribute( 'accept' ) || '' ).toLowerCase().split( ',' );
				var name = input.files && input.files.length ? input.files[0].name.toLowerCase() : '';
				var suffix = name.lastIndexOf( '.' ) < 0 ? '' : name.slice( name.lastIndexOf( '.' ) );
				var message = name && extensions.indexOf( suffix ) === -1 ? 'Недопустимый формат файла. Разрешены: ' + extensions.join( ', ' ) + '.' : '';
				input.setCustomValidity( message );
				if ( output ) { output.textContent = message; }
				if ( message ) { input.setAttribute( 'aria-invalid', 'true' ); } else { input.removeAttribute( 'aria-invalid' ); }
			}
			input.addEventListener( 'change', validate );
		} );
	}

	function init() {
		initProjectFiles();
		initPasswordToggles();
		initSubmitGuard();
		initProjectTimers();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
