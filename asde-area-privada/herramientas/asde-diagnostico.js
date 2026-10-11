/* Herramientas → Diagnóstico segundo plano (v0.31: antes, un <script> en la página). Los datos, en window.asdeDiag (Asde_Diagnostico_Segundo_Plano::recursos). */
( function () {
	var boton = document.getElementById( 'asde-diag-boton' );
	var caja = document.getElementById( 'asde-diag-resultado' );
	var tiempo = document.getElementById( 'asde-diag-tiempo' );
	var cfg = window.asdeDiag || {};
	var ajax = cfg.ajax;
	var nonce = cfg.nonce;
	var ultimo = cfg.ultimo;
	var fechaUltimo = cfg.fechaUltimo;
	var ICONOS = { ok: '✅', fallo: '❌', aviso: '⚠️', esperando: '⏳' };
	var SI_NO = function ( v ) { return v ? 'sí' : 'no'; };

	function el( tag, clase, texto ) {
		var e = document.createElement( tag );
		if ( clase ) { e.className = clase; }
		if ( undefined !== texto ) { e.textContent = texto; }
		return e;
	}
	function pintar( v, fecha ) {
		caja.hidden = false;
		caja.textContent = '';
		if ( fecha ) {
			caja.appendChild( el( 'p', 'description', fecha ) );
		}
		caja.appendChild( el( 'div', 'asde-diag__global asde-diag__global--' + v.global[0], ICONOS[ v.global[0] ] + ' ' + v.global[1] ) );
		var ul = el( 'ul', 'asde-diag__pruebas' );
		Object.keys( v.pruebas ).forEach( function ( k ) {
			var p = v.pruebas[ k ];
			var li = el( 'li' );
			li.appendChild( el( 'span', 'asde-diag__icono', ICONOS[ p[0] ] ) );
			var t = el( 'div' );
			t.appendChild( el( 'strong', '', p[1] ) );
			t.appendChild( el( 'div', '', p[2] ) );
			li.appendChild( t );
			ul.appendChild( li );
		} );
		caja.appendChild( ul );
		if ( v.htaccess ) {
			caja.appendChild( el( 'h2', '', 'Cómo arreglarlo: «noabort» en el .htaccess' ) );
			caja.appendChild( el( 'p', '', 'Añade estas líneas al PRINCIPIO del fichero .htaccess de la raíz de la web (antes de «# BEGIN WordPress»). En Hostinger: hPanel → Archivos → Administrador de archivos → public_html/.htaccess. Guarda y vuelve a comprobar. Es la regla que recomienda LiteSpeed, limitada a las tareas del área privada y a wp-cron.php.' ) );
			caja.appendChild( el( 'pre', '', v.htaccess ) );
		}
		if ( v.cron_servidor ) {
			caja.appendChild( el( 'h2', '', 'Si WP-Cron falla' ) );
			caja.appendChild( el( 'p', '', 'Crea un cron del servidor que llame a ' + cfg.cron + ' cada 5 minutos (en Hostinger: hPanel → Avanzado → Cron jobs). Si el problema es el corte de LiteSpeed, la regla de arriba también lo arregla para wp-cron.php.' ) );
		}
		var e = v.entorno || {};
		var tabla = el( 'table', 'widefat striped' );
		[
			[ 'Servidor web', e.servidor ],
			[ 'PHP', ( e.php || '' ) + ' (' + ( e.sapi || '' ) + ')' ],
			[ '¿LiteSpeed?', SI_NO( e.litespeed ) ],
			[ '¿«noabort» en el .htaccess?', e.htaccess ? SI_NO( e.noabort ) : 'no se puede leer el .htaccess' ],
			[ 'WP-Cron automático', e.cron_desactivado ? 'desactivado (DISABLE_WP_CRON)' : ( e.cron_alternativo ? 'activado (ALTERNATE_WP_CRON)' : 'activado' ) ],
			[ 'Tareas programadas atrasadas (más de 15 min)', String( e.atrasadas ) ],
			[ 'Límite de tiempo de PHP', e.max_execution_time ? e.max_execution_time + ' s' : 'sin límite' ]
		].forEach( function ( fila ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'th', '', fila[0] ) );
			tr.appendChild( el( 'td', '', fila[1] ) );
			tabla.appendChild( tr );
		} );
		caja.appendChild( el( 'h2', '', 'Datos del servidor' ) );
		caja.appendChild( tabla );
		tiempo.textContent = v.terminado ? '' : v.segundos + ' s…';
	}
	function pedir( accion ) {
		var datos = new FormData();
		datos.append( 'action', accion );
		datos.append( 'nonce', nonce );
		return fetch( ajax, { method: 'POST', body: datos, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	}
	function seguir() {
		pedir( 'asde_diag_estado' ).then( function ( r ) {
			if ( r && r.success ) {
				pintar( r.data );
				if ( ! r.data.terminado ) {
					window.setTimeout( seguir, 1500 );
					return;
				}
			}
			boton.disabled = false;
		} ).catch( function () { window.setTimeout( seguir, 3000 ); } );
	}
	boton.addEventListener( 'click', function () {
		boton.disabled = true;
		tiempo.textContent = 'Lanzando las pruebas…';
		pedir( 'asde_diag_iniciar' ).then( function ( r ) {
			if ( r && r.success ) {
				pintar( r.data );
				window.setTimeout( seguir, 1500 );
			} else {
				tiempo.textContent = 'No se ha podido iniciar la comprobación.';
				boton.disabled = false;
			}
		} ).catch( function () {
			tiempo.textContent = 'No se ha podido iniciar la comprobación.';
			boton.disabled = false;
		} );
	} );
	if ( ultimo ) {
		pintar( ultimo, fechaUltimo );
	}
} )();
