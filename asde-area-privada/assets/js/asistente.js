/**
 * Asistente para crear un evento (doc 66): enseña un paso cada vez, avisa de lo que habrá que hacer y resume al final.
 * Todos los textos vienen ya traducidos en el HTML (marcadores data-n, data-aviso, data-r); aquí solo se muestran u ocultan.
 * Sin JavaScript, el formulario enseña todos los pasos seguidos y se envía igual.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-asde-asistente]' );
	if ( ! form ) {
		return;
	}
	var pasos = Array.prototype.slice.call( form.querySelectorAll( '[data-paso]' ) );
	var migas = Array.prototype.slice.call( form.querySelectorAll( '[data-ir]' ) );
	var atras = form.querySelector( '[data-atras]' );
	var adelante = form.querySelector( '[data-adelante]' );
	var crear = form.querySelector( '[data-crear]' );
	var actual = 1;

	function campo( nombre ) {
		return form.elements[ nombre ];
	}
	function valor( nombre ) {
		var c = campo( nombre );
		if ( ! c ) {
			return '';
		}
		if ( c.length && 'SELECT' !== c.tagName ) {
			var marcado = form.querySelector( '[name="' + nombre + '"]:checked' );
			return marcado ? marcado.value : '';
		}
		return c.value;
	}
	function secciones() {
		return Array.prototype.slice.call( form.querySelectorAll( 'input[name="secciones[]"]:checked' ) );
	}
	function numero( nombre ) {
		return parseInt( valor( nombre ), 10 ) || 0;
	}
	function mostrar( selector, si ) {
		Array.prototype.forEach.call( form.querySelectorAll( selector ), function ( e ) {
			e.hidden = ! si;
		} );
	}
	function poner( nombre, texto ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-n="' + nombre + '"]' ), function ( e ) {
			e.textContent = texto;
		} );
	}
	function dia( texto ) {
		var d = new Date( texto + 'T00:00:00' );
		return isNaN( d.getTime() ) ? null : d;
	}
	function noches() {
		var a = dia( valor( 'inicio_fecha' ) );
		var b = dia( valor( 'fin_fecha' ) );
		return a && b && b > a ? Math.round( ( b - a ) / 864e5 ) : 0;
	}
	function fecha( d ) {
		return d.toLocaleDateString( ( form.dataset.lang || 'es' ).replace( '_', '-' ) );
	}

	// Paso 2: las noches, y el fin no puede ser anterior al inicio.
	function fechas() {
		var n = noches();
		var hay = '' !== valor( 'inicio_fecha' ) && '' !== valor( 'fin_fecha' );
		mostrar( '[data-duracion]', hay );
		mostrar( '[data-duracion] [data-dia]', hay && ! n );
		mostrar( '[data-duracion] [data-noche]', n > 0 );
		poner( 'noches', n );
		campo( 'fin_fecha' ).min = valor( 'inicio_fecha' );
	}

	// Paso 3: las secciones que van, los participantes y los monitores que hacen falta. Las cifras de la regla vienen de
	// Normativa (data-*); aquí solo está su fórmula, la misma que Normativa::monitores_necesarios.
	function participantes() {
		var van = secciones().map( function ( c ) {
			return c.value;
		} );
		var total = 0;
		var plazas = 0;
		var menores = 0;
		Array.prototype.forEach.call( form.querySelectorAll( '[data-seccion]' ), function ( fila ) {
			var va = -1 !== van.indexOf( fila.dataset.seccion );
			fila.hidden = ! va;
			var n = va ? numero( 'previstas_' + fila.dataset.seccion ) : 0;
			total += n;
			plazas += va ? numero( 'maximas_' + fila.dataset.seccion ) : 0;
			menores += -1 !== form.dataset.menoresSecciones.split( ',' ).indexOf( fila.dataset.seccion ) ? n : 0;
		} );
		// Como en el servidor: lo mayor entre lo previsto y las plazas.
		return { total: Math.max( total, plazas ), menores: menores };
	}
	function ratio() {
		var p = participantes().total;
		var por = parseInt( form.dataset.porMonitor, 10 );
		var base = p > 0 ? Math.max( 1, Math.floor( p / por ) + ( p % por >= parseInt( form.dataset.restoMonitor, 10 ) ? 1 : 0 ) ) : 0;
		var apoyo = respuesta( 'dependencia' ) ? Math.ceil( numero( 'dependencia12' ) / parseInt( form.dataset.dependencia12, 10 ) ) + numero( 'dependencia3' ) : 0;
		poner( 'participantes', p );
		poner( 'monitores', base + apoyo );
		mostrar( '[data-ratio]', p > 0 );
	}

	// Paso 4: las preguntas de sí o no abren sus datos y la lista «tendrás que».
	function respuesta( nombre ) {
		return '1' === valor( nombre );
	}
	function preguntas() {
		// Un «No» oculta los datos de su pregunta y los desactiva, para que no se envíen ni cuenten.
		[ 'internacional', 'agua', 'riesgo', 'dependencia' ].forEach( function ( r ) {
			mostrar( '[data-sub="' + r + '"]', respuesta( r ) );
			Array.prototype.forEach.call( form.querySelectorAll( '[data-sub="' + r + '"] input' ), function ( c ) {
				c.disabled = ! respuesta( r );
			} );
		} );
		var n = noches();
		var comunica = 'si' === valor( 'comunicacion' ) || ( 'no' !== valor( 'comunicacion' ) && n >= parseInt( form.dataset.noches, 10 ) && participantes().menores >= parseInt( form.dataset.menores, 10 ) );
		var inicio = dia( valor( 'inicio_fecha' ) );
		if ( inicio ) {
			inicio.setDate( inicio.getDate() - parseInt( form.dataset.dias, 10 ) );
			poner( 'limite', fecha( inicio ) );
		}
		var hay = {
			coste: respuesta( 'con_coste' ),
			comunicacion: comunica,
			internacional: respuesta( 'internacional' ),
			agua: respuesta( 'agua' ),
			riesgo: respuesta( 'riesgo' ),
			dependencia: respuesta( 'dependencia' )
		};
		var alguno = false;
		Object.keys( hay ).forEach( function ( k ) {
			mostrar( '[data-aviso="' + k + '"]', hay[ k ] );
			alguno = alguno || hay[ k ];
		} );
		mostrar( '[data-tendras]', alguno );
	}

	// Paso 6: lo escrito, con los textos de los campos.
	function resumen() {
		var tipo = form.querySelector( 'input[name="tipo"]:checked' );
		var nombre = tipo ? tipo.parentNode.textContent.trim() + ' · ' : '';
		var ini = dia( valor( 'inicio_fecha' ) );
		var fin = dia( valor( 'fin_fecha' ) );
		var d = {
			evento: nombre + valor( 'nombre' ),
			secciones: secciones().map( function ( c ) {
				return c.parentNode.textContent.trim();
			} ).join( ', ' ),
			fechas: ( ini ? fecha( ini ) + ' ' + valor( 'inicio_hora' ) : '' ) + ' → ' + ( fin ? fecha( fin ) + ' ' + valor( 'fin_hora' ) : '' ),
			lugar: valor( 'lugar' ) || '—',
			participantes: participantes().total,
			coste: form.querySelector( '[name="con_coste"]:checked + span' ).textContent
		};
		Object.keys( d ).forEach( function ( k ) {
			form.querySelector( '[data-r="' + k + '"]' ).textContent = d[ k ];
		} );
	}

	function ir( n, desplazar ) {
		actual = Math.min( pasos.length, Math.max( 1, n ) );
		pasos.forEach( function ( p, i ) {
			p.hidden = i + 1 !== actual;
		} );
		migas.forEach( function ( m, i ) {
			m.parentNode.className = i + 1 < actual ? 'asde-hecho' : ( i + 1 === actual ? 'asde-actual' : '' );
			if ( i + 1 === actual ) {
				m.setAttribute( 'aria-current', 'step' );
			} else {
				m.removeAttribute( 'aria-current' );
			}
		} );
		atras.hidden = 1 === actual;
		adelante.hidden = pasos.length === actual;
		crear.hidden = pasos.length !== actual;
		fechas();
		ratio();
		preguntas();
		if ( pasos.length === actual ) {
			resumen();
		}
		if ( desplazar ) {
			form.scrollIntoView( { block: 'start' } );
		}
	}

	// Un paso se da por bueno si sus campos obligatorios están bien y hay alguna sección elegida.
	function valido() {
		var campos = pasos[ actual - 1 ].querySelectorAll( 'input, select, textarea' );
		for ( var i = 0; i < campos.length; i++ ) {
			if ( ! campos[ i ].checkValidity() ) {
				campos[ i ].reportValidity();
				return false;
			}
		}
		if ( 1 === actual && ! secciones().length && 'grupo' !== valor( 'ambito' ) ) {
			var primera = form.querySelector( 'input[name="secciones[]"]' );
			if ( primera ) {
				primera.setCustomValidity( primera.dataset.aviso || '' );
				primera.reportValidity();
				primera.setCustomValidity( '' );
			}
			return false;
		}
		return true;
	}

	form.classList.add( 'asde-asistente--js' );
	form.querySelector( '[data-asde-pasos]' ).hidden = false;
	adelante.addEventListener( 'click', function () {
		if ( valido() ) {
			ir( actual + 1, true );
		}
	} );
	atras.addEventListener( 'click', function () {
		ir( actual - 1, true );
	} );
	migas.forEach( function ( m, i ) {
		m.addEventListener( 'click', function () {
			if ( i + 1 < actual || valido() ) {
				ir( i + 1, true );
			}
		} );
	} );
	// Intro en un campo de texto = «Siguiente» (no crea el evento a medias).
	form.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && 'INPUT' === e.target.tagName && 'submit' !== e.target.type && pasos.length !== actual ) {
			e.preventDefault();
			adelante.click();
		}
	} );
	form.addEventListener( 'change', function ( e ) {
		if ( 'tipo' === e.target.name ) {
			var coste = form.querySelector( '[name="con_coste"][value="' + e.target.dataset.coste + '"]' );
			if ( coste ) {
				coste.checked = true;
			}
			campo( 'comunicacion' ).value = e.target.dataset.comunicacion || 'auto';
		}
		if ( 'inicio_fecha' === e.target.name && ( '' === valor( 'fin_fecha' ) || valor( 'fin_fecha' ) < valor( 'inicio_fecha' ) ) ) {
			campo( 'fin_fecha' ).value = valor( 'inicio_fecha' );
		}
		fechas();
		ratio();
		preguntas();
	} );
	form.addEventListener( 'input', function () {
		ratio();
		preguntas();
	} );
	ir( parseInt( form.dataset.inicial, 10 ) || 1, false );
}() );
