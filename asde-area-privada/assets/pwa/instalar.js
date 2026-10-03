/*
 * Invitación a instalar la app (docs/pwa/, sección 3). Un aviso al pie de la pantalla de
 * acceso y del inicio del área:
 *  - Android / Chrome (y otros navegadores con el evento beforeinstallprompt): botón «Instalar».
 *  - iPhone / iPad: guía de «Compartir → Añadir a pantalla de inicio» (iOS no deja instalar por código).
 * No sale si la app ya está instalada ni si la persona lo cerró hace menos de 30 días.
 *
 * Los textos los pone PHP en window.asdePwa.textos (ya traducidos). No guarda nada más que
 * la fecha en que se cerró el aviso (localStorage) y no hace ninguna petición.
 *
 * Se envuelve en una función que recibe `w` (la ventana) para poder probarla con una ventana falsa.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.asdePwa;
	if ( ! cfg || ! cfg.textos ) {
		return;
	}
	var T         = cfg.textos;
	var CLAVE     = 'asde_pwa_instalar_cerrado';
	var DIAS      = 30;
	var evento    = null;
	var aviso     = null;

	function instalada() {
		try {
			return ( w.matchMedia && w.matchMedia( '(display-mode: standalone)' ).matches ) || true === w.navigator.standalone;
		} catch ( e ) {
			return false;
		}
	}

	function cerrada() {
		try {
			var cuando = parseInt( w.localStorage.getItem( CLAVE ), 10 );
			return cuando > 0 && ( Date.now() - cuando ) < DIAS * 86400000;
		} catch ( e ) {
			return false;
		}
	}

	function recordar() {
		try {
			w.localStorage.setItem( CLAVE, String( Date.now() ) );
		} catch ( e ) { /* sin almacenamiento: el aviso volverá a salir, no pasa nada */ }
	}

	function esIos() {
		var nav = w.navigator;
		return /iPad|iPhone|iPod/.test( nav.userAgent || '' ) || ( 'MacIntel' === nav.platform && nav.maxTouchPoints > 1 );
	}

	function quitar() {
		if ( aviso && aviso.parentNode ) {
			aviso.parentNode.removeChild( aviso );
		}
		aviso = null;
	}

	function boton( texto, principal, alPulsar ) {
		var b = d.createElement( 'button' );
		b.type = 'button';
		b.className = 'asde-pwa-btn' + ( principal ? ' asde-pwa-btn--principal' : '' );
		b.textContent = texto;
		b.addEventListener( 'click', alPulsar );
		return b;
	}

	/** Pinta el aviso. `pasos` es una lista opcional de textos (guía de iPhone). */
	function mostrar( titulo, texto, pasos, botones ) {
		if ( aviso || ! d.body ) {
			return;
		}
		aviso = d.createElement( 'section' );
		aviso.className = 'asde-pwa-aviso';
		aviso.setAttribute( 'role', 'region' );
		aviso.setAttribute( 'aria-label', titulo );

		var cuerpo = d.createElement( 'div' );
		cuerpo.className = 'asde-pwa-aviso__texto';
		var t = d.createElement( 'strong' );
		t.textContent = titulo;
		cuerpo.appendChild( t );
		if ( texto ) {
			var p = d.createElement( 'p' );
			p.textContent = texto;
			cuerpo.appendChild( p );
		}
		if ( pasos && pasos.length ) {
			var ol = d.createElement( 'ol' );
			pasos.forEach( function ( paso ) {
				var li = d.createElement( 'li' );
				li.textContent = paso;
				ol.appendChild( li );
			} );
			cuerpo.appendChild( ol );
		}

		var acciones = d.createElement( 'div' );
		acciones.className = 'asde-pwa-aviso__acciones';
		botones.forEach( function ( b ) { acciones.appendChild( b ); } );

		aviso.appendChild( cuerpo );
		aviso.appendChild( acciones );
		d.body.appendChild( aviso );
	}

	function cerrarAviso() {
		recordar();
		quitar();
	}

	function mostrarAndroid() {
		mostrar( T.titulo, T.texto, null, [
			boton( T.instalar, true, function () {
				if ( ! evento ) {
					return;
				}
				var e = evento;
				evento = null;
				quitar();
				try {
					e.prompt();
					if ( e.userChoice && e.userChoice.then ) {
						e.userChoice.then( function ( eleccion ) {
							if ( ! eleccion || 'accepted' !== eleccion.outcome ) {
								recordar();
							}
						} );
					}
				} catch ( err ) { /* el navegador no deja instalar ahora */ }
			} ),
			boton( T.ahora_no, false, cerrarAviso )
		] );
	}

	function mostrarIos() {
		mostrar( T.ios_titulo, '', [ T.ios_paso1, T.ios_paso2 ], [ boton( T.entendido, true, cerrarAviso ) ] );
	}

	function iniciar() {
		if ( instalada() || cerrada() ) {
			return;
		}
		if ( esIos() ) {
			mostrarIos();
			return;
		}
		w.addEventListener( 'beforeinstallprompt', function ( e ) {
			e.preventDefault(); // Sin esto Chrome saca su propia barra: el aviso lo damos nosotros.
			evento = e;
			mostrarAndroid();
		} );
		w.addEventListener( 'appinstalled', function () {
			evento = null;
			recordar();
			quitar();
		} );
	}

	if ( 'loading' === d.readyState ) {
		d.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
}( window, document ) );
