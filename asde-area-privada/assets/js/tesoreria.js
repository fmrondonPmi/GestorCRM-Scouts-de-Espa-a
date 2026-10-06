/**
 * Tesorería → ficha de una familia (v0.20). Mejora progresiva.
 *
 * «Registrar pago» con un ingreso del banco: al elegirlo se rellenan la fecha, el
 * importe, la forma de pago (transferencia) y la referencia con los del banco, que
 * son los que se registran (el servidor no usa los escritos a mano en ese caso).
 */
( function () {
	'use strict';

	var banco = document.querySelector( '[data-asde-pago-banco]' );
	if ( ! banco ) {
		return;
	}
	var form = banco.form;
	var campos = {
		fecha: form.querySelector( '[name="fecha"]' ),
		importe: form.querySelector( '[name="importe"]' ),
		metodo: form.querySelector( '[name="metodo"]' ),
		referencia: form.querySelector( '[name="referencia"]' )
	};
	var antes = {};
	Object.keys( campos ).forEach( function ( k ) {
		antes[ k ] = campos[ k ] ? campos[ k ].value : '';
	} );

	banco.addEventListener( 'change', function () {
		var o = banco.options[ banco.selectedIndex ];
		var elegido = '' !== banco.value;
		if ( elegido ) {
			campos.fecha.value = o.getAttribute( 'data-fecha' );
			campos.importe.value = o.getAttribute( 'data-importe' );
			if ( campos.metodo && campos.metodo.querySelector( 'option[value="transferencia"]' ) ) {
				campos.metodo.value = 'transferencia';
			}
			if ( campos.referencia && o.getAttribute( 'data-ref' ) ) {
				campos.referencia.value = o.getAttribute( 'data-ref' );
			}
		} else {
			Object.keys( campos ).forEach( function ( k ) {
				if ( campos[ k ] ) {
					campos[ k ].value = antes[ k ];
				}
			} );
		}
		// Con un movimiento del banco, la fecha y el importe son los suyos: no se pueden cambiar.
		[ campos.fecha, campos.importe ].forEach( function ( c ) {
			c.readOnly = elegido;
		} );
		if ( campos.metodo ) {
			campos.metodo.disabled = elegido; // Se registra como transferencia.
		}
	} );
} )();
