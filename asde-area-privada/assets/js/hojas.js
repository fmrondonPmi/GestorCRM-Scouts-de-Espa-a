/**
 * Hojas de la sección «Usuarios» (v0.25): Usuarios, Dar acceso y Registro de
 * emails son hojas de un mismo libro, como en una hoja de cálculo
 * (templates/usuarios/hojas.php).
 *
 * - Al pulsar una hoja se ve sin recargar la página. La primera vez se pide su
 *   página al servidor y de ella se toma la hoja; después se conserva tal como se
 *   dejó (filtros, búsqueda, página) y la dirección del navegador vuelve a ser la suya.
 * - Los enlaces y las búsquedas (formularios GET) de una hoja que llevan a una
 *   página de la sección se cargan en su hoja, también sin recargar.
 * - Atrás y adelante del navegador cambian de hoja.
 * Lo demás (guardar, la ventana emergente, las descargas) va como siempre. Sin
 * JavaScript, o si algo falla, cada hoja es un enlace a su página.
 */
( function () {
	'use strict';

	var libro = document.querySelector( '[data-asde-libro]' );
	if ( ! libro || ! window.fetch || ! window.DOMParser || ! window.URL || ! window.FormData || ! window.history || ! window.history.pushState ) {
		return;
	}
	var barra = libro.querySelector( '[data-asde-hojas]' );
	var primera = libro.querySelector( '[data-asde-hoja]' );
	if ( ! barra || ! primera ) {
		return;
	}
	var activa = primera.getAttribute( 'data-asde-hoja' );
	var direcciones = {}; // hoja => dirección en la que se dejó (con sus filtros)
	var titulos = {}; // hoja => título de la pestaña del navegador
	var ocupado = false;
	direcciones[ activa ] = window.location.href;
	titulos[ activa ] = document.title;

	var avisosHoja = document.createElement( 'div' );
	avisosHoja.className = 'asde-libro__avisos';
	avisosHoja.setAttribute( 'aria-live', 'polite' );
	barra.parentNode.insertBefore( avisosHoja, barra.nextSibling );

	function pestanas() {
		return Array.prototype.slice.call( barra.querySelectorAll( '[data-asde-hoja-ir]' ) );
	}

	function panel( hoja ) {
		return libro.querySelector( '[data-asde-hoja="' + hoja + '"]' );
	}

	/** ¿De qué hoja es esta dirección? (misma ruta y, con enlaces «?page_id=», misma página). */
	function hojaDe( url ) {
		var u;
		try {
			u = new window.URL( url, window.location.href );
		} catch ( e ) {
			return null;
		}
		if ( u.origin !== window.location.origin ) {
			return null;
		}
		var hoja = null;
		pestanas().forEach( function ( a ) {
			var p = new window.URL( a.href );
			if ( p.pathname === u.pathname && ( p.searchParams.get( 'page_id' ) || '' ) === ( u.searchParams.get( 'page_id' ) || '' ) ) {
				hoja = a.getAttribute( 'data-asde-hoja-ir' );
			}
		} );
		return hoja;
	}

	/** Enseña una hoja (ya en el libro) y marca su pestaña. */
	function mostrar( hoja ) {
		pestanas().forEach( function ( a ) {
			var si = a.getAttribute( 'data-asde-hoja-ir' ) === hoja;
			a.classList.toggle( 'is-active', si );
			if ( si ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
		Array.prototype.forEach.call( libro.querySelectorAll( '[data-asde-hoja]' ), function ( p ) {
			p.hidden = p.getAttribute( 'data-asde-hoja' ) !== hoja;
		} );
		if ( hoja !== activa ) {
			avisosHoja.innerHTML = '';
		}
		activa = hoja;
		if ( titulos[ hoja ] ) {
			document.title = titulos[ hoja ];
		}
	}

	/** Los avisos que trae la página pedida (p. ej. «Invitación reenviada»), encima de la hoja. */
	function avisos( doc ) {
		avisosHoja.innerHTML = '';
		Array.prototype.forEach.call( doc.querySelectorAll( '.asde-ap > .asde-notice' ), function ( n ) {
			avisosHoja.appendChild( document.importNode( n, true ) );
		} );
	}

	/** Lo que necesita una hoja recién puesta: sus listados, formularios y plegables. */
	function iniciar( hoja ) {
		if ( window.asdeUsuarios ) {
			window.asdeUsuarios.iniciar( hoja.querySelector( '[data-asde-usuarios]' ) );
		}
		if ( window.asdeAlta ) {
			window.asdeAlta.iniciar( hoja );
		}
		if ( window.asdeFicha ) {
			window.asdeFicha.iniciar( hoja );
		}
		document.dispatchEvent( new window.CustomEvent( 'asde:parcial' ) );
	}

	/** Pide la página de una hoja y pone (o cambia) su hoja en el libro. */
	function cargar( url, apilar ) {
		if ( ocupado ) {
			return;
		}
		ocupado = true;
		libro.setAttribute( 'aria-busy', 'true' );
		libro.classList.add( 'asde-libro--cargando' );
		window.fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
			return r.text().then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var final = r.url || url;
				var nueva = doc.querySelector( '[data-asde-libro] [data-asde-hoja]' );
				var hoja = nueva ? nueva.getAttribute( 'data-asde-hoja' ) : '';
				if ( ! r.ok || ! nueva || hojaDe( final ) !== hoja ) {
					throw new Error( 'sin hoja' );
				}
				nueva = document.importNode( nueva, true );
				var vieja = panel( hoja );
				if ( vieja ) {
					vieja.replaceWith( nueva );
				} else {
					libro.appendChild( nueva );
				}
				titulos[ hoja ] = doc.title || document.title;
				direcciones[ hoja ] = final;
				if ( apilar ) {
					window.history.pushState( { asdeHoja: hoja }, '', final );
				} else {
					window.history.replaceState( { asdeHoja: hoja }, '', final );
				}
				mostrar( hoja );
				avisos( doc );
				iniciar( nueva );
			} );
		} ).catch( function () {
			window.location.href = url; // Plan B: la página de siempre.
		} ).then( function () {
			ocupado = false;
			libro.removeAttribute( 'aria-busy' );
			libro.classList.remove( 'asde-libro--cargando' );
		} );
	}

	/** Pulsar una hoja: la ya abierta se enseña como se dejó; la otra se pide. */
	function abrir( hoja, url ) {
		if ( ocupado ) {
			return;
		}
		if ( hoja === activa ) {
			// La hoja que ya se ve no cambia (ni sus filtros), salvo si se está dentro de
			// algo suyo (un email del registro): entonces vuelve al principio de la hoja.
			var dentro = new window.URL( window.location.href ).searchParams;
			if ( dentro.has( 'ver' ) || dentro.has( 'socio' ) ) {
				cargar( url, true );
			}
			return;
		}
		direcciones[ activa ] = window.location.href;
		if ( panel( hoja ) ) {
			window.history.pushState( { asdeHoja: hoja }, '', direcciones[ hoja ] || url );
			mostrar( hoja );
			return;
		}
		cargar( url, true );
	}

	function normal( e ) {
		return 0 === e.button && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey && ! e.altKey && ! e.defaultPrevented;
	}

	// En el libro (no en document): así se actúa antes que el aviso de carga de area.js.
	libro.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href]' ) : null;
		if ( ! a || ! normal( e ) ) {
			return;
		}
		if ( a.hasAttribute( 'data-asde-hoja-ir' ) ) {
			e.preventDefault();
			abrir( a.getAttribute( 'data-asde-hoja-ir' ), a.href );
			return;
		}
		if ( a.target || a.hasAttribute( 'download' ) || a.hasAttribute( 'data-asde-modal' ) || a.hasAttribute( 'data-asde-recarga' ) || '#' === ( a.getAttribute( 'href' ) || '' ).charAt( 0 ) || /[?&]formato=/.test( a.search ) || ! hojaDe( a.href ) ) {
			return;
		}
		e.preventDefault();
		direcciones[ activa ] = window.location.href;
		cargar( a.href, true );
	} );

	libro.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( e.defaultPrevented || form.target || form.hasAttribute( 'data-asde-recarga' ) || 'get' !== ( form.getAttribute( 'method' ) || 'get' ).toLowerCase() ) {
			return;
		}
		var destino = new window.URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
		if ( ! hojaDe( destino.href ) ) {
			return;
		}
		e.preventDefault();
		var pagina = destino.searchParams.get( 'page_id' );
		var datos = new window.URLSearchParams( new window.FormData( form ) );
		if ( e.submitter && e.submitter.name ) {
			datos.append( e.submitter.name, e.submitter.value );
		}
		if ( pagina && ! datos.has( 'page_id' ) ) {
			datos.set( 'page_id', pagina ); // Con enlaces «?page_id=», el navegador lo perdería.
		}
		destino.search = datos.toString();
		direcciones[ activa ] = window.location.href;
		cargar( destino.href, true );
	} );

	// Atrás / adelante: la hoja de esa dirección (si se dejó en otra dirección, se vuelve a pedir).
	window.addEventListener( 'popstate', function () {
		var hoja = hojaDe( window.location.href );
		if ( ! hoja ) {
			return;
		}
		if ( panel( hoja ) && direcciones[ hoja ] === window.location.href ) {
			mostrar( hoja );
			return;
		}
		cargar( window.location.href, false );
	} );

	// Al cerrar la ventana emergente después de guardar (p. ej. dar acceso desde la
	// búsqueda): la hoja que se ve se actualiza sin recargar la página. La de Usuarios
	// ya lo hace sola (usuarios.js).
	document.addEventListener( 'asde:modal-cerrado', function ( e ) {
		if ( 'usuarios' === activa ) {
			return;
		}
		e.preventDefault();
		cargar( window.location.href, false );
	} );
} )();
