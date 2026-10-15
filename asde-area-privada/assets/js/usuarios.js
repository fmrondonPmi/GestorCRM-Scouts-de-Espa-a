/**
 * Listado de usuarios: filtra por columna, ordena y pagina en memoria.
 *
 * La página llega con todos los usuarios (las filas llevan sus datos en atributos
 * data-*). Aquí se indexan una vez y cada filtro, orden o cambio de página se
 * resuelve con esa lista, sin volver al servidor ni recargar. Lo que sí pregunta al
 * servidor, sin recargar la página:
 *  - reenviar una invitación o desactivar un usuario (devuelve la fila nueva);
 *  - «Actualizar» y el cierre de la ventana «Gestionar acceso» (devuelven la lista).
 * Filtros, orden y página se guardan en la dirección (se puede recargar o compartir).
 * Sin JavaScript se ve la lista entera, sin filtros.
 */
( function () {
	'use strict';

	if ( ! window.fetch || ! window.FormData || ! window.URLSearchParams ) {
		return;
	}

	/** v0.25: también para la hoja «Usuarios» que se abre sin recargar la página (assets/js/hojas.js). */
	function iniciar( raiz ) {
		var tabla = raiz ? raiz.querySelector( '[data-asde-tabla]' ) : null;
		if ( ! tabla || raiz.hasAttribute( 'data-asde-iniciado' ) ) {
			return;
		}
		raiz.setAttribute( 'data-asde-iniciado', '' );

		var COLUMNAS = [ 'usuario', 'persona', 'roles', 'ambito', 'estado' ];
		var TEXTOS = [ 'usuario', 'persona' ];
		var cuerpo = raiz.querySelector( '[data-asde-filas]' );
		var vacio = raiz.querySelector( '[data-asde-vacio]' );
		var cajaPaginas = raiz.querySelector( '[data-asde-paginacion]' );
		var cajaAvisos = raiz.querySelector( '[data-asde-avisos]' );
		var total = raiz.querySelector( '[data-asde-total]' );
		var botonLimpiar = raiz.querySelector( '[data-asde-limpiar]' );
		var botonActualizar = raiz.querySelector( '[data-asde-actualizar]' );
		var ordenMovil = raiz.querySelector( '[data-asde-orden-movil]' );
		var url = raiz.getAttribute( 'data-url' );
		var opciones = ( raiz.getAttribute( 'data-opciones' ) || '25,50,75,100' ).split( ',' ).map( Number );

		var filas = [];
		var estado = {
			filtros: { usuario: '', persona: '', roles: '', ambito: '', estado: '' },
			orden: 'usuario',
			dir: 'asc',
			pagina: 1,
			porPagina: Number( raiz.getAttribute( 'data-por-pagina' ) ) || opciones[ 0 ]
		};
		var controles = {};
		var temporizador = null;

		// ---------------------------------------------------------------------
		// Datos en memoria
		// ---------------------------------------------------------------------
		function normalizar( texto ) {
			texto = String( texto || '' ).toLowerCase();
			return texto.normalize ? texto.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ) : texto;
		}

		function indexar() {
			filas = [];
			Array.prototype.forEach.call( cuerpo.querySelectorAll( 'tr[data-asde-fila]' ), function ( tr ) {
				filas.push( fila( tr, filas.length ) );
			} );
		}

		function fila( tr, posicion ) {
			return {
				tr: tr,
				id: tr.getAttribute( 'data-asde-fila' ),
				posicion: posicion,
				claves: {
					usuario: normalizar( tr.getAttribute( 'data-usuario' ) ),
					persona: normalizar( tr.getAttribute( 'data-persona' ) ),
					roles: normalizar( tr.getAttribute( 'data-roles-texto' ) ),
					ambito: normalizar( tr.getAttribute( 'data-ambito' ).replace( /\|/g, ' ' ) ),
					estado: normalizar( tr.getAttribute( 'data-estado' ) )
				},
				roles: tr.getAttribute( 'data-roles' ) || '',
				ambito: tr.getAttribute( 'data-ambito' ) || '',
				estadoValor: tr.getAttribute( 'data-estado' ) || ''
			};
		}

		function coincide( f ) {
			var fl = estado.filtros;
			var i, palabras;
			for ( i = 0; i < TEXTOS.length; i++ ) {
				palabras = normalizar( fl[ TEXTOS[ i ] ] ).split( /\s+/ ).filter( Boolean );
				if ( palabras.some( function ( p ) { return -1 === f.claves[ TEXTOS[ i ] ].indexOf( p ); } ) ) {
					return false;
				}
			}
			if ( fl.roles && -1 === f.roles.indexOf( '|' + fl.roles + '|' ) ) {
				return false;
			}
			if ( fl.ambito && -1 === f.ambito.indexOf( '|' + fl.ambito + '|' ) ) {
				return false;
			}
			return ! fl.estado || f.estadoValor === fl.estado;
		}

		function comparar( a, b ) {
			var va = a.claves[ estado.orden ];
			var vb = b.claves[ estado.orden ];
			var c;
			if ( '' === va || '' === vb ) {
				// Los vacíos, siempre al final.
				c = ( '' === va ? 1 : 0 ) - ( '' === vb ? 1 : 0 );
				return 0 !== c ? c : a.posicion - b.posicion;
			}
			c = va.localeCompare( vb, 'es', { numeric: true } );
			if ( 'desc' === estado.dir ) {
				c = -c;
			}
			return 0 !== c ? c : a.posicion - b.posicion; // Orden estable.
		}

		function hayFiltros() {
			return COLUMNAS.some( function ( c ) {
				return '' !== estado.filtros[ c ];
			} );
		}

		// ---------------------------------------------------------------------
		// Pintar
		// ---------------------------------------------------------------------
		function pintar() {
			var visibles = filas.filter( coincide ).sort( comparar );
			var n = visibles.length;
			var paginas = Math.max( 1, Math.ceil( n / estado.porPagina ) );
			estado.pagina = Math.min( Math.max( 1, estado.pagina ), paginas );
			var desde = ( estado.pagina - 1 ) * estado.porPagina;
			var pagina = visibles.slice( desde, desde + estado.porPagina );

			filas.forEach( function ( f ) {
				f.tr.hidden = true;
			} );
			pagina.forEach( function ( f ) {
				f.tr.hidden = false;
				cuerpo.appendChild( f.tr ); // En el orden pedido.
			} );

			vacio.hidden = 0 !== n;
			botonLimpiar.hidden = ! hayFiltros();
			total.textContent = ( hayFiltros() ? asdeT( '%s de %s', n, filas.length ) : filas.length ) + ' ' + ( 1 === filas.length ? asdeT( 'usuario' ) : asdeT( 'usuarios' ) );
			pintarPaginas( n, paginas, desde, pagina.length );
			pintarCabeceras();
			guardarEnDireccion();
		}

		function pintarCabeceras() {
			Array.prototype.forEach.call( tabla.querySelectorAll( '.asde-cabeceras th[data-col]' ), function ( th ) {
				var col = th.getAttribute( 'data-col' );
				var activa = col === estado.orden;
				var enlace = th.querySelector( 'a' );
				if ( ! enlace ) {
					enlace = document.createElement( 'a' );
					enlace.href = '#';
					enlace.className = 'asde-orden';
					enlace.setAttribute( 'data-col', col );
					enlace.innerHTML = '<span></span> <span class="asde-orden__flecha" aria-hidden="true"></span>';
					enlace.firstChild.textContent = th.textContent.trim();
					th.textContent = '';
					th.appendChild( enlace );
				}
				enlace.className = 'asde-orden' + ( activa ? ' is-active' : '' );
				enlace.lastChild.textContent = activa ? ( 'asc' === estado.dir ? '▲' : '▼' ) : '↕';
				enlace.title = asdeT( 'Ordenar por %s (%s)', enlace.firstChild.textContent.toLowerCase(), activa && 'asc' === estado.dir ? asdeT( 'descendente' ) : asdeT( 'ascendente' ) );
				if ( activa ) {
					th.setAttribute( 'aria-sort', 'asc' === estado.dir ? 'ascending' : 'descending' );
				} else {
					th.removeAttribute( 'aria-sort' );
				}
			} );
			if ( ordenMovil ) {
				ordenMovil.querySelector( '[data-orden-col]' ).value = estado.orden;
				ordenMovil.querySelector( '[data-orden-dir]' ).value = estado.dir;
			}
		}

		function pintarPaginas( n, paginas, desde, enPagina ) {
			if ( ! n ) {
				cajaPaginas.innerHTML = '';
				return;
			}
			var lista = [];
			var p;
			for ( p = 1; p <= paginas; p++ ) {
				if ( 1 === p || p === paginas || Math.abs( p - estado.pagina ) <= 2 ) {
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
			var html = '<nav class="asde-paginacion" aria-label="' + asdeEsc( asdeT( 'Paginación' ) ) + '"><p class="asde-paginacion__info">' + asdeT( 'Mostrando %s–%s de %s', desde + 1, desde + enPagina, n ) + '</p>';
			if ( paginas > 1 ) {
				html += '<ul class="asde-paginacion__botones">'
					+ boton( estado.pagina > 1, 1, '«', asdeT( 'Primera página' ) )
					+ boton( estado.pagina > 1, estado.pagina - 1, '‹', asdeT( 'Página anterior' ) );
				lista.forEach( function ( q ) {
					if ( '…' === q ) {
						html += '<li><span class="asde-paginacion__hueco" aria-hidden="true">…</span></li>';
					} else if ( q === estado.pagina ) {
						html += '<li><span class="is-current" aria-current="page">' + q + '</span></li>';
					} else {
						html += '<li><a href="#" data-pag="' + q + '" aria-label="' + asdeEsc( asdeT( 'Página %s', q ) ) + '">' + q + '</a></li>';
					}
				} );
				html += boton( estado.pagina < paginas, estado.pagina + 1, '›', asdeT( 'Página siguiente' ) )
					+ boton( estado.pagina < paginas, paginas, '»', asdeT( 'Última página' ) )
					+ '</ul>';
			}
			html += '<div class="asde-paginacion__tamano"><label for="asde-usuarios-por-pagina">' + asdeT( 'Mostrar' ) + '</label>'
				+ '<select id="asde-usuarios-por-pagina" data-tamano>'
				+ opciones.map( function ( o ) {
					return '<option value="' + o + '"' + ( o === estado.porPagina ? ' selected' : '' ) + '>' + o + '</option>';
				} ).join( '' )
				+ '</select><span>' + asdeT( 'por página' ) + '</span></div></nav>';
			cajaPaginas.innerHTML = html;
		}

		// ---------------------------------------------------------------------
		// Dirección (filtros, orden y página se pueden recargar y compartir)
		// ---------------------------------------------------------------------
		function guardarEnDireccion() {
			// Con la hoja oculta (se ve otra hoja de Usuarios), la dirección es de la otra.
			if ( ! window.history || ! window.history.replaceState || raiz.closest( '[hidden]' ) ) {
				return;
			}
			var q = new window.URLSearchParams( window.location.search );
			[ 'rol', 'pag', 'orden', 'dir' ].concat( COLUMNAS.map( function ( c ) {
				return 'f_' + c;
			} ) ).forEach( function ( k ) {
				q.delete( k );
			} );
			COLUMNAS.forEach( function ( c ) {
				if ( estado.filtros[ c ] ) {
					q.set( 'f_' + c, estado.filtros[ c ] );
				}
			} );
			if ( 'usuario' !== estado.orden || 'asc' !== estado.dir ) {
				q.set( 'orden', estado.orden );
				q.set( 'dir', estado.dir );
			}
			if ( estado.pagina > 1 ) {
				q.set( 'pag', estado.pagina );
			}
			var cadena = q.toString();
			window.history.replaceState( null, '', window.location.pathname + ( cadena ? '?' + cadena : '' ) + window.location.hash );
		}

		function leerDireccion() {
			var q = new window.URLSearchParams( window.location.search );
			COLUMNAS.forEach( function ( c ) {
				estado.filtros[ c ] = q.get( 'f_' + c ) || '';
			} );
			if ( ! estado.filtros.roles && raiz.getAttribute( 'data-rol' ) ) {
				estado.filtros.roles = raiz.getAttribute( 'data-rol' ); // Enlaces antiguos: ?rol=…
			}
			if ( COLUMNAS.indexOf( q.get( 'orden' ) ) > -1 ) {
				estado.orden = q.get( 'orden' );
			}
			estado.dir = 'desc' === q.get( 'dir' ) ? 'desc' : 'asc';
			estado.pagina = Math.max( 1, parseInt( q.get( 'pag' ), 10 ) || 1 );
		}

		// ---------------------------------------------------------------------
		// Controles
		// ---------------------------------------------------------------------
		function ordenarPor( col ) {
			if ( col === estado.orden ) {
				estado.dir = 'asc' === estado.dir ? 'desc' : 'asc';
			} else {
				estado.orden = col;
				estado.dir = 'asc';
			}
			estado.pagina = 1;
			pintar();
		}

		function iniciarControles() {
			Array.prototype.forEach.call( tabla.querySelectorAll( '[data-filtro]' ), function ( el ) {
				var col = el.getAttribute( 'data-filtro' );
				controles[ col ] = el;
				el.value = estado.filtros[ col ];
				if ( el.value !== estado.filtros[ col ] ) {
					estado.filtros[ col ] = el.value; // Valor que no existe (enlace antiguo).
				}
				var cambia = function () {
					if ( estado.filtros[ col ] === el.value.trim() ) {
						return; // «input» y «change» llegan los dos en los desplegables.
					}
					estado.filtros[ col ] = el.value.trim();
					estado.pagina = 1;
					// Al teclear se espera un instante para no repintar con cada letra.
					window.clearTimeout( temporizador );
					temporizador = window.setTimeout( pintar, 'SELECT' === el.tagName ? 0 : 120 );
				};
				el.addEventListener( 'input', cambia );
				el.addEventListener( 'change', cambia ); // Safari antiguo: los desplegables solo avisan con «change».
			} );
			tabla.querySelector( '.asde-filtros' ).hidden = false;
			botonLimpiar.addEventListener( 'click', function () {
				COLUMNAS.forEach( function ( c ) {
					estado.filtros[ c ] = '';
					controles[ c ].value = '';
				} );
				estado.pagina = 1;
				pintar();
			} );
			botonActualizar.hidden = false;
			botonActualizar.addEventListener( 'click', function () {
				actualizar( true, botonActualizar );
			} );

			tabla.querySelector( 'thead' ).addEventListener( 'click', function ( e ) {
				var a = e.target.closest ? e.target.closest( 'a[data-col]' ) : null;
				if ( a ) {
					e.preventDefault();
					ordenarPor( a.getAttribute( 'data-col' ) );
				}
			} );
			if ( ordenMovil ) {
				ordenMovil.hidden = false;
				ordenMovil.addEventListener( 'change', function () {
					estado.orden = ordenMovil.querySelector( '[data-orden-col]' ).value;
					estado.dir = ordenMovil.querySelector( '[data-orden-dir]' ).value;
					estado.pagina = 1;
					pintar();
				} );
			}

			cajaPaginas.addEventListener( 'click', function ( e ) {
				var a = e.target.closest ? e.target.closest( 'a[data-pag]' ) : null;
				if ( a ) {
					e.preventDefault();
					estado.pagina = Number( a.getAttribute( 'data-pag' ) );
					pintar();
					tabla.scrollIntoView( { block: 'start' } );
				}
			} );
			cajaPaginas.addEventListener( 'change', function ( e ) {
				if ( ! e.target.hasAttribute( 'data-tamano' ) ) {
					return;
				}
				estado.porPagina = Number( e.target.value );
				estado.pagina = 1;
				pintar();
				// Se recuerda el tamaño elegido (el mismo en todos los listados).
				var datos = new window.FormData();
				datos.append( 'action', 'asde_ap_por_pagina' );
				datos.append( 'nonce', raiz.getAttribute( 'data-nonce' ) );
				datos.append( 'por_pagina', estado.porPagina );
				window.fetch( raiz.getAttribute( 'data-ajax' ), { method: 'POST', body: datos, credentials: 'same-origin' } ).catch( function () {} );
			} );
		}

		// ---------------------------------------------------------------------
		// Servidor, sin recargar
		// ---------------------------------------------------------------------
		function avisos( html ) {
			var viejo = raiz.querySelector( '[data-asde-aviso-crm]' );
			if ( viejo ) {
				viejo.parentNode.removeChild( viejo );
			}
			cajaAvisos.innerHTML = html || '';
			if ( html ) {
				cajaAvisos.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			}
		}

		/** Un aviso de error. Con «anadir» se pone detrás de los que ya hay (no los borra). */
		function error( texto, anadir ) {
			var p = document.createElement( 'div' );
			p.className = 'asde-notice asde-notice--error';
			p.setAttribute( 'role', 'alert' );
			p.textContent = texto;
			if ( ! anadir ) {
				cajaAvisos.innerHTML = '';
			}
			cajaAvisos.appendChild( p );
			cajaAvisos.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}

		function pedir( direccion, opciones ) {
			opciones = opciones || {};
			opciones.credentials = 'same-origin';
			opciones.headers = { 'X-Asde-Json': '1' };
			return window.fetch( direccion, opciones ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'respuesta' );
				}
				return j.data;
			} );
		}

		/** Vuelve a pedir toda la lista (y, si se pide, los nombres del CRM). */
		function actualizar( desdeCrm, boton ) {
			var texto = boton ? boton.textContent : '';
			if ( boton ) {
				boton.disabled = true;
				boton.textContent = asdeT( 'Actualizando…' );
			}
			return pedir( url + ( desdeCrm ? ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + 'refrescar=1&_wpnonce=' + encodeURIComponent( raiz.getAttribute( 'data-nonce-refrescar' ) || '' ) : '' ) ).then( function ( d ) {
				cuerpo.innerHTML = d.filas;
				indexar();
				avisos( d.avisos );
				if ( d.aviso ) {
					error( d.aviso, true );
				}
				pintar();
			} ).catch( function () {
				error( asdeT( 'No se ha podido actualizar la lista. Recarga la página.' ) );
			} ).then( function () {
				if ( boton ) {
					boton.disabled = false;
					boton.textContent = texto;
				}
			} );
		}

		/** Reenviar invitación y Desactivar: se envían sin salir de la página y la fila se actualiza. */
		cuerpo.addEventListener( 'submit', function ( e ) {
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
			var tr = form.closest( 'tr' );
			var botones = tr.querySelectorAll( 'button' );
			Array.prototype.forEach.call( botones, function ( b ) {
				b.disabled = true;
			} );
			pedir( form.getAttribute( 'action' ) || url, { method: 'POST', body: datos } ).then( function ( d ) {
				reemplazar( tr.getAttribute( 'data-asde-fila' ), d.fila );
				avisos( d.avisos );
			} ).catch( function () {
				Array.prototype.forEach.call( botones, function ( b ) {
					b.disabled = false;
				} );
				error( asdeT( 'No se ha podido completar la acción. Recarga la página para ver cómo ha quedado.' ) );
			} );
		} );

		function reemplazar( id, html ) {
			var i;
			for ( i = 0; i < filas.length; i++ ) {
				if ( filas[ i ].id === id ) {
					break;
				}
			}
			if ( i === filas.length ) {
				return;
			}
			var viejo = filas[ i ];
			var caja = document.createElement( 'tbody' );
			caja.innerHTML = html || '';
			var nuevo = caja.firstElementChild;
			if ( nuevo ) {
				cuerpo.replaceChild( nuevo, viejo.tr );
				filas[ i ] = fila( nuevo, viejo.posicion );
			} else {
				cuerpo.removeChild( viejo.tr );
				filas.splice( i, 1 );
			}
			pintar();
		}

		// «Gestionar acceso» (ventana emergente) puede haber creado o cambiado usuarios:
		// en vez de recargar la página se vuelve a pedir la lista.
		document.addEventListener( 'asde:modal-cerrado', function ( e ) {
			if ( ! document.contains( raiz ) ) {
				return; // La hoja se ha vuelto a cargar: ya la atiende su nuevo listado.
			}
			e.preventDefault();
			actualizar( false );
		} );

		leerDireccion();
		indexar();
		iniciarControles();
		pintar();
	}

	window.asdeUsuarios = { iniciar: iniciar };
	iniciar( document.querySelector( '[data-asde-usuarios]' ) );
} )();
