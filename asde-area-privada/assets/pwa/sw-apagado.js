/*
 * Service worker de «PWA apagada» (docs/pwa/). Si la PWA se desactiva (filtro
 * asde_ap_pwa_activa), /asde-sw.js sirve este fichero: quien ya tenía la app instalada
 * recibe esta versión, que borra las cachés de la PWA y se da de baja sola. A partir
 * de ahí la web funciona como una web normal en ese navegador.
 */
'use strict';

self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( evento ) {
	evento.waitUntil(
		caches.keys()
			.then( function ( claves ) {
				return Promise.all(
					claves
						.filter( function ( clave ) { return clave.indexOf( 'asde-pwa-' ) === 0; } )
						.map( function ( clave ) { return caches.delete( clave ); } )
				);
			} )
			.then( function () { return self.registration.unregister(); } )
	);
} );
