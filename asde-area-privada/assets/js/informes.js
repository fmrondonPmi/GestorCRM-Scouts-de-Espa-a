/**
 * Informes (mejora progresiva: sin JavaScript todo funciona igual).
 *
 * 1. Editor: marcar/desmarcar todas las columnas de un grupo.
 * 2. Imprimir (v0.29): la versión para imprimir del informe (una página propia, con la
 *    tabla entera) se abre en una ventana emergente, dentro de un marco, con «Imprimir»
 *    (imprime solo el informe) y «Abrir en otra pestaña». Sin JavaScript, el enlace la
 *    abre en otra pestaña.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-asde-informe] [data-asde-marcar]' ).forEach( function ( boton ) {
		boton.addEventListener( 'click', function () {
			var grupo = boton.closest( 'fieldset' );
			var marcar = 'todas' === boton.getAttribute( 'data-asde-marcar' );
			grupo.querySelectorAll( 'input[type="checkbox"][name="cols[]"]' ).forEach( function ( c ) {
				c.checked = marcar;
			} );
		} );
	} );

	if ( 'function' !== typeof window.HTMLDialogElement ) {
		return; // Navegador antiguo: el enlace abre la versión para imprimir en otra pestaña.
	}

	function abrir( url, nombre ) {
		var dialogo = document.createElement( 'dialog' );
		dialogo.className = 'asde-modal asde-modal--imprimir';
		dialogo.setAttribute( 'aria-labelledby', 'asde-imprimir-titulo' );
		dialogo.innerHTML = '<div class="asde-modal__barra">'
			+ '<span class="asde-modal__titulo" id="asde-imprimir-titulo"></span>'
			+ '<button type="button" class="button asde-modal__imprimir">' + asdeT( 'Imprimir' ) + '</button>'
			+ '<a class="asde-modal__pagina" target="_blank" rel="noopener">' + asdeT( 'Abrir en otra pestaña' ) + '</a>'
			+ '<button type="button" class="asde-modal__cerrar" aria-label="' + asdeEsc( asdeT( 'Cerrar' ) ) + '">×</button>'
			+ '</div><iframe class="asde-modal__marco"></iframe>';
		dialogo.querySelector( '.asde-modal__titulo' ).textContent = nombre;
		dialogo.querySelector( '.asde-modal__pagina' ).href = url;
		var marco = dialogo.querySelector( 'iframe' );
		marco.title = asdeT( 'Versión para imprimir' );
		marco.src = url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + 'marco=1';

		function cerrar() {
			dialogo.close();
			dialogo.remove();
		}
		dialogo.querySelector( '.asde-modal__cerrar' ).addEventListener( 'click', cerrar );
		dialogo.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			cerrar();
		} );
		dialogo.addEventListener( 'click', function ( e ) {
			if ( e.target === dialogo ) {
				cerrar(); // Clic fuera de la ventana.
			}
		} );
		dialogo.querySelector( '.asde-modal__imprimir' ).addEventListener( 'click', function () {
			try {
				marco.contentWindow.focus();
				marco.contentWindow.print();
			} catch ( err ) {
				window.open( url, '_blank', 'noopener' );
			}
		} );

		// Junto al área, para heredar sus colores y su tipografía (como la ventana de area.js).
		var area = document.querySelector( '.asde-ap' );
		( area && area.parentNode ? area.parentNode : document.body ).appendChild( dialogo );
		dialogo.showModal();
	}

	// En la fase de captura: que el tema (las transiciones de página de Enfold) no se quede con el clic.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[data-asde-imprimir]' ) : null;
		if ( ! a || 0 !== e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		abrir( a.href, a.getAttribute( 'data-asde-imprimir' ) || a.textContent.trim() );
	}, true );
} )();
