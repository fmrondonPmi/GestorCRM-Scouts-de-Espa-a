/**
 * Tienda (v0.45, doc 48, T2): en los productos con varias tallas se ve una fila «cantidad + talla»
 * y el botón «Añadir otra talla» enseña las siguientes. Sin JavaScript se ven todas las filas.
 */
( function () {
	'use strict';

	function iniciar( bloque ) {
		var extras = Array.prototype.slice.call( bloque.querySelectorAll( '[data-asde-linea-extra]' ) );
		var boton = bloque.querySelector( '[data-asde-linea-mas]' );
		if ( ! boton || ! extras.length ) {
			return;
		}
		extras.forEach( function ( fila ) {
			fila.hidden = true;
		} );
		boton.hidden = false;
		boton.addEventListener( 'click', function () {
			var fila = extras.shift();
			if ( fila ) {
				fila.hidden = false;
				var campo = fila.querySelector( 'input' );
				if ( campo ) {
					campo.focus();
				}
			}
			if ( ! extras.length ) {
				boton.hidden = true;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-asde-tienda-lineas]' ), iniciar );
	} );
}() );
