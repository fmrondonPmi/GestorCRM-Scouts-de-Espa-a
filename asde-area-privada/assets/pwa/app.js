/*
 * La app instalada (docs/pwa/, sección 4). Solo actúa si la web se abre «como app»
 * (pantalla completa: display-mode standalone o navigator.standalone de iPhone):
 *  - marca <html> con la clase asde-app (el CSS de app.css oculta la cabecera y el pie del tema);
 *  - añade viewport-fit=cover para usar toda la pantalla (las zonas seguras las cuida app.css);
 *  - en pantallas táctiles, una barra inferior con «Atrás», «Inicio» y «Recargar» (sin la barra
 *    del navegador no hay otro modo de hacerlo, y en iPhone nunca hay botón de atrás);
 *  - «Salir» cierra la sesión sin salir del alcance de la app (si no, en el móvil se abriría
 *    el login de WordPress fuera de ella).
 *
 * Los textos y las direcciones los pone PHP en window.asdePwa.app. Se envuelve en una función
 * que recibe `w` (la ventana) para poder probarla con una ventana falsa.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.asdePwa && w.asdePwa.app;
	if ( ! cfg || ! cfg.etiquetas ) {
		return;
	}

	function enApp() {
		try {
			return ( w.matchMedia && w.matchMedia( '(display-mode: standalone)' ).matches ) || true === w.navigator.standalone;
		} catch ( e ) {
			return false;
		}
	}

	function tactil() {
		try {
			return !! ( w.matchMedia && w.matchMedia( '(pointer: coarse)' ).matches );
		} catch ( e ) {
			return false;
		}
	}

	if ( ! enApp() ) {
		return;
	}

	var T = cfg.etiquetas;
	d.documentElement.classList.add( 'asde-app' );

	/** Pantalla completa de verdad en móviles con muesca (el CSS deja los márgenes con env(safe-area-inset-*)). */
	function viewportCover() {
		var meta = d.querySelector( 'meta[name="viewport"]' );
		if ( meta && -1 === ( meta.getAttribute( 'content' ) || '' ).indexOf( 'viewport-fit' ) ) {
			meta.setAttribute( 'content', meta.getAttribute( 'content' ) + ',viewport-fit=cover' );
		}
	}

	/** «Salir» sin salir de la app: se manda al cierre de sesión del plugin, que vuelve al acceso. */
	function salida() {
		if ( ! cfg.salir ) {
			return;
		}
		d.querySelectorAll( 'a[href*="action=logout"]' ).forEach( function ( a ) {
			a.setAttribute( 'href', cfg.salir );
		} );
	}

	function crearBarra() {
		if ( ! tactil() || d.querySelector( '.asde-app-barra' ) || ! d.body ) {
			return;
		}
		var barra = d.createElement( 'nav' );
		barra.className = 'asde-app-barra';
		barra.setAttribute( 'aria-label', T.barra );

		var atras = d.createElement( 'button' );
		atras.type = 'button';
		atras.className = 'asde-app-barra__boton';
		atras.textContent = '‹ ' + T.atras;
		atras.disabled = ! ( w.history && w.history.length > 1 );
		atras.addEventListener( 'click', function () {
			w.history.back();
		} );

		var inicio = d.createElement( 'a' );
		inicio.className = 'asde-app-barra__boton';
		inicio.href = cfg.inicio || '/';
		inicio.textContent = T.inicio;

		var recargar = d.createElement( 'button' );
		recargar.type = 'button';
		recargar.className = 'asde-app-barra__boton';
		recargar.textContent = '↻ ' + T.recargar;
		recargar.addEventListener( 'click', function () {
			w.location.reload();
		} );

		barra.appendChild( atras );
		barra.appendChild( inicio );
		barra.appendChild( recargar );
		d.body.appendChild( barra );
		d.documentElement.classList.add( 'asde-app-con-barra' );
	}

	function iniciar() {
		viewportCover();
		salida();
		crearBarra();
	}

	if ( 'loading' === d.readyState ) {
		d.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
}( window, document ) );
