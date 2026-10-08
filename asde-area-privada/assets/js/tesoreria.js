/**
 * Tesorería → ficha de una familia (v0.20; ventana con buscador desde la v0.20.1). Mejora progresiva.
 *
 * «Registrar pago» con un ingreso del banco: «Buscar en el banco…» abre la ventana
 * emergente con los ingresos por conciliar (primero los sugeridos para la familia) y
 * un buscador que filtra mientras se escribe: nombre del tutor o del socio, concepto,
 * importe o fecha. Al elegir uno se cierra la ventana y se rellenan la fecha, el
 * importe, la forma de pago (transferencia) y la referencia con los del banco, que
 * son los que se registran (el servidor no usa los escritos a mano en ese caso).
 * Sin JavaScript, la ventana es una página y «Elegir» vuelve a la ficha con el ingreso.
 */
( function () {
	'use strict';

	/** Como Conciliacion::normalizar(): minúsculas, sin acentos, letras y cifras. */
	function normalizar( texto ) {
		var t = String( texto || '' ).toLowerCase();
		if ( t.normalize ) {
			t = t.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}
		return t.replace( /[^a-z0-9]+/g, ' ' ).trim();
	}

	function normal( e ) {
		return 0 === e.button && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey && ! e.altKey && ! e.defaultPrevented;
	}

	// ---------------------------------------------------------------------
	// 1. El buscador de la ventana (y de su página)
	// ---------------------------------------------------------------------
	function filtrar( lista ) {
		var caja = lista.querySelector( '[data-asde-banco-q]' );
		var palabras = normalizar( caja ? caja.value : '' ).split( ' ' ).filter( function ( p ) {
			return '' !== p;
		} );
		var total = 0;
		Array.prototype.forEach.call( lista.querySelectorAll( '[data-asde-banco-grupo]' ), function ( grupo ) {
			var visibles = 0;
			Array.prototype.forEach.call( grupo.querySelectorAll( '[data-asde-banco-fila]' ), function ( fila ) {
				var texto = fila.getAttribute( 'data-texto' ) || '';
				var si = palabras.every( function ( p ) {
					return -1 !== texto.indexOf( p );
				} );
				fila.hidden = ! si;
				visibles += si ? 1 : 0;
			} );
			grupo.hidden = 0 === visibles;
			total += visibles;
		} );
		var vacio = lista.querySelector( '[data-asde-banco-vacio]' );
		if ( vacio ) {
			vacio.hidden = total > 0;
		}
	}

	document.addEventListener( 'input', function ( e ) {
		var lista = e.target.matches && e.target.matches( '[data-asde-banco-q]' ) ? e.target.closest( '[data-asde-banco-elegir]' ) : null;
		if ( lista ) {
			filtrar( lista );
		}
	} );

	// Buscar no pide nada al servidor: la lista ya está entera (y así no se sale de la ventana).
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		var lista = form.matches && form.matches( '[data-asde-banco-buscador]' ) ? form.closest( '[data-asde-banco-elegir]' ) : null;
		if ( lista ) {
			e.preventDefault();
			filtrar( lista );
		}
	}, true );

	// ---------------------------------------------------------------------
	// 2. Atajos (los nombres de la familia) y «Elegir»
	// ---------------------------------------------------------------------
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest || ! normal( e ) ) {
			return;
		}
		var atajo = e.target.closest( '[data-asde-banco-atajo]' );
		var lista = atajo ? atajo.closest( '[data-asde-banco-elegir]' ) : null;
		var caja = lista ? lista.querySelector( '[data-asde-banco-q]' ) : null;
		if ( caja ) {
			e.preventDefault();
			e.stopPropagation();
			caja.value = atajo.getAttribute( 'data-asde-banco-atajo' );
			filtrar( lista );
			caja.focus();
			return;
		}
		var usar = e.target.closest( '[data-asde-banco-usar]' );
		var dialogo = usar ? usar.closest( 'dialog' ) : null;
		// En su propia página (sin la ficha detrás) el enlace lleva a la ficha con el ingreso puesto.
		if ( dialogo && pago ) {
			e.preventDefault();
			e.stopPropagation();
			pago.elegir( usar );
			var cerrar = dialogo.querySelector( '.asde-modal__cerrar' );
			if ( cerrar ) {
				cerrar.click(); // Sin nada guardado: la ficha no se recarga.
			}
		}
	}, true );

	// ---------------------------------------------------------------------
	// 3. «Registrar pago» en la ficha de la familia. Desde la v0.25 la ficha se
	// actualiza sin recargar la página (parcial.js): al cambiar, se vuelve a preparar.
	// ---------------------------------------------------------------------
	var pago = null;

	function preparar() {
		var caja = document.querySelector( '[data-asde-pago-banco]' );
		pago = null;
		if ( ! caja ) {
			return;
		}
		var form = caja.closest( 'form' );
		var id = caja.querySelector( '[data-asde-pago-banco-id]' );
		var elegido = caja.querySelector( '[data-asde-pago-banco-elegido]' );
		var ninguno = caja.querySelector( '[data-asde-pago-banco-ninguno]' );
		var quitar = caja.querySelector( '[data-asde-pago-banco-quitar]' );
		var campos = {
			fecha: form.querySelector( '[name="fecha"]' ),
			importe: form.querySelector( '[name="importe"]' ),
			metodo: form.querySelector( '[name="metodo"]' ),
			referencia: form.querySelector( '[name="referencia"]' )
		};
		var metodoAMano = campos.metodo && ! campos.metodo.disabled ? campos.metodo.value : '';
		// Si la ficha ya viene con un ingreso elegido (desde su página, sin JavaScript),
		// «Quitar» sigue siendo un enlace: la ficha vuelve sin él.
		var enJs = false;

		/** Pone en el formulario el ingreso del banco elegido, o null para volver a escribirlo a mano. */
		function poner( m ) {
			var con = null !== m;
			id.value = con ? m.id : '';
			campos.fecha.value = con ? m.fecha : caja.getAttribute( 'data-fecha' );
			campos.importe.value = con ? m.importe : caja.getAttribute( 'data-importe' );
			if ( campos.referencia ) {
				campos.referencia.value = con && m.ref ? m.ref : caja.getAttribute( 'data-ref' );
			}
			if ( campos.metodo ) {
				if ( con && campos.metodo.querySelector( 'option[value="transferencia"]' ) ) {
					campos.metodo.value = 'transferencia';
				} else if ( ! con && metodoAMano ) {
					campos.metodo.value = metodoAMano;
				}
				campos.metodo.disabled = con; // Se registra como transferencia.
			}
			// Con un movimiento del banco, la fecha y el importe son los suyos: no se pueden cambiar.
			campos.fecha.readOnly = con;
			campos.importe.readOnly = con;
			elegido.textContent = con ? m.resumen : '';
			elegido.hidden = ! con;
			ninguno.hidden = con;
			quitar.hidden = ! con;
		}

		quitar.addEventListener( 'click', function ( e ) {
			if ( enJs && normal( e ) ) {
				e.preventDefault();
				e.stopPropagation();
				poner( null );
			}
		} );

		pago = {
			elegir: function ( a ) {
				enJs = true;
				poner( {
					id: a.getAttribute( 'data-asde-banco-usar' ),
					fecha: a.getAttribute( 'data-fecha' ),
					importe: a.getAttribute( 'data-importe' ),
					ref: a.getAttribute( 'data-ref' ),
					resumen: a.getAttribute( 'data-resumen' )
				} );
			}
		};
	}

	preparar();
	document.addEventListener( 'asde:parcial', preparar );
} )();
