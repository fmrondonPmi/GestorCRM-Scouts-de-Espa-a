/**
 * Tesorería → Banco (v0.20, doc 16). Mejora progresiva: sin JavaScript, el botón
 * «Marcar como revisados…» lleva a su página con los elegidos.
 *
 * - Casilla para marcar todos los pendientes de la página y cuántos hay marcados.
 * - «Marcar como revisados…» abre la ventana emergente con los elegidos.
 * - En la ventana, «El mismo tipo para todos» lo pone en las filas en las que encaja.
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
} )();
