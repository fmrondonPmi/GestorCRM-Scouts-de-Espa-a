<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio as EventosServicio;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\HistorialPagos;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Pagos;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Tesorería · control de pagos: cuota de cada familia en la ronda, lo pagado,
 * lo pendiente y los plazos; ficha de familia con sus movimientos.
 */
final class TesoreriaView extends TesoreriaBaseView {

	public const FILTROS = array(
		''                       => 'Todas',
		Calculadora::ATRASADO    => 'Con plazos vencidos',
		Calculadora::PENDIENTE   => 'Pendientes',
		Calculadora::PARCIAL     => 'Pago parcial',
		Calculadora::PAGADO      => 'Pagadas',
		Calculadora::EXENTO      => 'Sin cuota',
	);

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'tesoreria', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		$ronda = $this->ronda();

		try {
			$familia = (int) $this->get( 'familia' );
			if ( $familia && '' !== $this->get( 'elegir_banco' ) ) {
				$html = $this->elegir_banco( $repo, $familia, $ronda );
			} else {
				$html = $familia ? $this->detalle( $repo, $familia, $ronda ) : $this->control( $repo, $ronda );
			}
		} catch ( CrmException $e ) {
			return $this->error_crm( 'tesoreria', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}
		return $this->layout( 'tesoreria', ( self::es_modal() ? '' : $this->subnav( 'tesoreria', $ronda ) ) . $html );
	}

	/** Los movimientos del banco solo los usan Secretaría, Coordinación y Tesorería, y si el grupo usa el banco. */
	private static function ve_banco(): bool {
		return current_user_can( self::CAP_CONFIG ) && Modulos::activo( Modulos::BANCO );
	}

	/**
	 * Ingresos del banco por conciliar para elegir con cuál paga una familia, con un
	 * buscador (v0.20.1). Se abre en la ventana emergente desde «Registrar pago»; sin
	 * JavaScript, en su página, y «Elegir» vuelve a la ficha con el ingreso puesto.
	 */
	private function elegir_banco( PersonaRepository $repo, int $id, string $ronda ): string {
		$f = FamiliasTable::obtener( $id );
		if ( ! $f || ! self::ve_banco() ) {
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', $f ? asde_t( 'Los movimientos del banco solo los usan Secretaría, Coordinación y Tesorería.' ) : asde_t( 'Esa familia no existe.' ) ) ) ) );
		}
		$claves = Conciliacion::claves( $repo->obtener_varias( $f['miembros'] ) );
		return $this->tpl(
			'tesoreria/familia-banco',
			array(
				'familia' => $f,
				'ronda'   => $ronda,
				'movs'    => Conciliacion::pendientes_para( $f, $claves ),
				'claves'  => $claves,
				'q'       => $this->get( 'q' ),
				'url'     => $this->url( 'tesoreria' ),
				'volver'  => $this->url( 'tesoreria', array( 'familia' => $id, 'ronda' => $ronda ) ),
			)
		);
	}

	/** Todas las familias con su estado en la ronda. @return array{0: array, 1: array} [filas, personas] */
	public static function estados( PersonaRepository $repo, string $ronda ): array {
		$cfg      = Config::de( $ronda );
		$familias = FamiliasTable::todas();
		$nums     = array();
		foreach ( $familias as $f ) {
			$nums = array_merge( $nums, $f['miembros'] );
		}
		$personas = $repo->obtener_varias( $nums );
		$totales  = MovimientosTable::totales( $ronda );
		$filas    = array();
		foreach ( $familias as $id => $f ) {
			$filas[ $id ] = array(
				'familia' => $f,
				'calc'    => Calculadora::familia( $f, $personas, $cfg, $totales[ $id ] ?? array() ),
			);
		}
		return array( $filas, $personas );
	}

	private function control( PersonaRepository $repo, string $ronda ): string {
		list( $filas, $personas ) = self::estados( $repo, $ronda );
		$cfg                      = Config::de( $ronda );

		// Resumen.
		$resumen = array(
			'familias'  => 0,
			'hijos'     => 0,
			'a_pagar'   => 0.0,
			'pagado'    => 0.0,
			'pendiente' => 0.0,
			'estados'   => array(),
		);
		foreach ( $filas as $f ) {
			$c = $f['calc'];
			if ( Calculadora::EXENTO !== $c['estado'] ) {
				++$resumen['familias'];
			}
			$resumen['hijos']     += count( $c['pagan'] );
			$resumen['a_pagar']   += $c['a_pagar'];
			$resumen['pagado']    += $c['pagado'];
			$resumen['pendiente'] += max( 0, $c['pendiente'] );
			$resumen['estados'][ $c['estado'] ] = ( $resumen['estados'][ $c['estado'] ] ?? 0 ) + 1;
		}

		// Educandos activos que no están en ninguna familia (no se les está cobrando).
		$mapa      = FamiliasTable::mapa_socios();
		$sin_fam   = array_filter( $repo->todas( true ), static fn( $p ) => ! isset( $mapa[ $p->num_socio_asde ] ) && array_intersect( $p->secciones, $cfg['secciones_pagan'] ) );

		// Bajas dadas desde la web (doc 34, fase 3) de personas de alguna familia: dejan de contar en su cuota.
		$bajas = array();
		foreach ( Altas::bajas_recientes() as $b ) {
			$id = $mapa[ $b['socio'] ] ?? 0;
			if ( $id && isset( $filas[ $id ] ) ) {
				$bajas[] = $b + array(
					'familia' => $id,
					'numero'  => (string) $filas[ $id ]['familia']['numero'],
					'nombre'  => isset( $personas[ $b['socio'] ] ) ? $personas[ $b['socio'] ]->nombre_completo() : $b['socio'],
				);
			}
		}

		// Filtros.
		$estado = isset( self::FILTROS[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : '';
		$q      = mb_strtolower( remove_accents( $this->get( 'q' ) ) );
		$lista  = array_filter(
			$filas,
			function ( $f ) use ( $estado, $q, $personas ) {
				if ( '' !== $estado && $estado !== $f['calc']['estado'] ) {
					return false;
				}
				if ( '' === $q ) {
					return true;
				}
				$texto = strtolower( $f['familia']['numero'] );
				foreach ( $f['familia']['miembros'] as $n ) {
					$texto .= ' ' . mb_strtolower( remove_accents( isset( $personas[ $n ] ) ? $personas[ $n ]->nombre_completo() : '' ) );
				}
				return str_contains( $texto, $q );
			}
		);

		return $this->tpl(
			'tesoreria/control',
			array(
				'ronda'     => $ronda,
				'cfg'       => $cfg,
				'lista'     => $lista,
				'personas'  => $personas,
				'resumen'   => $resumen,
				'sin_fam'   => count( $sin_fam ),
				'bajas'     => $bajas,
				'estado'    => $estado,
				'q'         => $this->get( 'q' ),
				'url'       => $this->url( 'tesoreria' ),
				'url_fam'   => $this->url( 'familias' ),
				'url_csv'   => $this->url( 'tesoreria', array( 'ronda' => $ronda, 'formato' => 'xlsx', '_wpnonce' => wp_create_nonce( 'asde_tesoreria_csv' ) ) ),
			)
		);
	}

	private function detalle( PersonaRepository $repo, int $id, string $ronda ): string {
		$f = FamiliasTable::obtener( $id );
		if ( ! $f ) {
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', asde_t( 'Esa familia no existe.' ) ) ) ) ) . $this->control( $repo, $ronda );
		}
		$cfg      = Config::de( $ronda );
		$personas = $repo->obtener_varias( $f['miembros'] );
		$calc     = Calculadora::familia( $f, $personas, $cfg, MovimientosTable::totales( $ronda )[ $id ] ?? array() );

		$cuenta = null;
		foreach ( $f['miembros'] as $n ) {
			$cuenta = Usuarios::tutores_de( $n )[0] ?? null;
			if ( $cuenta ) {
				break;
			}
		}

		$movs = MovimientosTable::de_familia( $id, $ronda );
		return $this->tpl(
			'tesoreria/familia',
			array(
				'familia'     => $f,
				'personas'    => $personas,
				'calc'        => $calc,
				'cfg'         => $cfg,
				'ronda'       => $ronda,
				'movimientos' => $movs,
				// v0.25: cuándo se registró y se eliminó cada movimiento, quién y por qué.
				'historial'   => Pagos::historial( $movs ),
				// v0.37: también los pagos de eventos y de uniformes de la familia.
				'otros'       => HistorialPagos::otros( $f ),
				// v0.37: eventos con la inscripción abierta a los que se puede apuntar el pago (solo quien cobra eventos).
				'eventos'     => Modulos::activo( Modulos::EVENTOS ) && Cobros::puede() ? HistorialPagos::eventos_para_cobrar( $f ) : array(),
				// v0.20: asociar un ingreso del banco al registrar el pago (solo quien ve el banco); se elige en una ventana con buscador (v0.20.1).
				'banco'       => self::ve_banco() ? $this->banco_para( $f, $personas, $ronda ) : null,
				'cuenta'      => $cuenta,
				'url'         => $this->url( 'tesoreria', array( 'ronda' => $ronda ) ),
				'url_editar'  => $this->url( 'familias', array( 'familia' => $id ) ),
			)
		);
	}

	/**
	 * Lo que necesita «Registrar pago» para el ingreso del banco: cuántos hay por
	 * conciliar (y sugeridos para la familia), el elegido si se vuelve de su página
	 * con ?banco= (sin JavaScript) y las direcciones de la ventana y de «Quitar».
	 *
	 * @return array{pendientes:int, sugeridos:int, elegido:?array, url:string, url_quitar:string}
	 */
	private function banco_para( array $f, array $personas, string $ronda ): array {
		$pend    = Conciliacion::pendientes_para( $f, Conciliacion::claves( $personas ) );
		$elegido = (int) $this->get( 'banco' ) ? BancoTable::obtener( (int) $this->get( 'banco' ) ) : null;
		$aqui    = array(
			'familia' => (int) $f['id'],
			'ronda'   => $ronda,
		);
		return array(
			'pendientes' => count( $pend ),
			'sugeridos'  => count( array_filter( $pend, static fn( $m ) => $m['sugerido'] ) ),
			'elegido'    => $elegido && BancoTable::PENDIENTE === $elegido['estado'] && $elegido['importe'] > 0 ? $elegido : null,
			'url'        => $this->url( 'tesoreria', $aqui + array( 'elegir_banco' => '1' ) ),
			'url_quitar' => $this->url( 'tesoreria', $aqui ),
		);
	}

	/**
	 * Descarga del control de pagos en Excel (se llama antes de pintar la página). Hasta la v0.36 era
	 * un CSV; desde la v0.37 es una hoja de cálculo con formato (título, moneda, estados con color y
	 * totales), petición del usuario.
	 */
	public function exportar(): void {
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_tesoreria_csv' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			wp_die( esc_html__( 'CRM no configurado.', 'asde-area-privada' ), 500 );
		}
		$ronda = $this->ronda();
		try {
			list( $filas, $personas ) = self::estados( $repo, $ronda );
		} catch ( CrmException $e ) {
			wp_die( esc_html( $e->getMessage() ), 500 );
		}
		list( $datos, $suma ) = self::datos_excel( $filas, $personas );
		$colores = array(
			Calculadora::PAGADO    => 'verde',
			Calculadora::PARCIAL   => 'ambar',
			Calculadora::PENDIENTE => 'ambar',
			Calculadora::ATRASADO  => 'rojo',
			Calculadora::EXENTO    => 'gris',
		);
		$por_texto = array();
		foreach ( Calculadora::ETIQUETAS as $estado => $etiqueta ) {
			$por_texto[ asde_t( $etiqueta ) ] = $colores[ $estado ] ?? 'gris';
		}
		Logger::log( 'tesoreria_exportada', 'ronda', $ronda, array( 'familias' => count( $datos ) ) );
		/* translators: 1: ronda, 2: fecha */
		$subtitulo = sprintf( asde_t( 'Ronda %1$s · generado el %2$s' ), $ronda, wp_date( 'd/m/Y' ) );
		Xlsx::descargar(
			'control-de-pagos-' . $ronda . '-' . wp_date( 'Ymd' ) . '.xlsx',
			asde_t( 'Control de pagos' ),
			array( asde_t( 'Nº familia' ), asde_t( 'Socios' ), asde_t( 'Hijos que pagan' ), asde_t( 'Cuota' ), asde_t( 'Ajustes' ), asde_t( 'A pagar' ), asde_t( 'Pagado' ), asde_t( 'Pendiente' ), asde_t( 'Estado' ) ),
			$datos,
			array(
				'titulo'    => asde_t( 'Control de pagos' ) . ' · ' . Grupo::nombre(),
				'subtitulo' => $subtitulo,
				'tipos'     => array( 'texto', 'texto', 'entero', 'euros', 'euros', 'euros', 'euros', 'euros', 'estado' ),
				'colores'   => $por_texto,
				'totales'   => array( asde_t( 'Total' ), sprintf( asde_n( '%d familia', '%d familias', count( $datos ) ), count( $datos ) ), $suma['hijos'], round( $suma['cuota'], 2 ), round( $suma['ajustes'], 2 ), round( $suma['a_pagar'], 2 ), round( $suma['pagado'], 2 ), round( $suma['pendiente'], 2 ), '' ),
			)
		);
		exit;
	}

	/**
	 * Las filas del Excel del control de pagos y sus totales. v0.58 (petición del usuario): sin las familias que no
	 * tienen ningún asociado con membresía activa (todos dados de baja o fuera del grupo).
	 *
	 * @param array<int, array{familia:array, calc:array}>              $filas    De estados().
	 * @param array<string, \AsdeBalears\AreaPrivada\Domain\Persona> $personas
	 * @return array{0: list<array>, 1: array<string, float>}
	 */
	public static function datos_excel( array $filas, array $personas ): array {
		$datos = array();
		$suma  = array_fill_keys( array( 'hijos', 'cuota', 'ajustes', 'a_pagar', 'pagado', 'pendiente' ), 0.0 );
		foreach ( $filas as $f ) {
			$c = $f['calc'];
			if ( ! $c['pagan'] && ! $c['no_pagan'] ) {
				continue;
			}
			$nombres = array_map( static fn( $n ) => isset( $personas[ $n ] ) ? $personas[ $n ]->nombre_completo() : $n, $f['familia']['miembros'] );
			$datos[] = array(
				(string) $f['familia']['numero'],
				implode( ', ', $nombres ),
				count( $c['pagan'] ),
				round( (float) $c['cuota']['total'], 2 ),
				round( (float) $c['ajustes'], 2 ),
				round( (float) $c['a_pagar'], 2 ),
				round( (float) $c['pagado'], 2 ),
				round( (float) $c['pendiente'], 2 ),
				asde_t( Calculadora::ETIQUETAS[ $c['estado'] ] ),
			);
			$suma['hijos']     += count( $c['pagan'] );
			$suma['cuota']     += $c['cuota']['total'];
			$suma['ajustes']   += $c['ajustes'];
			$suma['a_pagar']   += $c['a_pagar'];
			$suma['pagado']    += $c['pagado'];
			$suma['pendiente'] += $c['pendiente'];
		}
		return array( $datos, $suma );
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$familia = (int) $this->post( 'familia' );
		$ronda   = Config::es_ronda( $this->post( 'ronda' ) ) ? $this->post( 'ronda' ) : Config::ronda_actual();
		$volver  = $this->url( 'tesoreria', array( 'familia' => $familia, 'ronda' => $ronda ) );
		if ( ! FamiliasTable::obtener( $familia ) ) {
			Notices::add( 'error', asde_t( 'Familia no válida.' ) );
			$this->redirect( $this->url( 'tesoreria' ) );
		}
		check_admin_referer( 'asde_mov_' . $familia );
		$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );

		switch ( $this->post( 'asde_action' ) ) {
			case 'registrar_pago':
				$banco  = (int) $this->post( 'banco' );
				$evento = (int) $this->post( 'evento' );
				if ( $evento ) {
					// v0.37: el pago es de un evento (con la inscripción abierta), no de la cuota de la ronda.
					$e = Modulos::activo( Modulos::EVENTOS ) ? EventosTable::obtener( $evento ) : null;
					$f = FamiliasTable::obtener( $familia );
					if ( ! $e || ! $f || ! in_array( $evento, array_map( static fn( $x ) => $x['evento']['id'], HistorialPagos::eventos_para_cobrar( $f ) ), true ) ) {
						$r = new \WP_Error( 'x', asde_t( 'Ese evento no está abierto a inscripciones o esta familia no tiene nada pendiente en él.' ) );
					} elseif ( $banco ) {
						$r = new \WP_Error( 'x', asde_t( 'El ingreso del banco solo se asocia a la cuota de la ronda. Para un evento, quita el ingreso y escribe el pago.' ) );
					} elseif ( ! in_array( sanitize_key( $this->post( 'metodo' ) ), \AsdeBalears\AreaPrivada\Eventos\MovimientosTable::FORMAS_MANUALES, true ) ) {
						$r = new \WP_Error( 'x', asde_t( 'Para un evento, la forma de pago es efectivo, transferencia o Bizum.' ) );
					} else {
						$r = Cobros::pagar_familia( $e, $familia, $importe, sanitize_key( $this->post( 'metodo' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
					}
					/* translators: 1: importe, 2: nombre del evento */
					$ok = $e ? sprintf( asde_t( 'Pago de %1$s registrado en el evento «%2$s». Queda en el historial de pagos de la familia.' ), Calculadora::euros( $importe ), $e['nombre'] ) : '';
					break;
				}
				if ( $banco ) {
					// Con un movimiento del banco (v0.20): el pago lleva su fecha y su importe y el movimiento queda conciliado.
					$mb = BancoTable::obtener( $banco );
					if ( ! self::ve_banco() ) {
						$r = new \WP_Error( 'x', asde_t( 'Los movimientos del banco solo los usan Secretaría, Coordinación y Tesorería.' ) );
					} else {
						$r = Conciliacion::conciliar( $banco, $familia, $ronda );
					}
					/* translators: 1: importe, 2: fecha */
					$ok = $mb ? sprintf( asde_t( 'Pago de %1$s registrado con el ingreso del banco del %2$s, que queda conciliado con esta familia.' ), Calculadora::euros( $mb['importe'] ), wp_date( 'd/m/Y', strtotime( $mb['fecha'] . ' 12:00' ) ) ) : '';
					break;
				}
				$r = MovimientosTable::registrar( $familia, $ronda, MovimientosTable::PAGO, $this->post( 'fecha' ), $importe, sanitize_key( $this->post( 'metodo' ) ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$ok = sprintf( asde_t( 'Pago de %s registrado.' ), Calculadora::euros( $importe ) );
				break;
			case 'registrar_ajuste':
				$signo = 'descuento' === $this->post( 'sentido' ) ? -1 : 1;
				$r     = MovimientosTable::registrar( $familia, $ronda, MovimientosTable::AJUSTE, $this->post( 'fecha' ), $signo * abs( $importe ), '', '', $this->post( 'nota' ) );
				$ok    = sprintf( asde_t( 'Ajuste de %s registrado.' ), Calculadora::euros( $signo * abs( $importe ) ) );
				break;
			case 'anular': // Hasta la v0.24 se llamaba así.
			case 'eliminar':
				// v0.25: con motivo y en el historial; si vino del banco, el ingreso vuelve a quedar por conciliar.
				// v0.29: solo Tesorería (P-94).
				$res = Pagos::puede_eliminar() ? Pagos::eliminar( (int) $this->post( 'movimiento' ), $familia, $this->post( 'motivo' ) ) : new \WP_Error( 'asde_pago', asde_t( 'Eliminar un pago o un ajuste lo hace Tesorería.' ) );
				if ( is_wp_error( $res ) ) {
					$r  = $res;
					$ok = '';
					break;
				}
				$r  = (int) $res['mov']['id'];
				$ok = MovimientosTable::PAGO === $res['mov']['tipo']
					/* translators: %s: importe */
					? sprintf( asde_t( 'Pago de %s eliminado. Queda en el historial de la familia.' ), Calculadora::euros( (float) $res['mov']['importe'] ) )
					/* translators: %s: importe */
					: sprintf( asde_t( 'Ajuste de %s eliminado. Queda en el historial de la familia.' ), Calculadora::euros( (float) $res['mov']['importe'] ) );
				if ( $res['banco'] ) {
					/* translators: %s: fecha */
					$ok .= ' ' . sprintf( asde_t( 'El ingreso del banco del %s vuelve a estar por conciliar.' ), wp_date( 'd/m/Y', strtotime( $res['banco']['fecha'] . ' 12:00' ) ) );
				}
				if ( 'sepa' === $res['mov']['origen'] ) {
					$ok .= ' ' . asde_t( 'Venía del cobro de una remesa SEPA: la remesa lo sigue dando por cobrado.' );
				}
				break;
			default:
				$r = new \WP_Error( 'x', asde_t( 'Acción desconocida.' ) );
				$ok = '';
		}

		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
		} else {
			Logger::log( 'tesoreria_' . $this->post( 'asde_action' ), 'familia', (string) $familia, array( 'movimiento' => $r, 'ronda' => $ronda ) );
			Notices::add( 'success', esc_html( $ok ) );
		}
		$this->redirect( $volver );
	}
}
