/**
 * Eventos (v0.52): al publicar un evento se abre una ventana donde se elige a quién avisar por email
 * (familias, scouters o todos). Sin JavaScript (o sin <dialog>), el mismo formulario va dentro de <noscript>.
 */
( function () {
	'use strict';

	// Reenviar el aviso (v0.60): el mismo tipo de ventana; la persona solo se pide con «Una persona» y la casilla
	// «sin inscribir» solo con «Familias».
	function reenviar() {
		var dialogo = document.querySelector( '[data-asde-reenviar-dialogo]' );
		var abrir = document.querySelector( '[data-asde-reenviar-abrir]' );
		if ( ! dialogo || ! abrir || 'function' !== typeof dialogo.showModal ) {
			return;
		}
		abrir.hidden = false;
		var cerrar = dialogo.querySelector( '[data-asde-cerrar]' );
		if ( cerrar ) {
			cerrar.hidden = false;
			cerrar.addEventListener( 'click', function () {
				dialogo.close();
			} );
		}
		abrir.addEventListener( 'click', function () {
			dialogo.showModal();
		} );
		dialogo.addEventListener( 'close', function () {
			abrir.focus();
		} );
		var persona = dialogo.querySelector( '[data-asde-reenviar-persona]' );
		var pendientes = dialogo.querySelector( '[data-asde-reenviar-pendientes]' );
		var ajustar = function () {
			var marcado = dialogo.querySelector( '[data-asde-reenviar-destino]:checked' );
			var valor = marcado ? marcado.value : '';
			if ( persona ) {
				persona.hidden = 'persona' !== valor;
			}
			if ( pendientes ) {
				pendientes.hidden = 'familias' !== valor;
			}
		};
		Array.prototype.slice.call( dialogo.querySelectorAll( '[data-asde-reenviar-destino]' ) ).forEach( function ( r ) {
			r.addEventListener( 'change', ajustar );
		} );
		ajustar();
	}

	document.addEventListener( 'DOMContentLoaded', reenviar );

	document.addEventListener( 'DOMContentLoaded', function () {
		var dialogo = document.querySelector( '[data-asde-publicar-dialogo]' );
		var abrir = document.querySelector( '[data-asde-publicar-abrir]' );
		if ( ! dialogo || ! abrir || 'function' !== typeof dialogo.showModal ) {
			return;
		}
		abrir.hidden = false;
		var cerrar = dialogo.querySelector( '[data-asde-cerrar]' );
		if ( cerrar ) {
			cerrar.hidden = false;
			cerrar.addEventListener( 'click', function () {
				dialogo.close();
			} );
		}
		abrir.addEventListener( 'click', function () {
			dialogo.showModal();
		} );
		dialogo.addEventListener( 'close', function () {
			abrir.focus();
		} );

		// «Todos» marca (o desmarca) familias y scouters; y se marca solo cuando están las dos.
		var todos = dialogo.querySelector( '[data-asde-aviso-todos]' );
		var partes = Array.prototype.slice.call( dialogo.querySelectorAll( '[data-asde-aviso-parte]' ) );
		if ( todos ) {
			todos.addEventListener( 'change', function () {
				partes.forEach( function ( p ) {
					p.checked = todos.checked;
				} );
			} );
			partes.forEach( function ( p ) {
				p.addEventListener( 'change', function () {
					todos.checked = partes.every( function ( x ) {
						return x.checked;
					} );
				} );
			} );
		}
	} );
}() );

/**
 * Menú del evento (v0.60.2): la opción «Nuevo» del desplegable de platos abre una ventana para crear la receta
 * (nombre, tipo, porciones, ingredientes con sus alérgenos) y añadirla a la comida elegida. Sin JavaScript, el
 * desplegable envía «nuevo» y el servidor lleva al recetario.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var dialogo = document.querySelector( '[data-asde-menu-nueva]' );
		if ( ! dialogo || 'function' !== typeof dialogo.showModal ) {
			return;
		}
		var form = dialogo.querySelector( 'form' );
		var lineas = dialogo.querySelector( '[data-asde-menu-lineas]' );
		var resumen = dialogo.querySelector( '[data-asde-menu-resumen]' );
		var texto = dialogo.querySelector( '[data-asde-menu-resumen-texto]' );
		var MAX = 40;
		var origen = null;

		function actualizarResumen() {
			var codigos = [];
			Array.prototype.forEach.call( lineas.querySelectorAll( '[data-asde-menu-ing]' ), function ( s ) {
				var o = s.options[ s.selectedIndex ];
				var a = o ? o.getAttribute( 'data-alergenos' ) : '';
				( a ? a.split( '|' ) : [] ).forEach( function ( c ) {
					if ( c && -1 === codigos.indexOf( c ) ) {
						codigos.push( c );
					}
				} );
			} );
			texto.textContent = codigos.length ? codigos.join( ', ' ) : resumen.getAttribute( 'data-sin' );
		}

		// Botón para más filas (se copia la última, vacía): oculto hasta que hay JavaScript.
		var mas = dialogo.querySelector( '[data-asde-menu-mas]' );
		mas.hidden = false;
		function numerar( el, n ) {
			var etiqueta = el.getAttribute( 'aria-label' );
			if ( etiqueta ) {
				el.setAttribute( 'aria-label', etiqueta.replace( /\d+$/, String( n ) ) );
			}
		}
		mas.addEventListener( 'click', function () {
			var filas = lineas.querySelectorAll( '[data-asde-menu-linea]' );
			if ( filas.length >= MAX ) {
				return;
			}
			var nueva = filas[ filas.length - 1 ].cloneNode( true );
			nueva.querySelector( 'select' ).selectedIndex = 0;
			nueva.querySelector( 'input' ).value = '';
			var n = filas.length + 1;
			numerar( nueva.querySelectorAll( 'select' )[ 0 ], n );
			numerar( nueva.querySelector( 'input' ), n );
			numerar( nueva.querySelectorAll( 'select' )[ 1 ], n );
			lineas.appendChild( nueva );
		} );
		lineas.addEventListener( 'change', actualizarResumen );

		// «Nuevo» en el desplegable de cualquier comida: se recuerda cuál y se abre la ventana.
		document.addEventListener( 'change', function ( e ) {
			var s = e.target;
			if ( ! s || 'SELECT' !== s.tagName || 'receta' !== s.name || 'nuevo' !== s.value ) {
				return;
			}
			var f = s.form;
			s.value = '0';
			if ( ! f || ! f.hasAttribute( 'data-asde-menu-anadir' ) ) {
				return;
			}
			origen = s;
			form.reset();
			form.querySelector( '[data-asde-menu-dia]' ).value = f.elements.dia.value;
			form.querySelector( '[data-asde-menu-comida]' ).value = f.elements.comida.value;
			dialogo.querySelector( '[data-asde-menu-destino]' ).textContent = f.getAttribute( 'data-asde-destino' ) || '';
			actualizarResumen();
			dialogo.showModal();
			var nombre = form.elements.nombre;
			if ( nombre ) {
				nombre.focus();
			}
		} );

		var cerrar = dialogo.querySelector( '[data-asde-cerrar]' );
		if ( cerrar ) {
			cerrar.addEventListener( 'click', function () {
				dialogo.close();
			} );
		}
		dialogo.addEventListener( 'close', function () {
			if ( origen ) {
				origen.focus();
			}
		} );
	} );
}() );
