/**
 * Páginas del plugin en wp-admin (v0.31: antes, onsubmit/onclick en las plantillas; WordPress
 * pide no poner JavaScript en ellas). data-asde-confirmar="pregunta" en un formulario o en su
 * botón: se pregunta antes de enviarlo y, si se cancela, no se envía.
 */
( function () {
	'use strict';
	var ultimoBoton = null;
	document.addEventListener( 'click', function ( e ) {
		ultimoBoton = e.target.closest ? e.target.closest( 'button[type="submit"], input[type="submit"]' ) : null;
	}, true );
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		var boton = e.submitter || ( ultimoBoton && ultimoBoton.form === form ? ultimoBoton : null );
		var pregunta = boton && boton.hasAttribute( 'data-asde-confirmar' ) ? boton.getAttribute( 'data-asde-confirmar' ) : form.getAttribute( 'data-asde-confirmar' );
		ultimoBoton = null;
		if ( pregunta && ! window.confirm( pregunta ) ) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	}, true );
} )();
