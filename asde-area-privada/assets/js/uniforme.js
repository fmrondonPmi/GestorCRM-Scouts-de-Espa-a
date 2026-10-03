/**
 * Uniforme (v0.11): total del pedido mientras se rellena el formulario, y la
 * talla obligatoria solo si se pide la camisa. Sin JavaScript, el total se ve
 * al enviar el pedido.
 *
 * v0.12.5: las tablas de medidas de la camisa y la guía de las insignias (enlaces con
 * data-asde-imagen) se abren en una ventana encima del pedido: no se sale de la página
 * ni se pierde lo que se lleva rellenado. Sin JavaScript, el enlace abre la imagen en
 * otra pestaña.
 */
( function () {
	'use strict';

	// ---------------------------------------------------------------------
	// Visor de imágenes
	// ---------------------------------------------------------------------
	var visor = null;

	function crearVisor() {
		visor = document.createElement( 'dialog' );
		visor.className = 'asde-visor';
		visor.setAttribute( 'aria-labelledby', 'asde-visor-titulo' );
		visor.innerHTML = '<div class="asde-visor__barra">'
			+ '<span class="asde-visor__titulo" id="asde-visor-titulo"></span>'
			+ '<a class="asde-visor__abrir" href="#" target="_blank" rel="noopener">' + asdeT( 'Abrir en otra pestaña' ) + '</a>'
			+ '<button type="button" class="asde-visor__cerrar" aria-label="' + asdeT( 'Cerrar' ) + '">×</button>'
			+ '</div><div class="asde-visor__cuerpo" tabindex="-1"><img alt=""></div>';
		// Junto al contenido del área (no al final de <body>): así hereda los colores y la tipografía del tema.
		var area = document.querySelector( '.asde-ap' );
		( area && area.parentNode ? area.parentNode : document.body ).appendChild( visor );
		visor.querySelector( '.asde-visor__cerrar' ).addEventListener( 'click', function () {
			visor.close();
		} );
		visor.addEventListener( 'click', function ( e ) {
			if ( e.target === visor ) {
				visor.close(); // Clic fuera de la ventana.
			}
		} );
	}

	if ( 'function' === typeof window.HTMLDialogElement ) {
		// En la fase de captura, antes que el tema (Enfold anima el cambio de página con los enlaces).
		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a[data-asde-imagen]' ) : null;
			if ( ! a || 0 !== e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			if ( ! visor ) {
				crearVisor();
			}
			var titulo = a.getAttribute( 'data-titulo' ) || a.textContent.trim();
			var imagen = visor.querySelector( 'img' );
			imagen.src = a.href;
			imagen.alt = titulo;
			visor.querySelector( '.asde-visor__titulo' ).textContent = titulo;
			visor.querySelector( '.asde-visor__abrir' ).href = a.href;
			if ( ! visor.open ) {
				visor.showModal();
			}
			visor.querySelector( '.asde-visor__cuerpo' ).scrollTop = 0;
		}, true );
	}

	var form = document.querySelector( '[data-asde-uniforme]' );
	if ( ! form ) {
		return;
	}
	var total = form.querySelector( '[data-asde-total]' );
	var nota = form.querySelector( '[data-asde-total-nota]' );
	var camisa = form.querySelector( 'input[name="con_camisa"]' );
	var talla = form.querySelector( 'select[name="camisa"]' );
	var euros = window.Intl ? new window.Intl.NumberFormat( 'es-ES', { style: 'currency', currency: 'EUR' } ) : null;

	function calcular() {
		var suma = 0;
		var porConfirmar = false;
		if ( camisa && camisa.checked ) {
			suma += parseFloat( camisa.getAttribute( 'data-precio' ) ) || 0;
		}
		if ( talla ) {
			talla.required = ! camisa || camisa.checked;
			talla.disabled = !! camisa && ! camisa.checked;
		}
		Array.prototype.forEach.call( form.querySelectorAll( 'input[type="number"][data-precio]' ), function ( campo ) {
			var cantidad = parseInt( campo.value, 10 ) || 0;
			var precio = campo.getAttribute( 'data-precio' );
			if ( cantidad <= 0 ) {
				return;
			}
			if ( '' === precio ) {
				porConfirmar = true;
				return;
			}
			suma += cantidad * ( parseFloat( precio ) || 0 );
		} );
		if ( total ) {
			total.textContent = euros ? euros.format( suma ) : suma.toFixed( 2 ).replace( '.', ',' ) + ' €';
		}
		if ( nota ) {
			nota.hidden = ! porConfirmar;
		}
	}

	// La tabla de medidas del modelo elegido se destaca entre los enlaces.
	function marcarModelo() {
		var modelo = talla ? talla.value.split( '|' )[ 0 ] : '';
		Array.prototype.forEach.call( form.querySelectorAll( 'a[data-modelo]' ), function ( a ) {
			a.classList.toggle( 'is-actual', '' !== modelo && a.getAttribute( 'data-modelo' ) === modelo );
		} );
	}

	form.addEventListener( 'input', calcular );
	form.addEventListener( 'change', calcular );
	form.addEventListener( 'change', marcarModelo );
	calcular();
	marcarModelo();
} )();
