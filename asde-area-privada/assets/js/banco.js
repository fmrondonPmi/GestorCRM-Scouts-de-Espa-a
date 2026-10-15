/**
 * Tesorería → Banco (v0.20, doc 16). Mejora progresiva: sin JavaScript, el botón
 * «Marcar como revisados…» lleva a su página con los elegidos.
 *
 * - Casilla para marcar todos los pendientes de la página y cuántos hay marcados.
 * - «Marcar como revisados…» abre la ventana emergente con los elegidos.
 * - En la ventana, «El mismo tipo para todos» lo pone en las filas en las que encaja.
 * - v0.34: el «+» junto al tipo de cada fila abre encima una ventana pequeña para crear un
 *   tipo sin perder lo elegido; el tipo nuevo entra en los desplegables en los que encaja y
 *   queda elegido en esa fila. Sin JavaScript, el «+» lleva a los tipos y vuelve.
 */
( function () {
	'use strict';

	var raiz = document.querySelector( '.asde-ap' ) || document.body;

	function marcadas() {
		return document.querySelectorAll( 'input[name="m[]"][form="asde-banco-lote"]:checked' );
	}

	function contar() {
		var aviso = document.querySelector( '[data-asde-banco-marcados]' );
		var n = marcadas().length;
		if ( aviso ) {
			aviso.textContent = 0 === n
				? asdeT( 'Marca en la lista los movimientos que no son cuotas.' )
				: ( 1 === n ? asdeT( '1 movimiento marcado' ) : asdeT( '%s movimientos marcados', String( n ) ) );
		}
		return n;
	}

	document.addEventListener( 'change', function ( e ) {
		var el = e.target;
		if ( el.matches && el.matches( '[data-asde-banco-todos]' ) ) {
			document.querySelectorAll( 'input[name="m[]"][form="asde-banco-lote"]' ).forEach( function ( c ) {
				c.checked = el.checked;
			} );
			contar();
		} else if ( el.matches && el.matches( 'input[name="m[]"][form="asde-banco-lote"]' ) ) {
			contar();
		} else if ( el.matches && el.matches( '[data-asde-tipo-todos]' ) && '' !== el.value ) {
			// En la ventana: el mismo tipo en las filas en las que existe (un tipo de gasto, solo en los gastos).
			var form = el.closest( 'form' );
			( form || document ).querySelectorAll( '[data-asde-tipo-fila]' ).forEach( function ( s ) {
				if ( s.querySelector( 'option[value="' + el.value + '"]' ) ) {
					s.value = el.value;
				}
			} );
		}
	} );

	// Después de actualizar el listado sin recargar, el recuento vuelve a empezar.
	document.addEventListener( 'asde:parcial', contar );

	raiz.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( 'asde-banco-lote' !== form.id || e.defaultPrevented ) {
			return;
		}
		e.preventDefault();
		if ( ! contar() ) {
			window.alert( asdeT( 'Marca en la lista los movimientos que no son cuotas.' ) );
			return;
		}
		var url = new window.URL( form.getAttribute( 'action' ), window.location.href );
		url.search = new window.URLSearchParams( new window.FormData( form ) ).toString();
		// La ventana emergente la abre area.js con cualquier enlace data-asde-modal.
		var a = document.createElement( 'a' );
		a.href = url.href;
		a.setAttribute( 'data-asde-modal', '' );
		a.hidden = true;
		raiz.appendChild( a );
		a.click();
		a.remove();
	} );

	// v0.34: «+» → ventana pequeña «Tipo nuevo». En la fase de captura de window, antes que
	// area.js (que llevaría el enlace a su página) y que el tema.
	function anadirTipo( caja, tipo, fila ) {
		var opcion = caja.querySelector( 'template[data-asde-banco-tipo-plantilla]' ).content.querySelector( 'select[name="naturaleza"] option[value="' + tipo.naturaleza + '"]' );
		var etiqueta = opcion ? opcion.textContent : tipo.naturaleza;
		caja.querySelectorAll( '[data-asde-tipo-fila]' ).forEach( function ( s ) {
			if ( 'ambos' !== tipo.naturaleza && s.getAttribute( 'data-asde-signo' ) !== tipo.naturaleza ) {
				return; // Un tipo de ingreso no sirve para un gasto (TiposMovimiento::encaja).
			}
			var grupo = s.querySelector( 'optgroup[data-naturaleza="' + tipo.naturaleza + '"]' );
			if ( ! grupo ) {
				grupo = document.createElement( 'optgroup' );
				grupo.label = etiqueta;
				grupo.setAttribute( 'data-naturaleza', tipo.naturaleza );
				s.appendChild( grupo );
			}
			grupo.appendChild( new window.Option( tipo.nombre, String( tipo.id ) ) );
		} );
		if ( fila.querySelector( 'option[value="' + tipo.id + '"]' ) ) {
			fila.value = String( tipo.id );
		}
		var todos = caja.querySelector( '[data-asde-tipo-todos]' );
		if ( todos ) {
			todos.add( new window.Option( tipo.nombre + ' (' + etiqueta.toLowerCase() + ')', String( tipo.id ) ) );
		}
	}

	window.addEventListener( 'click', function ( e ) {
		var mas = e.target.closest ? e.target.closest( '[data-asde-banco-tipo-nuevo]' ) : null;
		var caja = mas ? mas.closest( '.asde-banco-revisar' ) : null;
		var plantilla = caja ? caja.querySelector( 'template[data-asde-banco-tipo-plantilla]' ) : null;
		if ( ! plantilla || ! window.fetch || 'function' !== typeof document.createElement( 'dialog' ).showModal || 0 !== e.button || e.metaKey || e.ctrlKey || e.shiftKey ) {
			return; // Sin todo lo necesario, el enlace lleva a la página de los tipos.
		}
		e.preventDefault();
		e.stopPropagation();
		var fila = mas.parentNode.querySelector( '[data-asde-tipo-fila]' );
		var d = document.createElement( 'dialog' );
		d.className = 'asde-modal asde-modal--mini';
		d.setAttribute( 'aria-labelledby', 'asde-bt-mini-titulo' );
		d.appendChild( plantilla.content.cloneNode( true ) );
		// Fuera de la tarjeta (plegable: plegada, escondería la ventana) y de su formulario (no puede ir uno dentro de otro).
		caja.parentNode.appendChild( d );
		var form = d.querySelector( 'form' );
		var error = d.querySelector( '[data-asde-error]' );
		form.elements.naturaleza.value = fila ? fila.getAttribute( 'data-asde-signo' ) : 'ingreso';
		d.addEventListener( 'close', function () {
			d.remove();
			mas.focus();
		} );
		d.querySelector( '[data-asde-cerrar]' ).addEventListener( 'click', function () {
			d.close();
		} );
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			ev.stopPropagation(); // Que area.js no lo mande dentro de la ventana grande.
			var boton = form.querySelector( 'button[type="submit"]' );
			boton.disabled = true;
			error.hidden = true;
			window.fetch( form.getAttribute( 'action' ), { method: 'POST', body: new window.FormData( form ), credentials: 'same-origin', headers: { 'X-Asde-Json': '1' } } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( r ) {
					if ( r && r.success ) {
						anadirTipo( caja, r.data, fila );
						d.close();
						return;
					}
					// El motivo que da el servidor (p. ej. «Ya hay un tipo…»).
					error.textContent = r && r.data && r.data.mensaje ? r.data.mensaje : asdeT( 'No se ha podido crear el tipo. Vuelve a intentarlo.' );
					error.hidden = false;
					boton.disabled = false;
				} )
				.catch( function () {
					// Sin conexión o una respuesta que no es JSON (p. ej. la sesión ha caducado).
					error.textContent = asdeT( 'No se ha podido crear el tipo. Vuelve a intentarlo.' );
					error.hidden = false;
					boton.disabled = false;
				} );
		} );
		d.showModal();
		form.elements.nombre.focus();
	}, true );
} )();
