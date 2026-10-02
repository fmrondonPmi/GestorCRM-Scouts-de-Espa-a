/**
 * Alta de usuarios: mejora progresiva (el formulario funciona sin JS y todo se
 * vuelve a comprobar en el servidor).
 * - Las secciones se muestran si tiene (o se le marca) Scouter o una
 *   responsabilidad (Secretaría, Coordinación, Tesorería).
 * - «Voluntario sin sección» excluye las secciones educativas y el rol de Scouter.
 *
 * window.asdeAlta.iniciar( raiz ) lo aplica a un trozo de página (la ventana
 * emergente, area.js); al cargar, se aplica a la página.
 */
( function () {
	'use strict';

	var RESPONSABILIDADES = [ 'asde_secretaria', 'asde_coordinacion', 'asde_tesoreria' ];

	function iniciar( raiz ) {
		raiz.querySelectorAll( '[data-asde-alta-persona]' ).forEach( function ( form ) {
			var secciones = form.querySelector( '[data-asde-secciones]' );
			var scouter = form.querySelector( 'input[name="roles[]"][value="asde_scouter"]' );
			if ( ! secciones || ! scouter || form.hasAttribute( 'data-asde-iniciado' ) ) {
				return;
			}
			form.setAttribute( 'data-asde-iniciado', '1' );
			var sinSeccion = secciones.querySelector( '[data-asde-sin-seccion]' );
			var educativas = Array.prototype.filter.call( secciones.querySelectorAll( 'input[name="secciones[]"]' ), function ( i ) {
				return i !== sinSeccion;
			} );
			var responsabilidades = Array.prototype.filter.call( form.querySelectorAll( 'input[name="roles[]"]' ), function ( i ) {
				return -1 !== RESPONSABILIDADES.indexOf( i.value );
			} );
			var scouterBloqueado = scouter.disabled; // Ya lo tiene o no es elegible.

			function actualizar() {
				var algunaResp = responsabilidades.some( function ( i ) {
					return i.checked;
				} );
				secciones.hidden = ! scouter.checked && ! algunaResp;
				if ( sinSeccion ) {
					if ( sinSeccion.checked ) {
						educativas.forEach( function ( i ) {
							i.checked = false;
						} );
						if ( ! scouterBloqueado ) {
							scouter.checked = false;
							scouter.disabled = true;
						}
					} else if ( ! scouterBloqueado ) {
						scouter.disabled = false;
					}
				}
			}

			scouter.addEventListener( 'change', actualizar );
			responsabilidades.forEach( function ( i ) {
				i.addEventListener( 'change', actualizar );
			} );
			educativas.forEach( function ( i ) {
				i.addEventListener( 'change', function () {
					if ( i.checked && sinSeccion ) {
						sinSeccion.checked = false;
					}
					actualizar();
				} );
			} );
			if ( sinSeccion ) {
				sinSeccion.addEventListener( 'change', actualizar );
			}
			actualizar();
		} );
	}

	window.asdeAlta = { iniciar: iniciar };
	iniciar( document );
} )();
