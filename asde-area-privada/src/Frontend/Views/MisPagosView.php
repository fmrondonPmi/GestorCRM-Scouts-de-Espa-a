<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Eventos\Cobros as EventosCobros;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable as EventosMovimientos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Sepa\Avisos;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Sepa\CuentaCrm;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * «Mis pagos» (petición del usuario, 2026-09-29): lo que ha registrado Tesorería
 * de la propia persona o de su familia, solo para consultar. La cuota del grupo
 * en la ronda en curso (a pagar, pagado, pendiente y plazos), los pagos de todas
 * las rondas y los del uniforme. Sin las notas internas de Tesorería.
 *
 * Desde la v0.12 también la domiciliación SEPA: las cuentas familiares (madre,
 * padre o tutor/a) y los asociados mayores de edad firman aquí la autorización
 * de adeudo directo de su familia, la ven, la imprimen o la anulan. Y ven el
 * aviso previo del próximo cargo.
 */
final class MisPagosView extends View {

	public const CAP = 'asde_mis_pagos';

	/** Lo escrito en la autorización que no se ha podido guardar (misma petición: se vuelve a enseñar en el formulario). @var array|null */
	private static ?array $valores = null;

	/** Nº Scouts de España de la propia persona y de los menores que representa. @return string[] */
	private function nums( Ambito $a ): array {
		$nums = array_keys( $a->representados );
		if ( $a->persona ) {
			array_unshift( $nums, $a->persona->num_socio_asde );
		}
		return array_values( array_unique( array_map( 'strval', $nums ) ) );
	}

	/** @return array<int, array> Las familias de Tesorería de esos socios (id => familia). */
	private function familias( array $nums ): array {
		$familias = array();
		foreach ( $nums as $n ) {
			$f = FamiliasTable::de_socio( $n );
			if ( $f ) {
				$familias[ $f['id'] ] = $f;
			}
		}
		return $familias;
	}

	public function render( string $content = '' ): string {
		$a = Ambito::actual();
		if ( ! current_user_can( self::CAP ) || ( ! $a->persona && ! $a->representados ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'pagos', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar.' ) );
		}
		$nums     = $this->nums( $a );
		$familias = $this->familias( $nums );

		if ( (int) $this->get( 'autorizacion' ) ) {
			return $this->autorizacion( $a, $familias, (int) $this->get( 'autorizacion' ) );
		}

		$ronda = TesoreriaConfig::ronda_actual();
		$cuotas = array();
		$pagos  = array();
		try {
			$totales = MovimientosTable::totales( $ronda );
			$cfg     = TesoreriaConfig::de( $ronda );
			foreach ( $familias as $id => $f ) {
				$personas      = $repo->obtener_varias( $f['miembros'] );
				$cuotas[ $id ] = array(
					'familia'  => $f,
					'personas' => $personas,
					'calc'     => Calculadora::familia( $f, $personas, $cfg, $totales[ $id ] ?? array() ),
				);
				foreach ( MovimientosTable::de_familia_todas( $id ) as $m ) {
					if ( MovimientosTable::PAGO !== $m['tipo'] ) {
						continue; // Los ajustes (descuentos, becas) ya se ven en lo que hay que pagar, sin sus motivos.
					}
					$pagos[] = array(
						'fecha'    => (string) $m['fecha'],
						'concepto' => sprintf( asde_t( 'Cuota del grupo %s · familia %s' ), $m['ronda'], $f['numero'] ),
						'metodo'   => asde_t( MovimientosTable::METODOS[ $m['metodo'] ] ?? ( 'banco' === $m['origen'] ? 'Transferencia' : '' ) ),
						'importe'  => (float) $m['importe'],
						'ref'      => (string) $m['referencia'],
					);
				}
			}
		} catch ( CrmException $e ) {
			return $this->error_crm( 'pagos', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		$nombres    = array_map( static fn( $p ) => $p->nombre, $a->representados ) + ( $a->persona ? array( $a->persona->num_socio_asde => $a->persona->nombre ) : array() );
		$uniformes  = array_filter( PedidosTable::de_socios( $nums ), static fn( $p ) => null === $p['cancelado_en'] );
		$pendientes = array();
		foreach ( $uniformes as $p ) {
			$concepto = sprintf( asde_t( 'Uniforme %s%s · %s' ), PedidosTable::numero( $p ), count( $nums ) > 1 && isset( $nombres[ $p['socio'] ] ) ? ' (' . $nombres[ $p['socio'] ] . ')' : '', Servicio::descripcion( $p['lineas'] ) );
			if ( null !== $p['pago_en'] ) {
				$pagos[] = array(
					'fecha'    => (string) $p['pago_fecha'],
					'concepto' => $concepto,
					'metodo'   => asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ),
					'importe'  => (float) $p['pago_importe'],
					'ref'      => (string) $p['pago_ref'],
				);
			} else {
				$pendientes[] = array(
					'concepto' => $concepto,
					'importe'  => Servicio::importe( $p ),
				);
			}
		}
		// v0.35: inscripciones a eventos (lo pagado y lo que queda, con su referencia).
		$eventos      = array_column( EventosTable::todos(), null, 'id' );
		$inscripciones = InscripcionesTable::de_socios( $nums );
		$ev_movs      = EventosMovimientos::de_inscripciones( array_column( $inscripciones, 'id' ) );
		$hay_eventos  = false;
		foreach ( $inscripciones as $f ) {
			$e = $eventos[ $f['evento_id'] ] ?? null;
			if ( ! $e ) {
				continue;
			}
			$concepto = sprintf( asde_t( 'Evento %1$s%2$s · %3$s' ), EventosTable::numero( $e ), count( $nums ) > 1 && isset( $nombres[ $f['socio'] ] ) ? ' (' . $nombres[ $f['socio'] ] . ')' : '', $e['nombre'] );
			foreach ( $ev_movs[ $f['id'] ] ?? array() as $m ) {
				if ( EventosMovimientos::PAGO === $m['tipo'] && null === $m['anulado_en'] ) {
					$pagos[]     = array(
						'fecha'    => (string) $m['fecha'],
						'concepto' => $concepto,
						'metodo'   => asde_t( EventosMovimientos::FORMAS[ $m['forma'] ] ?? '' ),
						'importe'  => (float) $m['importe'],
						'ref'      => (string) $m['referencia'],
					);
					$hay_eventos = true;
				}
			}
			$cobro = EventosCobros::de( $f, $ev_movs[ $f['id'] ] ?? array() );
			if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) && EventosTable::CANCELADO !== $e['estado'] && $cobro['pendiente'] > 0 ) {
				$pendientes[] = array(
					'concepto' => $concepto,
					'importe'  => Calculadora::euros( $cobro['pendiente'] ),
					'ref'      => EventosCobros::referencia( $e, $f ),
				);
				$hay_eventos  = true;
			}
		}
		usort( $pagos, static fn( $x, $y ) => strcmp( $y['fecha'], $x['fecha'] ) );

		// Domiciliación SEPA de cada familia.
		$sepa_cfg = SepaConfig::de();
		$sepa     = array(
			'lista'    => SepaConfig::lista( $sepa_cfg ) && \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::SEPA ),
			'acreedor' => $sepa_cfg,
			'texto'    => Mandatos::texto( $sepa_cfg ),
			'familias' => array(),
		);
		foreach ( $familias as $id => $f ) {
			$m                       = Mandatos::activo_de_familia( $id );
			$sepa['familias'][ $id ] = array(
				'mandato'  => $m,
				'cuenta'   => $m ? self::cuenta( $repo, $f, $m ) : null,
				'puede'    => Mandatos::puede_firmar( $a, $f ),
				'proximos' => Remesas::proximos_de_familia( $id ),
				'valores'  => self::$valores && (int) ( self::$valores['familia'] ?? 0 ) === $id ? self::$valores['datos'] : null,
			);
		}

		return $this->layout(
			'pagos',
			$this->tpl(
				'pagos/mis-pagos',
				array(
					'ronda'      => $ronda,
					'cuotas'     => $cuotas,
					'pagos'      => $pagos,
					'pendientes' => $pendientes,
					'url_uniforme' => $this->url( 'uniforme' ),
					'url_eventos'  => $hay_eventos ? $this->url( 'mis_eventos' ) : '',
					'sepa'       => $sepa,
					'url'        => $this->url( 'pagos' ),
				)
			)
		);
	}

	/**
	 * La cuenta autorizada de la familia, de las fichas del CRM (v0.33, P-131), o la guardada en
	 * una autorización antigua. null si la de las fichas ya no es la autorizada; false si el CRM
	 * no responde.
	 *
	 * @return array|null|false
	 */
	private static function cuenta( ?PersonaRepository $repo, array $familia, array $m ) {
		$local = Mandatos::cuenta_local( $m );
		if ( $local ) {
			return $local;
		}
		if ( ! $repo ) {
			return false;
		}
		try {
			return CuentaCrm::de_familia( $familia, (string) $m['iban_huella'], CuentaCrm::leer( $repo, $familia['miembros'] ) );
		} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
			return false;
		}
	}

	/** La autorización para ver e imprimir (solo quien es de esa familia). */
	private function autorizacion( Ambito $a, array $familias, int $id ): string {
		$m       = Mandatos::obtener( $id );
		$familia = $m ? ( $familias[ $m['familia_id'] ] ?? null ) : null;
		if ( ! $m || ! $familia ) {
			return $this->denegado( asde_t( 'No tienes acceso a esa autorización.' ) );
		}
		$cuenta = Mandatos::ACTIVO === $m['estado'] ? self::cuenta( PersonaRepository::make(), $familia, $m ) : Mandatos::cuenta_local( $m );
		return $this->layout(
			'pagos',
			$this->tpl(
				'pagos/autorizacion',
				array(
					'mandato'   => $m,
					'cuenta'    => is_array( $cuenta ) ? $cuenta : null,
					'familia'   => $familia,
					'acreedor'  => SepaConfig::de(),
					'texto'     => Mandatos::texto( SepaConfig::de() ),
					'completo'  => true === Mandatos::puede_firmar( $a, $familia ),
					'firmante'  => $m['firmado_por'] ? get_userdata( $m['firmado_por'] ) : null,
					'url'       => $this->url( 'pagos' ),
				)
			)
		);
	}

	public function handle_post(): void {
		$a = Ambito::actual();
		if ( ! current_user_can( self::CAP ) || ( ! $a->persona && ! $a->representados ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$volver   = $this->url( 'pagos' );
		$familias = $this->familias( $this->nums( $a ) );

		switch ( $this->post( 'asde_action' ) ) {
			case 'sepa_firmar':
				$fid = (int) $this->post( 'familia' );
				check_admin_referer( 'asde_sepa_firmar_' . $fid );
				$familia = $familias[ $fid ] ?? null;
				$puede   = $familia ? Mandatos::puede_firmar( $a, $familia ) : asde_t( 'Esa familia no es la tuya.' );
				if ( true !== $puede ) {
					Notices::add( 'error', esc_html( (string) $puede ) );
					$this->redirect( $volver );
				}
				if ( ! SepaConfig::lista( SepaConfig::de() ) ) {
					Notices::add( 'error', asde_t( 'El grupo aún no tiene activadas las domiciliaciones SEPA.' ) );
					$this->redirect( $volver );
				}
				$v       = Mandatos::validar(
					array(
						'titular'   => $this->post( 'titular' ),
						'iban'      => $this->post( 'iban' ),
						'entidad'   => $this->post( 'entidad' ),
						'calle'     => $this->post( 'calle' ),
						'cp'        => $this->post( 'cp' ),
						'poblacion' => $this->post( 'poblacion' ),
						'provincia' => $this->post( 'provincia' ),
						'pais'      => $this->post( 'pais' ),
					)
				);
				$errores = $v['errores'];
				if ( '1' !== $this->post( 'acepto' ) ) {
					$errores[] = asde_t( 'Marca la casilla para firmar la autorización.' );
				}
				$id = $errores ? 0 : Mandatos::crear( $fid, $v['datos'], get_current_user_id() );
				if ( is_wp_error( $id ) ) {
					$errores[] = $id->get_error_message();
				}
				if ( $errores ) {
					// Sin redirigir: se vuelve a enseñar el formulario con lo escrito y los errores.
					foreach ( $errores as $e ) {
						Notices::add( 'error', esc_html( $e ) );
					}
					self::$valores = array(
						'familia' => $fid,
						'datos'   => $v['datos'],
					);
					return;
				}
				$mandato = Mandatos::obtener( (int) $id );
				if ( $mandato ) {
					Avisos::autorizacion( $mandato, wp_get_current_user() );
				}
				Notices::add( 'success', sprintf( asde_t( 'Autorización guardada: referencia <strong>%s</strong>. Te hemos enviado un email de confirmación y te avisaremos antes de cada cargo.' ), esc_html( $mandato['referencia'] ?? '' ) ) );
				$this->redirect( $volver );
				break;

			case 'sepa_anular':
				$mid = (int) $this->post( 'mandato' );
				check_admin_referer( 'asde_sepa_anular_' . $mid );
				$m       = Mandatos::obtener( $mid );
				$familia = $m ? ( $familias[ $m['familia_id'] ] ?? null ) : null;
				if ( ! $m || ! $familia || true !== Mandatos::puede_firmar( $a, $familia ) ) {
					Notices::add( 'error', asde_t( 'No puedes anular esa autorización.' ) );
				} elseif ( Mandatos::revocar( $mid, get_current_user_id(), 'Anulada por la familia' ) ) {
					Notices::add( 'success', asde_t( 'Autorización anulada. A partir de ahora no se cobrará nada por domiciliación en esa cuenta.' ) );
				} else {
					Notices::add( 'error', asde_t( 'Esa autorización ya estaba anulada.' ) );
				}
				$this->redirect( $volver );
				break;

			default:
				Notices::add( 'error', asde_t( 'Petición no válida.' ) );
				$this->redirect( $volver );
		}
	}
}
