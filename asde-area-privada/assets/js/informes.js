/**
 * Editor de informes: marcar/desmarcar todas las columnas de un grupo.
 * (El formulario funciona igual sin JS.)
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-asde-informe] [data-asde-marcar]' ).forEach( function ( boton ) {
		boton.addEventListener( 'click', function () {
			var grupo = boton.closest( 'fieldset' );
			var marcar = 'todas' === boton.getAttribute( 'data-asde-marcar' );
			grupo.querySelectorAll( 'input[type="checkbox"][name="cols[]"]' ).forEach( function ( c ) {
				c.checked = marcar;
			} );
		} );
	} );
} )();
