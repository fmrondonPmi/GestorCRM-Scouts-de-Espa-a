/**
 * Reintegros (v0.47, doc 53): el campo del IBAN solo se enseña si se elige cobrar por transferencia.
 * Sin JavaScript se ve siempre (y el servidor solo lo exige por transferencia).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '[data-asde-reintegro]' );
		if ( ! form ) {
			return;
		}
		var bloque = form.querySelector( '[data-asde-iban]' );
		var campo = form.querySelector( '[name="forma"]' );
		if ( ! bloque || ! campo ) {
			return;
		}
		function actualizar() {
			bloque.hidden = 'transferencia' !== campo.value;
		}
		campo.addEventListener( 'change', actualizar );
		actualizar();
	} );
}() );
