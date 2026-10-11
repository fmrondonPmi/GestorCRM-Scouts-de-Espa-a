/**
 * Botón «Imprimir» de las páginas propias para imprimir (v0.31; antes, onclick en la plantilla):
 * cualquier elemento con data-asde-imprimir-pagina abre el cuadro de impresión del navegador.
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '[data-asde-imprimir-pagina]' ) ) {
			e.preventDefault();
			window.print();
		}
	} );
} )();
