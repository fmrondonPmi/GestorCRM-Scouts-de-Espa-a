/*
 * Registra el service worker del área privada (docs/pwa/). La dirección del service
 * worker y el alcance los pone PHP en window.asdePwa (src/Pwa/Pwa.php).
 */
( function () {
	'use strict';
	var cfg = window.asdePwa;
	if ( ! cfg || ! cfg.sw || ! ( 'serviceWorker' in navigator ) ) {
		return;
	}
	window.addEventListener( 'load', function () {
		navigator.serviceWorker
			.register( cfg.sw, { scope: cfg.alcance, updateViaCache: 'none' } )
			.catch( function () { /* sin service worker la web funciona igual */ } );
	} );
}() );
