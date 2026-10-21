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
		var radios = form.querySelectorAll( 'input[name="forma"]' );
		if ( ! bloque || ! radios.length ) {
			return;
		}
		function actualizar() {
			var elegido = form.querySelector( 'input[name="forma"]:checked' );
			bloque.hidden = ! elegido || 'transferencia' !== elegido.value;
		}
		Array.prototype.forEach.call( radios, function ( r ) {
			r.addEventListener( 'change', actualizar );
		} );
		actualizar();
	} );
}() );
