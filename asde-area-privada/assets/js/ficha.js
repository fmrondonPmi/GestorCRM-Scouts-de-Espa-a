/**
 * Ficha de una persona (mejora progresiva: sin JavaScript todo sigue funcionando).
 *
 * 1. Apartados plegables (<details>): se recuerda en el navegador cuáles se
 *    dejaron contraídos, hay «Contraer todo / Expandir todo» y, si al guardar
 *    falta un dato obligatorio en un apartado contraído, se abre.
 * 2. Desplegables con explicación (select.asde-select-ayuda): al abrir la lista
 *    y pasar el ratón por una opción se ve su explicación en un globo. En
 *    pantallas táctiles se usa el desplegable del sistema; en todos los casos la
 *    explicación de la opción elegida se ve debajo del campo.
 *
 * window.asdeFicha.iniciar( raiz ) lo aplica a un trozo de página (la ficha en
 * la ventana emergente, area.js); al cargar, se aplica a la página. Dentro de la
 * ventana emergente se usa el desplegable del sistema (con la explicación debajo).
 */
( function () {
	'use strict';

	// ---------------------------------------------------------------------
	// 1. Apartados plegables
	// ---------------------------------------------------------------------
	// v0.29: los apartados empiezan contraídos (petición del usuario) y se recuerdan los que se abren.
	var CLAVE = 'asde_ficha_abiertos';

	function leer() {
		try {
			var v = JSON.parse( window.localStorage.getItem( CLAVE ) || '[]' );
			return Array.isArray( v ) ? v : [];
		} catch ( e ) {
			return [];
		}
	}

	function guardar( lista ) {
		try {
			window.localStorage.setItem( CLAVE, JSON.stringify( lista ) );
		} catch ( e ) {
			// Sin almacenamiento (modo privado): solo vale para esta página.
		}
	}

	function iniciar( raiz ) {
	// Solo los apartados de la ficha (data-grupo): otros <details> (los tutores del alta, la cuota
	// de la familia) tienen su propio estado.
	var secciones = Array.prototype.slice.call( raiz.querySelectorAll( 'details.asde-plegable[data-grupo]' ) );
	var abiertos = leer();
	secciones.forEach( function ( d ) {
		d.open = -1 !== abiertos.indexOf( d.getAttribute( 'data-grupo' ) );
		d.addEventListener( 'toggle', function () {
			var grupo = d.getAttribute( 'data-grupo' );
			var lista = leer().filter( function ( g ) {
				return g !== grupo;
			} );
			if ( d.open ) {
				lista.push( grupo );
			}
			guardar( lista );
		} );
	} );

	// v0.29: la barra va arriba del todo y pliega también los cuadros de la ficha (nombre,
	// membresía en el CRM, cambios propuestos), que son los plegables de area.js.
	var barra = raiz.querySelector( '.asde-ficha__plegar' );
	if ( barra && secciones.length + raiz.querySelectorAll( 'section.asde-card[data-asde-plegable]' ).length > 1 ) {
		barra.hidden = false;
		barra.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-asde-plegar]' );
			if ( ! b ) {
				return;
			}
			var abrir = 'nada' === b.getAttribute( 'data-asde-plegar' );
			secciones.forEach( function ( d ) {
				d.open = abrir;
			} );
			if ( window.asdePlegables ) {
				Array.prototype.forEach.call( raiz.querySelectorAll( 'section.asde-card--plegable' ), function ( c ) {
					window.asdePlegables.plegar( c, ! abrir, true );
				} );
			}
		} );
	}

	// Un dato obligatorio vacío en un apartado contraído: se abre para corregirlo.
	raiz.querySelectorAll( 'form.asde-ficha' ).forEach( function ( form ) {
		form.addEventListener( 'invalid', function ( e ) {
			var d = e.target.closest( 'details' );
			if ( d ) {
				d.open = true;
			}
		}, true );
	} );

	// ---------------------------------------------------------------------
	// 2. Desplegables con explicación de cada opción
	// ---------------------------------------------------------------------
	var conRaton = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;

	var enVentana = !! ( raiz.closest && raiz.closest( 'dialog' ) );
	raiz.querySelectorAll( 'select.asde-select-ayuda' ).forEach( function ( select ) {
		var texto = document.getElementById( select.id + '-ayuda' );
		function textoElegida() {
			var o = select.options[ select.selectedIndex ];
			if ( texto ) {
				texto.textContent = o && o.getAttribute( 'data-ayuda' ) ? o.getAttribute( 'data-ayuda' ) : '';
			}
		}
		select.addEventListener( 'change', textoElegida );
		if ( ! conRaton || select.required || enVentana || select.hasAttribute( 'data-asde-iniciado' ) ) {
			return; // Pantalla táctil o ventana emergente: desplegable del sistema.
		}
		select.setAttribute( 'data-asde-iniciado', '1' );

		var boton = document.createElement( 'button' );
		boton.type = 'button';
		boton.className = 'asde-select-ayuda__boton';
		boton.id = select.id + '-boton';
		boton.setAttribute( 'aria-haspopup', 'listbox' );
		boton.setAttribute( 'aria-expanded', 'false' );
		boton.setAttribute( 'aria-controls', select.id + '-lista' );
		if ( texto ) {
			boton.setAttribute( 'aria-describedby', texto.id );
		}
		// Mismo aspecto que los campos del tema.
		var cs = window.getComputedStyle( select );
		[ 'backgroundColor', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderStyle', 'borderRadius', 'color', 'fontSize', 'fontFamily', 'minHeight', 'paddingTop', 'paddingBottom', 'paddingLeft' ].forEach( function ( p ) {
			boton.style[ p ] = cs[ p ];
		} );

		var lista = document.createElement( 'ul' );
		lista.id = select.id + '-lista';
		lista.className = 'asde-select-ayuda__lista';
		lista.setAttribute( 'role', 'listbox' );
		lista.hidden = true;
		var globo = document.createElement( 'div' );
		globo.className = 'asde-select-ayuda__globo';
		globo.setAttribute( 'role', 'tooltip' );
		globo.hidden = true;

		var etiqueta = document.querySelector( 'label[for="' + select.id + '"]' );
		if ( etiqueta ) {
			etiqueta.htmlFor = boton.id;
		}
		select.hidden = true;
		select.tabIndex = -1;
		select.parentNode.insertBefore( boton, select );
		// La lista y el globo cuelgan del <body> para quedar por encima de todo.
		document.body.appendChild( lista );
		document.body.appendChild( globo );

		var items = Array.prototype.map.call( select.options, function ( o, i ) {
			var li = document.createElement( 'li' );
			li.id = select.id + '-op-' + i;
			li.setAttribute( 'role', 'option' );
			li.textContent = o.text;
			var ayuda = o.getAttribute( 'data-ayuda' );
			if ( ayuda ) {
				li.setAttribute( 'data-ayuda', ayuda );
				var sr = document.createElement( 'span' );
				sr.className = 'screen-reader-text';
				sr.textContent = '. ' + ayuda;
				li.appendChild( sr );
			}
			lista.appendChild( li );
			return li;
		} );
		var activa = -1;

		function pintarBoton() {
			var o = select.options[ select.selectedIndex ];
			boton.textContent = o ? o.text : '—';
		}
		function colocar() {
			var r = boton.getBoundingClientRect();
			lista.style.left = ( r.left + window.scrollX ) + 'px';
			lista.style.top = ( r.bottom + window.scrollY + 2 ) + 'px';
			lista.style.minWidth = r.width + 'px';
		}
		function mostrarGlobo( li ) {
			var ayuda = li && li.getAttribute( 'data-ayuda' );
			if ( ! ayuda ) {
				globo.hidden = true;
				return;
			}
			globo.textContent = ayuda;
			globo.hidden = false;
			var r = li.getBoundingClientRect();
			var g = globo.getBoundingClientRect();
			var izquierda = r.right + 10;
			if ( izquierda + g.width > window.innerWidth - 8 ) {
				izquierda = Math.max( 8, r.left - g.width - 10 ); // Sin sitio a la derecha: a la izquierda.
			}
			globo.style.left = ( izquierda + window.scrollX ) + 'px';
			globo.style.top = ( r.top + window.scrollY + ( r.height - g.height ) / 2 ) + 'px';
		}
		function marcar( i, desplazar ) {
			activa = ( i + items.length ) % items.length;
			items.forEach( function ( li, j ) {
				li.setAttribute( 'aria-selected', j === activa ? 'true' : 'false' );
			} );
			boton.setAttribute( 'aria-activedescendant', items[ activa ].id );
			if ( desplazar ) {
				items[ activa ].scrollIntoView( { block: 'nearest' } );
			}
			mostrarGlobo( items[ activa ] );
		}
		function abrir() {
			colocar();
			lista.hidden = false;
			boton.setAttribute( 'aria-expanded', 'true' );
			marcar( Math.max( 0, select.selectedIndex ), true );
		}
		function cerrar() {
			lista.hidden = true;
			globo.hidden = true;
			boton.setAttribute( 'aria-expanded', 'false' );
			boton.removeAttribute( 'aria-activedescendant' );
		}
		function elegir( i ) {
			select.selectedIndex = i;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			pintarBoton();
			cerrar();
			boton.focus();
		}

		boton.addEventListener( 'click', function () {
			if ( lista.hidden ) {
				abrir();
			} else {
				cerrar();
			}
		} );
		boton.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				if ( lista.hidden ) {
					abrir();
				} else {
					marcar( activa + ( 'ArrowDown' === e.key ? 1 : -1 ), true );
				}
			} else if ( ( 'Enter' === e.key || ' ' === e.key ) && ! lista.hidden ) {
				e.preventDefault();
				elegir( activa );
			} else if ( 'Escape' === e.key || 'Tab' === e.key ) {
				cerrar();
			}
		} );
		items.forEach( function ( li, i ) {
			li.addEventListener( 'mouseenter', function () {
				marcar( i, false );
			} );
			li.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				elegir( i );
			} );
		} );
		lista.addEventListener( 'mouseleave', function () {
			globo.hidden = true;
		} );
		document.addEventListener( 'mousedown', function ( e ) {
			if ( ! lista.hidden && e.target !== boton && ! lista.contains( e.target ) ) {
				cerrar();
			}
		} );
		window.addEventListener( 'scroll', function () {
			if ( ! lista.hidden ) {
				colocar();
				mostrarGlobo( items[ activa ] );
			}
		}, { passive: true } );
		window.addEventListener( 'resize', cerrar );
		pintarBoton();
	} );
	}

	window.asdeFicha = { iniciar: iniciar };
	iniciar( document );
} )();
