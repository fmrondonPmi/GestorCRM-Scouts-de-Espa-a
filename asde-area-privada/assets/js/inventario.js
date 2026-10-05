/**
 * Inventario de material (v0.19, doc 32). Mejora progresiva: sin JavaScript todo
 * funciona con recargas.
 *
 * 1. Filtros de la lista (tipo y texto) en el navegador, sin recargar.
 * 2. Al marcar para la lista de compra un material con pocas unidades, se
 *    pregunta «¿seguro?» (no bloquea: si se acepta, queda marcado).
 * 3. «Nueva lista de compra»: importes y total en vivo.
 * 4. El «+» del tipo abre los tipos en la ventana emergente; al cerrarla, el
 *    desplegable se actualiza sin recargar (no se pierde lo escrito) y queda
 *    elegido el tipo recién creado.
 */
( function () {
	'use strict';

	function normal( t ) {
		return ( t || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).trim();
	}

	function euros( v ) {
		return v.toLocaleString( 'es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' €';
	}

	/** «12,50», «12.5», «1.234,50» → 12.5; vacío o no válido → null. */
	function importe( texto ) {
		var v = ( texto || '' ).replace( /[\s€]/g, '' );
		if ( '' === v ) {
			return null;
		}
		if ( -1 !== v.indexOf( ',' ) ) {
			v = v.replace( /\./g, '' ).replace( ',', '.' );
		}
		return /^\d+(\.\d{1,2})?$/.test( v ) ? parseFloat( v ) : null;
	}

	// ---------------------------------------------------------------------
	// 1. Filtros
	// ---------------------------------------------------------------------
	var filtro = document.querySelector( '[data-asde-inv-filtro]' );
	var compra = document.querySelector( '[data-asde-inv-compra]' );
	if ( filtro && compra ) {
		var q = filtro.querySelector( '[name="q"]' );
		var tipo = filtro.querySelector( '[name="tipo"]' );
		var vacio = compra.querySelector( '.asde-inv-vacio' );
		var filas = compra.querySelectorAll( 'tbody tr' );
		var aplicar = function () {
			var texto = normal( q.value );
			var t = tipo.value;
			var vistas = 0;
			Array.prototype.forEach.call( filas, function ( tr ) {
				var ok = ( '0' === t || tr.getAttribute( 'data-tipo' ) === t ) && ( '' === texto || -1 !== ( tr.getAttribute( 'data-texto' ) || '' ).indexOf( texto ) );
				tr.hidden = ! ok;
				vistas += ok ? 1 : 0;
			} );
			if ( vacio ) {
				vacio.hidden = vistas > 0;
			}
		};
		q.addEventListener( 'input', aplicar );
		tipo.addEventListener( 'change', aplicar );
		// «Ver también los dados de baja» sí necesita al servidor.
		var bajas = filtro.querySelector( '[name="bajas"]' );
		if ( bajas ) {
			bajas.addEventListener( 'change', function () {
				filtro.submit();
			} );
		}
	}

	// ---------------------------------------------------------------------
	// 2. Marcar para la lista de compra
	// ---------------------------------------------------------------------
	if ( compra ) {
		var umbral = parseInt( compra.getAttribute( 'data-umbral' ) || '5', 10 );
		var contador = compra.querySelector( '[data-asde-inv-marcados]' );
		var contar = function () {
			var n = compra.querySelectorAll( 'input[name="m[]"]:checked' ).length;
			if ( contador ) {
				contador.textContent = 0 === n ? '' : ( 1 === n ? asdeT( '1 material marcado' ) : asdeT( '%s materiales marcados', String( n ) ) );
			}
			return n;
		};
		compra.addEventListener( 'change', function ( e ) {
			var c = e.target;
			if ( ! c.matches || ! c.matches( 'input[name="m[]"]' ) ) {
				return;
			}
			var cantidad = parseInt( c.getAttribute( 'data-cantidad' ) || '0', 10 );
			if ( c.checked && cantidad < umbral ) {
				var nombre = c.getAttribute( 'data-nombre' ) || '';
				var pregunta = 0 === cantidad
					? asdeT( 'No queda ninguna unidad de «%s» en el inventario. ¿Seguro que quieres añadirlo a la lista de compra?', nombre )
					: ( 1 === cantidad
						? asdeT( 'En el inventario solo hay 1 unidad de «%s». ¿Seguro que quieres añadirlo a la lista de compra?', nombre )
						: asdeT( 'En el inventario solo hay %s unidades de «%s». ¿Seguro que quieres añadirlo a la lista de compra?', String( cantidad ), nombre ) );
				if ( ! window.confirm( pregunta ) ) {
					c.checked = false;
				}
			}
			contar();
		} );
		compra.addEventListener( 'submit', function ( e ) {
			if ( 0 === contar() ) {
				e.preventDefault();
				window.alert( asdeT( 'Marca el material que hay que comprar.' ) );
			}
		} );
		contar();
	}

	// ---------------------------------------------------------------------
	// 3. Nueva lista: importes y total
	// ---------------------------------------------------------------------
	var nueva = document.querySelector( '[data-asde-inv-nueva]' );
	if ( nueva ) {
		var total = nueva.querySelector( '[data-asde-inv-total]' );
		var recalcular = function () {
			var suma = 0;
			var sinPrecio = false;
			Array.prototype.forEach.call( nueva.querySelectorAll( '[data-asde-inv-linea]' ), function ( tr ) {
				var c = parseInt( tr.querySelector( '[data-asde-inv-cantidad]' ).value || '0', 10 ) || 0;
				var p = importe( tr.querySelector( '[data-asde-inv-precio]' ).value );
				var celda = tr.querySelector( '[data-asde-inv-importe]' );
				tr.classList.toggle( 'asde-fila--cancelada', 0 === c );
				if ( null === p ) {
					celda.textContent = '—';
					sinPrecio = sinPrecio || c > 0;
					return;
				}
				celda.textContent = euros( p * c );
				suma += p * c;
			} );
			total.textContent = euros( suma ) + ( sinPrecio ? ' ' + asdeT( '+ sin precio' ) : '' );
		};
		nueva.addEventListener( 'input', recalcular );
		recalcular();
	}

	// ---------------------------------------------------------------------
	// 4. Tipos: refrescar el desplegable al cerrar la ventana emergente
	// ---------------------------------------------------------------------
	var select = document.querySelector( '[data-asde-inv-tipos]' );
	var mas = document.querySelector( '[data-asde-inv-mas]' );
	if ( select && mas && window.fetch ) {
		document.addEventListener( 'asde:modal-cerrado', function ( e ) {
			e.preventDefault(); // Sin recargar: se perdería lo escrito en el formulario.
			var antes = {};
			Array.prototype.forEach.call( select.options, function ( o ) {
				antes[ o.value ] = true;
			} );
			window.fetch( mas.href, { credentials: 'same-origin', headers: { 'X-Asde-Json': '1' } } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( r ) {
					if ( ! r || ! r.success ) {
						return;
					}
					var elegido = select.value;
					var nuevo = 0;
					while ( select.options.length > 1 ) {
						select.remove( 1 ); // La primera es «Sin tipo».
					}
					r.data.tipos.forEach( function ( t ) {
						select.add( new window.Option( t.nombre, String( t.id ) ) );
						if ( ! antes[ String( t.id ) ] && t.id > nuevo ) {
							nuevo = t.id;
						}
					} );
					select.value = nuevo ? String( nuevo ) : elegido;
					if ( select.value !== ( nuevo ? String( nuevo ) : elegido ) ) {
						select.value = '0'; // El que estaba elegido se ha borrado.
					}
				} )
				.catch( function () {
					window.location.reload();
				} );
		} );
	}
} )();
