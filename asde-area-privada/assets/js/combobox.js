/**
 * Desplegable predictivo: convierte un <select class="asde-combobox"> en un
 * campo de texto que empieza a buscar a partir de la 3.ª letra (data-min) y
 * sugiere las opciones que coinciden (sin distinguir tildes ni mayúsculas).
 *
 * Mejora progresiva: sin JavaScript sigue funcionando el <select>. Accesible
 * (patrón combobox de WAI-ARIA): flechas para moverse, Intro para elegir,
 * Esc para cerrar.
 *
 * window.asdeCombobox.iniciar( raiz ) prepara los que lleguen después (p. ej. el
 * listado del banco, que se actualiza sin recargar la página).
 */
( function () {
	'use strict';

	var MAX = 50;
	var contador = 0;
	var abiertos = []; // { lista, caja }: para quitar las listas de lo que ya no está en la página.

	function normalizar( s ) {
		return s.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	function iniciar( raiz ) {
		abiertos = abiertos.filter( function ( a ) {
			if ( a.caja.isConnected ) {
				return true;
			}
			a.lista.remove();
			return false;
		} );
		( raiz || document ).querySelectorAll( 'select.asde-combobox:not([data-asde-combo])' ).forEach( preparar );
	}

	function preparar( select ) {
		var n = contador++;
		select.setAttribute( 'data-asde-combo', '1' );
		var min = parseInt( select.getAttribute( 'data-min' ) || '3', 10 );
		var opciones = Array.prototype.filter.call( select.options, function ( o ) {
			return '' !== o.value;
		} ).map( function ( o ) {
			return { valor: o.value, texto: o.text, clave: normalizar( o.text ) };
		} );
		var id = select.id || 'asde-combobox-' + n;

		var caja = document.createElement( 'div' );
		caja.className = 'asde-combobox';
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'asde-combobox__input';
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id + '-lista' );
		input.placeholder = select.getAttribute( 'data-placeholder' ) || asdeT( 'Escribe al menos %s letras…', min );
		var lista = document.createElement( 'ul' );
		lista.id = id + '-lista';
		lista.className = 'asde-combobox__lista';
		lista.setAttribute( 'role', 'listbox' );
		lista.hidden = true;
		var aviso = document.createElement( 'span' );
		aviso.className = 'screen-reader-text';
		aviso.setAttribute( 'aria-live', 'polite' );

		// La etiqueta del <select> pasa a nombrar el campo de texto.
		input.id = id;
		select.id = id + '-select';
		select.hidden = true;
		select.tabIndex = -1;
		select.parentNode.insertBefore( caja, select );
		caja.appendChild( input );
		caja.appendChild( aviso );
		caja.appendChild( select );
		// La lista cuelga del <body> para quedar siempre por encima (el tema crea
		// capas que taparían una lista dentro del contenido, p. ej. el pie).
		document.body.appendChild( lista );
		abiertos.push( { lista: lista, caja: caja } );

		var activa = -1;
		var resultados = [];

		function colocar() {
			var r = input.getBoundingClientRect();
			lista.style.left = ( r.left + window.scrollX ) + 'px';
			lista.style.top = ( r.bottom + window.scrollY + 2 ) + 'px';
			lista.style.width = r.width + 'px';
		}
		window.addEventListener( 'resize', function () {
			if ( ! lista.hidden ) {
				colocar();
			}
		} );
		window.addEventListener( 'scroll', function () {
			if ( ! lista.hidden ) {
				colocar();
			}
		}, { passive: true } );

		function cerrar() {
			lista.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			activa = -1;
		}

		function elegir( i ) {
			var o = resultados[ i ];
			if ( ! o ) {
				return;
			}
			input.value = o.texto;
			select.value = o.valor;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			cerrar();
		}

		function marcar( i ) {
			var items = lista.querySelectorAll( 'li' );
			if ( ! items.length ) {
				return;
			}
			activa = ( i + items.length ) % items.length;
			items.forEach( function ( li, j ) {
				li.setAttribute( 'aria-selected', j === activa ? 'true' : 'false' );
			} );
			input.setAttribute( 'aria-activedescendant', items[ activa ].id );
			items[ activa ].scrollIntoView( { block: 'nearest' } );
		}

		function buscar() {
			var q = normalizar( input.value.trim() );
			select.value = '';
			if ( q.length < min ) {
				cerrar();
				aviso.textContent = q.length ? asdeT( 'Escribe al menos %s letras.', min ) : '';
				return;
			}
			var terminos = q.split( /\s+/ );
			resultados = opciones.filter( function ( o ) {
				return terminos.every( function ( t ) {
					return -1 !== o.clave.indexOf( t );
				} );
			} ).slice( 0, MAX );
			lista.innerHTML = '';
			if ( ! resultados.length ) {
				var vacio = document.createElement( 'li' );
				vacio.className = 'asde-combobox__vacio';
				vacio.textContent = asdeT( 'Nadie coincide con «%s»', input.value.trim() );
				lista.appendChild( vacio );
			}
			resultados.forEach( function ( o, i ) {
				var li = document.createElement( 'li' );
				li.id = id + '-op-' + i;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				li.textContent = o.texto;
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault(); // Que no se pierda el foco antes de elegir.
					elegir( i );
				} );
				lista.appendChild( li );
			} );
			colocar();
			lista.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			activa = -1;
			aviso.textContent = resultados.length + ( 1 === resultados.length ? ' resultado' : ' resultados' );
		}

		input.addEventListener( 'input', buscar );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				if ( lista.hidden ) {
					buscar();
				}
				marcar( activa + 1 );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				marcar( activa - 1 );
			} else if ( 'Enter' === e.key && ! lista.hidden ) {
				e.preventDefault();
				elegir( activa >= 0 ? activa : 0 );
			} else if ( 'Escape' === e.key ) {
				cerrar();
			}
		} );
		input.addEventListener( 'blur', function () {
			window.setTimeout( cerrar, 150 );
			// Si el texto no es una opción elegida, no se envía nada.
			var elegida = opciones.filter( function ( o ) {
				return o.valor === select.value;
			} )[ 0 ];
			if ( ! elegida || elegida.texto !== input.value ) {
				select.value = '';
			}
		} );
		// Opción ya elegida (p. ej. una sugerencia): se muestra en el campo.
		if ( select.value ) {
			var inicial = opciones.filter( function ( o ) {
				return o.valor === select.value;
			} )[ 0 ];
			input.value = inicial ? inicial.texto : '';
		}
		// Obligatorio: sin opción elegida no se envía el formulario.
		if ( select.required ) {
			select.required = false;
			input.required = true;
			select.form.addEventListener( 'submit', function ( e ) {
				if ( ! select.value ) {
					e.preventDefault();
					input.setCustomValidity( asdeT( 'Elige una opción de la lista.' ) );
					input.reportValidity();
				}
			} );
			input.addEventListener( 'input', function () {
				input.setCustomValidity( '' );
			} );
		}
	}

	window.asdeCombobox = { iniciar: iniciar };
	iniciar( document );
} )();
