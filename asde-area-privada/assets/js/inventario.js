/**
 * Inventario de material (v0.19, doc 32). Mejora progresiva: sin JavaScript todo
 * funciona con recargas.
 *
 * 1. Filtros de la lista (tipo y texto) en el navegador, sin recargar.
 * 2. Al marcar para la lista de compra un material del que ya hay bastantes
 *    unidades (v0.30, P-62: antes era al revés), se pregunta «¿seguro que hace falta
 *    comprar más?» (no bloquea: si se acepta, queda marcado).
 * 3. «Nueva lista de compra»: importes y total en vivo y, v0.30 (P-63), «Añadir
 *    otra línea» de material nuevo sin recargar.
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
			// P-62 (v0.30): se pregunta cuando ya hay bastantes (con el umbral a 0, nunca).
			if ( c.checked && umbral > 0 && cantidad >= umbral ) {
				var nombre = c.getAttribute( 'data-nombre' ) || '';
				var pregunta = 1 === cantidad
					? asdeT( 'Ya hay 1 unidad de «%s» en el inventario. ¿Seguro que hace falta comprar más?', nombre )
					: asdeT( 'Ya hay %s unidades de «%s» en el inventario. ¿Seguro que hace falta comprar más?', String( cantidad ), nombre );
				if ( ! window.confirm( pregunta ) ) {
					c.checked = false;
				}
			}
			contar();
		} );
		compra.addEventListener( 'submit', function ( e ) {
			// v0.30: sin nada marcado también se puede seguir, para una lista solo de material nuevo.
			if ( 0 === contar() && ! window.confirm( asdeT( 'No has marcado ningún material del inventario. ¿Preparar una lista solo con material nuevo?' ) ) ) {
				e.preventDefault();
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
				var desc = tr.querySelector( '[data-asde-inv-desc]' );
				if ( desc && '' === desc.value.trim() ) {
					// Fila de material nuevo en blanco: no cuenta.
					celda.textContent = '—';
					tr.classList.remove( 'asde-fila--cancelada' );
					return;
				}
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

		// v0.30 (P-63): «Añadir otra línea» de material nuevo sin recargar (sin JavaScript, el
		// botón envía el formulario y vuelve con una fila más).
		var nuevos = nueva.querySelector( '[data-asde-inv-nuevos]' );
		var otra = nueva.querySelector( '[data-asde-inv-otra]' );
		if ( nuevos && otra ) {
			otra.addEventListener( 'click', function ( e ) {
				var filas = nuevos.querySelectorAll( '[data-asde-inv-nuevo]' );
				var modelo = filas[ filas.length - 1 ];
				if ( ! modelo ) {
					return; // Sin fila de modelo: que lo haga el servidor.
				}
				e.preventDefault();
				var i = filas.length;
				var fila = modelo.cloneNode( true );
				Array.prototype.forEach.call( fila.querySelectorAll( 'input' ), function ( campo ) {
					campo.name = campo.name.replace( /\[\d+\]$/, '[' + i + ']' );
					campo.id = campo.id.replace( /-\d+$/, '-' + i );
					campo.value = campo.hasAttribute( 'data-asde-inv-cantidad' ) ? '1' : '';
				} );
				Array.prototype.forEach.call( fila.querySelectorAll( 'label' ), function ( label ) {
					label.htmlFor = label.htmlFor.replace( /-\d+$/, '-' + i );
					label.textContent = label.textContent.replace( /\d+/, String( i + 1 ) );
				} );
				nuevos.appendChild( fila );
				recalcular();
				fila.querySelector( '[data-asde-inv-desc]' ).focus();
			} );
		}
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

	// ---------------------------------------------------------------------
	// 5. Camisas del uniforme (v0.29): modelo y talla solo con el tipo «Camisa
	// uniforme»; la descripción deja de ser obligatoria (se pone sola) y la talla
	// enseña solo las del modelo elegido.
	// ---------------------------------------------------------------------
	var camisa = document.querySelector( '[data-asde-inv-camisa]' );
	if ( select && camisa ) {
		var modelo = camisa.querySelector( 'select[name="modelo"]' );
		var talla = camisa.querySelector( 'select[name="talla"]' );
		var desc = document.getElementById( 'asde-inv-desc' );
		var asterisco = document.querySelector( '[data-asde-inv-no-camisa]' );
		var mostrar = function () {
			var es = '0' !== camisa.getAttribute( 'data-asde-inv-camisa' ) && select.value === camisa.getAttribute( 'data-asde-inv-camisa' );
			camisa.hidden = ! es;
			modelo.required = es;
			talla.required = es;
			if ( desc ) {
				desc.required = ! es;
			}
			if ( asterisco ) {
				asterisco.hidden = es;
			}
		};
		var tallas = function () {
			Array.prototype.forEach.call( talla.querySelectorAll( 'optgroup' ), function ( g ) {
				var suyo = g.getAttribute( 'data-modelo' ) === modelo.value;
				g.hidden = ! suyo;
				g.disabled = ! suyo;
			} );
			var elegida = talla.options[ talla.selectedIndex ];
			if ( elegida && elegida.parentNode.disabled ) {
				talla.value = '';
			}
		};
		select.addEventListener( 'change', mostrar );
		modelo.addEventListener( 'change', tallas );
		mostrar();
		tallas();
	}
} )();
