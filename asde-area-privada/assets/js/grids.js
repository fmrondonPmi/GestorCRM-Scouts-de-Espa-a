/**
 * Paginación de los grids (v0.61, doc 64, skill «grids-area-privada»).
 *
 * Toda tabla de datos del área privada va paginada. Las que se paginan en el servidor (clase Tabla: orden, filtros,
 * muchas filas) ya traen su barra; el resto lleva `data-asde-paginar` y esta rutina las pagina en el navegador con el
 * MISMO aspecto y los mismos textos que la barra del servidor (nav.asde-paginacion):
 *  - 10 filas por página por defecto; 5 en pantallas estrechas (≤ 600 px) si la persona no ha elegido otro tamaño;
 *  - el tamaño que elige la persona se recuerda en su perfil (el mismo aviso AJAX que usa el listado de Usuarios);
 *  - una fila con una sola celda que ocupa todo el ancho (colspan) es el detalle de la anterior y viaja con ella;
 *  - una fila con el atributo `hidden` (otro script la ha filtrado) no cuenta;
 *  - las tablas que cambian de filas (lista cargada por AJAX o fila añadida) se vuelven a paginar solas.
 * Sin JavaScript se ven todas las filas (mejora progresiva).
 */
( function () {
	'use strict';

	var OPCIONES = [ 5, 10, 15, 20, 25, 30, 35, 40, 45, 50 ];
	var config = window.asdeGrids || {};
	var contador = 0;

	function porPaginaInicial() {
		var elegido = parseInt( config.elegido, 10 ) || 0;
		if ( OPCIONES.indexOf( elegido ) > -1 ) {
			return elegido;
		}
		return window.matchMedia && window.matchMedia( '(max-width: 600px)' ).matches ? 5 : 10;
	}

	var porPagina = porPaginaInicial();
	var grids = [];

	function esDetalle( tr ) {
		return 1 === tr.cells.length && tr.cells[ 0 ].colSpan > 1;
	}

	function Grid( tabla ) {
		this.tabla = tabla;
		this.pagina = 1;
		this.id = 'asde-grid-' + ( ++contador );
		this.caja = document.createElement( 'div' );
		this.caja.className = 'asde-grid-paginas';
		tabla.parentNode.insertBefore( this.caja, tabla.nextSibling );
		var self = this;
		this.caja.addEventListener( 'click', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a[data-pag]' ) : null;
			if ( a ) {
				e.preventDefault();
				self.pagina = parseInt( a.getAttribute( 'data-pag' ), 10 ) || 1;
				self.pintar();
			}
		} );
		this.caja.addEventListener( 'change', function ( e ) {
			if ( e.target.matches && e.target.matches( '[data-tamano]' ) ) {
				cambiarTamano( parseInt( e.target.value, 10 ) );
			}
		} );
		this.firma = '';
	}

	// Grupos de filas: cada fila de datos con sus filas de detalle.
	Grid.prototype.grupos = function () {
		var grupos = [];
		var filas = this.tabla.querySelectorAll( ':scope > tbody > tr' );
		Array.prototype.forEach.call( filas, function ( tr ) {
			if ( tr.hasAttribute( 'hidden' ) ) {
				return;
			}
			if ( esDetalle( tr ) && grupos.length ) {
				grupos[ grupos.length - 1 ].push( tr );
			} else {
				grupos.push( [ tr ] );
			}
		} );
		return grupos;
	};

	Grid.prototype.pintar = function () {
		var grupos = this.grupos();
		var n = grupos.length;
		var paginas = Math.max( 1, Math.ceil( n / porPagina ) );
		this.pagina = Math.min( Math.max( 1, this.pagina ), paginas );
		var desde = ( this.pagina - 1 ) * porPagina;
		var hasta = Math.min( n, desde + porPagina );
		Array.prototype.forEach.call( this.tabla.querySelectorAll( ':scope > tbody > tr' ), function ( tr ) {
			tr.classList.remove( 'asde-grid-oculta' );
		} );
		grupos.forEach( function ( g, i ) {
			if ( i < desde || i >= hasta ) {
				g.forEach( function ( tr ) {
					tr.classList.add( 'asde-grid-oculta' );
				} );
			}
		} );
		this.caja.innerHTML = this.barra( n, paginas, desde, hasta );
		this.firma = this.tabla.tBodies.length + ':' + this.tabla.querySelectorAll( ':scope > tbody > tr' ).length;
	};

	Grid.prototype.barra = function ( n, paginas, desde, hasta ) {
		var actual = this.pagina;
		var lista = [];
		var p;
		for ( p = 1; p <= paginas; p++ ) {
			if ( 1 === p || p === paginas || Math.abs( p - actual ) <= 2 ) {
				lista.push( p );
			} else if ( '…' !== lista[ lista.length - 1 ] ) {
				lista.push( '…' );
			}
		}
		var boton = function ( activo, destino, texto, etiqueta ) {
			return activo
				? '<li><a href="#" data-pag="' + destino + '" aria-label="' + asdeEsc( etiqueta ) + '">' + texto + '</a></li>'
				: '<li><span class="is-disabled" aria-hidden="true">' + texto + '</span></li>';
		};
		var html = '<nav class="asde-paginacion" aria-label="' + asdeEsc( asdeT( 'Pagination' ) ) + '"><p class="asde-paginacion__info">'
			+ ( n ? asdeT( 'Showing %1$s–%2$s of %3$s', desde + 1, hasta, n ) : asdeT( 'Showing %1$s–%2$s of %3$s', 0, 0, 0 ) ) + '</p>';
		if ( paginas > 1 ) {
			html += '<ul class="asde-paginacion__botones">'
				+ boton( actual > 1, 1, '«', asdeT( 'First page' ) )
				+ boton( actual > 1, actual - 1, '‹', asdeT( 'Previous page' ) );
			lista.forEach( function ( q ) {
				if ( '…' === q ) {
					html += '<li><span class="asde-paginacion__hueco" aria-hidden="true">…</span></li>';
				} else if ( q === actual ) {
					html += '<li><span class="is-current" aria-current="page">' + q + '</span></li>';
				} else {
					html += '<li><a href="#" data-pag="' + q + '" aria-label="' + asdeEsc( asdeT( 'Page %s', q ) ) + '">' + q + '</a></li>';
				}
			} );
			html += boton( actual < paginas, actual + 1, '›', asdeT( 'Next page' ) )
				+ boton( actual < paginas, paginas, '»', asdeT( 'Last page' ) )
				+ '</ul>';
		}
		html += '<div class="asde-paginacion__tamano"><label for="' + this.id + '-tam">' + asdeT( 'Show' ) + '</label>'
			+ '<select id="' + this.id + '-tam" data-tamano>'
			+ OPCIONES.map( function ( o ) {
				return '<option value="' + o + '"' + ( o === porPagina ? ' selected' : '' ) + '>' + o + '</option>';
			} ).join( '' )
			+ '</select><span>' + asdeT( 'per page' ) + '</span></div></nav>';
		return html;
	};

	function cambiarTamano( n ) {
		if ( OPCIONES.indexOf( n ) < 0 ) {
			return;
		}
		porPagina = n;
		grids.forEach( function ( g ) {
			g.pagina = 1;
			g.pintar();
		} );
		// Se recuerda para la próxima vez (en todos los listados).
		if ( config.ajax && config.nonce && window.fetch ) {
			var datos = new window.URLSearchParams();
			datos.set( 'action', 'asde_ap_por_pagina' );
			datos.set( 'nonce', config.nonce );
			datos.set( 'por_pagina', n );
			window.fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: datos } ).catch( function () {} );
		}
	}

	function iniciar( raiz ) {
		Array.prototype.forEach.call( ( raiz || document ).querySelectorAll( 'table[data-asde-paginar]' ), function ( tabla ) {
			if ( tabla.asdeGrid ) {
				return;
			}
			var g = new Grid( tabla );
			tabla.asdeGrid = g;
			grids.push( g );
			g.pintar();
		} );
		// Las tablas que ya no están en la página (la lista se ha vuelto a cargar) se olvidan.
		grids = grids.filter( function ( g ) {
			if ( document.body.contains( g.tabla ) ) {
				return true;
			}
			if ( g.caja.parentNode ) {
				g.caja.parentNode.removeChild( g.caja );
			}
			return false;
		} );
	}

	// Una fila que cambia (se añade, se oculta) vuelve a pintar la tabla; otro script puede pedirlo con «asde:grid».
	var pendiente = null;
	function revisar() {
		if ( pendiente ) {
			return;
		}
		pendiente = window.setTimeout( function () {
			pendiente = null;
			iniciar( document );
			grids.forEach( function ( g ) {
				var firma = g.tabla.tBodies.length + ':' + g.tabla.querySelectorAll( ':scope > tbody > tr' ).length;
				if ( firma !== g.firma ) {
					g.pintar();
				}
			} );
		}, 60 );
	}

	function arrancar() {
		iniciar( document );
		if ( window.MutationObserver ) {
			new window.MutationObserver( function ( cambios ) {
				// Los cambios de nuestras propias barras y de las clases de fila no cuentan.
				var util = cambios.some( function ( c ) {
					return 'childList' === c.type && ! ( c.target.classList && c.target.classList.contains( 'asde-grid-paginas' ) );
				} );
				if ( util ) {
					revisar();
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		}
		document.addEventListener( 'asde:grid', revisar );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', arrancar );
	} else {
		arrancar();
	}
}() );
