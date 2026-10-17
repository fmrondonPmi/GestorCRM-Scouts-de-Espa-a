/**
 * Selector de alérgenos de la ficha de salud (mejora progresiva: sin JavaScript queda el campo de texto).
 *
 * El campo guarda un texto separado por comas. Este script lo pasa a una ventana con pestañas y casillas, y a alérgenos escritos a
 * mano. Al abrir, lo que ya hubiera escrito se reparte: lo que coincide con una casilla la marca y el resto se conserva como
 * «escrito a mano» (no se pierde nada). window.asdeAlergenos.iniciar( raiz ) lo aplica a un trozo de página (la ficha en la ventana
 * emergente); al cargar, se aplica a la página.
 */
( function () {
	'use strict';

	function norm( t ) {
		var s = String( t || '' ).toLowerCase();
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}
		return s.replace( /[^a-z0-9]+/g, ' ' ).replace( /^ | $/g, '' );
	}

	function partes( texto ) {
		var vistos = {};
		var out = [];
		String( texto || '' ).split( /[,;\/\n\r]+/ ).forEach( function ( p ) {
			p = p.replace( /^\s+|\s+$/g, '' );
			var k = norm( p );
			if ( k && ! vistos[ k ] ) {
				vistos[ k ] = true;
				out.push( p );
			}
		} );
		return out;
	}

	function limpiarPropio( t ) {
		return String( t || '' ).replace( /[,;\/\r\n]+/g, ' ' ).replace( /\s+/g, ' ' ).replace( /^ | $/g, '' );
	}

	function iniciarUno( w ) {
		if ( w.hasAttribute( 'data-asde-iniciado' ) ) {
			return;
		}
		var dlg = w.querySelector( 'dialog' );
		var input = w.querySelector( 'input.asde-alerg__texto' );
		var resumen = w.querySelector( '.asde-alerg__resumen' );
		if ( ! dlg || ! input || ! resumen ) {
			return;
		}
		w.setAttribute( 'data-asde-iniciado', '1' );
		var maxlen = parseInt( w.getAttribute( 'data-maxlen' ) || '0', 10 );
		var chips = w.querySelector( '[data-asde-alerg-chips]' );
		var propiosUl = w.querySelector( '[data-asde-alerg-propios]' );
		var propioInput = w.querySelector( '[data-asde-alerg-propio]' );
		var error = w.querySelector( '[data-asde-alerg-error]' );
		var checks = Array.prototype.slice.call( w.querySelectorAll( 'input[type=checkbox][data-valor]' ) );
		var tabs = Array.prototype.slice.call( w.querySelectorAll( '[role=tab]' ) );
		var paneles = Array.prototype.slice.call( w.querySelectorAll( '[role=tabpanel]' ) );
		var propios = []; // Escritos a mano (en la ventana).

		// Con JavaScript, el campo de texto se esconde y se enseña el resumen con su botón.
		input.hidden = true;
		resumen.hidden = false;

		function etiquetaDe( c ) {
			var s = c.parentNode.querySelector( 'span' );
			return s ? s.textContent : c.getAttribute( 'data-valor' );
		}

		// Reparte un texto entre las casillas y los escritos a mano.
		function repartir( texto ) {
			var elegidos = {};
			var extra = [];
			partes( texto ).forEach( function ( p ) {
				var k = norm( p );
				var c = checks.filter( function ( x ) {
					return x.getAttribute( 'data-clave' ) === k || x.getAttribute( 'data-clave-t' ) === k;
				} )[ 0 ];
				if ( c ) {
					elegidos[ c.getAttribute( 'data-valor' ) ] = true;
				} else {
					extra.push( p );
				}
			} );
			return { elegidos: elegidos, extra: extra };
		}

		function texto( elegidos, extra ) {
			var lista = [];
			checks.forEach( function ( c ) {
				if ( elegidos[ c.getAttribute( 'data-valor' ) ] ) {
					lista.push( c.getAttribute( 'data-valor' ) );
				}
			} );
			return lista.concat( extra ).join( ', ' );
		}

		function pintarResumen() {
			var r = repartir( input.value );
			chips.innerHTML = '';
			var n = 0;
			checks.forEach( function ( c ) {
				if ( r.elegidos[ c.getAttribute( 'data-valor' ) ] ) {
					var li = document.createElement( 'li' );
					li.textContent = etiquetaDe( c );
					chips.appendChild( li );
					n++;
				}
			} );
			r.extra.forEach( function ( p ) {
				var li = document.createElement( 'li' );
				li.textContent = p;
				chips.appendChild( li );
				n++;
			} );
			if ( ! n ) {
				var v = document.createElement( 'li' );
				v.className = 'asde-alerg__vacio';
				v.textContent = w.getAttribute( 'data-msg-vacio' ) || '';
				chips.appendChild( v );
			}
		}

		function pintarPropios() {
			propiosUl.innerHTML = '';
			propios.forEach( function ( p, i ) {
				var li = document.createElement( 'li' );
				li.appendChild( document.createTextNode( p + ' ' ) );
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'asde-btn-link';
				b.textContent = '✕';
				b.setAttribute( 'aria-label', '✕ ' + p );
				b.addEventListener( 'click', function () {
					propios.splice( i, 1 );
					pintarPropios();
				} );
				li.appendChild( b );
				propiosUl.appendChild( li );
			} );
		}

		function activar( clave ) {
			var existe = tabs.some( function ( t ) {
				return t.getAttribute( 'data-pestana' ) === clave;
			} );
			clave = existe ? clave : tabs[ 0 ].getAttribute( 'data-pestana' );
			tabs.forEach( function ( t ) {
				var on = t.getAttribute( 'data-pestana' ) === clave;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.setAttribute( 'tabindex', on ? '0' : '-1' );
				t.classList.toggle( 'is-active', on );
			} );
			paneles.forEach( function ( p ) {
				p.hidden = ( p.id.indexOf( '-panel-' + clave ) === -1 );
			} );
		}

		tabs.forEach( function ( t, i ) {
			t.addEventListener( 'click', function () {
				activar( t.getAttribute( 'data-pestana' ) );
			} );
			t.addEventListener( 'keydown', function ( e ) {
				var d = 'ArrowRight' === e.key ? 1 : ( 'ArrowLeft' === e.key ? -1 : 0 );
				if ( d ) {
					e.preventDefault();
					var n = tabs[ ( i + d + tabs.length ) % tabs.length ];
					activar( n.getAttribute( 'data-pestana' ) );
					n.focus();
				}
			} );
		} );

		function abrir() {
			var r = repartir( input.value );
			checks.forEach( function ( c ) {
				c.checked = !! r.elegidos[ c.getAttribute( 'data-valor' ) ];
			} );
			propios = r.extra.slice();
			pintarPropios();
			error.hidden = true;
			propioInput.value = '';
			activar( w.getAttribute( 'data-inicial' ) || tabs[ 0 ].getAttribute( 'data-pestana' ) );
			if ( dlg.showModal ) {
				dlg.showModal();
			} else {
				dlg.setAttribute( 'open', '' );
			}
		}

		function cerrar() {
			if ( dlg.close ) {
				dlg.close();
			} else {
				dlg.removeAttribute( 'open' );
			}
		}

		function anadirPropio() {
			var t = limpiarPropio( propioInput.value );
			if ( t && propios.every( function ( p ) {
				return norm( p ) !== norm( t );
			} ) ) {
				propios.push( t );
				pintarPropios();
			}
			propioInput.value = '';
			propioInput.focus();
		}

		w.querySelector( '[data-asde-alerg-abrir]' ).addEventListener( 'click', abrir );
		w.querySelector( '[data-asde-alerg-anadir]' ).addEventListener( 'click', anadirPropio );
		propioInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault(); // Enter no envía la ficha: añade el alérgeno.
				anadirPropio();
			}
		} );
		w.querySelector( '[data-asde-alerg-cancelar]' ).addEventListener( 'click', cerrar );
		w.querySelector( '[data-asde-alerg-aceptar]' ).addEventListener( 'click', function () {
			if ( limpiarPropio( propioInput.value ) ) {
				anadirPropio(); // Lo escrito y sin añadir también cuenta.
			}
			var elegidos = {};
			checks.forEach( function ( c ) {
				if ( c.checked ) {
					elegidos[ c.getAttribute( 'data-valor' ) ] = true;
				}
			} );
			var valor = texto( elegidos, propios );
			if ( maxlen > 0 && valor.length > maxlen ) {
				error.textContent = w.getAttribute( 'data-msg-largo' ) || '';
				error.hidden = false;
				return;
			}
			input.value = valor;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			pintarResumen();
			cerrar();
		} );
		// Esc cierra sin aplicar; el diálogo nativo ya lo hace.
		pintarResumen();
	}

	function iniciar( raiz ) {
		Array.prototype.forEach.call( ( raiz || document ).querySelectorAll( '[data-asde-alerg]' ), iniciarUno );
	}

	window.asdeAlergenos = { iniciar: iniciar, interpretarTexto: partes };
	iniciar( document );
} )();
