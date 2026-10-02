/**
 * wp-admin → Grupos Scouts → Colores: selectores de color y vista previa en vivo.
 *
 * Cada vez que cambia un color se pide al servidor (Frontend\Colores) el CSS y los avisos de
 * contraste de esa combinación y se pintan en la vista previa; así lo que se ve es exactamente
 * lo que se va a guardar. Un campo vacío es «el color de siempre».
 */
( function ( $ ) {
	'use strict';

	var cfg = window.asdeColores;
	var $form = $( '#asde-colores-form' );
	if ( ! cfg || ! $form.length ) {
		return;
	}
	var $css = $( '#asde-colores-css' );
	var $avisos = $( '#asde-colores-avisos' );
	var temporizador = null;
	var peticion = 0;

	function colores() {
		var datos = {};
		$form.find( 'input.asde-color' ).each( function () {
			var valor = $.trim( $( this ).val() );
			if ( valor ) {
				datos[ this.name.replace( /^colores\[|\]$/g, '' ) ] = valor;
			}
		} );
		return datos;
	}

	function actualizar() {
		var esta = ++peticion;
		$.post( cfg.ajax, { action: cfg.accion, nonce: cfg.nonce, colores: colores() } ).done( function ( r ) {
			if ( esta !== peticion || ! r || ! r.success ) {
				return;
			}
			$css.text( r.data.css || '' );
			if ( r.data.avisos && r.data.avisos.length ) {
				$avisos.find( 'p' ).text( r.data.avisos.join( ' ' ) );
				$avisos.prop( 'hidden', false );
			} else {
				$avisos.prop( 'hidden', true );
			}
		} );
	}

	function programar() {
		window.clearTimeout( temporizador );
		temporizador = window.setTimeout( actualizar, 200 );
	}

	$form.find( 'input.asde-color' ).wpColorPicker( {
		change: function ( evento, ui ) {
			$( evento.target ).val( ui.color.toString() );
			programar();
		},
		clear: programar,
	} );
	// Escribir el valor a mano también actualiza la vista previa.
	$form.on( 'input change', 'input.asde-color', programar );
} )( window.jQuery );
