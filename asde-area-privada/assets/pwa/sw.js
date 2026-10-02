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
