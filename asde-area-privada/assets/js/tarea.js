/**
 * Monitor de la corrección de números en segundo plano.
 *
 * Pregunta al servidor cómo va cada 3 s (cada 15 s si la pestaña no está a la
 * vista) sin recargar ni bloquear la página: se puede seguir trabajando o
 * cambiar de página, y la tarea continúa en el servidor. Al consultar, el
 * servidor además reactiva la tarea si lleva rato parada.
 */
( function () {
	'use strict';

	var caja = document.querySelector( '[data-asde-tarea]' );
	if ( ! caja || ! window.fetch ) {
		return;
	}
	var ajax = caja.getAttribute( 'data-ajax' );
	var nonce = caja.getAttribute( 'data-nonce' );
	var estado = caja.getAttribute( 'data-estado' );
	var texto = caja.querySelector( '[data-campo="texto"]' );
	var barra = caja.querySelector( '[data-campo="barra"]' );
	var botones = {};
	caja.querySelectorAll( '[data-asde-tarea-accion]' ).forEach( function ( b ) {
		botones[ b.getAttribute( 'data-asde-tarea-accion' ) ] = b;
	} );
	var enPersonas = '1' === caja.getAttribute( 'data-en-personas' );
	var temporizador = null;
	var fallosSeguidos = 0;

	function pedir( accion ) {
		var datos = new FormData();
		datos.append( 'action', accion );
		datos.append( 'nonce', nonce );
		return fetch( ajax, { method: 'POST', body: datos, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function pintar( m ) {
		if ( ! m || 'ninguna' === m.estado ) {
			caja.hidden = true;
			parar();
			return;
		}
		var antes = estado;
		estado = m.estado;
		caja.className = 'asde-tarea asde-tarea--' + m.estado;
		caja.setAttribute( 'data-estado', m.estado );
		texto.textContent = m.texto;
		barra.value = m.avance;
		barra.textContent = m.avance + ' %';
		var activa = 'en_curso' === m.estado;
		botones.detener.hidden = ! activa;
		botones.cerrar.hidden = activa;
		// En Personas, al acabar: la lista de la página es de antes de corregir.
		botones.recargar.hidden = activa || ! enPersonas || 'en_curso' !== antes;
		if ( ! activa ) {
			parar();
		}
	}

	function sondear() {
		temporizador = null;
		pedir( 'asde_ap_tarea_estado' )
			.then( function ( r ) {
				fallosSeguidos = 0;
				if ( r && r.success ) {
					pintar( r.data );
				}
			} )
			.catch( function () {
				++fallosSeguidos; // Sin conexión un momento: se vuelve a intentar, más despacio.
			} )
			.then( programar );
	}

	function programar() {
		if ( 'en_curso' !== estado || temporizador ) {
			return;
		}
		var espera = document.hidden ? 15000 : 3000;
		temporizador = window.setTimeout( sondear, espera * Math.min( 5, 1 + fallosSeguidos ) );
	}

	function parar() {
		window.clearTimeout( temporizador );
		temporizador = null;
	}

	botones.detener.addEventListener( 'click', function () {
		if ( ! window.confirm( asdeT( 'Se detendrá la corrección. Lo ya corregido se queda corregido. ¿Detener?' ) ) ) {
			return;
		}
		botones.detener.disabled = true;
		pedir( 'asde_ap_tarea_detener' ).then( function ( r ) {
			botones.detener.disabled = false;
			if ( r && r.success ) {
				pintar( r.data );
			}
		} );
	} );
	botones.cerrar.addEventListener( 'click', function () {
		pedir( 'asde_ap_tarea_cerrar' ).then( function () {
			caja.hidden = true;
		} );
	} );
	botones.recargar.addEventListener( 'click', function () {
		window.location.reload();
	} );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && 'en_curso' === estado ) {
			parar();
			sondear(); // Al volver a la pestaña, se actualiza enseguida.
		}
	} );

	programar();
} )();
