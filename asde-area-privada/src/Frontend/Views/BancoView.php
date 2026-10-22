<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Codificacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\CsvBanco;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

defined( 'ABSPATH' ) || exit;

/**
 * Tesorería → Banco: importar los movimientos de la cuenta (fichero Norma 43 o
 * CSV descargado de la banca online) y conciliar los ingresos con las familias.
 *
 * El fichero NO se guarda: se lee, se enseña una vista previa (en un transient
 * cifrado de 30 minutos) y al confirmar se guardan solo los movimientos nuevos.
 * Solo Secretaría, Coordinación y Tesorería (configuración de Tesorería).
 */
final class BancoView extends TesoreriaBaseView {

	private const PREVIA       = 'asde_ap_banco_previa_';
	private const MAPEOS       = 'asde_ap_banco_csv';
	private const MAX_BYTES    = 5 * MB_IN_BYTES;
	private const EXTENSIONES  = array( 'n43', 'q43', 'aeb', 'txt', 'dat', 'csv' );

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP_CONFIG ) ) {
			return $this->denegado( asde_t( 'El banco solo lo ven Secretaría, Coordinación y Tesorería.' ) );
		}
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::BANCO ) ) {
			return $this->denegado( asde_t( 'El banco no está activo en tu grupo.' ) );
		}
		$ronda = $this->ronda();
		TiposMovimiento::sembrar();
		if ( '1' === $this->get( 'tipos' ) ) {
			return $this->layout( 'tesoreria', $this->subnav( 'banco', $ronda ) . $this->tpl( 'tesoreria/banco-tipos', array( 'tipos' => TiposMovimiento::todos(), 'usos' => TiposMovimiento::usos(), 'url' => $this->url( 'banco' ), 'volver' => $this->volver_tipos() ) ) );
		}
		// Marcar como revisado (uno o varios) con su tipo: también se abre en la ventana emergente.
		$revisar = $this->get( 'revisar' );
		if ( '' !== $revisar ) {
			$cuerpo = 'hecho' === $revisar
				? $this->tpl( 'tesoreria/banco-revisado', array( 'url' => $this->url( 'banco' ) ) )
				: $this->revisar();
			return $this->layout( 'tesoreria', ( self::es_modal() ? '' : $this->subnav( 'banco', $ronda ) ) . $cuerpo );
		}
		$token = $this->get( 'previa' );
		if ( $token ) {
			$previa = $this->previa( $token );
			if ( $previa ) {
				return $this->layout( 'tesoreria', $this->subnav( 'banco', $ronda ) . $this->render_previa( $token, $previa ) );
			}
			Notices::add( 'error', asde_t( 'La vista previa ha caducado. Vuelve a subir el fichero.' ) );
		}

		$filtros = array(
			'estado' => isset( BancoTable::ESTADOS[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : ( '' === $this->get( 'estado' ) && ! isset( $_GET['estado'] ) ? BancoTable::PENDIENTE : '' ), // phpcs:ignore WordPress.Security.NonceVerification
			'tipo'   => in_array( $this->get( 'tipo' ), array( 'ingresos', 'gastos' ), true ) ? $this->get( 'tipo' ) : '',
			'q'      => $this->get( 'q' ),
			'clase'  => 'sin' === $this->get( 'clase' ) || ctype_digit( $this->get( 'clase' ) ) ? $this->get( 'clase' ) : '',
		);
		$movs     = BancoTable::movimientos( $filtros );
		$familias = $this->familias();
		foreach ( $movs as &$m ) {
			$m['sugerencia'] = BancoTable::PENDIENTE === $m['estado'] ? Conciliacion::sugerencia( $m, $familias['por_numero'] ) : null;
		}
		unset( $m );

		$tabla  = new Tabla(
			array(
				'fecha'    => static fn( $m ) => $m['fecha'],
				'concepto' => static fn( $m ) => trim( $m['ordenante'] . ' ' . $m['concepto'] ),
				'importe'  => static fn( $m ) => (float) $m['importe'],
				'estado'   => static fn( $m ) => asde_t( BancoTable::ESTADOS[ $m['estado'] ] ?? $m['estado'] ),
			),
			'fecha',
			'desc'
		);
		$total  = count( $movs );
		$sugeridas = count( array_filter( $movs, static fn( $m ) => ! empty( $m['sugerencia']['fiable'] ) ) );
		$pagina = $tabla->aplicar( $movs );

		return $this->layout(
			'tesoreria',
			$this->subnav( 'banco', $ronda ) . $this->tpl(
				'tesoreria/banco',
				array(
					'movs'       => $pagina,
					'total'      => $total,
					'sugeridas'  => $sugeridas,
					'tabla'      => $tabla,
					'filtros'    => $filtros,
					'familias'   => $familias,
					'resumen'    => BancoTable::resumen(),
					'lotes'      => BancoTable::lotes( 5 ),
					'rondas'     => Config::rondas(),
					'tipos'      => TiposMovimiento::todos(),
					'eventos'    => \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::EVENTOS ) ? array_column( \AsdeBalears\AreaPrivada\Eventos\EventosTable::todos(), 'nombre', 'id' ) : array(),
					'url'        => $this->url( 'banco' ),
					'url_fam'    => $this->url( 'familias' ),
				)
			)
		);
	}

	/** Ventana «Marcar como revisado»: los movimientos elegidos (?m[]=id), cada uno con su tipo. */
	private function revisar(): string {
		$ids  = isset( $_GET['m'] ) && is_array( $_GET['m'] ) ? array_map( 'absint', wp_unslash( $_GET['m'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- solo números (absint) para pintar un formulario
		$movs = array_values( array_filter( BancoTable::movimientos( array( 'ids' => $ids ) ), static fn( $m ) => BancoTable::CONCILIADO !== $m['estado'] ) );
		$url  = $this->url( 'banco' );
		return $this->tpl(
			'tesoreria/banco-revisar',
			array(
				'movs'   => $movs,
				'tipos'  => TiposMovimiento::todos(),
				// v0.52: a qué evento y a qué familia se asocia (los dos opcionales).
				'eventos'  => \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::EVENTOS ) ? array_column( \AsdeBalears\AreaPrivada\Eventos\EventosTable::todos(), 'nombre', 'id' ) : array(),
				'familias' => $this->familias()['etiquetas'],
				'url'    => add_query_arg( 'revisar', '1', $url ),
				'volver' => wp_validate_redirect( esc_url_raw( $this->get( 'volver' ) ), $url ) ?: $url,
				'conciliados' => count( $ids ) - count( $movs ),
				'url_tipos'   => add_query_arg( 'tipos', '1', $url ),
				// v0.34: el «+» de cada fila. Con JavaScript crea el tipo en una ventana pequeña; sin él, va a los tipos y vuelve aquí.
				'url_mas'     => add_query_arg(
					array(
						'tipos'  => '1',
						'volver' => rawurlencode( $this->url_actual( add_query_arg( 'revisar', '1', $url ) ) ),
					),
					$url
				),
			)
		);
	}

	/** Adónde vuelve la página de tipos (v0.34): esc_url_raw y no get(), que borraría los %5B%5D de los m[] de la dirección. */
	private function volver_tipos(): string {
		return isset( $_GET['volver'] ) ? (string) wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['volver'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo una dirección de vuelta de este sitio
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_CONFIG ) || ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::BANCO ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		$volver = $this->url_actual( $this->url( 'banco' ) ); // La misma vista (filtros y página) tras conciliar o deshacer.

		switch ( $accion ) {
			case 'analizar':
				check_admin_referer( 'asde_banco_analizar' );
				$this->analizar();
				break;

			case 'mapear':
				check_admin_referer( 'asde_banco_previa' );
				$this->mapear();
				break;

			case 'importar':
				check_admin_referer( 'asde_banco_previa' );
				$this->importar();
				break;

			case 'cancelar':
				check_admin_referer( 'asde_banco_previa' );
				delete_transient( $this->clave_previa( $this->post( 'token' ) ) );
				$this->redirect( $this->url( 'banco' ) );
				break;

			case 'conciliar':
				$id = (int) $this->post( 'mov' );
				check_admin_referer( 'asde_banco_mov_' . $id );
				$r = Conciliacion::conciliar( $id, (int) $this->post( 'familia' ), $this->post( 'ronda_pago' ) );
				is_wp_error( $r )
					? Notices::add( 'error', esc_html( $r->get_error_message() ) )
					: Notices::add( 'success', asde_t( 'Ingreso conciliado: se ha registrado el pago en el control de pagos de la familia.' ) );
				$this->redirect( $volver );
				break;

			case 'conciliar_sugeridas':
				check_admin_referer( 'asde_banco_sugeridas' );
				$familias = $this->familias()['por_numero'];
				$hechas   = 0;
				foreach ( BancoTable::movimientos( array( 'estado' => BancoTable::PENDIENTE, 'tipo' => 'ingresos' ) ) as $m ) {
					$s = Conciliacion::sugerencia( $m, $familias );
					if ( $s && $s['fiable'] && ! is_wp_error( Conciliacion::conciliar( (int) $m['id'], (int) $s['familia']['id'] ) ) ) {
						++$hechas;
					}
				}
				Notices::add( 'success', sprintf( asde_t( '%d ingresos conciliados por su referencia de familia.' ), $hechas ) );
				$this->redirect( $volver );
				break;

			case 'deshacer':
				$id = (int) $this->post( 'mov' );
				check_admin_referer( 'asde_banco_mov_' . $id );
				$r = Conciliacion::deshacer( $id );
				is_wp_error( $r )
					? Notices::add( 'error', esc_html( $r->get_error_message() ) )
					: Notices::add( 'success', asde_t( 'Movimiento de nuevo pendiente (si tenía un pago, se ha anulado).' ) );
				$this->redirect( $volver );
				break;

			case 'revisar':
				// Uno o varios movimientos: revisados (no son cuota) con su tipo (v0.20).
				check_admin_referer( 'asde_banco_revisar' );
				$tipos   = $this->post_mapa( 'tipo' );
				$eventos = $this->post_mapa( 'evento' );
				$fams    = $this->post_mapa( 'familia' );
				$nota    = $this->post( 'nota' );
				$hechos  = 0;
				$errores = array();
				foreach ( $tipos as $id => $tipo ) {
					$r = Conciliacion::ignorar( $id, (int) $tipo, $nota, (int) ( $eventos[ $id ] ?? 0 ), (int) ( $fams[ $id ] ?? 0 ) );
					if ( is_wp_error( $r ) ) {
						$m         = BancoTable::obtener( $id );
						$errores[] = ( $m ? wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ' · ' . Calculadora::euros( $m['importe'] ) . ': ' : '' ) . $r->get_error_message();
					} else {
						++$hechos;
					}
				}
				if ( $hechos ) {
					Notices::add( 'success', esc_html( sprintf( asde_n( '%d movimiento marcado como revisado.', '%d movimientos marcados como revisados.', $hechos ), $hechos ) ) );
				}
				foreach ( $errores as $e ) {
					Notices::add( 'error', esc_html( $e ) );
				}
				if ( ! $tipos ) {
					Notices::add( 'error', asde_t( 'No has elegido ningún movimiento.' ) );
				}
				$lista = wp_validate_redirect( esc_url_raw( $this->post( 'volver' ) ), $this->url( 'banco' ) );
				$this->redirect( Router::pide_modal() ? $this->url( 'banco', array( 'revisar' => 'hecho' ) ) : $lista );
				break;

			case 'tipo_crear':
			case 'tipo_cambiar':
			case 'tipo_borrar':
				check_admin_referer( 'asde_banco_tipos' );
				$id = (int) $this->post( 'tipo' );
				$r  = 'tipo_crear' === $accion
					? TiposMovimiento::crear( $this->post( 'nombre' ), $this->post( 'naturaleza' ) )
					: ( 'tipo_cambiar' === $accion ? TiposMovimiento::cambiar( $id, $this->post( 'nombre' ), $this->post( 'naturaleza' ) ) : TiposMovimiento::borrar( $id ) );
				// v0.34: el «+» de «Marcar como revisado» crea el tipo sin salir de la ventana y recibe el tipo en JSON.
				$json = 'tipo_crear' === $accion && Router::pide_json();
				if ( is_wp_error( $r ) ) {
					if ( $json ) {
						wp_send_json_error( array( 'mensaje' => $r->get_error_message() ) );
					}
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
				} else {
					Logger::log( 'banco_' . $accion, 'banco_tipo', (string) ( 'tipo_crear' === $accion ? $r : $id ) );
					if ( $json ) {
						$t = TiposMovimiento::obtener( (int) $r );
						wp_send_json_success(
							array(
								'id'         => (int) $r,
								'nombre'     => (string) ( $t['nombre'] ?? '' ),
								'naturaleza' => (string) ( $t['naturaleza'] ?? '' ),
							)
						);
					}
					Notices::add( 'success', 'tipo_crear' === $accion ? asde_t( 'Tipo creado.' ) : ( 'tipo_cambiar' === $accion ? asde_t( 'Tipo guardado.' ) : asde_t( 'Tipo borrado.' ) ) );
				}
				$a_tipos = $this->volver_tipos();
				$this->redirect( $this->url( 'banco', array( 'tipos' => '1' ) + ( '' !== $a_tipos ? array( 'volver' => rawurlencode( $a_tipos ) ) : array() ) ) );
				break;

			default:
				Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
				$this->redirect( $volver );
		}
	}

	// ---------------------------------------------------------------------
	// Importación
	// ---------------------------------------------------------------------

	private function analizar(): void {
		$f = $_FILES['fichero'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba antes (check_admin_referer en handle_post); del fichero solo se usan datos validados con is_uploaded_file()
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			$this->volver_con_error( UPLOAD_ERR_INI_SIZE === ( $f['error'] ?? 0 ) || UPLOAD_ERR_FORM_SIZE === ( $f['error'] ?? 0 ) ? asde_t( 'El fichero es demasiado grande.' ) : asde_t( 'Elige el fichero de movimientos del banco.' ) );
		}
		$nombre = sanitize_file_name( wp_unslash( (string) $f['name'] ) );
		$ext    = strtolower( pathinfo( $nombre, PATHINFO_EXTENSION ) );
		if ( (int) $f['size'] > self::MAX_BYTES ) {
			$this->volver_con_error( asde_t( 'El fichero es demasiado grande (máximo 5 MB).' ) );
		}
		if ( ! in_array( $ext, self::EXTENSIONES, true ) ) {
			$this->volver_con_error( asde_t( 'Formato no admitido: sube el fichero Norma 43 (.n43, .q43, .aeb, .txt) o un CSV.' ) );
		}
		$contenido = (string) file_get_contents( (string) $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( Codificacion::es_binario( $contenido ) ) {
			$this->volver_con_error( asde_t( 'Parece un fichero Excel u otro formato binario. Descárgalo del banco como Norma 43 o como CSV.' ) );
		}

		$formato = in_array( $this->post( 'formato' ), array( 'n43', 'csv' ), true ) ? $this->post( 'formato' ) : ( Norma43::parece( $contenido ) ? 'n43' : 'csv' );
		$cod     = isset( Codificacion::LISTA[ $this->post( 'codificacion' ) ] ) ? $this->post( 'codificacion' ) : 'auto';
		$previa  = array(
			'formato'      => $formato,
			'codificacion' => $cod,
			'fichero'      => $nombre,
			'hash'         => hash( 'sha256', $contenido ),
			'contenido'    => base64_encode( $contenido ), // Solo para volver a leerlo al elegir columnas (CSV).
		);
		$previa['lectura'] = $this->leer( $previa );
		$this->guardar_previa( $previa );
	}

	private function mapear(): void {
		$token  = $this->token_valido( $this->post( 'token' ) );
		$previa = $this->previa( $token );
		if ( ! $previa ) {
			$this->volver_con_error( asde_t( 'La vista previa ha caducado. Vuelve a subir el fichero.' ) );
		}
		$mapeo = array();
		foreach ( array_keys( CsvBanco::CAMPOS ) as $campo ) {
			$v = $this->post( 'col_' . $campo );
			if ( '' !== $v && ctype_digit( $v ) ) {
				$mapeo[ $campo ] = (int) $v;
			}
		}
		$previa['mapeo']   = $mapeo;
		$previa['lectura'] = $this->leer( $previa );
		if ( ! $previa['lectura']['necesita_mapeo'] && $previa['lectura']['movimientos'] ) {
			// Se recuerda para los próximos ficheros con las mismas columnas.
			$todos = get_option( self::MAPEOS, array() );
			$todos = is_array( $todos ) ? $todos : array();
			$todos[ Grupo::numero() ][ $previa['lectura']['firma'] ] = $mapeo;
			update_option( self::MAPEOS, $todos, false );
		}
		delete_transient( $this->clave_previa( $token ) );
		$this->guardar_previa( $previa );
	}

	private function importar(): void {
		$token  = $this->token_valido( $this->post( 'token' ) );
		$previa = $this->previa( $token );
		if ( ! $previa ) {
			$this->volver_con_error( asde_t( 'La vista previa ha caducado. Vuelve a subir el fichero.' ) );
		}
		$lectura = $previa['lectura'];
		if ( $lectura['errores'] || ! $lectura['movimientos'] ) {
			$this->volver_con_error( asde_t( 'Este fichero tiene errores y no se puede importar.' ) );
		}
		// Se vuelve a comprobar qué es nuevo: puede haberse importado algo mientras tanto.
		$lectura['movimientos'] = BancoTable::clasificar( $lectura['movimientos'] );
		$r                      = BancoTable::importar( $lectura, $previa['formato'], $previa['fichero'], $previa['hash'] );
		delete_transient( $this->clave_previa( $token ) );
		if ( is_wp_error( $r ) ) {
			$this->volver_con_error( $r->get_error_message() );
		}
		Logger::log(
			'banco_importado',
			'banco_lote',
			(string) $r['lote_id'],
			array(
				'formato'    => $previa['formato'],
				'hash'       => $previa['hash'],
				'nuevos'     => $r['nuevos'],
				'duplicados' => $r['duplicados'],
			)
		);
		Notices::add( 'success', sprintf( asde_t( 'Importados %d movimientos nuevos%s. El fichero no se ha guardado.' ), $r['nuevos'], $r['duplicados'] ? sprintf( asde_t( ' (%d ya estaban importados y se han ignorado)' ), $r['duplicados'] ) : '' ) );
		$this->redirect( $this->url( 'banco' ) );
	}

	/** Lee el fichero de la vista previa con el formato, la codificación y (en CSV) las columnas elegidas. */
	private function leer( array $previa ): array {
		$contenido = (string) base64_decode( $previa['contenido'] );
		if ( 'n43' === $previa['formato'] ) {
			$lectura = Norma43::leer( $contenido, $previa['codificacion'] );
			$lectura += array(
				'columnas'       => array(),
				'mapeo'          => array(),
				'necesita_mapeo' => false,
				'firma'          => '',
			);
		} else {
			$mapeo = $previa['mapeo'] ?? null;
			if ( null === $mapeo ) {
				$auto  = CsvBanco::leer( $contenido, null, $previa['codificacion'] );
				$guard = get_option( self::MAPEOS, array() );
				$mapeo = $auto['firma'] ? ( $guard[ Grupo::numero() ][ $auto['firma'] ] ?? null ) : null;
				if ( null === $mapeo ) {
					$lectura = $auto;
				}
			}
			$lectura = $lectura ?? CsvBanco::leer( $contenido, $mapeo, $previa['codificacion'] );
		}
		$lectura['movimientos'] = BancoTable::clasificar( $lectura['movimientos'] );
		$lectura['ya_importado'] = BancoTable::lote_por_hash( $previa['hash'] );
		return $lectura;
	}

	private function guardar_previa( array $previa ): void {
		$token = bin2hex( random_bytes( 16 ) );
		// Incluye nombres de personas (ordenantes): cifrado y solo 30 minutos.
		set_transient( $this->clave_previa( $token ), Crypto::encrypt( (string) wp_json_encode( $previa ) ), 30 * MINUTE_IN_SECONDS );
		$this->redirect( $this->url( 'banco', array( 'previa' => $token ) ) );
	}

	/** Cada vista previa es solo de quien subió el fichero. */
	private function clave_previa( string $token ): string {
		return self::PREVIA . get_current_user_id() . '_' . $this->token_valido( $token );
	}

	private function previa( string $token ): ?array {
		if ( '' === $this->token_valido( $token ) ) {
			return null;
		}
		$enc = get_transient( $this->clave_previa( $token ) );
		$raw = is_string( $enc ) ? Crypto::decrypt( $enc ) : null;
		$p   = $raw ? json_decode( $raw, true ) : null;
		return is_array( $p ) ? $p : null;
	}

	/** Solo tokens propios con formato válido; devuelve la parte de la clave del transient. */
	private function token_valido( string $token ): string {
		return preg_match( '/^[0-9a-f]{32}$/', $token ) ? $token : '';
	}

	/** @return never */
	private function volver_con_error( string $mensaje ): void {
		Notices::add( 'error', esc_html( $mensaje ) );
		$this->redirect( $this->url( 'banco' ) );
	}

	private function render_previa( string $token, array $previa ): string {
		$lectura = $previa['lectura'];
		$tabla   = new Tabla(
			array(
				'fecha'    => static fn( $m ) => $m['fecha'],
				'concepto' => static fn( $m ) => trim( $m['ordenante'] . ' ' . $m['concepto'] ),
				'importe'  => static fn( $m ) => (float) $m['importe'],
				'estado'   => static fn( $m ) => $m['duplicado'] ? 2 : ( $m['posible_duplicado'] ? 1 : 0 ),
			),
			'fecha'
		);
		$movs  = $lectura['movimientos'];
		$total = count( $movs );
		return $this->tpl(
			'tesoreria/banco-previa',
			array(
				'token'    => $token,
				'previa'   => $previa,
				'lectura'  => $lectura,
				'movs'     => $tabla->aplicar( $movs ),
				'total'    => $total,
				'nuevos'   => count( array_filter( $movs, static fn( $m ) => ! $m['duplicado'] ) ),
				'posibles' => count( array_filter( $movs, static fn( $m ) => $m['posible_duplicado'] ) ),
				'tabla'    => $tabla,
				'url'      => $this->url( 'banco' ),
			)
		);
	}

	/**
	 * Familias del grupo con una etiqueta legible (nº · apellidos (nombres)),
	 * leyendo los nombres del CRM en una sola consulta.
	 *
	 * @return array{por_numero: array<string, array>, etiquetas: array<int, string>}
	 */
	private function familias(): array {
		$todas     = FamiliasTable::todas();
		$etiquetas = array();
		$personas  = array();
		$repo      = PersonaRepository::make();
		if ( $repo && $todas ) {
			try {
				$personas = $repo->obtener_varias( array_merge( ...array_values( array_map( static fn( $f ) => $f['miembros'], $todas ) ) ) );
			} catch ( CrmException $e ) {
				$personas = array();
			}
		}
		$por_numero = array();
		foreach ( $todas as $f ) {
			$apellidos = array();
			$nombres   = array();
			foreach ( $f['miembros'] as $n ) {
				if ( isset( $personas[ $n ] ) ) {
					$apellidos[] = (string) strtok( $personas[ $n ]->apellidos, ' ' );
					$nombres[]   = $personas[ $n ]->nombre;
				}
			}
			$etiquetas[ $f['id'] ] = $f['numero'] . ( $nombres ? ' · ' . implode( ' / ', array_unique( $apellidos ) ) . ' (' . implode( ', ', $nombres ) . ')' : '' );
			$por_numero[ $f['numero'] ] = $f;
		}
		return array(
			'por_numero' => $por_numero,
			'etiquetas'  => $etiquetas,
		);
	}
}
