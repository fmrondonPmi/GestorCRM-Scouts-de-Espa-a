/**
 * Área privada (mejora progresiva: sin JavaScript los enlaces abren la página).
 *
 * 1. Ventana emergente (v0.10): los enlaces con data-asde-modal (fichas, dar
 *    acceso, solicitudes) se abren sobre la página en la que se está. El
 *    servidor devuelve solo su contenido (cabecera X-Asde-Modal). Los
 *    formularios de dentro se envían sin salir de la ventana; al cerrarla, si
 *    se ha guardado algo, se recarga la página de detrás para verlo.
 * 2. Botones «Copiar» (data-asde-copiar="id del elemento con el texto").
 */
( function () {
	'use strict';

	// ---------------------------------------------------------------------
	// 2. Copiar
	// ---------------------------------------------------------------------
	function seleccionar( el ) {
		var rango = document.createRange();
		rango.selectNodeContents( el );
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( rango );
	}
	document.addEventListener( 'click', function ( e ) {
		var boton = e.target.closest ? e.target.closest( '[data-asde-copiar]' ) : null;
		if ( ! boton ) {
			return;
		}
		var el = document.getElementById( boton.getAttribute( 'data-asde-copiar' ) );
		if ( ! el ) {
			return;
		}
		var texto = boton.textContent;
		var hecho = function () {
			boton.textContent = asdeT( 'Copiado' );
			window.setTimeout( function () {
				boton.textContent = texto;
			}, 2000 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( el.textContent.trim() ).then( hecho, function () {
				seleccionar( el );
			} );
		} else {
			seleccionar( el );
		}
	} );

	// ---------------------------------------------------------------------
	// 3. Aviso de carga (v0.12.3): al pulsar un enlace o enviar un formulario del área
	// aparece una barra arriba y el cursor pasa a «cargando», así no parece que la
	// página se haya quedado colgada mientras el servidor trabaja.
	// ---------------------------------------------------------------------
	var barra = null;

	function cargando( si ) {
		if ( si && ! barra ) {
			barra = document.createElement( 'div' );
			barra.className = 'asde-progreso';
			barra.setAttribute( 'role', 'progressbar' );
			barra.setAttribute( 'aria-label', asdeT( 'Cargando la página' ) );
			document.body.appendChild( barra );
		}
		document.documentElement.classList.toggle( 'asde-cargando', si );
		if ( barra ) {
			barra.hidden = ! si;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '.asde-ap a[href]' ) : null;
		if ( ! a || e.defaultPrevented || 0 !== e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		var destino = a.getAttribute( 'href' ) || '';
		if ( a.target || a.hasAttribute( 'download' ) || a.hasAttribute( 'data-asde-modal' ) || a.hasAttribute( 'data-asde-copiar' ) || '#' === destino.charAt( 0 ) || /^(mailto|tel|javascript):/i.test( destino ) || a.origin !== window.location.origin ) {
			return;
		}
		if ( /[?&]formato=csv/.test( a.search ) ) {
			return; // Descarga: la página no cambia.
		}
		cargando( true );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		// Los formularios que descargan un fichero (data-asde-sin-carga) no cambian de página: no hay nada que esperar.
		if ( ! e.defaultPrevented && form.closest && form.closest( '.asde-ap' ) && ! form.target && ! form.hasAttribute( 'data-asde-sin-carga' ) ) {
			cargando( true );
		}
	} );

	// Al volver con «atrás» el navegador puede enseñar la página tal como se dejó.
	window.addEventListener( 'pageshow', function () {
		cargando( false );
	} );

	// ---------------------------------------------------------------------
	// 4. Cuadros plegables que se recuerdan (v0.20): <details data-asde-recordar="clave">
	// se queda abierto o cerrado como lo dejó la persona, en este navegador.
	// ---------------------------------------------------------------------
	function recordar( raiz ) {
		Array.prototype.forEach.call( ( raiz || document ).querySelectorAll( 'details[data-asde-recordar]:not([data-asde-recordado])' ), function ( d ) {
			var clave = 'asde_plegado_' + d.getAttribute( 'data-asde-recordar' );
			d.setAttribute( 'data-asde-recordado', '' );
			try {
				var guardado = window.localStorage.getItem( clave );
				if ( null !== guardado ) {
					d.open = '1' === guardado;
				}
			} catch ( e ) {}
			d.addEventListener( 'toggle', function () {
				try {
					window.localStorage.setItem( clave, d.open ? '1' : '0' );
				} catch ( e ) {}
			} );
		} );
	}
	recordar( document );
	// Lo que llega al actualizar una zona sin recargar (parcial.js, v0.25) también se recuerda.
	document.addEventListener( 'asde:parcial', function () {
		recordar( document );
	} );

	// ---------------------------------------------------------------------
	// 1. Ventana emergente
	// ---------------------------------------------------------------------
	if ( 'function' !== typeof window.HTMLDialogElement || ! window.fetch || ! window.FormData ) {
		return; // Navegador antiguo: los enlaces abren la página.
	}

	var dialogo = null;
	var cuerpo = null;
	var titulo = null;
	var abrirPagina = null;
	var actual = '';
	var cambiado = false;

	function crear() {
		dialogo = document.createElement( 'dialog' );
		dialogo.className = 'asde-modal';
		dialogo.setAttribute( 'aria-labelledby', 'asde-modal-titulo' );
		dialogo.innerHTML = '<div class="asde-modal__barra">'
			+ '<span class="asde-modal__titulo" id="asde-modal-titulo"></span>'
			+ '<a class="asde-modal__pagina" href="#">' + asdeT( 'Abrir en su página' ) + '</a>'
			+ '<button type="button" class="asde-modal__cerrar" aria-label="' + asdeT( 'Cerrar' ) + '">×</button>'
			+ '</div><div class="asde-modal__cuerpo" tabindex="-1"></div>';
		// Junto al contenido del área (no al final de <body>): así hereda los colores y
		// la tipografía del tema. Con showModal() se dibuja encima de todo igualmente.
		var area = document.querySelector( '.asde-ap' );
		( area && area.parentNode ? area.parentNode : document.body ).appendChild( dialogo );
		cuerpo = dialogo.querySelector( '.asde-modal__cuerpo' );
		titulo = dialogo.querySelector( '.asde-modal__titulo' );
		abrirPagina = dialogo.querySelector( '.asde-modal__pagina' );
		dialogo.querySelector( '.asde-modal__cerrar' ).addEventListener( 'click', cerrar );
		dialogo.addEventListener( 'cancel', function ( e ) {
			e.preventDefault(); // Esc: se cierra igual, pero pasando por cerrar().
			cerrar();
		} );
		dialogo.addEventListener( 'click', function ( e ) {
			if ( e.target === dialogo ) {
				cerrar(); // Clic fuera de la ventana.
			}
		} );
		cuerpo.addEventListener( 'submit', enviarDentro );
	}

	function cerrar() {
		dialogo.close();
		cuerpo.innerHTML = '';
		if ( cambiado ) {
			cambiado = false;
			// Se ha guardado algo: la página de detrás lo muestra. Las páginas que saben
			// refrescarse solas (el listado de usuarios) cancelan el evento y evitan la recarga.
			var aviso = new window.CustomEvent( 'asde:modal-cerrado', { cancelable: true } );
			if ( document.dispatchEvent( aviso ) ) {
				window.location.reload();
			}
		}
	}

	function pintar( url, html ) {
		actual = url;
		cuerpo.innerHTML = html;
		cuerpo.removeAttribute( 'aria-busy' );
		abrirPagina.href = url;
		var h = cuerpo.querySelector( 'h2, h3' );
		titulo.textContent = h ? h.textContent.trim() : '';
		if ( window.asdeFicha ) {
			window.asdeFicha.iniciar( cuerpo );
		}
		if ( window.asdeAlta ) {
			window.asdeAlta.iniciar( cuerpo );
		}
		cuerpo.scrollTop = 0;
		cuerpo.focus( { preventScroll: true } );
	}

	function cargar( url, opciones ) {
		opciones = opciones || {};
		opciones.credentials = 'same-origin';
		opciones.headers = { 'X-Asde-Modal': '1' };
		cuerpo.setAttribute( 'aria-busy', 'true' );
		return window.fetch( url, opciones ).then( function ( r ) {
			return r.text().then( function ( html ) {
				// Sin sesión, sin permiso o un error: mejor la página completa.
				if ( -1 === html.indexOf( 'asde-ap--modal' ) ) {
					window.location.href = r.url || url;
					return;
				}
				pintar( r.url || url, html );
			} );
		} ).catch( function () {
			window.location.href = url;
		} );
	}

	function abrir( url ) {
		if ( ! dialogo ) {
			crear();
		}
		cuerpo.innerHTML = '<p class="asde-muted asde-modal__cargando">' + asdeT( 'Cargando…' ) + '</p>';
		titulo.textContent = '';
		if ( ! dialogo.open ) {
			dialogo.showModal();
		}
		cargar( url );
	}

	function normal( e ) {
		return 0 === e.button && ! e.metaKey && ! e.ctrlKey && ! e.shiftKey && ! e.altKey && ! e.defaultPrevented;
	}

	// En la fase de captura, antes que nadie: el tema (p. ej. las transiciones de
	// página de Enfold) no debe quedarse con el clic y navegar. Dentro de la
	// ventana, los enlaces a fichas, altas o solicitudes se quedan en ella; los
	// demás abren su página.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[data-asde-modal]' ) : null;
		if ( ! a || ! normal( e ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		if ( dialogo && dialogo.open && dialogo.contains( a ) ) {
			cargar( a.href );
		} else {
			abrir( a.href );
		}
	}, true );

	function enviarDentro( e ) {
		var form = e.target;
		// Un confirm() cancelado deja el envío anulado: no se hace nada.
		if ( e.defaultPrevented || 'post' !== ( form.getAttribute( 'method' ) || '' ).toLowerCase() ) {
			return;
		}
		e.preventDefault();
		var datos = new window.FormData( form );
		if ( e.submitter && e.submitter.name ) {
			datos.append( e.submitter.name, e.submitter.value );
		}
		// Un formulario que solo consulta (data-asde-solo-consulta, p. ej. «¿Está ya en el
		// CRM?») no cambia nada: al cerrar la ventana no hace falta recargar la página.
		if ( ! form.hasAttribute( 'data-asde-solo-consulta' ) ) {
			cambiado = true;
		}
		cuerpo.setAttribute( 'aria-busy', 'true' );
		Array.prototype.forEach.call( form.querySelectorAll( 'button, input[type="submit"]' ), function ( b ) {
			b.disabled = true;
		} );
		cargar( form.getAttribute( 'action' ) || actual, { method: 'POST', body: datos } );
	}
} )();
