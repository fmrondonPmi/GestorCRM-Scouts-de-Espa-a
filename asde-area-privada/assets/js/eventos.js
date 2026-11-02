/**
 * Eventos (v0.52): al publicar un evento se abre una ventana donde se elige a quién avisar por email
 * (familias, scouters o todos). Sin JavaScript (o sin <dialog>), el mismo formulario va dentro de <noscript>.
 */
( function () {
	'use strict';

	// Reenviar el aviso (v0.60): el mismo tipo de ventana; la persona solo se pide con «Una persona» y la casilla
	// «sin inscribir» solo con «Familias».
	function reenviar() {
		var dialogo = document.querySelector( '[data-asde-reenviar-dialogo]' );
		var abrir = document.querySelector( '[data-asde-reenviar-abrir]' );
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
		var persona = dialogo.querySelector( '[data-asde-reenviar-persona]' );
		var pendientes = dialogo.querySelector( '[data-asde-reenviar-pendientes]' );
		var ajustar = function () {
			var marcado = dialogo.querySelector( '[data-asde-reenviar-destino]:checked' );
			var valor = marcado ? marcado.value : '';
			if ( persona ) {
				persona.hidden = 'persona' !== valor;
			}
			if ( pendientes ) {
				pendientes.hidden = 'familias' !== valor;
			}
		};
		Array.prototype.slice.call( dialogo.querySelectorAll( '[data-asde-reenviar-destino]' ) ).forEach( function ( r ) {
			r.addEventListener( 'change', ajustar );
		} );
		ajustar();
	}

	document.addEventListener( 'DOMContentLoaded', reenviar );

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
