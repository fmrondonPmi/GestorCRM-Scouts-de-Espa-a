/**
 * Gestión de accesos (v0.53, doc 56).
 *
 * - `window.asdeAccesos.puede()` / `depurar()` hacen en el navegador el mismo cálculo que `Accesos\Catalogo`
 *   (PHP): una acción exige antes uno de los permisos de «ver» de los que depende. El servidor vuelve a comprobarlo.
 * - Matriz: al marcar un permiso se marca lo que necesita; al desmarcar un «ver» se desmarcan las acciones que
 *   se apoyaban solo en él. Las casillas bloqueadas (el rol propio) no se tocan: si hiciera falta marcarlas,
 *   la casilla que se acaba de marcar vuelve a quedar desmarcada.
 * - Conceder: la fecha solo se enseña con «Hasta una fecha».
 */
( function () {
	'use strict';

	/** ¿Se cumple lo que pide este permiso con este conjunto? catalogo: permiso => [uno de estos] */
	function cumple( catalogo, tiene, permiso ) {
		var req = catalogo[ permiso ] || [];
		if ( ! req.length ) {
			return true;
		}
		return req.some( function ( r ) {
			return !! tiene[ r ];
		} );
	}

	/** Quita del conjunto lo que ya no cumple su requisito (hasta que no cambie nada). */
	function depurar( catalogo, tiene ) {
		var cambio;
		do {
			cambio = false;
			Object.keys( tiene ).forEach( function ( p ) {
				if ( tiene[ p ] && catalogo[ p ] && ! cumple( catalogo, tiene, p ) ) {
					tiene[ p ] = false;
					cambio = true;
				}
			} );
		} while ( cambio );
		return tiene;
	}

	/** can( estado, permiso ): el permiso está en el conjunto y cumple lo que pide. */
	function puede( catalogo, tiene, permiso ) {
		return !! tiene[ permiso ] && cumple( catalogo, tiene, permiso );
	}

	window.asdeAccesos = { cumple: cumple, depurar: depurar, puede: puede };

	function iniciarMatriz( form ) {
		var catalogo = {};
		try {
			catalogo = JSON.parse( form.getAttribute( 'data-asde-accesos' ) ) || {};
		} catch ( e ) {
			return;
		}
		function cajas( rol ) {
			return Array.prototype.slice.call( form.querySelectorAll( 'input[data-asde-acc-rol="' + rol + '"]' ) );
		}
		function estado( rol ) {
			var tiene = {};
			cajas( rol ).forEach( function ( c ) {
				tiene[ c.getAttribute( 'data-asde-acc-permiso' ) ] = c.checked;
			} );
			return tiene;
		}
		form.addEventListener( 'change', function ( ev ) {
			var caja = ev.target;
			if ( ! caja || 'checkbox' !== caja.type || ! caja.hasAttribute( 'data-asde-acc-rol' ) ) {
				return;
			}
			var rol = caja.getAttribute( 'data-asde-acc-rol' );
			var permiso = caja.getAttribute( 'data-asde-acc-permiso' );
			var lista = cajas( rol );
			var por = {};
			lista.forEach( function ( c ) {
				por[ c.getAttribute( 'data-asde-acc-permiso' ) ] = c;
			} );
			if ( caja.checked ) {
				// Marcar lo que necesita: el primero de su lista que se pueda marcar.
				var guardia = 0;
				while ( ! cumple( catalogo, estado( rol ), permiso ) && guardia++ < 6 ) {
					var req = ( catalogo[ permiso ] || [] ).filter( function ( r ) {
						return por[ r ] && ! por[ r ].disabled;
					} );
					if ( ! req.length ) {
						caja.checked = false;
						return;
					}
					por[ req[ 0 ] ].checked = true;
					permiso = req[ 0 ];
				}
				return;
			}
			var tiene = depurar( catalogo, estado( rol ) );
			lista.forEach( function ( c ) {
				var p = c.getAttribute( 'data-asde-acc-permiso' );
				if ( c.checked && ! tiene[ p ] ) {
					c.checked = false;
				}
			} );
		} );
	}

	function iniciarConceder( form ) {
		var tipo = form.querySelector( '[data-asde-acc-tipo]' );
		var fecha = form.querySelector( '[data-asde-acc-fecha]' );
		if ( ! tipo || ! fecha ) {
			return;
		}
		var campo = fecha.querySelector( 'input' );
		function actualizar() {
			var con = 'fecha' === tipo.value;
			fecha.hidden = ! con;
			if ( campo ) {
				campo.required = con;
				campo.disabled = ! con;
			}
		}
		tipo.addEventListener( 'change', actualizar );
		actualizar();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var matriz = document.querySelector( 'form[data-asde-accesos]' );
		if ( matriz ) {
			iniciarMatriz( matriz );
		}
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-asde-accesos-conceder]' ), iniciarConceder );
	} );
}() );
