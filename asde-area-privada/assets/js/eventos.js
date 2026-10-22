/**
 * Eventos (v0.52): al publicar un evento se abre una ventana donde se elige a quién avisar por email
 * (familias, scouters o todos). Sin JavaScript (o sin <dialog>), el mismo formulario va dentro de <noscript>.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var dialogo = document.querySelector( '[data-asde-publicar-dialogo]' );
		var abrir = document.querySelector( '[data-asde-publicar-abrir]' );
		if ( ! dialogo || ! abrir || 'function' !== typeof dialogo.showModal ) {
			return;
		}
		abrir.hidden = false;
		var cerrar = dialogo.querySelector( '[data-asde-cerrar]' );
		if ( cerrar ) {
			cerrar.hidden = false;
			cerrar.addEventListener( 'click', function () {
				dialogo.close();
			} );
		}
		abrir.addEventListener( 'click', function () {
			dialogo.showModal();
		} );
		dialogo.addEventListener( 'close', function () {
			abrir.focus();
		} );

		// «Todos» marca (o desmarca) familias y scouters; y se marca solo cuando están las dos.
		var todos = dialogo.querySelector( '[data-asde-aviso-todos]' );
		var partes = Array.prototype.slice.call( dialogo.querySelectorAll( '[data-asde-aviso-parte]' ) );
		if ( todos ) {
			todos.addEventListener( 'change', function () {
				partes.forEach( function ( p ) {
					p.checked = todos.checked;
				} );
			} );
			partes.forEach( function ( p ) {
				p.addEventListener( 'change', function () {
					todos.checked = partes.every( function ( x ) {
						return x.checked;
					} );
				} );
			} );
		}
	} );
}() );
