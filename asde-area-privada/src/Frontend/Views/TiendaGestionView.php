<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\Avisos;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\ConciliacionBanco;
use AsdeBalears\AreaPrivada\Tienda\Informes;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de los pedidos de la tienda (scouters, Tesorería, Coordinación y administración):
 * validar el pago, incluir en el pedido del grupo, marcar la entrega, cancelar; informes de
 * ventas y descarga en CSV.
 *
 * v0.44 (doc 52): ya no se sirven ni asignan camisas del Inventario general; la tienda tiene
 * sus propias existencias (Productos).
 */
final class TiendaGestionView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			return $this->denegado();
		}
		$a = Ambito::actual();
		if ( '1' === $this->get( 'informe' ) ) {
			return $this->layout( 'tienda_gestion', Servicio::puede_informes() ? $this->informe( $a ) : $this->denegado( asde_t( 'Los informes de ventas los ven Tesorería, Coordinación y la administración de la tienda.' ) ) );
		}
		$id = (int) $this->get( 'pedido' );
		if ( $id ) {
			$p = PedidosTable::obtener( $id );
			if ( ! $p || ! Servicio::en_gestion( $p, $a ) ) {
				return $this->denegado( asde_t( 'No tienes acceso a ese pedido.' ) );
			}
			return $this->layout( 'tienda_gestion', $this->detalle( $p, $a ) );
		}

		$cfg      = Config::de();
		$todos    = Servicio::de_gestion( $a );
		$personas = $this->personas( $todos );
		$filtro   = $this->filtro();
		$seccion  = $this->get( 'seccion' );
		$lista    = $this->filtrar( $todos, $personas, $filtro, $seccion, $this->get( 'q' ) );

		$cuentas = array();
		foreach ( array_keys( PedidosTable::FILTROS ) as $f ) {
			$cuentas[ $f ] = count( array_filter( $todos, static fn( $p ) => PedidosTable::cumple( $p, $f ) ) );
		}
		$activos   = array_filter( $todos, static fn( $p ) => null === $p['cancelado_en'] );
		$cobrado   = array_sum( array_map( static fn( $p ) => (float) $p['pago_importe'], array_filter( $activos, static fn( $p ) => null !== $p['pago_en'] ) ) );
		$pendiente = array_sum( array_map( array( Servicio::class, 'importe_a_cobrar' ), array_filter( $activos, static fn( $p ) => null === $p['pago_en'] ) ) );
		$por_pedir = array_filter( $todos, static fn( $p ) => PedidosTable::cumple( $p, PedidosTable::POR_PEDIR ) );

		// v0.40 (fase 5): ingresos del banco que mencionan un pedido sin pagar (solo Tesorería, con el banco activo).
		$sugerencias = Servicio::vision_global( $a ) ? ConciliacionBanco::sugerencias( $todos ) : array();

		return $this->layout(
			'tienda_gestion',
			$this->subnav( 'tienda_gestion' ) . $this->tpl(
				'tienda/banco-sugerencias',
				array(
					'sugerencias' => $sugerencias,
					'personas'    => $sugerencias ? $this->personas( array_column( $sugerencias, 'pedido' ) ) : array(),
					'volver'      => $this->url( 'tienda_gestion' ),
				)
			) . $this->tpl(
				'tienda/gestion',
				array(
					'cfg'          => $cfg,
					'ambito'       => $a,
					'lista'        => $lista,
					'personas'     => $personas,
					'cuentas'      => $cuentas,
					'cobrado'      => $cobrado,
					'pendiente'    => $pendiente,
					'filtro'       => $filtro,
					'seccion'      => $seccion,
					'q'            => $this->get( 'q' ),
					'global'       => Servicio::vision_global( $a ),
					'resumen'      => Servicio::resumen( array_filter( $por_pedir, static fn( $p ) => null !== $p['pago_en'] ), $cfg ),
					'sin_pagar'    => count( array_filter( $por_pedir, static fn( $p ) => null === $p['pago_en'] ) ),
						'url_informe'  => Servicio::puede_informes() ? $this->url( 'tienda_gestion', array( 'informe' => '1' ) ) : '',
					'url'          => $this->url( 'tienda_gestion' ),
					'url_csv'      => $this->url(
						'tienda_gestion',
						array(
							'formato'  => 'csv',
							'estado'   => $filtro,
							'seccion'  => $seccion,
							'q'        => $this->get( 'q' ),
							'_wpnonce' => wp_create_nonce( 'asde_uniformes_csv' ),
						)
					),
					'volver'       => $this->url(
						'tienda_gestion',
						array_filter(
							array(
								'estado'  => $filtro,
								'seccion' => $seccion,
								'q'       => $this->get( 'q' ),
							)
						)
					),
				)
			)
		);
	}

	private function filtro(): string {
		return isset( PedidosTable::FILTROS[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : '';
	}

	/** @param array<string, \AsdeBalears\AreaPrivada\Domain\Persona> $personas */
	private function filtrar( array $pedidos, array $personas, string $filtro, string $seccion, string $q ): array {
		$q = mb_strtolower( remove_accents( trim( $q ) ) );
		return array_values(
			array_filter(
				$pedidos,
				static function ( array $p ) use ( $personas, $filtro, $seccion, $q ): bool {
					if ( ! PedidosTable::cumple( $p, $filtro ) ) {
						return false;
					}
					if ( '' !== $seccion && ! in_array( $seccion, $p['secciones'], true ) ) {
						return false;
					}
					if ( '' === $q ) {
						return true;
					}
					$texto = strtolower( PedidosTable::numero( $p ) ) . ' ' . mb_strtolower( remove_accents( isset( $personas[ $p['socio'] ] ) ? $personas[ $p['socio'] ]->nombre_completo() : $p['socio'] ) );
					return str_contains( $texto, $q );
				}
			)
		);
	}

	private function detalle( array $p, Ambito $a ): string {
		$personas = $this->personas( array( $p ) );
		$usuario  = static fn( int $id ): string => $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '';
		return $this->tpl(
			'tienda/detalle',
			array(
				'p'        => $p,
				'persona'  => $personas[ $p['socio'] ] ?? null,
				'ambito'   => $a,
				'usuario'  => $usuario,
				'a_cobrar' => Servicio::importe_a_cobrar( $p ),
				'url'      => $this->url( 'tienda_gestion' ),
				'url_ficha' => $this->url( 'ficha', array( 'socio' => $p['socio'] ) ),
			)
		);
	}

	/** Periodo del informe: dos fechas (Y-m-d) o '' (sin límite). @return array{0:string,1:string} */
	private function periodo(): array {
		$fecha = fn( string $k ): string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $this->get( $k ) ) ? $this->get( $k ) : '';
		return array( $fecha( 'desde' ), $fecha( 'hasta' ) );
	}

	/** Los pedidos del periodo que esta persona gestiona. */
	private function del_periodo( Ambito $a ): array {
		list( $desde, $hasta ) = $this->periodo();
		return Informes::del_periodo( Servicio::de_gestion( $a ), $desde, $hasta );
	}

	/** «Ventas e informes» (v0.40, fase 5): por producto, por forma de pago y pendientes de cobro. */
	private function informe( Ambito $a ): string {
		list( $desde, $hasta ) = $this->periodo();
		$pedidos    = $this->del_periodo( $a );
		$pendientes = Informes::pendientes( $pedidos );
		$excel      = fn( string $hoja ) => $this->url(
			'tienda_gestion',
			array_filter(
				array(
					'informe'  => '1',
					'hoja'     => $hoja,
					'formato'  => 'xlsx',
					'desde'    => $desde,
					'hasta'    => $hasta,
					'_wpnonce' => wp_create_nonce( 'asde_tienda_informe' ),
				)
			)
		);
		return $this->subnav( 'tienda_gestion' ) . $this->tpl(
			'tienda/informe',
			array(
				'desde'      => $desde,
				'hasta'      => $hasta,
				'n'          => count( $pedidos ),
				'productos'  => Informes::por_producto( $pedidos, Config::de() ),
				'formas'     => Informes::por_forma( $pedidos ),
				'pendientes' => $pendientes,
				'personas'   => $this->personas( $pendientes ),
				'url'        => $this->url( 'tienda_gestion' ),
				'excel'      => array(
					'productos'  => $excel( 'productos' ),
					'formas'     => $excel( 'formas' ),
					'pendientes' => $excel( 'pendientes' ),
				),
			)
		);
	}

	/** Excel de un informe de ventas (se llama antes de pintar la página). */
	public function exportar_informe(): void {
		if ( ! Servicio::puede_informes() || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_tienda_informe' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$a       = Ambito::actual();
		$pedidos = $this->del_periodo( $a );
		list( $desde, $hasta ) = $this->periodo();
		$hoja = $this->get( 'hoja' );
		$cfg  = Config::de();
		$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
		/* translators: 1: periodo, 2: fecha */
		$periodo   = ( '' === $desde && '' === $hasta ) ? asde_t( 'Todos los pedidos' ) : trim( ( '' !== $desde ? sprintf( asde_t( 'desde el %s' ), $fecha( $desde ) ) : '' ) . ' ' . ( '' !== $hasta ? sprintf( asde_t( 'hasta el %s' ), $fecha( $hasta ) ) : '' ) );
		$subtitulo = sprintf( asde_t( '%1$s · generado el %2$s' ), $periodo, wp_date( 'd/m/Y' ) );
		$grupo     = \AsdeBalears\AreaPrivada\Domain\Grupo::nombre();

		if ( 'formas' === $hoja ) {
			$titulo = asde_t( 'Ventas por forma de pago' );
			$filas  = array();
			$suma   = array( 0, 0.0, 0.0, 0.0 );
			foreach ( Informes::por_forma( $pedidos ) as $forma => $d ) {
				$filas[] = array( '' !== $forma ? asde_t( PedidosTable::FORMAS[ $forma ] ) : asde_t( 'Sin indicar' ), $d['pedidos'], round( $d['total'], 2 ), round( $d['cobrado'], 2 ), round( $d['pendiente'], 2 ) );
				$suma[0] += $d['pedidos'];
				$suma[1] += $d['total'];
				$suma[2] += $d['cobrado'];
				$suma[3] += $d['pendiente'];
			}
			$cabecera = array( asde_t( 'Forma de pago' ), asde_t( 'Pedidos' ), asde_t( 'Importe' ), asde_t( 'Cobrado' ), asde_t( 'Por cobrar' ) );
			$tipos    = array( 'texto', 'entero', 'euros', 'euros', 'euros' );
			$totales  = array( asde_t( 'Total' ), $suma[0], round( $suma[1], 2 ), round( $suma[2], 2 ), round( $suma[3], 2 ) );
			$archivo  = 'ventas-por-forma-de-pago';
		} elseif ( 'pendientes' === $hoja ) {
			$titulo   = asde_t( 'Pendientes de cobro' );
			$personas = $this->personas( Informes::pendientes( $pedidos ) );
			$filas    = array();
			$suma     = 0.0;
			foreach ( Informes::pendientes( $pedidos ) as $p ) {
				$importe = Servicio::importe_a_cobrar( $p );
				$suma   += $importe;
				$filas[] = array( PedidosTable::numero( $p ), Servicio::comprador( $p, $personas[ $p['socio'] ] ?? null ), $fecha( $p['creado'] ), asde_t( PedidosTable::FORMAS[ $p['forma_pago'] ] ?? 'Sin indicar' ), (int) $p['dias'], round( $importe, 2 ) );
			}
			$cabecera = array( asde_t( 'Pedido' ), asde_t( 'Quién' ), asde_t( 'Fecha' ), asde_t( 'Forma de pago' ), asde_t( 'Días esperando' ), asde_t( 'Importe' ) );
			$tipos    = array( 'texto', 'texto', 'texto', 'texto', 'entero', 'euros' );
			$totales  = array( asde_t( 'Total' ), '', '', '', '', round( $suma, 2 ) );
			$archivo  = 'pendientes-de-cobro';
		} else {
			$titulo  = asde_t( 'Ventas por producto' );
			$filas   = array();
			$suma    = array( 0, 0.0, 0.0 );
			foreach ( Informes::por_producto( $pedidos, $cfg ) as $d ) {
				$filas[] = array( $d['producto'], $d['variante'], $d['unidades'], round( $d['importe'], 2 ), round( $d['pagado'], 2 ) );
				$suma[0] += $d['unidades'];
				$suma[1] += $d['importe'];
				$suma[2] += $d['pagado'];
			}
			$cabecera = array( asde_t( 'Producto' ), asde_t( 'Variante' ), asde_t( 'Unidades' ), asde_t( 'Importe' ), asde_t( 'De ellos, pagado' ) );
			$tipos    = array( 'texto', 'texto', 'entero', 'euros', 'euros' );
			$totales  = array( asde_t( 'Total' ), '', $suma[0], round( $suma[1], 2 ), round( $suma[2], 2 ) );
			$archivo  = 'ventas-por-producto';
		}
		Logger::log( 'tienda_informe_exportado', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'hoja' => $archivo ) );
		Xlsx::descargar(
			$archivo . '-' . wp_date( 'Ymd' ) . '.xlsx',
			$titulo,
			$cabecera,
			$filas,
			array(
				'titulo'    => $titulo . ' · ' . $grupo,
				'subtitulo' => $subtitulo,
				'tipos'     => $tipos,
				'totales'   => $totales,
			)
		);
		exit;
	}

	/** Descarga CSV de la lista (se llama antes de pintar la página). */
	public function exportar(): void {
		if ( ! current_user_can( Servicio::CAP_GESTION ) || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_uniformes_csv' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$todos    = Servicio::de_gestion( Ambito::actual() );
		$personas = $this->personas( $todos );
		$lista    = $this->filtrar( $todos, $personas, $this->filtro(), $this->get( 'seccion' ), $this->get( 'q' ) );
		Logger::log( 'uniformes_exportados', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'pedidos' => count( $lista ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="pedidos-uniforme-' . wp_date( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output (descarga), no es el sistema de ficheros
		fputcsv( $out, array( asde_t( 'Nº pedido' ), asde_t( 'Socio/a' ), asde_t( 'Sección' ), asde_t( 'Modelo' ), asde_t( 'Talla' ), asde_t( 'Insignias' ), asde_t( 'Importe' ), asde_t( 'Pagado' ), asde_t( 'Método' ), asde_t( 'Pedido del grupo' ), asde_t( 'Entregado' ), asde_t( 'Recibido' ), asde_t( 'Estado' ) ), ';' );
		$num   = static fn( ?float $v ) => null === $v ? '' : number_format( $v, 2, ',', '' );
		$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
		foreach ( $lista as $p ) {
			$c         = $p['lineas']['camisa'];
			$insignias = implode( ', ', array_map( static fn( $i ) => $i['nombre'] . ' ×' . (int) $i['cantidad'], $p['lineas']['insignias'] ) );
			$secciones = implode( ', ', array_map( static fn( $s ) => Secciones::all()[ $s ]['nombre'] ?? $s, $p['secciones'] ) );
			$fila      = array(
				PedidosTable::numero( $p ),
				isset( $personas[ $p['socio'] ] ) ? $personas[ $p['socio'] ]->nombre_completo() : $p['socio'],
				$secciones,
				$c ? $c['nombre'] : '',
				$c ? $c['talla'] : '',
				$insignias,
				$num( $p['total'] ) . ( $p['provisional'] ? asde_t( ' + insignias por confirmar' ) : '' ),
				null !== $p['pago_en'] ? $fecha( $p['pago_fecha'] ) . ' · ' . $num( $p['pago_importe'] ) : '',
				asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ),
				null !== $p['pedido_en'] ? $p['pedido_ref'] : '',
				$fecha( $p['entregado_en'] ),
				$fecha( $p['recibido_en'] ),
				asde_t( PedidosTable::ESTADOS[ PedidosTable::estado( $p ) ] ),
			);
			fputcsv( $out, array_map( array( Generador::class, 'celda_segura' ), $fila ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output (descarga)
		exit;
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$a      = Ambito::actual();
		$accion = $this->post( 'asde_action' );
		$lista  = wp_validate_redirect( esc_url_raw( $this->post( 'volver' ) ), $this->url( 'tienda_gestion' ) );

		// Incluir varios en el pedido del grupo (Coordinación).
		if ( 'uniforme_incluir_lote' === $accion ) {
			check_admin_referer( 'asde_uniformes_lote' );
			if ( ! current_user_can( Servicio::CAP_PEDIDO ) ) {
				Notices::add( 'error', asde_t( 'Incluir en el pedido del grupo lo hace Coordinación.' ) );
				$this->redirect( $lista );
			}
			$ids = array_filter(
				array_map( 'intval', $this->post_array( 'pedidos' ) ),
				static fn( int $id ) => ( $p = PedidosTable::obtener( $id ) ) && Servicio::en_gestion( $p, $a )
			);
			$ref = $this->referencia_pedido();
			$n   = $ids ? PedidosTable::marcar_pedido( $ids, $ref ) : 0;
			if ( $n ) {
				Logger::log( 'uniformes_incluidos', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'pedidos' => $n, 'ref' => $ref ) );
				Notices::add( 'success', esc_html( sprintf( asde_n( '%1$d pedido incluido en «%2$s».', '%1$d pedidos incluidos en «%2$s».', $n ), $n, $ref ) ) );
			} else {
				Notices::add( 'error', asde_t( 'Marca los pedidos que quieres incluir (los que aún no están en un pedido).' ) );
			}
			$this->redirect( $lista );
		}

		// Marcar pagado un pedido con un ingreso del banco que lo menciona (v0.40, fase 5).
		if ( 'tienda_banco_pagar' === $accion ) {
			$pid = (int) $this->post( 'pedido' );
			$mid = (int) $this->post( 'movimiento' );
			check_admin_referer( 'asde_tienda_banco_' . $pid . '_' . $mid );
			$pedido = PedidosTable::obtener( $pid );
			if ( ! $pedido || ! Servicio::en_gestion( $pedido, $a ) || ! Servicio::puede_pagar( $pedido ) ) {
				Notices::add( 'error', asde_t( 'No tienes permiso para hacer esto con este pedido.' ) );
			} else {
				$r = ConciliacionBanco::confirmar( $pid, $mid );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
				} else {
					/* translators: %s: nº de pedido */
					Notices::add( 'success', esc_html( sprintf( asde_t( 'Pedido %s marcado como pagado con el ingreso del banco.' ), PedidosTable::numero( $pedido ) ) ) );
				}
			}
			$this->redirect( $lista );
		}

		$p = PedidosTable::obtener( (int) $this->post( 'pedido' ) );
		check_admin_referer( 'asde_uniforme_' . ( $p['id'] ?? 0 ) );
		if ( ! $p || ! Servicio::en_gestion( $p, $a ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a ese pedido.' ) );
			$this->redirect( $lista );
		}
		$volver = 'detalle' === $this->post( 'desde' ) ? $this->url( 'tienda_gestion', array( 'pedido' => (string) $p['id'] ) ) : $lista;
		$num    = PedidosTable::numero( $p );
		$error  = asde_t( 'No tienes permiso para hacer esto con este pedido.' );
		$hecho  = '';

		switch ( $accion ) {
			case 'uniforme_pago':
				if ( ! Servicio::puede_pagar( $p ) ) {
					break;
				}
				$metodo  = sanitize_key( $this->post( 'metodo' ) );
				$fecha   = $this->post( 'fecha' );
				$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );
				if ( ! isset( PedidosTable::METODOS[ $metodo ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || $fecha > wp_date( 'Y-m-d' ) || $importe < 0 ) {
					$error = asde_t( 'Indica cómo se ha pagado (efectivo, ingreso o Bizum), la fecha (no posterior a hoy) y el importe.' );
					break;
				}
				PedidosTable::marcar_pago( $p['id'], $fecha, $metodo, $importe, $this->post( 'referencia' ) );
				Avisos::enviar( (array) PedidosTable::obtener( $p['id'] ), Avisos::PAGADO ); // v0.39: aviso a quien hizo el pedido.
				Logger::log( 'uniforme_pago', 'uniforme', (string) $p['id'], array( 'metodo' => $metodo, 'importe' => $importe ) );
				$hecho = sprintf( asde_t( 'Pago de %s (%s) validado en el pedido %s.' ), Calculadora::euros( $importe ), mb_strtolower( asde_t( PedidosTable::METODOS[ $metodo ] ) ), $num );
				break;

			case 'uniforme_deshacer_pago':
				if ( Servicio::puede_pagar( $p ) && PedidosTable::deshacer_pago( $p['id'] ) ) {
					Logger::log( 'uniforme_pago_deshecho', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'Pago deshecho en el pedido %s.' ), $num );
					if ( ConciliacionBanco::deshacer( $p ) ) {
						$hecho .= ' ' . asde_t( 'El ingreso del banco vuelve a estar por conciliar.' ); // v0.40 (fase 5).
					}
				}
				break;

			case 'uniforme_incluir':
				if ( Servicio::puede_pedir( $p ) && PedidosTable::marcar_pedido( array( $p['id'] ), $this->referencia_pedido() ) ) {
					Logger::log( 'uniforme_incluido', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'Pedido %s incluido en el pedido del grupo.' ), $num );
				}
				break;

			case 'uniforme_deshacer_pedido':
				if ( Servicio::puede_pedir( $p ) && PedidosTable::deshacer_pedido( $p['id'] ) ) {
					Logger::log( 'uniforme_incluido_deshecho', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'El pedido %s ya no consta en el pedido del grupo.' ), $num );
				}
				break;

			case 'uniforme_entregar':
				if ( Servicio::puede_entregar( $p, $a ) && null === $p['entregado_en'] && PedidosTable::marcar_entregado( $p['id'] ) ) {
					Avisos::enviar( (array) PedidosTable::obtener( $p['id'] ), Avisos::ENTREGADO ); // v0.39.
					Logger::log( 'uniforme_entregado', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'Pedido %s marcado como entregado. Falta que lo confirme el socio/a o su familia.' ), $num );
				}
				break;

			case 'uniforme_deshacer_entrega':
				if ( Servicio::puede_entregar( $p, $a ) && PedidosTable::deshacer_entrega( $p['id'] ) ) {
					Logger::log( 'uniforme_entrega_deshecha', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'Entrega deshecha en el pedido %s.' ), $num );
				}
				break;

			case 'uniforme_cancelar':
				$motivo = $this->post( 'motivo' );
				if ( Servicio::puede_cancelar_gestion( $p ) ) {
					if ( '' === trim( $motivo ) ) {
						$error = asde_t( 'Indica el motivo de la cancelación.' );
						break;
					}
					Servicio::cancelar( $p, $motivo ); // Lo del catálogo vuelve a las existencias.
					Logger::log( 'uniforme_cancelado', 'uniforme', (string) $p['id'], array( 'por' => 'grupo' ) );
					$hecho  = sprintf( asde_t( 'Pedido %s cancelado.' ), $num );
					$volver = $lista;
				}
				break;

			default:
				$error = asde_t( 'Acción desconocida.' );
		}

		'' !== $hecho ? Notices::add( 'success', esc_html( $hecho ) ) : Notices::add( 'error', esc_html( $error ) );
		$this->redirect( $volver );
	}

	/** Nombre del pedido del grupo al proveedor (p. ej. «Pedido 29/09/2026»). */
	private function referencia_pedido(): string {
		$ref = trim( sanitize_text_field( $this->post( 'referencia_pedido' ) ) );
		return '' !== $ref ? mb_substr( $ref, 0, 60 ) : sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) );
	}
}
