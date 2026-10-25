<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Caja\Cajas;
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Caja\Periodo;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

/**
 * Tesorería → Libro mayor (v0.59, doc 61, P-206/P-207): el banco y cada caja, con su saldo al empezar y al acabar el
 * periodo (ronda o año natural), sus apuntes con el saldo acumulado, y el resumen por partida. Solo lectura; el saldo
 * inicial del banco (P-208) se pone aquí una vez. Excel: los apuntes de todas las cuentas o el resumen.
 * Solo quien lleva todas las cajas (Tesorería, Secretaría y Coordinación).
 */
final class LibroMayorView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! Cajas::todas_suyas() ) {
			return $this->denegado( asde_t( 'El libro mayor lo ven Tesorería, Secretaría y Coordinación, con el módulo de caja activo.' ) );
		}
		$ronda   = $this->ronda();
		$periodo = Periodo::de( '' !== $this->get( 'periodo' ) ? $this->get( 'periodo' ) : 'r' . $ronda );
		$libro   = LibroMayor::calcular( $periodo );
		$excel   = fn( string $hoja ) => $this->url(
			'libro_mayor',
			array(
				'periodo'  => $periodo['clave'],
				'hoja'     => $hoja,
				'formato'  => 'xlsx',
				'_wpnonce' => wp_create_nonce( 'asde_libro_mayor_excel' ),
			)
		);
		return $this->layout(
			'tesoreria',
			$this->subnav( 'libro_mayor', $ronda ) . $this->tpl(
				'tesoreria/libro-mayor',
				array(
					'periodo'       => $periodo,
					'periodos'      => Periodo::opciones(),
					'libro'         => $libro,
					'banco'         => Modulos::activo( Modulos::BANCO ),
					'banco_inicial' => CajaTable::inicial( CajaTable::BANCO ),
					'hoy'           => current_time( 'Y-m-d' ),
					'url'           => $this->url( 'libro_mayor' ),
					'url_caja'      => $this->url( 'caja' ),
					'url_apuntes'   => $excel( 'apuntes' ),
					'url_resumen'   => $excel( 'resumen' ),
				)
			)
		);
	}

	/** Excel: ?hoja=apuntes (todas las cuentas, con su saldo) o ?hoja=resumen (por partida). */
	public function exportar(): void {
		if ( ! Cajas::todas_suyas() || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_libro_mayor_excel' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$periodo = Periodo::de( $this->get( 'periodo' ) );
		$resumen = 'resumen' === $this->get( 'hoja' );
		list( $cabecera, $filas, $opciones ) = $resumen ? self::excel_resumen( LibroMayor::calcular( $periodo ), $periodo ) : self::excel_apuntes( LibroMayor::calcular( $periodo ), $periodo );
		Logger::log( 'libro_mayor_exportado', 'caja', $periodo['clave'], array( 'hoja' => $resumen ? 'resumen' : 'apuntes' ) );
		Xlsx::descargar( 'libro-mayor-' . ( $resumen ? 'resumen-' : '' ) . $periodo['clave'] . '-' . wp_date( 'Ymd' ) . '.xlsx', asde_t( 'Libro mayor' ), $cabecera, $filas, $opciones );
		exit;
	}

	/** @return array{0: string[], 1: list<array>, 2: array} */
	public static function excel_apuntes( array $libro, array $periodo ): array {
		$filas = array();
		foreach ( $libro['cuentas'] as $c ) {
			$filas[] = array( $c['nombre'], '', asde_t( 'Saldo al empezar' ), '', '', '', round( $c['inicial'], 2 ) );
			foreach ( $c['filas'] as $f ) {
				$filas[] = array( $c['nombre'], wp_date( 'd/m/Y', strtotime( $f['fecha'] . ' 12:00' ) ), $f['concepto'], $f['partida'], $f['importe'] > 0 ? round( $f['importe'], 2 ) : '', $f['importe'] < 0 ? round( -$f['importe'], 2 ) : '', round( $f['saldo'], 2 ) );
			}
		}
		return array(
			array( asde_t( 'Cuenta' ), asde_t( 'Fecha' ), asde_t( 'Concepto' ), asde_t( 'Partida' ), asde_t( 'Entrada' ), asde_t( 'Salida' ), asde_t( 'Saldo' ) ),
			$filas,
			array(
				'titulo'    => asde_t( 'Libro mayor' ) . ' · ' . Grupo::nombre(),
				/* translators: 1: periodo, 2: fecha */
				'subtitulo' => sprintf( asde_t( '%1$s · generado el %2$s' ), $periodo['etiqueta'], wp_date( 'd/m/Y' ) ),
				'tipos'     => array( 'texto', 'texto', 'texto', 'texto', 'euros', 'euros', 'euros' ),
			),
		);
	}

	/** @return array{0: string[], 1: list<array>, 2: array} */
	public static function excel_resumen( array $libro, array $periodo ): array {
		$filas = array();
		$ent   = 0.0;
		$sal   = 0.0;
		foreach ( $libro['resumen'] as $partida => $r ) {
			$filas[] = array( $partida, $r['entradas'], $r['salidas'], round( $r['entradas'] - $r['salidas'], 2 ) );
			$ent    += $r['entradas'];
			$sal    += $r['salidas'];
		}
		return array(
			array( asde_t( 'Partida' ), asde_t( 'Entradas' ), asde_t( 'Salidas' ), asde_t( 'Neto' ) ),
			$filas,
			array(
				'titulo'    => asde_t( 'Libro mayor: resumen por partida' ) . ' · ' . Grupo::nombre(),
				/* translators: 1: periodo, 2: fecha */
				'subtitulo' => sprintf( asde_t( '%1$s · generado el %2$s' ), $periodo['etiqueta'], wp_date( 'd/m/Y' ) ),
				'tipos'     => array( 'texto', 'euros', 'euros', 'euros' ),
				'totales'   => array( asde_t( 'Total' ), round( $ent, 2 ), round( $sal, 2 ), round( $ent - $sal, 2 ) ),
			),
		);
	}

	/** Lo que se enseña de un importe con signo: «+12,00 €» o «−12,00 €». */
	public static function con_signo( float $v ): string {
		return ( $v > 0 ? '+' : '' ) . Calculadora::euros( $v );
	}
}
