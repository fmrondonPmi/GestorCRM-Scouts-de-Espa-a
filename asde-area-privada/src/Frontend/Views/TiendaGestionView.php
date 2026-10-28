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
 * v0.44 (doc 48): ya no se sirven ni asignan camisas del Inventario general; la tienda tiene
 * sus propias existencias (Productos).
 */
final class TiendaGestionView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			return $this->denegado();
		}
		$a = Ambito::actual();
		if ( '1' === $this->get( 'informe' ) ) {
			return $this->layout( 'tienda_gestion', Servicio::puede_informes() ? $this->informe( $a ) : $this->denegado( __( 'Sales reports are seen by Treasury, Coordination and the shop administration.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
		}
		$id = (int) $this->get( 'pedido' );
		if ( $id ) {
			$p = PedidosTable::obtener( $id );
			if ( ! $p || ! Servicio::en_gestion( $p, $a ) ) {
				return $this->denegado( __( 'You do not have access to that order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
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
				'tienda/banco-datafono',
				array(
					'abonos' => ConciliacionBanco::sugerencias_datafono(),
					'volver' => $this->url( 'tienda_gestion' ),
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
					'n_pedir'      => count( \AsdeBalears\AreaPrivada\Tienda\Proveedor::lineas_de( \AsdeBalears\AreaPrivada\Tienda\Proveedor::por_pedir() ) ), // v0.48: lo pagado pendiente de pedir (su pestaña lo detalla).
					'url_proveedor' => $this->url( 'tienda_proveedor' ),
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
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_tienda_informe' ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! Servicio::puede_informes() ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$a       = Ambito::actual();
		$pedidos = $this->del_periodo( $a );
		list( $desde, $hasta ) = $this->periodo();
		$hoja = $this->get( 'hoja' );
		$cfg  = Config::de();
		$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
		/* translators: 1: periodo, 2: fecha */
		$periodo   = ( '' === $desde && '' === $hasta ) ? __( 'All orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : trim( ( '' !== $desde ? sprintf( /* translators: %s: text */ __( 'from %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $desde ) ) : '' ) . ' ' . ( '' !== $hasta ? sprintf( /* translators: %s: text */ __( 'until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $hasta ) ) : '' ) );
		$subtitulo = sprintf( /* translators: 1: text, 2: text */ __( '%1$s · generated on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $periodo, wp_date( 'd/m/Y' ) );
		$grupo     = \AsdeBalears\AreaPrivada\Domain\Grupo::nombre();

		if ( 'formas' === $hoja ) {
			$titulo = __( 'Sales by payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			$filas  = array();
			$suma   = array( 0, 0.0, 0.0, 0.0 );
			foreach ( Informes::por_forma( $pedidos ) as $forma => $d ) {
				$filas[] = array( '' !== $forma ? asde_t( PedidosTable::FORMAS[ $forma ] ) : __( 'Not stated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $d['pedidos'], round( $d['total'], 2 ), round( $d['cobrado'], 2 ), round( $d['pendiente'], 2 ) );
				$suma[0] += $d['pedidos'];
				$suma[1] += $d['total'];
				$suma[2] += $d['cobrado'];
				$suma[3] += $d['pendiente'];
			}
			$cabecera = array( __( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'To collect', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$tipos    = array( 'texto', 'entero', 'euros', 'euros', 'euros' );
			$totales  = array( __( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $suma[0], round( $suma[1], 2 ), round( $suma[2], 2 ), round( $suma[3], 2 ) );
			$archivo  = 'ventas-por-forma-de-pago';
		} elseif ( 'pendientes' === $hoja ) {
			$titulo   = __( 'Pending collection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			$personas = $this->personas( Informes::pendientes( $pedidos ) );
			$filas    = array();
			$suma     = 0.0;
			foreach ( Informes::pendientes( $pedidos ) as $p ) {
				$importe = Servicio::importe_a_cobrar( $p );
				$suma   += $importe;
				$filas[] = array( PedidosTable::numero( $p ), Servicio::comprador( $p, $personas[ $p['socio'] ] ?? null ), $fecha( $p['creado'] ), asde_t( PedidosTable::FORMAS[ $p['forma_pago'] ] ?? 'Sin indicar' ), (int) $p['dias'], round( $importe, 2 ) );
			}
			$cabecera = array( __( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Days waiting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$tipos    = array( 'texto', 'texto', 'texto', 'texto', 'entero', 'euros' );
			$totales  = array( __( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', '', '', '', round( $suma, 2 ) );
			$archivo  = 'pendientes-de-cobro';
		} else {
			$titulo  = __( 'Sales by product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			$filas   = array();
			$suma    = array( 0, 0.0, 0.0 );
			foreach ( Informes::por_producto( $pedidos, $cfg ) as $d ) {
				$filas[] = array( $d['producto'], $d['variante'], $d['unidades'], round( $d['importe'], 2 ), round( $d['pagado'], 2 ) );
				$suma[0] += $d['unidades'];
				$suma[1] += $d['importe'];
				$suma[2] += $d['pagado'];
			}
			$cabecera = array( __( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Units', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Of which, paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$tipos    = array( 'texto', 'texto', 'entero', 'euros', 'euros' );
			$totales  = array( __( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', $suma[0], round( $suma[1], 2 ), round( $suma[2], 2 ) );
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
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_uniformes_csv' ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
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
		fputcsv( $out, array( __( 'Order no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Size', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Badges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Group order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), ';' );
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
				$num( $p['total'] ) . ( $p['provisional'] ? __( ' + badges to be confirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '' ),
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$a      = Ambito::actual();
		$accion = $this->post( 'asde_action' );
		$lista  = wp_validate_redirect( esc_url_raw( $this->post( 'volver' ) ), $this->url( 'tienda_gestion' ) );

		// Incluir varios en el pedido del grupo (Coordinación).
		if ( 'uniforme_incluir_lote' === $accion ) {
			check_admin_referer( 'asde_uniformes_lote' );
			if ( ! current_user_can( Servicio::CAP_PEDIDO ) ) {
				Notices::add( 'error', __( 'Including in the group\'s order is done by Coordination.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
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
				Notices::add( 'success', esc_html( sprintf( /* translators: 1: number, 2: text */ _n( '%1$d order included in "%2$s".', '%1$d orders included in "%2$s".', $n , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n, $ref ) ) );
			} else {
				Notices::add( 'error', __( 'Tick the orders you want to include (those not yet in an order).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$this->redirect( $lista );
		}

		// El abono del datáfono (v0.46, T3): Tesorería confirma que un ingreso del banco es el de estos cobros.
		if ( 'tienda_banco_datafono' === $accion ) {
			$mid = (int) $this->post( 'movimiento' );
			check_admin_referer( 'asde_tienda_datafono_' . $mid );
			$r = ConciliacionBanco::confirmar_datafono( $mid, array_map( 'intval', $this->post_array( 'pedidos' ) ) );
			if ( is_wp_error( $r ) ) {
				Notices::add( 'error', esc_html( $r->get_error_message() ) );
			} else {
				Notices::add( 'success', esc_html( __( 'Card terminal payout reconciled with the bank deposit.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
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
				Notices::add( 'error', __( 'You do not have permission to do this with this order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			} else {
				$r = ConciliacionBanco::confirmar( $pid, $mid );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
				} else {
					/* translators: %s: nº de pedido */
					Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( 'Order %s marked as paid with the bank deposit.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), PedidosTable::numero( $pedido ) ) ) );
				}
			}
			$this->redirect( $lista );
		}

		$p = PedidosTable::obtener( (int) $this->post( 'pedido' ) );
		check_admin_referer( 'asde_uniforme_' . ( $p['id'] ?? 0 ) );
		if ( ! $p || ! Servicio::en_gestion( $p, $a ) ) {
			Notices::add( 'error', __( 'You do not have access to that order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $lista );
		}
		$volver = 'detalle' === $this->post( 'desde' ) ? $this->url( 'tienda_gestion', array( 'pedido' => (string) $p['id'] ) ) : $lista;
		$num    = PedidosTable::numero( $p );
		$error  = __( 'You do not have permission to do this with this order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
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
					$error = __( 'State how it was paid (cash, deposit, Bizum or card terminal), the date (not later than today) and the amount.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					break;
				}
				PedidosTable::marcar_pago( $p['id'], $fecha, $metodo, $importe, $this->post( 'referencia' ) );
				Avisos::enviar( (array) PedidosTable::obtener( $p['id'] ), Avisos::PAGADO ); // v0.39: aviso a quien hizo el pedido.
				Logger::log( 'uniforme_pago', 'uniforme', (string) $p['id'], array( 'metodo' => $metodo, 'importe' => $importe ) );
				$hecho = sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Payment of %1$s (%2$s) validated on order %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $importe ), mb_strtolower( asde_t( PedidosTable::METODOS[ $metodo ] ) ), $num );
				break;

			case 'uniforme_deshacer_pago':
				if ( Servicio::puede_pagar( $p ) && PedidosTable::deshacer_pago( $p['id'] ) ) {
					Logger::log( 'uniforme_pago_deshecho', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Payment undone on order %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
					if ( ConciliacionBanco::deshacer( $p ) || ConciliacionBanco::deshacer_datafono( $p ) ) {
						$hecho .= ' ' . __( 'The bank deposit is pending reconciliation again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); // v0.40 (fase 5) y v0.46 (datáfono).
					}
				}
				break;

			case 'uniforme_incluir':
				if ( Servicio::puede_pedir( $p ) && PedidosTable::marcar_pedido( array( $p['id'] ), $this->referencia_pedido() ) ) {
					Logger::log( 'uniforme_incluido', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Order %s included in the group\'s order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'uniforme_deshacer_pedido':
				if ( Servicio::puede_pedir( $p ) && PedidosTable::deshacer_pedido( $p['id'] ) ) {
					Logger::log( 'uniforme_incluido_deshecho', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Order %s is no longer part of the group\'s order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'uniforme_entregar':
				if ( Servicio::puede_entregar( $p, $a ) && null === $p['entregado_en'] && PedidosTable::marcar_entregado( $p['id'] ) ) {
					Avisos::enviar( (array) PedidosTable::obtener( $p['id'] ), Avisos::ENTREGADO ); // v0.39.
					Logger::log( 'uniforme_entregado', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Order %s marked as delivered. The member or their family still has to confirm it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'uniforme_deshacer_entrega':
				if ( Servicio::puede_entregar( $p, $a ) && PedidosTable::deshacer_entrega( $p['id'] ) ) {
					Logger::log( 'uniforme_entrega_deshecha', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Delivery undone on order %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'uniforme_cancelar':
				$motivo = $this->post( 'motivo' );
				if ( Servicio::puede_cancelar_gestion( $p ) ) {
					if ( '' === trim( $motivo ) ) {
						$error = __( 'State the reason for the cancellation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						break;
					}
					Servicio::cancelar( $p, $motivo ); // Lo del catálogo vuelve a las existencias.
					Logger::log( 'uniforme_cancelado', 'uniforme', (string) $p['id'], array( 'por' => 'grupo' ) );
					$hecho  = sprintf( /* translators: %s: text */ __( 'Order %s cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
					$volver = $lista;
				}
				break;

			default:
				$error = __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}

		'' !== $hecho ? Notices::add( 'success', esc_html( $hecho ) ) : Notices::add( 'error', esc_html( $error ) );
		$this->redirect( $volver );
	}

	/** Nombre del pedido del grupo al proveedor (p. ej. «Pedido 29/09/2026»). */
	private function referencia_pedido(): string {
		$ref = trim( sanitize_text_field( $this->post( 'referencia_pedido' ) ) );
		return '' !== $ref ? mb_substr( $ref, 0, 60 ) : sprintf( /* translators: %s: text */ __( 'Order %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y' ) );
	}
}
