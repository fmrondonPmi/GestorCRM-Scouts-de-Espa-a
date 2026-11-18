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

	var MAX = 100;
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
		( raiz || document ).querySelectorAll( 'select:not([data-asde-combo])' ).forEach( function ( s ) {
			if ( s.classList.contains( 'asde-combobox' ) ) {
				preparar( s );
			} else if ( automatico( s ) ) {
				s.setAttribute( 'data-min', s.getAttribute( 'data-min' ) || '1' );
				preparar( s );
			}
		} );
	}

	// v0.64.11: cualquier lista de más de 10 opciones se vuelve predictiva sola (salvo las marcadas con data-asde-sin-combo,
	// las de selección múltiple, las desactivadas y los filtros que se envían al cambiar).
	function automatico( s ) {
		return ! s.multiple && ! s.disabled && s.options.length > 11 && ! s.closest( '.asde-combobox' ) &&
			! s.hasAttribute( 'data-asde-sin-combo' ) && ! s.hasAttribute( 'data-asde-autoenviar' ) && ! s.hasAttribute( 'data-orden-col' );
	}

	// Para una fila copiada con cloneNode(): vuelve a dejar el <select> sin predictivo para prepararlo de nuevo.
	function reiniciar( raiz ) {
		raiz.querySelectorAll( '.asde-combobox' ).forEach( function ( caja ) {
			var select = caja.querySelector( 'select' );
			if ( ! select ) {
				return;
			}
			select.hidden = false;
			select.removeAttribute( 'tabindex' );
			select.removeAttribute( 'data-asde-combo' );
			select.id = select.id.replace( /-select$/, '' );
			if ( 0 === select.id.indexOf( 'asde-combobox-' ) ) {
				select.removeAttribute( 'id' ); // El id lo había puesto el propio predictivo: la copia recibirá uno nuevo.
			}
			if ( select.hasAttribute( 'data-asde-req' ) ) {
				select.required = true;
			}
			select.value = '';
			caja.parentNode.insertBefore( select, caja );
			caja.remove();
		} );
	}

	function preparar( select ) {
		var n = contador++;
		select.setAttribute( 'data-asde-combo', '1' );
		var min = parseInt( select.getAttribute( 'data-min' ) || '3', 10 );
		// Se leen al momento: otras partes de la página pueden añadir opciones (p. ej. un ingrediente recién creado).
		function leer() {
			return Array.prototype.filter.call( select.options, function ( o ) {
				return '' !== o.value;
			} ).map( function ( o ) {
				return { valor: o.value, texto: o.text, clave: normalizar( o.text ) };
			} );
		}
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
		var vacia = Array.prototype.filter.call( select.options, function ( o ) {
			return '' === o.value && '' !== o.text.trim();
		} )[ 0 ];
		input.placeholder = select.getAttribute( 'data-placeholder' ) || ( min <= 1 ? ( vacia ? vacia.text.trim() : asdeT( 'Type to search…' ) ) : asdeT( 'Enter at least %s letters…', min ) );
		var lista = document.createElement( 'ul' );
		lista.id = id + '-lista';
		lista.className = 'asde-combobox__lista';
		lista.setAttribute( 'role', 'listbox' );
		lista.hidden = true;
		var aviso = document.createElement( 'span' );
		aviso.className = 'screen-reader-text';
		aviso.setAttribute( 'aria-live', 'polite' );

		// La etiqueta del <select> pasa a nombrar el campo de texto.
		if ( select.getAttribute( 'aria-label' ) ) {
			input.setAttribute( 'aria-label', select.getAttribute( 'aria-label' ) );
		}
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
		// Dentro de un <dialog> modal, lo del <body> queda por debajo (capa superior): cuelga del propio <dialog>.
		( select.closest( 'dialog' ) || document.body ).appendChild( lista );
		abiertos.push( { lista: lista, caja: caja } );

		var activa = -1;
		var resultados = [];

		function colocar() {
			var r = input.getBoundingClientRect();
			// En una ventana emergente (<dialog> modal) la lista cuelga de ella y va fija a la pantalla.
			var fija = !! lista.closest( 'dialog' );
			lista.style.position = fija ? 'fixed' : '';
			lista.style.left = ( r.left + ( fija ? 0 : window.scrollX ) ) + 'px';
			lista.style.top = ( r.bottom + ( fija ? 0 : window.scrollY ) + 2 ) + 'px';
			lista.style.width = r.width + 'px';
		}
		window.addEventListener( 'resize', function () {
			if ( ! lista.hidden ) {
				colocar();
			}
		} );
		// En fase de captura: el desplazamiento del cuerpo de la ventana emergente no sube hasta window.
		window.addEventListener( 'scroll', function () {
			if ( ! lista.hidden ) {
				colocar();
			}
		}, { passive: true, capture: true } );

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
			if ( select.value !== o.valor ) { // Otro código cambió la opción al elegir (p. ej. «Plato nuevo»): el campo la refleja.
				var actual = leer().filter( function ( x ) {
					return x.valor === select.value;
				} )[ 0 ];
				input.value = actual ? actual.texto : '';
			}
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

		function buscar( modo ) {
			var q = 'todo' === modo ? '' : normalizar( input.value.trim() );
			if ( 'todo' !== modo ) {
				select.value = '';
			}
			if ( q.length < min && min > 1 ) {
				cerrar();
				aviso.textContent = q.length ? asdeT( 'Enter at least %s letters.', min ) : '';
				return;
			}
			var terminos = q.split( /\s+/ );
			resultados = leer().filter( function ( o ) {
				return terminos.every( function ( t ) {
					return -1 !== o.clave.indexOf( t );
				} );
			} ).slice( 0, MAX );
			lista.innerHTML = '';
			if ( ! resultados.length ) {
				var vacio = document.createElement( 'li' );
				vacio.className = 'asde-combobox__vacio';
				vacio.textContent = asdeT( 'Nobody matches "%s"', input.value.trim() );
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
			aviso.textContent = 1 === resultados.length ? asdeT( '1 result' ) : asdeT( '%s results', resultados.length );
		}

		input.addEventListener( 'input', function () {
			buscar();
		} );
		if ( min <= 1 ) { // Con pocas letras se puede ver la lista entera al entrar en el campo.
			input.addEventListener( 'focus', function () {
				if ( lista.hidden ) {
					buscar( 'todo' );
				}
			} );
		}
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				if ( lista.hidden ) {
					buscar( min <= 1 ? 'todo' : undefined );
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
			var elegida = leer().filter( function ( o ) {
				return o.valor === select.value;
			} )[ 0 ];
			if ( ! elegida || elegida.texto !== input.value ) {
				select.value = '';
			}
		} );
		// Si otro código cambia la opción (value = … y change), el campo muestra la elegida.
		select.addEventListener( 'change', function () {
			var actual = leer().filter( function ( x ) {
				return x.valor === select.value;
			} )[ 0 ];
			if ( actual && actual.texto !== input.value ) {
				input.value = actual.texto;
			} else if ( ! select.value && document.activeElement !== input ) {
				input.value = '';
			}
		} );
		// Opción ya elegida (p. ej. una sugerencia): se muestra en el campo.
		if ( select.value ) {
			var inicial = leer().filter( function ( o ) {
				return o.valor === select.value;
			} )[ 0 ];
			input.value = inicial ? inicial.texto : '';
		}
		// Obligatorio: sin opción elegida no se envía el formulario.
		if ( select.required ) {
			select.setAttribute( 'data-asde-req', '1' );
			select.required = false;
			input.required = true;
			select.form.addEventListener( 'submit', function ( e ) {
				if ( ! select.value ) {
					e.preventDefault();
					input.setCustomValidity( asdeT( 'Choose an option from the list.' ) );
					input.reportValidity();
				}
			} );
			input.addEventListener( 'input', function () {
				input.setCustomValidity( '' );
			} );
		}
	}

	window.asdeCombobox = { iniciar: iniciar, reiniciar: reiniciar };
	iniciar( document );
} )();
