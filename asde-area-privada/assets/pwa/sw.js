/*
 * Service worker del área privada (docs/pwa/ARQUITECTURA.md, D6).
 *
 * Lo sirve PHP en /asde-sw.js (src/Pwa/ServiceWorker.php), que sustituye
 * __ASDE_CONFIG__ por la configuración de este WordPress.
 *
 * Qué hace:
 *  - guarda la página «sin conexión» (estática, sin datos de nadie);
 *  - guarda los ficheros estáticos del plugin (CSS y JS) para abrir antes;
 *  - si falla la red al abrir una página del área, enseña «sin conexión».
 *
 * Qué NO hace, nunca: guardar páginas del área, respuestas JSON, formularios ni
 * nada con datos personales (fichas, pagos, menores). Esas peticiones ni siquiera
 * pasan por aquí: van siempre a la red.
 */
'use strict';

const CONFIG  = __ASDE_CONFIG__;
const PREFIJO = 'asde-pwa-';
const CACHE   = PREFIJO + CONFIG.version;

self.addEventListener( 'install', function ( evento ) {
	evento.waitUntil(
		caches.open( CACHE )
			.then( function ( cache ) { return cache.addAll( CONFIG.precache ); } )
			.then( function () { return self.skipWaiting(); } )
	);
} );

self.addEventListener( 'activate', function ( evento ) {
	evento.waitUntil(
		caches.keys()
			.then( function ( claves ) {
				return Promise.all(
					claves
						.filter( function ( clave ) { return clave.indexOf( PREFIJO ) === 0 && clave !== CACHE; } )
						.map( function ( clave ) { return caches.delete( clave ); } )
				);
			} )
			.then( function () { return self.clients.claim(); } )
	);
} );

function esEstatico( url ) {
	return url.origin === self.location.origin && url.pathname.indexOf( CONFIG.estaticos ) === 0;
}

/* Estáticos: se sirve lo guardado y se refresca por detrás. */
function estatico( peticion ) {
	return caches.open( CACHE ).then( function ( cache ) {
		return cache.match( peticion ).then( function ( guardada ) {
			const red = fetch( peticion )
				.then( function ( respuesta ) {
					if ( respuesta && 200 === respuesta.status && 'basic' === respuesta.type ) {
						cache.put( peticion, respuesta.clone() );
					}
					return respuesta;
				} )
				.catch( function () { return null; } );
			if ( guardada ) {
				return guardada;
			}
			return red.then( function ( respuesta ) { return respuesta || Response.error(); } );
		} );
	} );
}

self.addEventListener( 'fetch', function ( evento ) {
	const peticion = evento.request;
	if ( 'GET' !== peticion.method ) {
		return; // Formularios y envíos: siempre a la red.
	}
	const url = new URL( peticion.url );
	if ( url.origin !== self.location.origin ) {
		return;
	}
	if ( 'navigate' === peticion.mode ) {
		// Páginas del área: siempre de la red, sin guardar nada. Solo si la red falla, «sin conexión».
		evento.respondWith(
			fetch( peticion ).catch( function () { return caches.match( CONFIG.offline ); } )
		);
		return;
	}
	if ( esEstatico( url ) ) {
		evento.respondWith( estatico( peticion ) );
	}
	// Todo lo demás (JSON, la ventana emergente, descargas…) no pasa por aquí.
} );

/*
 * Avisos push (docs/pwa/PUSH.md). El texto del aviso sale SIEMPRE de CONFIG.push (genérico: la pantalla de
 * bloqueo la puede ver cualquiera); el mensaje solo puede traer la página a la que llevar (`u`), y solo
 * si está dentro del alcance de la app. Chrome exige enseñar un aviso por cada push recibido.
 */
function destinoPush( ruta ) {
	const P = CONFIG.push;
	try {
		const url = new URL( ruta, self.location.origin );
		if ( url.origin === self.location.origin && 0 === url.href.indexOf( P.alcance ) ) {
			return url.href;
		}
	} catch ( e ) { /* ruta inválida: al inicio */ }
	return P.inicio;
}

self.addEventListener( 'push', function ( evento ) {
	let datos = {};
	try {
		datos = evento.data ? evento.data.json() : {};
	} catch ( e ) {
		datos = {};
	}
	const P = CONFIG.push;
	evento.waitUntil(
		self.registration.showNotification( P.titulo, {
			body: P.texto,
			icon: P.icono,
			badge: P.icono,
			tag: 'asde-novedad',
			renotify: true,
			data: { url: destinoPush( datos && datos.u ? String( datos.u ) : '' ) }
		} )
	);
} );

self.addEventListener( 'notificationclick', function ( evento ) {
	evento.notification.close();
	const P = CONFIG.push;
	const destino = ( evento.notification.data && evento.notification.data.url ) || P.inicio;
	evento.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( ventanas ) {
			for ( let i = 0; i < ventanas.length; i++ ) {
				if ( 0 === ventanas[ i ].url.indexOf( P.alcance ) && 'focus' in ventanas[ i ] ) {
					return ventanas[ i ].focus().then( function ( ventana ) {
						return ventana && 'navigate' in ventana ? ventana.navigate( destino ) : ventana;
					} );
				}
			}
			return self.clients.openWindow( destino );
		} )
	);
} );
