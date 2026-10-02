<?php
/**
 * Plugin Name:       ASDE · Diagnóstico de tareas en segundo plano
 * Description:       Comprueba si este hosting permite las tareas en segundo plano del área privada: la petición interna del servidor a sí mismo, que no se corte al desconectarse quien la lanza (LiteSpeed sin «noabort») y WP-Cron. Herramientas → Diagnóstico segundo plano. No guarda datos personales ni se conecta al CRM: se puede instalar, usar y borrar.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ASDE Scouts de Balears
 * License:           GPL-2.0-or-later
 *
 * Este mismo fichero va dentro del plugin «ASDE Scouts de España - Gestor CRM
 * para Grupos Scouts» (carpeta herramientas/) y también se puede subir SOLO a
 * wp-content/plugins/ para comprobar un hosting antes de instalar el plugin.
 * Si están los dos, se carga una sola vez.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Asde_Diagnostico_Segundo_Plano', false ) ) :

	/**
	 * Las tareas en segundo plano del área privada (p. ej. corregir cientos de
	 * números de socio en el CRM) trabajan por tandas que se relanzan solas con
	 * una petición del servidor a sí mismo que NO espera respuesta, y tienen
	 * WP-Cron de respaldo. Aquí se prueba exactamente eso:
	 *
	 * 1. Petición interna esperando la respuesta (como «Salud del sitio»): ¿llega?
	 *    Si no, se explica el motivo (cortafuegos, contraseña del servidor, SSL…).
	 * 2. La misma petición SIN esperar, como la tarea real: tiene que llegar y
	 *    seguir trabajando unos segundos aunque quien la lanzó ya se haya ido.
	 *    LiteSpeed (Hostinger y otros) mata esos procesos si no se le indica
	 *    «noabort» en el .htaccess.
	 * 3. WP-Cron: se programa un evento de prueba y se comprueba que se ejecuta.
	 */
	final class Asde_Diagnostico_Segundo_Plano {

		const OPCION    = 'asde_diag_sp';
		const MARCA     = 'asde_diag_sp_m_'; // + llega | vivo | termina | cron
		const HOOK_CRON = 'asde_diag_sp_cron';
		const PING      = 'asde_diag_ping';  // También en el .htaccess recomendado.
		const DURACION  = 6;                 // s que la prueba 2 sigue trabajando tras la desconexión.
		const VENTANA   = 90;                // s como máximo por comprobación.
		const PAGINA    = 'asde-diagnostico-segundo-plano';

		public static function register() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'wp_ajax_asde_diag_iniciar', array( __CLASS__, 'ajax_iniciar' ) );
			add_action( 'wp_ajax_asde_diag_estado', array( __CLASS__, 'ajax_estado' ) );
			add_action( 'wp_ajax_nopriv_' . self::PING, array( __CLASS__, 'ajax_ping' ) );
			add_action( 'wp_ajax_' . self::PING, array( __CLASS__, 'ajax_ping' ) );
			add_action( self::HOOK_CRON, array( __CLASS__, 'cron' ) );
		}

		public static function menu() {
			add_management_page( 'Diagnóstico de tareas en segundo plano', 'Diagnóstico segundo plano', 'manage_options', self::PAGINA, array( __CLASS__, 'pagina' ) );
		}

		public static function url() {
			return admin_url( 'tools.php?page=' . self::PAGINA );
		}

		/** La acción va en la URL para que la regla «noabort» del .htaccess pueda limitarse a ella. */
		private static function url_ping() {
			return admin_url( 'admin-ajax.php?action=' . self::PING );
		}

		// ---------------------------------------------------------------
		// Comprobación
		// ---------------------------------------------------------------

		/** Lanza las tres pruebas. Lo que tarda de verdad se ve después con estado(). */
		public static function iniciar() {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::MARCA ) . '%' ) ); // phpcs:ignore WordPress.DB
			$previo = self::datos();
			if ( $previo ) {
				wp_clear_scheduled_hook( self::HOOK_CRON, array( $previo['id'] ) );
			}
			$d = array(
				'id'      => wp_generate_password( 12, false ),
				'token'   => wp_generate_password( 32, false ),
				'inicio'  => microtime( true ),
				'fecha'   => time(),
				'entorno' => self::entorno(),
				'directa' => array(),
				'cron'    => array(
					'programado' => false,
					'lanzado'    => false,
				),
			);
			update_option( self::OPCION, $d, false );

			$ssl = apply_filters( 'https_local_ssl_verify', false );

			// 1. Esperando la respuesta: si no llega, dice por qué.
			$t0           = microtime( true );
			$r            = wp_remote_post(
				self::url_ping(),
				array(
					'timeout'   => 10,
					'sslverify' => $ssl,
					'body'      => array(
						'token' => $d['token'],
						'modo'  => 'rapido',
					),
				)
			);
			$d['directa'] = array(
				'ms'     => (int) round( 1000 * ( microtime( true ) - $t0 ) ),
				'codigo' => is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r ),
				'error'  => is_wp_error( $r ) ? $r->get_error_message() : '',
				'pong'   => ! is_wp_error( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), 'asde-pong' ),
			);

			// 2. Sin esperar la respuesta, como la tarea real.
			wp_remote_post(
				self::url_ping(),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => $ssl,
					'body'      => array(
						'token' => $d['token'],
						'modo'  => 'largo',
					),
				)
			);

			// 3. WP-Cron (si está desactivado, lo lanza un cron del servidor: se mira si hay tareas atrasadas).
			if ( ! self::cron_desactivado() ) {
				$d['cron']['programado'] = true === wp_schedule_single_event( time() - 1, self::HOOK_CRON, array( $d['id'] ) );
				$d['cron']['lanzado']    = (bool) spawn_cron();
			}
			update_option( self::OPCION, $d, false );
			return $d;
		}

		/** Cómo va (y, cuando termina, se guarda el resultado y se cierra la puerta de la prueba). */
		public static function estado() {
			$d = self::datos();
			if ( ! $d ) {
				return null;
			}
			$m = self::marcas();
			if ( '' !== $d['token'] && ! $m['cron'] && ! self::cron_desactivado() ) {
				spawn_cron(); // El mismo empujón que da WordPress en cada visita.
			}
			$v = self::veredicto( $d, $m, microtime( true ) );
			if ( $v['terminado'] && '' !== $d['token'] ) {
				$d['token']     = '';
				$d['resultado'] = $v;
				update_option( self::OPCION, $d, false );
				wp_clear_scheduled_hook( self::HOOK_CRON, array( $d['id'] ) );
			}
			return $v;
		}

		/**
		 * Resultado a partir de las marcas de tiempo (función pura: se prueba sin servidor).
		 *
		 * @param array $d Datos de la comprobación (inicio, directa, entorno).
		 * @param array $m Marcas: llega, vivo, termina, cron (microtime o 0).
		 * @return array{terminado:bool, segundos:int, global:array, pruebas:array, htaccess:string, cron_servidor:bool, entorno:array}
		 */
		public static function veredicto( array $d, array $m, $ahora ) {
			$m       = $m + array(
				'llega'   => 0.0,
				'vivo'    => 0.0,
				'termina' => 0.0,
				'cron'    => 0.0,
			);
			$t       = $ahora - (float) $d['inicio'];
			$directa = (array) $d['directa'] + array(
				'codigo' => 0,
				'error'  => '',
				'pong'   => false,
				'ms'     => 0,
			);
			$entorno = (array) $d['entorno'] + array(
				'litespeed'        => false,
				'noabort'          => false,
				'cron_desactivado' => false,
				'atrasadas'        => 0,
			);

			// 1. ¿Llega la petición interna?
			if ( $directa['pong'] || $m['llega'] ) {
				$bucle = array( 'ok', 'Llega' . ( $directa['pong'] ? sprintf( ' (responde en %d ms).', $directa['ms'] ) : '.' ) );
			} elseif ( $t < 12 ) {
				$bucle = array( 'esperando', 'Comprobando…' );
			} else {
				$bucle = array( 'fallo', 'No llega: ' . self::explicar_error( $directa ) );
			}

			// 2. ¿Sigue trabajando aunque quien la lanzó se desconecte?
			if ( $m['termina'] ) {
				$corte = array( 'ok', sprintf( 'Sí: completó los %d s de prueba después de desconectarse quien la lanzó.', self::DURACION ) );
			} elseif ( 'fallo' === $bucle[0] ) {
				$corte = array( 'fallo', 'No se puede comprobar: la petición interna no llega.' );
			} elseif ( $m['llega'] && $ahora - max( $m['llega'], $m['vivo'] ) > 5 && $ahora - $m['llega'] > self::DURACION + 4 ) {
				$corte = array( 'fallo', sprintf( 'No: el servidor la cortó a los %d s de desconectarse quien la lanzó.', (int) round( max( 0, $m['vivo'] - $m['llega'] ) ) ) . ( $entorno['litespeed'] ? ' Es lo típico de LiteSpeed sin «noabort».' : '' ) );
			} elseif ( ! $m['llega'] && $t > 20 ) {
				$corte = array( 'fallo', 'No: la petición que no espera respuesta no llega a trabajar (la que espera, sí). El servidor la corta al desconectarse quien la lanza' . ( $entorno['litespeed'] ? ': es lo típico de LiteSpeed sin «noabort».' : '.' ) );
			} else {
				$corte = array( 'esperando', sprintf( 'Comprobando… (dura unos %d s)', self::DURACION ) );
			}

			// 3. WP-Cron.
			if ( $m['cron'] ) {
				$cron = array( 'ok', sprintf( 'Funciona: ejecutó el evento de prueba a los %d s.', (int) round( $m['cron'] - (float) $d['inicio'] ) ) );
			} elseif ( $entorno['cron_desactivado'] ) {
				$cron = $entorno['atrasadas']
					? array( 'fallo', sprintf( 'El WP-Cron automático está desactivado (DISABLE_WP_CRON) y hay %d tareas programadas atrasadas más de 15 minutos: el cron del servidor que debería lanzarlo no funciona.', $entorno['atrasadas'] ) )
					: array( 'ok', 'El WP-Cron automático está desactivado (DISABLE_WP_CRON), pero no hay tareas atrasadas: lo lanza un cron del servidor.' );
			} elseif ( $t < 75 ) {
				$cron = array( 'esperando', 'Esperando a WP-Cron… (si acaba de ejecutarse, puede tardar hasta un minuto)' );
			} else {
				$cron = array( 'fallo', sprintf( 'No ha ejecutado el evento de prueba en %d s.', (int) $t ) . ( $entorno['atrasadas'] ? sprintf( ' Además hay %d tareas programadas atrasadas.', $entorno['atrasadas'] ) : '' ) );
			}

			$pruebas   = array(
				'bucle' => array( $bucle[0], 'Petición interna del servidor a sí mismo', $bucle[1] ),
				'corte' => array( $corte[0], 'Sigue trabajando al desconectarse quien la lanza', $corte[1] ),
				'cron'  => array( $cron[0], 'WP-Cron (respaldo)', $cron[1] ),
			);
			$terminado = $t > self::VENTANA || ! in_array( 'esperando', array( $bucle[0], $corte[0], $cron[0] ), true );
			if ( $t > self::VENTANA ) {
				foreach ( $pruebas as $k => $p ) {
					if ( 'esperando' === $p[0] ) {
						$pruebas[ $k ][0] = 'fallo';
						$pruebas[ $k ][2] = 'Sin respuesta en ' . self::VENTANA . ' s.';
					}
				}
			}
			$ok = static function ( $clave ) use ( $pruebas ) {
				return 'ok' === $pruebas[ $clave ][0];
			};

			if ( ! $terminado ) {
				$global = array( 'esperando', 'Comprobando… Tarda entre 10 segundos y minuto y medio; no cierres esta página.' );
			} elseif ( $ok( 'bucle' ) && $ok( 'corte' ) ) {
				$global = array( 'ok', 'Las tareas en segundo plano funcionan en este servidor: se relanzan solas aunque se cierre la página.' . ( $ok( 'cron' ) ? '' : ' Solo falla WP-Cron, que es el respaldo: conviene arreglarlo (ver abajo).' ) );
			} elseif ( $ok( 'bucle' ) ) {
				$global = array( 'fallo', 'El servidor corta las tareas en cuanto se desconecta quien las lanza. Hay que añadir «noabort» al .htaccess (abajo). Mientras tanto, las tareas solo avanzan con el monitor abierto.' );
			} elseif ( $ok( 'cron' ) ) {
				$global = array( 'aviso', 'La petición interna no funciona, pero WP-Cron sí: las tareas avanzan con WP-Cron, más despacio (una tanda cada vez que se ejecuta el cron).' );
			} else {
				$global = array( 'fallo', 'Ni la petición interna ni WP-Cron funcionan: las tareas solo avanzan mientras alguien tenga el monitor abierto.' );
			}

			return array(
				'terminado'     => $terminado,
				'segundos'      => (int) max( 0, $t ),
				'global'        => $global,
				'pruebas'       => $pruebas,
				// La regla se propone cuando la petición llega pero el servidor la corta.
				'htaccess'      => $terminado && $ok( 'bucle' ) && ! $ok( 'corte' ) ? self::htaccess() : '',
				'cron_servidor' => $terminado && ! $ok( 'cron' ),
				'entorno'       => $entorno,
			);
		}

		/** Regla recomendada por LiteSpeed, limitada a las peticiones de las tareas y a wp-cron.php. */
		public static function htaccess() {
			return "# BEGIN ASDE tareas en segundo plano\n"
				. "<IfModule Litespeed>\n"
				. "RewriteEngine On\n"
				. "RewriteCond %{QUERY_STRING} (^|&)action=asde_(ap_tarea|diag_ping)(&|$)\n"
				. "RewriteRule ^wp-admin/admin-ajax\\.php$ - [E=noabort:1,E=noconntimeout:1]\n"
				. "RewriteRule ^wp-cron\\.php$ - [E=noabort:1,E=noconntimeout:1]\n"
				. "</IfModule>\n"
				. '# END ASDE tareas en segundo plano';
		}

		private static function explicar_error( array $r ) {
			if ( '' !== (string) $r['error'] ) {
				$e = (string) $r['error'];
				if ( false !== stripos( $e, 'timed out' ) || false !== stripos( $e, 'cURL error 28' ) ) {
					return 'se agota el tiempo (' . $e . '). El servidor no consigue conectarse consigo mismo: suele ser un cortafuegos o el nombre del dominio dentro del propio servidor. Consúltalo con el hosting.';
				}
				if ( false !== stripos( $e, 'SSL' ) || false !== stripos( $e, 'certificate' ) ) {
					return 'error del certificado SSL (' . $e . ').';
				}
				return $e . '.';
			}
			switch ( (int) $r['codigo'] ) {
				case 401:
					return 'responde 401: la web pide usuario y contraseña del servidor (p. ej. un entorno de pruebas protegido). Hay que permitir las peticiones del propio servidor.';
				case 403:
					return 'responde 403: un cortafuegos (del hosting, Wordfence u otro) bloquea las peticiones del propio servidor. Hay que permitirlas.';
				case 404:
					return 'responde 404: no encuentra admin-ajax.php (¿está bloqueado /wp-admin/?).';
				case 503:
					return 'responde 503: el servidor está saturado o en mantenimiento.';
				default:
					return (int) $r['codigo'] ? 'responde ' . (int) $r['codigo'] . ' en lugar de la respuesta esperada.' : 'no hay respuesta.';
			}
		}

		/** Datos del servidor que ayudan a interpretar el resultado (sin datos personales). */
		private static function entorno() {
			$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
			$fichero  = ABSPATH . '.htaccess';
			$reglas   = is_readable( $fichero ) ? (string) file_get_contents( $fichero ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$limite   = time() - 15 * MINUTE_IN_SECONDS;
			$atrasadas = 0;
			foreach ( (array) _get_cron_array() as $cuando => $hooks ) {
				if ( (int) $cuando < $limite ) {
					foreach ( (array) $hooks as $eventos ) {
						$atrasadas += count( (array) $eventos );
					}
				}
			}
			return array(
				'servidor'           => '' !== $software ? $software : '(desconocido)',
				'sapi'               => PHP_SAPI,
				'php'                => PHP_VERSION,
				'litespeed'          => false !== stripos( $software, 'litespeed' ) || 'litespeed' === PHP_SAPI || isset( $_SERVER['LSWS_EDITION'] ) || defined( 'LSCWP_V' ),
				'htaccess'           => '' !== $reglas,
				'noabort'            => false !== stripos( $reglas, 'noabort' ),
				'cron_desactivado'   => self::cron_desactivado(),
				'cron_alternativo'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'atrasadas'          => $atrasadas,
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			);
		}

		private static function cron_desactivado() {
			return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		}

		private static function datos() {
			wp_cache_delete( self::OPCION, 'options' );
			$d = get_option( self::OPCION );
			return is_array( $d ) && isset( $d['id'], $d['token'], $d['inicio'] ) ? $d : null;
		}

		/** Marcas de tiempo leídas directamente de la base de datos (las escriben otras peticiones). */
		private static function marcas() {
			global $wpdb;
			$m     = array(
				'llega'   => 0.0,
				'vivo'    => 0.0,
				'termina' => 0.0,
				'cron'    => 0.0,
			);
			$filas = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::MARCA ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $filas as $f ) {
				$k = substr( $f['option_name'], strlen( self::MARCA ) );
				if ( isset( $m[ $k ] ) ) {
					$m[ $k ] = (float) $f['option_value'];
				}
			}
			return $m;
		}

		private static function marcar( $que ) {
			update_option( self::MARCA . $que, sprintf( '%.4F', microtime( true ) ), false );
		}

		/** Borra todo lo de la herramienta (al desactivarla o desinstalar el plugin). */
		public static function limpiar() {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPCION ) . '%' ) ); // phpcs:ignore WordPress.DB
			wp_unschedule_hook( self::HOOK_CRON );
		}

		// ---------------------------------------------------------------
		// Peticiones
		// ---------------------------------------------------------------

		/** La petición de prueba (sin sesión): solo vale con el token y durante la comprobación. */
		public static function ajax_ping() {
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$modo  = isset( $_POST['modo'] ) ? sanitize_key( wp_unslash( $_POST['modo'] ) ) : '';     // phpcs:ignore WordPress.Security.NonceVerification
			$d     = self::datos();
			if ( ! $d || '' === $d['token'] || '' === $token || ! hash_equals( (string) $d['token'], $token ) || microtime( true ) - (float) $d['inicio'] > self::VENTANA ) {
				wp_die( '', '', array( 'response' => 403 ) );
			}
			if ( 'largo' !== $modo ) {
				wp_die( 'asde-pong', '', array( 'response' => 200 ) );
			}
			ignore_user_abort( true );
			self::marcar( 'llega' );
			for ( $i = 0; $i < self::DURACION; $i++ ) {
				sleep( 1 );
				self::marcar( 'vivo' );
			}
			self::marcar( 'termina' );
			wp_die( 'asde-pong', '', array( 'response' => 200 ) );
		}

		public static function cron( $id ) {
			$d = self::datos();
			if ( $d && hash_equals( (string) $d['id'], (string) $id ) ) {
				self::marcar( 'cron' );
			}
		}

		public static function ajax_iniciar() {
			check_ajax_referer( 'asde_diag', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( null, 403 );
			}
			self::iniciar();
			wp_send_json_success( self::estado() );
		}

		public static function ajax_estado() {
			check_ajax_referer( 'asde_diag', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( null, 403 );
			}
			$v = self::estado();
			if ( ! $v ) {
				wp_send_json_error( 'No hay ninguna comprobación.' );
			}
			wp_send_json_success( $v );
		}

		// ---------------------------------------------------------------
		// Página (Herramientas → Diagnóstico segundo plano)
		// ---------------------------------------------------------------

		public static function pagina() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$d      = self::datos();
			$ultimo = $d && ! empty( $d['resultado'] ) ? $d['resultado'] : null;
			?>
			<div class="wrap asde-diag">
				<h1>Diagnóstico de tareas en segundo plano</h1>
				<p>Las tareas largas del área privada (por ejemplo, corregir los números de socio de todo el grupo en el CRM) trabajan en segundo plano: por tandas cortas que se relanzan solas con una petición del servidor a sí mismo, y con WP-Cron de respaldo. Esta página comprueba si <strong>este hosting</strong> lo permite. No guarda datos personales ni se conecta al CRM.</p>
				<p><button type="button" class="button button-primary" id="asde-diag-boton">Comprobar ahora</button> <span class="asde-diag__tiempo" id="asde-diag-tiempo"></span></p>
				<div id="asde-diag-resultado" hidden></div>
				<style>
					.asde-diag__global { padding: 12px 16px; border-left: 4px solid #72aee6; background: #fff; margin: 16px 0; font-size: 14px; }
					.asde-diag__global--ok { border-color: #00a32a; } .asde-diag__global--fallo { border-color: #d63638; } .asde-diag__global--aviso { border-color: #dba617; }
					.asde-diag__pruebas { background: #fff; border: 1px solid #c3c4c7; max-width: 900px; }
					.asde-diag__pruebas li { display: flex; gap: 10px; margin: 0; padding: 10px 14px; border-bottom: 1px solid #f0f0f1; }
					.asde-diag__icono { flex: 0 0 1.5em; font-size: 16px; }
					.asde-diag pre { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 12px; max-width: 900px; overflow: auto; }
					.asde-diag table { max-width: 900px; }
				</style>
				<script>
				( function () {
					var boton = document.getElementById( 'asde-diag-boton' );
					var caja = document.getElementById( 'asde-diag-resultado' );
					var tiempo = document.getElementById( 'asde-diag-tiempo' );
					var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var nonce = <?php echo wp_json_encode( wp_create_nonce( 'asde_diag' ) ); ?>;
					var ultimo = <?php echo wp_json_encode( $ultimo ); ?>;
					var fechaUltimo = <?php echo wp_json_encode( $ultimo ? 'Última comprobación: ' . wp_date( 'j/n/Y H:i', (int) $d['fecha'] ) . '.' : '' ); ?>;
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
							caja.appendChild( el( 'p', '', 'Crea un cron del servidor que llame a ' + <?php echo wp_json_encode( site_url( 'wp-cron.php' ) ); ?> + ' cada 5 minutos (en Hostinger: hPanel → Avanzado → Cron jobs). Si el problema es el corte de LiteSpeed, la regla de arriba también lo arregla para wp-cron.php.' ) );
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
				</script>
			</div>
			<?php
		}
	}

	Asde_Diagnostico_Segundo_Plano::register();
	if ( function_exists( 'register_deactivation_hook' ) ) {
		register_deactivation_hook( __FILE__, array( 'Asde_Diagnostico_Segundo_Plano', 'limpiar' ) );
	}

endif;
