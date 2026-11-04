/**
 * Receta (v0.60.2): el botón «Nuevo ingrediente» abre una ventana, crea el ingrediente por fetch y lo deja elegido en la receta
 * que se está rellenando, sin recargar la página (no se pierde lo escrito). Sirve en la ventana «Nuevo plato» del menú de un
 * evento (apilada sobre ella) y en el formulario del recetario. Sin JavaScript, el botón no sale y queda el enlace a «Ingredientes».
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var dialogo = document.querySelector( '[data-asde-ing-dialogo]' );
		var botones = document.querySelectorAll( '[data-asde-ing-nuevo]' );
		if ( ! dialogo || ! botones.length || 'function' !== typeof dialogo.showModal || 'function' !== typeof window.fetch ) {
			return;
		}
		var form = dialogo.querySelector( '[data-asde-ing-form]' );
		var error = dialogo.querySelector( '[data-asde-ing-error]' );
		var enviar = form.querySelector( 'button[type="submit"]' );
		var origen = null; // El botón que abrió la ventana: en su formulario de receta se deja el ingrediente.
		var enfocar = true; // Al guardar, el foco va a la cantidad del ingrediente nuevo y no al botón.

		Array.prototype.forEach.call( botones, function ( b ) {
			b.hidden = false;
			b.addEventListener( 'click', function () {
				origen = b;
				enfocar = true;
				form.reset();
				error.hidden = true;
				dialogo.showModal();
				form.elements.nombre.focus();
			} );
		} );

		dialogo.querySelector( '[data-asde-ing-cerrar]' ).addEventListener( 'click', function () {
			dialogo.close();
		} );
		// Al cerrarse, el foco vuelve al botón (que puede estar dentro de otra ventana que sigue abierta).
		dialogo.addEventListener( 'close', function () {
			if ( origen && enfocar ) {
				origen.focus();
			}
		} );

		// El ingrediente nuevo entra en todos los desplegables de ingredientes de la página y se elige en la primera fila vacía
		// de la receta (o en una fila nueva).
		function incorporar( d ) {
			Array.prototype.forEach.call( document.querySelectorAll( 'select[name="ing[]"]' ), function ( s ) {
				var o = document.createElement( 'option' );
				o.value = String( d.id );
				o.textContent = d.nombre;
				o.setAttribute( 'data-alergenos', ( d.alergenos || [] ).join( '|' ) );
				s.appendChild( o );
			} );
			var receta = origen.closest( 'form' );
			var selects = receta.querySelectorAll( 'select[name="ing[]"]' );
			var destino = null;
			Array.prototype.forEach.call( selects, function ( s ) {
				if ( ! destino && '0' === s.value ) {
					destino = s;
				}
			} );
			if ( ! destino ) {
				var mas = receta.querySelector( '[data-asde-menu-mas]' );
				if ( mas ) {
					mas.click(); // La ventana «Nuevo plato» ya sabe añadir filas.
				} else {
					var ultima = selects[ selects.length - 1 ].closest( 'tr' );
					var copia = ultima.cloneNode( true );
					copia.querySelector( 'select[name="ing[]"]' ).selectedIndex = 0;
					copia.querySelector( 'input[name="cant[]"]' ).value = '';
					ultima.parentNode.appendChild( copia );
				}
				selects = receta.querySelectorAll( 'select[name="ing[]"]' );
				destino = selects[ selects.length - 1 ];
			}
			destino.value = String( d.id );
			destino.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			error.hidden = true;
			enviar.disabled = true;
			window.fetch( form.action, { method: 'POST', body: new FormData( form ), credentials: 'same-origin', headers: { Accept: 'application/json' } } )
				.then( function ( r ) {
					return r.json().catch( function () {
						return null;
					} );
				} )
				.then( function ( j ) {
					enviar.disabled = false;
					if ( j && j.success && j.data ) {
						incorporar( j.data );
						enfocar = false;
						dialogo.close();
						var cantidad = origen.closest( 'form' ).querySelector( 'select[name="ing[]"] option[value="' + j.data.id + '"]:checked' );
						var fila = cantidad ? cantidad.closest( 'select' ).closest( 'tr, [data-asde-menu-linea]' ) : null;
						var campo = fila ? fila.querySelector( 'input[name="cant[]"]' ) : null;
						if ( campo ) {
							campo.focus();
						}
						return;
					}
					error.textContent = j && j.data && j.data.mensaje ? j.data.mensaje : dialogo.getAttribute( 'data-asde-ing-fallo' );
					error.hidden = false;
				} )
				.catch( function () {
					enviar.disabled = false;
					error.textContent = dialogo.getAttribute( 'data-asde-ing-fallo' );
					error.hidden = false;
				} );
		} );
	} );
}() );
