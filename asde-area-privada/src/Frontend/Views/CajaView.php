<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Caja\Cajas;
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Caja\Periodo;
use AsdeBalears\AreaPrivada\Caja\Servicio;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;

defined( 'ABSPATH' ) || exit;

/**
 * Tesorería → Caja (v0.59, doc 61): la caja de efectivo del grupo y la de cada sección (P-210). Cada una con su
 * saldo, sus apuntes del periodo (los propios y, en la del grupo, lo cobrado y pagado en efectivo en los demás
 * módulos, en vivo) y lo que se hace en ella: saldo inicial, apuntar, traspasar, arqueo y anular. Un traspaso con el
 * banco se asocia después con su movimiento del banco (?banco=ID, también en la ventana emergente).
 * La ven sus scouters (las de sus secciones) y Tesorería, Secretaría y Coordinación (todas).
 */
final class CajaView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		$cajas = Cajas::visibles();
		if ( ! $cajas ) {
			return $this->denegado( asde_t( 'La caja no está activa en tu grupo o no llevas ninguna caja.' ) );
		}
		$ronda = $this->ronda();
		if ( '' !== $this->get( 'banco' ) ) {
			return $this->layout( 'tesoreria', ( self::es_modal() ? '' : $this->subnav( 'caja', $ronda ) ) . $this->asociar_banco( (int) $this->get( 'banco' ) ) );
		}
		$caja    = in_array( $this->get( 'caja' ), $cajas, true ) ? $this->get( 'caja' ) : $cajas[0];
		$periodo = Periodo::de( '' !== $this->get( 'periodo' ) ? $this->get( 'periodo' ) : 'r' . $ronda );
		$e       = Servicio::extracto( $caja, $periodo );
		$bancos  = array_filter( array_column( $e['filas'], 'banco_id' ) );
		$hacia   = array();
		foreach ( Cajas::todas() as $c ) {
			if ( $c !== $caja ) {
				$hacia[ $c ] = Cajas::nombre( $c );
			}
		}
		$aqui = array(
			'caja'    => $caja,
			'periodo' => $periodo['clave'],
		);
		return $this->layout(
			'tesoreria',
			$this->subnav( 'caja', $ronda ) . $this->tpl(
				'tesoreria/caja',
				array(
					'caja'      => $caja,
					'cajas'     => array_combine( $cajas, array_map( array( Cajas::class, 'nombre' ), $cajas ) ),
					'periodo'   => $periodo,
					'periodos'  => Periodo::opciones(),
					'e'         => $e,
					'tipos'     => TiposMovimiento::todos(),
					'hacia'     => $hacia,
					'con_banco' => Cajas::todas_suyas(),
					'bancos'    => $bancos ? array_column( BancoTable::movimientos( array( 'ids' => array_values( $bancos ) ) ), null, 'id' ) : array(),
					'hoy'       => current_time( 'Y-m-d' ),
					'saldo_hoy' => Servicio::saldo( $caja, current_time( 'Y-m-d' ) ),
					'url'       => $this->url( 'caja' ),
					'aqui'      => $this->url( 'caja', $aqui ),
					'url_excel' => $this->url( 'caja', $aqui + array( 'formato' => 'xlsx', '_wpnonce' => wp_create_nonce( 'asde_caja_excel' ) ) ),
					'enlace'    => fn( array $a ) => '' !== (string) ( $a['pagina'] ?? '' ) ? $this->url( $a['pagina'], (array) $a['args'] ) : '',
				)
			)
		);
	}

	/** Ventana «Asociar con el banco»: el traspaso y los movimientos del banco que le pueden corresponder. */
	private function asociar_banco( int $id ): string {
		$a = CajaTable::obtener( $id );
		$ok = $a && CajaTable::TRASPASO === $a['clase'] && CajaTable::BANCO === $a['destino'] && null === $a['anulado_en'] && Cajas::todas_suyas();
		return $this->tpl(
			'tesoreria/caja-banco',
			array(
				'apunte'     => $ok ? $a : null,
				'candidatos' => $ok && ! $a['banco_id'] ? Servicio::candidatos_banco( $a ) : array(),
				'url'        => $this->url( 'caja' ),
				'volver'     => $this->url( 'caja', $a ? array( 'caja' => $a['caja'] ) : array() ),
				'hecho'      => '1' === $this->get( 'hecho' ),
			)
		);
	}

	/** Los apuntes de una caja en Excel (con el periodo elegido). Se llama antes de pintar la página. */
	public function exportar(): void {
		$caja = $this->get( 'caja' );
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_caja_excel' ) || ! Cajas::puede( $caja ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$periodo = Periodo::de( $this->get( 'periodo' ) );
		list( $cabecera, $filas, $opciones ) = self::excel( $caja, $periodo, Servicio::extracto( $caja, $periodo ) );
		\AsdeBalears\AreaPrivada\Audit\Logger::log( 'caja_exportada', 'caja', $caja, array( 'apuntes' => count( $filas ) ) );
		Xlsx::descargar( 'caja-' . $caja . '-' . wp_date( 'Ymd' ) . '.xlsx', Cajas::nombre( $caja ), $cabecera, $filas, $opciones );
		exit;
	}

	/** @return array{0: string[], 1: list<array>, 2: array} */
	public static function excel( string $caja, array $periodo, array $e ): array {
		$tipos = TiposMovimiento::todos();
		$filas = array();
		foreach ( $e['filas'] as $a ) {
			$filas[] = array(
				wp_date( 'd/m/Y', strtotime( $a['fecha'] . ' 12:00' ) ),
				LibroMayor::concepto_caja( $a ),
				CajaTable::INICIAL === $a['clase'] ? '' : LibroMayor::partida_caja( $a, $tipos ),
				$a['importe'] > 0 ? round( $a['importe'], 2 ) : '',
				$a['importe'] < 0 ? round( -$a['importe'], 2 ) : '',
				round( $a['saldo'], 2 ),
			);
		}
		return array(
			array( asde_t( 'Fecha' ), asde_t( 'Concepto' ), asde_t( 'Partida' ), asde_t( 'Entrada' ), asde_t( 'Salida' ), asde_t( 'Saldo' ) ),
			$filas,
			array(
				'titulo'    => Cajas::nombre( $caja ) . ' · ' . Grupo::nombre(),
				/* translators: 1: periodo, 2: saldo al empezar, 3: saldo final */
				'subtitulo' => sprintf( asde_t( '%1$s · al empezar %2$s · al acabar %3$s' ), $periodo['etiqueta'], \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $e['inicial'] ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $e['final'] ) ),
				'tipos'     => array( 'texto', 'texto', 'texto', 'euros', 'euros', 'euros' ),
				'totales'   => array( asde_t( 'Total' ), sprintf( asde_n( '%d apunte', '%d apuntes', count( $filas ) ), count( $filas ) ), '', $e['entradas'], $e['salidas'], $e['final'] ),
			),
		);
	}

	public function handle_post(): void {
		if ( ! Cajas::visibles() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_caja' );
		$caja    = $this->post( 'caja' );
		$importe = static fn( string $v ) => (float) str_replace( ',', '.', $v );
		$volver  = $this->url(
			'caja',
			array_filter(
				array(
					'caja'    => $caja,
					'periodo' => $this->post( 'periodo' ),
				)
			)
		);
		$ok = '';
		switch ( $this->post( 'asde_action' ) ) {
			case 'inicial':
				$r  = Servicio::poner_inicial( $caja, $this->post( 'fecha' ), $importe( $this->post( 'importe' ) ) );
				$ok = asde_t( 'Saldo inicial guardado.' );
				if ( CajaTable::BANCO === $caja ) {
					$volver = $this->url( 'libro_mayor', array_filter( array( 'periodo' => $this->post( 'periodo' ) ) ) );
				}
				break;
			case 'apuntar':
				$r  = Servicio::apuntar( $caja, $this->post( 'fecha' ), $this->post( 'sentido' ), $importe( $this->post( 'importe' ) ), (int) $this->post( 'tipo' ), $this->post( 'concepto' ), $this->post( 'nota' ) );
				$ok = asde_t( 'Apunte guardado.' );
				break;
			case 'traspasar':
				$r  = Servicio::traspasar( $caja, $this->post( 'hacia' ), $this->post( 'fecha' ), $importe( $this->post( 'importe' ) ), $this->post( 'concepto' ) );
				$ok = asde_t( 'Traspaso guardado.' );
				break;
			case 'arqueo':
				$r  = Servicio::arquear( $caja, $this->post( 'fecha' ), $importe( $this->post( 'recuento' ) ), $this->post( 'motivo' ) );
				$ok = ! is_wp_error( $r ) && abs( $r['diferencia'] ) < 0.01 ? asde_t( 'Arqueo guardado: la caja cuadra.' ) : asde_t( 'Arqueo guardado con la diferencia como ajuste.' );
				break;
			case 'anular':
				$a  = CajaTable::obtener( (int) $this->post( 'apunte' ) );
				$r  = Servicio::anular( (int) $this->post( 'apunte' ), $this->post( 'motivo' ) );
				$ok = asde_t( 'Apunte anulado.' );
				if ( $a && CajaTable::BANCO === $a['caja'] ) {
					$volver = $this->url( 'libro_mayor', array_filter( array( 'periodo' => $this->post( 'periodo' ) ) ) );
				}
				break;
			case 'banco':
				$id = (int) $this->post( 'apunte' );
				$r  = Servicio::asociar_banco( $id, (int) $this->post( 'banco' ) );
				$ok = (int) $this->post( 'banco' ) ? asde_t( 'Traspaso asociado con su movimiento del banco.' ) : asde_t( 'Asociación quitada.' );
				if ( Router::pide_modal() ) {
					$volver = $this->url( 'caja', array( 'banco' => $id, 'hecho' => is_wp_error( $r ) ? '' : '1' ) );
				}
				break;
			default:
				$r = new \WP_Error( 'asde_caja', asde_t( 'Acción desconocida.' ) );
		}
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
		} else {
			Notices::add( 'success', esc_html( $ok ) );
		}
		$this->redirect( $volver );
	}
}
