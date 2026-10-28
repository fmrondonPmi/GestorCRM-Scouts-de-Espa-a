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

	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function filtros(): array {
		return array(
		''                       => __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		Calculadora::ATRASADO    => __( 'With overdue instalments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		Calculadora::PENDIENTE   => __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		Calculadora::PARCIAL     => __( 'Partial payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		Calculadora::PAGADO      => __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		Calculadora::EXENTO      => __( 'No fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'tesoreria', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
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
			return $this->error_crm( 'tesoreria', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
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
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', $f ? __( 'Bank transactions are only used by the Secretary\'s office, Coordination and Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'That family does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) ) );
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

	/**
	 * v0.58.1 (petición del usuario): fuera del control de pagos (pantalla y Excel) las familias sin ningún asociado
	 * con membresía activa (todos dados de baja o fuera del grupo). Siguen en «Familias», para revisarlas o eliminarlas.
	 *
	 * @param array<int, array{familia:array, calc:array}> $filas De estados().
	 * @return array<int, array{familia:array, calc:array}>
	 */
	public static function con_activos( array $filas ): array {
		return array_filter( $filas, static fn( $f ) => $f['calc']['pagan'] || $f['calc']['no_pagan'] );
	}

	private function control( PersonaRepository $repo, string $ronda ): string {
		list( $todas, $personas ) = self::estados( $repo, $ronda );
		$filas                    = self::con_activos( $todas );
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
			// Con todas las familias: la baja que deja a una sin asociados activos también se avisa (v0.58.1).
			if ( $id && isset( $todas[ $id ] ) ) {
				$bajas[] = $b + array(
					'familia' => $id,
					'numero'  => (string) $todas[ $id ]['familia']['numero'],
					'nombre'  => isset( $personas[ $b['socio'] ] ) ? $personas[ $b['socio'] ]->nombre_completo() : $b['socio'],
				);
			}
		}

		// Filtros.
		$estado = isset( self::filtros()[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : '';
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
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', __( 'That family does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) ) ) . $this->control( $repo, $ronda );
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
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_tesoreria_csv' ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			wp_die( esc_html__( 'CRM not configured.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 500 );
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
		foreach ( Calculadora::etiquetas() as $estado => $etiqueta ) {
			$por_texto[  $etiqueta  ] = $colores[ $estado ] ?? 'gris';
		}
		Logger::log( 'tesoreria_exportada', 'ronda', $ronda, array( 'familias' => count( $datos ) ) );
		/* translators: 1: round, 2: date */
		$subtitulo = sprintf( __( 'Round %1$s · generated on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ronda, wp_date( 'd/m/Y' ) );
		Xlsx::descargar(
			'control-de-pagos-' . $ronda . '-' . wp_date( 'Ymd' ) . '.xlsx',
			__( 'Payment control', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			array( __( 'Family no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Children who pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'To pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			$datos,
			array(
				'titulo'    => __( 'Payment control', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ' · ' . Grupo::nombre(),
				'subtitulo' => $subtitulo,
				'tipos'     => array( 'texto', 'texto', 'entero', 'euros', 'euros', 'euros', 'euros', 'euros', 'estado' ),
				'colores'   => $por_texto,
				'totales'   => array( __( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), sprintf( /* translators: %d: number */ _n( '%d family', '%d families', count( $datos ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $datos ) ), $suma['hijos'], round( $suma['cuota'], 2 ), round( $suma['ajustes'], 2 ), round( $suma['a_pagar'], 2 ), round( $suma['pagado'], 2 ), round( $suma['pendiente'], 2 ), '' ),
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
		foreach ( self::con_activos( $filas ) as $f ) {
			$c = $f['calc'];
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
				( Calculadora::etiquetas()[ $c['estado'] ] ),
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$familia = (int) $this->post( 'familia' );
		$ronda   = Config::es_ronda( $this->post( 'ronda' ) ) ? $this->post( 'ronda' ) : Config::ronda_actual();
		$volver  = $this->url( 'tesoreria', array( 'familia' => $familia, 'ronda' => $ronda ) );
		if ( ! FamiliasTable::obtener( $familia ) ) {
			Notices::add( 'error', __( 'Invalid family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
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
						$r = new \WP_Error( 'x', __( 'That event is not open for sign-ups or this family has nothing pending in it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					} elseif ( $banco ) {
						$r = new \WP_Error( 'x', __( 'The bank deposit is only linked to the round\'s fee. For an event, remove the deposit and record the payment.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					} elseif ( ! in_array( sanitize_key( $this->post( 'metodo' ) ), \AsdeBalears\AreaPrivada\Eventos\MovimientosTable::FORMAS_MANUALES, true ) ) {
						$r = new \WP_Error( 'x', __( 'For an event, the payment method is cash, transfer or Bizum.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					} else {
						$r = Cobros::pagar_familia( $e, $familia, $importe, sanitize_key( $this->post( 'metodo' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
					}
					/* translators: 1: amount, 2: name of the event */
					$ok = $e ? sprintf( __( 'Payment of %1$s recorded in the event "%2$s". It remains in the family\'s payment history.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $importe ), $e['nombre'] ) : '';
					break;
				}
				if ( $banco ) {
					// Con un movimiento del banco (v0.20): el pago lleva su fecha y su importe y el movimiento queda conciliado.
					$mb = BancoTable::obtener( $banco );
					if ( ! self::ve_banco() ) {
						$r = new \WP_Error( 'x', __( 'Bank transactions are only used by the Secretary\'s office, Coordination and Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					} else {
						$r = Conciliacion::conciliar( $banco, $familia, $ronda );
					}
					/* translators: 1: amount, 2: date */
					$ok = $mb ? sprintf( __( 'Payment of %1$s recorded with the bank deposit of %2$s, which is reconciled with this family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $mb['importe'] ), wp_date( 'd/m/Y', strtotime( $mb['fecha'] . ' 12:00' ) ) ) : '';
					break;
				}
				$r = MovimientosTable::registrar( $familia, $ronda, MovimientosTable::PAGO, $this->post( 'fecha' ), $importe, sanitize_key( $this->post( 'metodo' ) ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$ok = sprintf( /* translators: %s: text */ __( 'Payment of %s recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $importe ) );
				break;
			case 'registrar_ajuste':
				$signo = 'descuento' === $this->post( 'sentido' ) ? -1 : 1;
				$r     = MovimientosTable::registrar( $familia, $ronda, MovimientosTable::AJUSTE, $this->post( 'fecha' ), $signo * abs( $importe ), '', '', $this->post( 'nota' ) );
				$ok    = sprintf( /* translators: %s: text */ __( 'Adjustment of %s recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $signo * abs( $importe ) ) );
				break;
			case 'anular': // Hasta la v0.24 se llamaba así.
			case 'eliminar':
				// v0.25: con motivo y en el historial; si vino del banco, el ingreso vuelve a quedar por conciliar.
				// v0.29: solo Tesorería (P-94).
				$res = Pagos::puede_eliminar() ? Pagos::eliminar( (int) $this->post( 'movimiento' ), $familia, $this->post( 'motivo' ) ) : new \WP_Error( 'asde_pago', __( 'Deleting a payment or an adjustment is done by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				if ( is_wp_error( $res ) ) {
					$r  = $res;
					$ok = '';
					break;
				}
				$r  = (int) $res['mov']['id'];
				$ok = MovimientosTable::PAGO === $res['mov']['tipo']
					/* translators: %s: amount */
					? sprintf( __( 'Payment of %s deleted. It remains in the family\'s history.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( (float) $res['mov']['importe'] ) )
					/* translators: %s: amount */
					: sprintf( __( 'Adjustment of %s removed. It remains in the family\'s history.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( (float) $res['mov']['importe'] ) );
				if ( $res['banco'] ) {
					/* translators: %s: date */
					$ok .= ' ' . sprintf( __( 'The bank deposit of %s is pending reconciliation again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $res['banco']['fecha'] . ' 12:00' ) ) );
				}
				if ( 'sepa' === $res['mov']['origen'] ) {
					$ok .= ' ' . __( 'It came from the collection of a SEPA batch: the batch still considers it collected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
				break;
			default:
				$r = new \WP_Error( 'x', __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
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
