/*
 * Tarjeta «Avisos en este móvil» del inicio del área (docs/pwa/PUSH.md, paso 7a): activar o desactivar los
 * avisos push en ESTE móvil y elegir qué categorías llegan. Los textos, las categorías, la clave pública
 * VAPID y el nonce los pone PHP en window.asdePwa.push. El permiso del navegador se pide solo cuando la
 * persona pulsa «Activar avisos» (en el iPhone, además, solo con la app instalada).
 *
 * Se envuelve en una función que recibe `w` (la ventana) para poder probarla con una ventana falsa.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.asdePwa && w.asdePwa.push;
	if ( ! cfg || ! cfg.textos ) {
		return;
	}
	var T = cfg.textos;

	function soportado() {
		return 'serviceWorker' in w.navigator && 'PushManager' in w && 'Notification' in w;
	}

	function enApp() {
		try {
			return ( w.matchMedia && w.matchMedia( '(display-mode: standalone)' ).matches ) || true === w.navigator.standalone;
		} catch ( e ) {
			return false;
		}
	}

	function esIos() {
		var nav = w.navigator;
		return /iPad|iPhone|iPod/.test( nav.userAgent || '' ) || ( 'MacIntel' === nav.platform && nav.maxTouchPoints > 1 );
	}

	/** Clave pública VAPID (base64url) a los bytes que pide pushManager.subscribe(). */
	function aBytes( b64url ) {
		var relleno = '='.repeat( ( 4 - ( b64url.length % 4 ) ) % 4 );
		var bin = w.atob( ( b64url + relleno ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		var out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			out[ i ] = bin.charCodeAt( i );
		}
		return out;
	}

	function llamar( datos ) {
		var f = new w.FormData();
		f.append( 'action', 'asde_ap_push' );
		f.append( 'nonce', cfg.nonce );
		Object.keys( datos ).forEach( function ( k ) {
			[].concat( datos[ k ] ).forEach( function ( v ) {
				f.append( Array.isArray( datos[ k ] ) ? k + '[]' : k, v );
			} );
		} );
		return w.fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: f } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( 'push' );
			}
			return j.data;
		} );
	}

	var tarjeta, estado, boton, cats;
	var suscripcion = null;

	function el( tag, clase, texto ) {
		var e = d.createElement( tag );
		if ( clase ) {
			e.className = clase;
		}
		if ( texto ) {
			e.textContent = texto;
		}
		return e;
	}

	function crear() {
		var cont = d.querySelector( '.asde-ap' );
		if ( ! cont || d.querySelector( '.asde-push' ) ) {
			return false;
		}
		tarjeta = el( 'section', 'asde-card asde-push' );
		tarjeta.setAttribute( 'aria-labelledby', 'asde-push-titulo' );
		var h = el( 'h2', '', T.titulo );
		h.id = 'asde-push-titulo';
		tarjeta.appendChild( h );
		tarjeta.appendChild( el( 'p', 'asde-push__texto', T.texto ) );
		estado = el( 'p', 'asde-push__estado' );
		estado.setAttribute( 'role', 'status' );
		tarjeta.appendChild( estado );
		var p = el( 'p' );
		boton = el( 'button', 'button asde-push__boton' );
		boton.type = 'button';
		p.appendChild( boton );
		tarjeta.appendChild( p );

		cats = el( 'fieldset', 'asde-push__categorias' );
		cats.appendChild( el( 'legend', '', T.categorias ) );
		Object.keys( cfg.categorias ).forEach( function ( clave ) {
			var etiqueta = el( 'label' );
			etiqueta.style.display = 'block';
			var caja = d.createElement( 'input' );
			caja.type = 'checkbox';
			caja.setAttribute( 'data-cat', clave );
			caja.checked = !! cfg.categorias[ clave ].activa;
			caja.addEventListener( 'change', guardarCategorias );
			etiqueta.appendChild( caja );
			etiqueta.appendChild( d.createTextNode( ' ' + cfg.categorias[ clave ].etiqueta ) );
			cats.appendChild( etiqueta );
		} );
		tarjeta.appendChild( cats );
		cont.appendChild( tarjeta );
		return true;
	}

	function pintar( texto, mostrarBoton, activo ) {
		estado.textContent = texto;
		boton.hidden = ! mostrarBoton;
		boton.disabled = false;
		boton.textContent = activo ? T.desactivar : T.activar;
		cats.hidden = ! activo;
	}

	function guardarCategorias() {
		var apagadas = [];
		cats.querySelectorAll( 'input[data-cat]' ).forEach( function ( c ) {
			if ( ! c.checked ) {
				apagadas.push( c.getAttribute( 'data-cat' ) );
			}
		} );
		llamar( { op: 'categorias', desactivadas: apagadas } ).catch( function () {
			estado.textContent = T.error;
		} );
	}

	function activar( reg ) {
		boton.disabled = true;
		return w.Notification.requestPermission().then( function ( permiso ) {
			if ( 'granted' !== permiso ) {
				pintar( 'denied' === permiso ? T.bloqueado : T.desactivados, 'denied' !== permiso, false );
				return null;
			}
			return reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: aBytes( cfg.vapid ) } ).then( function ( sub ) {
				var j = sub.toJSON();
				return llamar( { op: 'suscribir', endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth } ).then( function () {
					suscripcion = sub;
					pintar( T.activados, true, true );
				}, function ( e ) {
					sub.unsubscribe();
					throw e;
				} );
			} );
		} ).catch( function () {
			pintar( T.error, true, false );
		} );
	}

	function desactivar() {
		boton.disabled = true;
		var sub = suscripcion;
		var endpoint = sub ? sub.endpoint : '';
		return ( sub ? sub.unsubscribe() : Promise.resolve() ).then( function () {
			suscripcion = null;
			return endpoint ? llamar( { op: 'baja', endpoint: endpoint } ) : null;
		} ).then( function () {
			pintar( T.desactivados, true, false );
		} ).catch( function () {
			pintar( T.error, true, !! suscripcion );
		} );
	}

	function iniciar() {
		if ( ! crear() ) {
			return;
		}
		if ( ! soportado() ) {
			pintar( T.no_soporta, false, false );
			return;
		}
		if ( esIos() && ! enApp() ) {
			pintar( T.ios, false, false );
			return;
		}
		if ( 'denied' === w.Notification.permission ) {
			pintar( T.bloqueado, false, false );
			return;
		}
		pintar( T.desactivados, false, false ); // Hasta saber si ya hay una suscripción.
		w.navigator.serviceWorker.ready.then( function ( reg ) {
			boton.addEventListener( 'click', function () {
				return suscripcion ? desactivar() : activar( reg );
			} );
			return reg.pushManager.getSubscription().then( function ( sub ) {
				suscripcion = sub;
				pintar( sub ? T.activados : T.desactivados, true, !! sub );
			} );
		} ).catch( function () {
			pintar( T.no_soporta, false, false );
		} );
	}

	if ( 'loading' === d.readyState ) {
		d.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
}( window, document ) );
