<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de los pedidos de uniforme (scouters, Tesorería, Coordinación):
 * validar el pago, incluir en el pedido del grupo, marcar la entrega, cancelar;
 * resumen de tallas e insignias para el proveedor y descarga en CSV.
 *
 * v0.29: camisas del inventario del grupo (tipo «Camisa uniforme»): lo que hay por modelo
 * y talla, servir del inventario la camisa de un pedido y asignar una a quien no la pidió.
 */
final class TiendaGestionView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			return $this->denegado();
		}
		$a = Ambito::actual();
		if ( '1' === $this->get( 'asignar' ) ) {
			return $this->layout( 'tienda_gestion', Servicio::puede_inventario() ? $this->asignar( $a ) : $this->denegado( asde_t( 'Servir y asignar camisas del inventario lo hacen Coordinación y Tesorería.' ) ) );
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

		return $this->layout(
			'tienda_gestion',
			$this->subnav( 'tienda_gestion' ) . $this->tpl(
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
					'stock'        => Servicio::puede_inventario() ? MaterialesTable::camisas() : null,
					'url_asignar'  => $this->url( 'tienda_gestion', array( 'asignar' => '1' ) ),
					'url_inv'      => Servicio::puede_inventario() ? $this->url( 'inventario', array( 'tipo' => (string) TiposTable::camisa() ) ) : '',
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
				'stock'    => Servicio::puede_inventario() ? MaterialesTable::camisas() : array(),
				'url'      => $this->url( 'tienda_gestion' ),
				'url_ficha' => $this->url( 'ficha', array( 'socio' => $p['socio'] ) ),
			)
		);
	}

	/** «Asignar una camisa del inventario» (v0.29): en la ventana emergente o en su página. */
	private function asignar( Ambito $a ): string {
		$repo = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make();
		try {
			$personas = $repo ? $repo->todas() : array();
		} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
			$personas = array();
		}
		$mias = $a->secciones_scouter();
		if ( ! Servicio::vision_global( $a ) ) {
			$personas = array_filter( $personas, static fn( $p ) => (bool) array_intersect( $p->secciones, $mias ) );
		}
		return $this->tpl(
			'tienda/asignar',
			array(
				'personas'  => array_values( $personas ),
				'stock'     => MaterialesTable::camisas(),
				'cfg'       => Config::de(),
				'socio'     => $this->get( 'socio' ),
				'url'       => $this->url( 'tienda_gestion', array( 'asignar' => '1' ) ),
				'url_lista' => $this->url( 'tienda_gestion' ),
				'url_inv'   => $this->url( 'inventario', array( 'tipo' => (string) TiposTable::camisa() ) ),
			)
		);
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

		// Asignar una camisa del inventario a quien no la ha pedido (v0.29).
		if ( 'uniforme_asignar' === $accion ) {
			check_admin_referer( 'asde_uniformes_asignar' );
			$this->asignar_post( $a );
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
					$error = asde_t( 'Indica cómo se ha pagado (efectivo o ingreso), la fecha (no posterior a hoy) y el importe.' );
					break;
				}
				PedidosTable::marcar_pago( $p['id'], $fecha, $metodo, $importe, $this->post( 'referencia' ) );
				Logger::log( 'uniforme_pago', 'uniforme', (string) $p['id'], array( 'metodo' => $metodo, 'importe' => $importe ) );
				$hecho = sprintf( asde_t( 'Pago de %s (%s) validado en el pedido %s.' ), Calculadora::euros( $importe ), mb_strtolower( asde_t( PedidosTable::METODOS[ $metodo ] ) ), $num );
				break;

			case 'uniforme_deshacer_pago':
				if ( Servicio::puede_pagar( $p ) && PedidosTable::deshacer_pago( $p['id'] ) ) {
					Logger::log( 'uniforme_pago_deshecho', 'uniforme', (string) $p['id'] );
					$hecho = sprintf( asde_t( 'Pago deshecho en el pedido %s.' ), $num );
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

			case 'uniforme_servir':
				$r = Servicio::servir( $p );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
					break;
				}
				/* translators: %s: nº de pedido */
				$hecho = sprintf( asde_t( 'La camisa del pedido %s sale del inventario: se ha descontado una.' ), $num );
				break;

			case 'uniforme_devolver':
				$r = Servicio::devolver( $p );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
					break;
				}
				/* translators: %s: nº de pedido */
				$hecho = sprintf( asde_t( 'La camisa del pedido %s ha vuelto al inventario.' ), $num );
				break;

			case 'uniforme_cancelar':
				$motivo = $this->post( 'motivo' );
				if ( Servicio::puede_cancelar_gestion( $p ) ) {
					if ( '' === trim( $motivo ) ) {
						$error = asde_t( 'Indica el motivo de la cancelación.' );
						break;
					}
					Servicio::cancelar( $p, $motivo ); // Si su camisa había salido del inventario, vuelve a él (v0.29).
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

	/** Asigna una camisa del inventario (formulario de asignar()); siempre redirige. */
	private function asignar_post( Ambito $a ): void {
		$volver = $this->url( 'tienda_gestion', array( 'asignar' => '1' ) );
		$socio  = preg_match( '/^\d{1,20}$/', $this->post( 'socio' ) ) ? $this->post( 'socio' ) : '';
		$repo   = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make();
		try {
			$persona = '' !== $socio && $repo ? $repo->obtener( $socio ) : null;
		} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
			$persona = null;
		}
		if ( ! $persona || ! $persona->tiene_membresia_activa() || ( ! Servicio::vision_global( $a ) && ! array_intersect( $persona->secciones, $a->secciones_scouter() ) ) ) {
			Notices::add( 'error', asde_t( 'Elige a quién se le da la camisa (un socio/a con membresía activa).' ) );
			$this->redirect( $volver );
		}
		list( $modelo, $talla ) = array_pad( explode( '|', $this->post( 'camisa' ), 2 ), 2, '' );
		$r                      = Servicio::asignar( $persona, sanitize_key( $modelo ), $talla, $this->post( 'nota' ) );
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
			$this->redirect( add_query_arg( 'socio', $socio, $volver ) );
		}
		$nuevo = PedidosTable::obtener( (int) $r );
		/* translators: 1: nombre, 2: nº de pedido */
		Notices::add( 'success', esc_html( sprintf( asde_t( 'Camisa asignada a %1$s y descontada del inventario (pedido %2$s).' ), $persona->nombre_completo(), $nuevo ? PedidosTable::numero( $nuevo ) : '' ) ) );
		$this->redirect( $this->url( 'tienda_gestion', array( 'pedido' => (string) $r ) ) );
	}

	/** Nombre del pedido del grupo al proveedor (p. ej. «Pedido 29/09/2026»). */
	private function referencia_pedido(): string {
		$ref = trim( sanitize_text_field( $this->post( 'referencia_pedido' ) ) );
		return '' !== $ref ? mb_substr( $ref, 0, 60 ) : sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) );
	}
}
