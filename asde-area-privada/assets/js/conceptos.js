/**
 * «Otros conceptos» del coste federativo: botón para añadir filas (sin
 * JavaScript hay siempre una fila vacía para añadir de uno en uno).
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-asde-conceptos]' ).forEach( function ( caja ) {
		var boton = caja.querySelector( '[data-asde-conceptos-anadir]' );
		var plantilla = caja.querySelector( '[data-asde-conceptos-plantilla]' );
		var filas = caja.querySelector( '[data-asde-conceptos-filas]' );
		if ( ! boton || ! plantilla || ! filas ) {
			return;
		}
		var siguiente = parseInt( caja.getAttribute( 'data-siguiente' ), 10 ) || 1;
		var maximo = 20;
		boton.hidden = false;
		boton.addEventListener( 'click', function () {
			if ( filas.querySelectorAll( '.asde-conceptos__fila' ).length >= maximo ) {
				boton.disabled = true;
				return;
			}
			var html = plantilla.innerHTML.replace( /__i__/g, String( siguiente++ ) );
			filas.insertAdjacentHTML( 'beforeend', html );
			var nueva = filas.lastElementChild.querySelector( 'input' );
			if ( nueva ) {
				nueva.focus();
			}
		} );
	} );
} )();
