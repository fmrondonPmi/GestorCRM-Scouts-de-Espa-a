/**
 * Área privada: plegar o desplegar la cabecera morada del tema para ganar
 * espacio. Plegada, quedan fijos arriba el menú principal y, debajo, el menú
 * del área privada; el contenido se desplaza por debajo.
 *
 * El estado se guarda en el navegador (localStorage) y se aplica antes de
 * pintar la página desde un script en el <head> (Router::cabecera_guardada).
 */
( function () {
	'use strict';

	// Menús de pestañas en el móvil: una sola fila que se desplaza; la pestaña activa queda a la vista.
	function verActiva() {
		document.querySelectorAll( '.asde-nav__tabs, .asde-subnav__tabs' ).forEach( function ( lista ) {
			var activa = lista.querySelector( '[aria-current="page"]' );
			if ( activa && lista.scrollWidth > lista.clientWidth ) {
				var izq = activa.getBoundingClientRect().left - lista.getBoundingClientRect().left + lista.scrollLeft;
				lista.scrollLeft = Math.max( 0, izq - ( lista.clientWidth - activa.offsetWidth ) / 2 );
			}
		} );
	}
	verActiva();
	window.addEventListener( 'load', verActiva );

	var html = document.documentElement;
	var CLAVE = 'asde_cabecera_oculta';
	var boton = document.querySelector( '.asde-nav__cabecera' );
	if ( ! boton ) {
		return;
	}
	var texto = boton.querySelector( '.asde-nav__cabecera-texto' );
	var icono = boton.querySelector( '.asde-nav__cabecera-icono' );

	function alturaBarraAdmin() {
		var barra = document.getElementById( 'wpadminbar' );
		return barra && 'fixed' === window.getComputedStyle( barra ).position ? barra.offsetHeight : 0;
	}

	/** Alturas que usa el CSS para colocar la cabecera y el menú del área. */
	function medir() {
		var cabecera = document.getElementById( 'header' );
		var admin = alturaBarraAdmin();
		html.style.setProperty( '--asde-admin-h', admin + 'px' );
		if ( cabecera ) {
			html.style.setProperty( '--asde-cabecera-h', cabecera.offsetHeight + 'px' );
			html.style.setProperty( '--asde-nav-top', ( admin + cabecera.offsetHeight ) + 'px' );
		}
	}

	function pintarBoton( oculta ) {
		boton.setAttribute( 'aria-expanded', oculta ? 'false' : 'true' );
		texto.textContent = oculta ? 'Mostrar cabecera' : 'Ocultar cabecera';
		icono.textContent = oculta ? '▾' : '▴';
	}

	boton.addEventListener( 'click', function () {
		var oculta = ! html.classList.contains( 'asde-cabecera-oculta' );
		html.classList.toggle( 'asde-cabecera-oculta', oculta );
		try {
			window.localStorage.setItem( CLAVE, oculta ? '1' : '0' );
		} catch ( e ) {
			// Sin almacenamiento (modo privado): solo vale para esta página.
		}
		pintarBoton( oculta );
		medir();
		// Enfold recoloca su cabecera fija al cambiar el tamaño de la ventana.
		window.dispatchEvent( new Event( 'resize' ) );
	} );

	window.addEventListener( 'resize', function () {
		window.requestAnimationFrame( medir );
	} );
	window.addEventListener( 'load', medir );

	pintarBoton( html.classList.contains( 'asde-cabecera-oculta' ) );
	medir();
} )();
