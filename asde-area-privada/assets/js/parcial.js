/**
 * Zonas que se actualizan sin recargar la página (v0.20; primero, el listado del banco).
 *
 * Un elemento con data-asde-parcial="nombre" es una zona. Los enlaces (páginas,
 * orden) y los formularios (filtros, conciliar, revisado, deshacer…) que hay
 * dentro se piden con fetch y, de la página que contesta el servidor (la misma
 * de siempre), se toman las zonas con el mismo nombre y se cambian en el sitio.
 * Los avisos salen flotando abajo. La dirección del navegador se actualiza.
 *
 * Mejora progresiva: sin JavaScript, o si algo falla, todo funciona como antes,
 * recargando la página. Lo marcado con data-asde-recarga (p. ej. subir un
 * fichero) va siempre por el camino de siempre.
 */
( function () {
	'use strict';

	if ( ! window.fetch || ! window.DOMParser || ! window.FormData || ! document.querySelector( '[data-asde-parcial]' ) ) {
		return;
	}

	var ocupado = false;
	var flotantes = null;
	// Se escucha en el contenedor del área (no en document): así se actúa antes que el
	// aviso de carga de area.js (que escucha en document y respeta preventDefault) y
	// después de los confirm() y de la validación de los propios formularios.
	var raiz = document.querySelector( '[data-asde-parcial]' ).closest( '.asde-ap' ) || document.body;

	function zona( el ) {
		return el && el.closest ? el.closest( '[data-asde-parcial]' ) : null;
	}

	function avisar( nodos ) {
		if ( ! nodos.length ) {
			return;
		}
		if ( ! flotantes ) {
			flotantes = document.createElement( 'div' );
			flotantes.className = 'asde-ap asde-avisos-flotantes';
			document.body.appendChild( flotantes );
		}
		nodos.forEach( function ( n ) {
			var aviso = document.importNode( n, true );
			var cerrar = document.createElement( 'button' );
			cerrar.type = 'button';
			cerrar.className = 'asde-avisos-flotantes__cerrar';
			cerrar.setAttribute( 'aria-label', asdeT( 'Cerrar' ) );
			cerrar.textContent = '×';
			cerrar.addEventListener( 'click', function () {
				aviso.remove();
			} );
			aviso.appendChild( cerrar );
			flotantes.appendChild( aviso );
			// Los de éxito se van solos; los errores se quedan hasta que se cierran.
			if ( ! aviso.classList.contains( 'asde-notice--error' ) && ! aviso.classList.contains( 'asde-notice--warning' ) ) {
				window.setTimeout( function () {
					aviso.remove();
				}, 7000 );
			}
		} );
	}

	/** Pide la página y cambia las zonas. Si no puede, va a la página de la forma de siempre. */
	function pedir( url, opciones, origen, nuevaDireccion ) {
		if ( ocupado ) {
			return;
		}
		ocupado = true;
		var actual = zona( origen );
		if ( actual ) {
			actual.setAttribute( 'aria-busy', 'true' );
			actual.classList.add( 'asde-parcial--cargando' );
		}
		opciones = opciones || {};
		opciones.credentials = 'same-origin';
		window.fetch( url, opciones ).then( function ( r ) {
			return r.text().then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var cambiadas = 0;
				document.querySelectorAll( '[data-asde-parcial]' ).forEach( function ( vieja ) {
					var nueva = doc.querySelector( '[data-asde-parcial="' + vieja.getAttribute( 'data-asde-parcial' ) + '"]' );
					if ( nueva ) {
						vieja.replaceWith( document.importNode( nueva, true ) );
						++cambiadas;
					}
				} );
				if ( ! cambiadas || ! r.ok ) {
					throw new Error( 'sin zonas' );
				}
				avisar( Array.prototype.slice.call( doc.querySelectorAll( '.asde-ap .asde-notice' ) ).filter( function ( n ) {
					return ! zona( n );
				} ) );
				if ( nuevaDireccion && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', r.url || url );
				}
				if ( window.asdeCombobox ) {
					window.asdeCombobox.iniciar( document );
				}
				document.dispatchEvent( new window.CustomEvent( 'asde:parcial' ) );
			} );
		} ).catch( function () {
			// Plan B: la página completa (sin perder lo que se pedía).
			if ( opciones.method && 'POST' === opciones.method ) {
				window.location.reload();
			} else {
				window.location.href = url;
			}
		} ).then( function () {
			ocupado = false;
			var z = document.querySelector( '[aria-busy="true"][data-asde-parcial]' );
			if ( z ) {
				z.removeAttribute( 'aria-busy' );
				z.classList.remove( 'asde-parcial--cargando' );
			}
		} );
	}

	function normal( e ) {
		return 0 === e.button && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey && ! e.altKey && ! e.defaultPrevented;
	}

	raiz.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href]' ) : null;
		if ( ! a || ! zona( a ) || ! normal( e ) || a.target || a.hasAttribute( 'download' ) || a.hasAttribute( 'data-asde-modal' ) || a.hasAttribute( 'data-asde-recarga' ) || a.origin !== window.location.origin || a.pathname !== window.location.pathname ) {
			return;
		}
		e.preventDefault();
		var arriba = zona( a ).getBoundingClientRect().top < 0;
		pedir( a.href, {}, a, true );
		if ( arriba ) {
			zona( a ).scrollIntoView( { block: 'start' } );
		}
	} );

	// Al cerrar la ventana emergente después de guardar algo en ella (p. ej. marcar
	// movimientos como revisados), en vez de recargar la página se actualizan las zonas.
	document.addEventListener( 'asde:modal-cerrado', function ( e ) {
		var z = document.querySelector( '[data-asde-parcial]' );
		if ( z ) {
			e.preventDefault();
			pedir( window.location.href, {}, z, false );
		}
	} );

	raiz.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( e.defaultPrevented || ! zona( form ) || form.hasAttribute( 'data-asde-recarga' ) || form.target || 'multipart/form-data' === form.enctype ) {
			return;
		}
		var accion = form.getAttribute( 'action' ) || window.location.href;
		var destino = new window.URL( accion, window.location.href );
		if ( destino.origin !== window.location.origin || destino.pathname !== window.location.pathname ) {
			return;
		}
		e.preventDefault();
		var datos = new window.FormData( form );
		if ( e.submitter && e.submitter.name ) {
			datos.append( e.submitter.name, e.submitter.value );
		}
		if ( 'post' === ( form.getAttribute( 'method' ) || 'get' ).toLowerCase() ) {
			pedir( destino.href, { method: 'POST', body: datos }, form, false );
			return;
		}
		destino.search = new window.URLSearchParams( datos ).toString();
		pedir( destino.href, {}, form, true );
	} );
} )();
