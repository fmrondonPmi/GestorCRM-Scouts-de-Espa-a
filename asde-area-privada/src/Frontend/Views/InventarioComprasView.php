<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Inventario\AvisoRevision;
use AsdeBalears\AreaPrivada\Inventario\AvisoTesoreria;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;
use AsdeBalears\AreaPrivada\Inventario\ListaPdf;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Listas de compra de material (doc 32): preparar una con el material marcado en
 * el inventario, el listado, el detalle (en la ventana emergente), el visto bueno
 * de Tesorería y el PDF para imprimir, que solo sale con ese visto bueno.
 *
 * v0.30: material nuevo en la lista (P-63), email a quien la pidió al
 * aprobarla o rechazarla (P-65) y «Marcar como comprada» (P-66).
 */
final class InventarioComprasView extends InventarioBaseView {

	/** Filas en blanco de material nuevo que se pintan de más (sin JavaScript, «Añadir otra línea» suma una). */
	private const NUEVOS_EN_BLANCO = 1;

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP ) ) {
			return $this->denegado();
		}
		$id = (int) $this->get( 'lista' );
		if ( $id ) {
			$l = ComprasTable::obtener( $id );
			if ( ! $l ) {
				return $this->denegado( __( 'That shopping list does not belong to your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			return $this->layout( 'inventario_compras', $this->detalle( $l ) );
		}
		if ( '1' === $this->get( 'nueva' ) ) {
			return $this->layout( 'inventario_compras', $this->subnav( 'inventario_compras' ) . $this->nueva() );
		}

		$todas  = ComprasTable::todas();
		$estado = isset( ComprasTable::estados()[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : '';
		$cuenta = array();
		foreach ( array_keys( ComprasTable::estados() ) as $e ) {
			$cuenta[ $e ] = count( array_filter( $todas, static fn( $l ) => $e === $l['estado'] ) );
		}
		return $this->layout(
			'inventario_compras',
			$this->subnav( 'inventario_compras' ) . $this->tpl(
				'inventario/compras',
				array(
					'listas'   => '' === $estado ? $todas : array_values( array_filter( $todas, static fn( $l ) => $estado === $l['estado'] ) ),
					'estado'   => $estado,
					'cuenta'   => $cuenta,
					'total'    => count( $todas ),
					'aprueba'  => current_user_can( Servicio::CAP_APROBAR ),
					'url'      => $this->url( 'inventario_compras' ),
					'url_inv'  => $this->url( 'inventario' ),
					'url_pdf'  => fn( array $l ) => $this->url_pdf( $l ),
					'usuario'  => array( Servicio::class, 'usuario' ),
				)
			)
		);
	}

	/** Formulario «Nueva lista» con el material marcado en el inventario (?m[]=id) y, v0.30, material nuevo. */
	private function nueva(): string {
		$borrador = $this->borrador();
		$ids      = $borrador ? array_keys( (array) ( $borrador['cantidad'] ?? array() ) ) : $this->marcados();
		$todos    = MaterialesTable::todos();
		$elegidos = array_values( array_filter( array_map( static fn( $id ) => $todos[ (int) $id ] ?? null, $ids ) ) );
		// Material nuevo: lo escrito la vez anterior y, al final, al menos una fila en blanco.
		$nuevos = array_values( (array) ( $borrador['nuevos'] ?? array() ) );
		$ultima = end( $nuevos );
		if ( ! $nuevos || '' !== trim( (string) ( $ultima['descripcion'] ?? '' ) ) || '' !== trim( (string) ( $ultima['precio'] ?? '' ) ) ) {
			for ( $i = 0; $i < self::NUEVOS_EN_BLANCO; $i++ ) {
				$nuevos[] = array(
					'descripcion' => '',
					'cantidad'    => '1',
					'precio'      => '',
				);
			}
		}
		return $this->tpl(
			'inventario/nueva',
			array(
				'materiales' => $elegidos,
				'nuevos'     => array_slice( $nuevos, 0, ComprasTable::MAX_LINEAS ),
				'tipos'      => TiposTable::todos(),
				'borrador'   => $borrador,
				'perdidos'   => count( $ids ) - count( $elegidos ),
				'url'        => $this->url( 'inventario_compras' ),
				'url_inv'    => $this->url( 'inventario' ),
			)
		);
	}

	/** @return int[] Material marcado en el inventario. */
	private function marcados(): array {
		$m = isset( $_GET['m'] ) && is_array( $_GET['m'] ) ? wp_unslash( $_GET['m'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- solo se leen números (absint) para pintar un formulario
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $m ) ) ) );
	}

	private function borrador(): array {
		$clave = 'asde_ap_inv_compra_' . get_current_user_id();
		$datos = get_transient( $clave );
		delete_transient( $clave );
		return is_array( $datos ) ? $datos : array();
	}

	private function detalle( array $l ): string {
		return $this->tpl(
			'inventario/compra',
			array(
				'l'        => $l,
				'usuario'  => array( Servicio::class, 'usuario' ),
				'url'      => $this->url( 'inventario_compras', array( 'lista' => (string) $l['id'] ) ),
				'url_list' => $this->url( 'inventario_compras' ),
				'url_pdf'  => Servicio::puede_imprimir( $l ) ? $this->url_pdf( $l ) : '',
				'url_inv'  => $this->url( 'inventario' ),
			)
		);
	}

	private function url_pdf( array $l ): string {
		return $this->url(
			'inventario_compras',
			array(
				'formato'  => 'pdf',
				'lista'    => (string) $l['id'],
				'_wpnonce' => wp_create_nonce( 'asde_inventario_pdf_' . ( $l['id'] ?? 0 ) ),
			)
		);
	}

	/** PDF para imprimir (se llama antes de pintar la página). Sin el visto bueno de Tesorería, no. */
	public function pdf(): void {
		$l = ComprasTable::obtener( (int) $this->get( 'lista' ) );
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_inventario_pdf_' . ( $l['id'] ?? 0 ) ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! $l || ! current_user_can( Servicio::CAP ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! Servicio::puede_imprimir( $l ) ) {
			wp_die( esc_html( __( 'This shopping list does not yet have Treasury\'s approval: it cannot be printed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 403 );
		}
		$pdf = ListaPdf::generar( $l );
		ComprasTable::marcar_impresa( $l['id'] );
		Logger::log( 'inventario_compra_pdf', 'inventario_compra', (string) $l['id'] );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'lista-compra-' . ComprasTable::numero( $l ) . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga PDF, no HTML
		exit;
	}

	public function handle_post(): void {
		$this->denegar_post();
		$accion = $this->post( 'asde_action' );
		$listas = $this->url( 'inventario_compras' );

		if ( 'compra_crear' === $accion ) {
			check_admin_referer( 'asde_inventario_compra_nueva' );
			$cantidades = $this->post_mapa( 'cantidad' );
			$precios    = $this->post_mapa( 'precio' );
			$nuevos     = $this->nuevos();
			$nota       = $this->post( 'nota' );
			$borrador   = array(
				'cantidad' => $cantidades,
				'precio'   => $precios,
				'nuevos'   => $nuevos,
				'nota'     => $nota,
			);
			// v0.30: «Añadir otra línea» sin JavaScript vuelve al formulario, con lo escrito y una fila más.
			if ( '1' === $this->post( 'mas_lineas' ) ) {
				$borrador['nuevos'][] = array(
					'descripcion' => '',
					'cantidad'    => '1',
					'precio'      => '',
				);
				set_transient( 'asde_ap_inv_compra_' . get_current_user_id(), $borrador, 10 * MINUTE_IN_SECONDS );
				$this->redirect( add_query_arg( 'nueva', '1', $listas ) . '#asde-inv-nuevos' );
			}
			$lineas = Servicio::lineas( $cantidades, $precios, $nuevos );
			if ( is_wp_error( $lineas ) ) {
				set_transient( 'asde_ap_inv_compra_' . get_current_user_id(), $borrador, 10 * MINUTE_IN_SECONDS );
				Notices::add( 'error', esc_html( $lineas->get_error_message() ) );
				$this->redirect( add_query_arg( 'nueva', '1', $listas ) );
			}
			$id = ComprasTable::crear( $lineas, $nota );
			$l  = ComprasTable::obtener( $id );
			Logger::log(
				'inventario_compra_creada',
				'inventario_compra',
				(string) $id,
				array(
					'lineas' => count( $lineas ),
					'nuevas' => count( array_filter( $lineas, static fn( $x ) => 0 === $x['material'] ) ),
				)
			);
			$aviso = AvisoTesoreria::enviar( $l );
			$num   = ComprasTable::numero( $l );
			if ( $aviso['enviados'] && $aviso['contacto'] ) {
				/* translators: %s: nº de la lista */
				$texto = sprintf( /* translators: %s: text */ __( 'List %s created. There is nobody in Treasury with an email in the private area: the group\'s contact email has been notified. When Treasury approves it, you will be able to print it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
			} elseif ( $aviso['enviados'] ) {
				$texto = sprintf( /* translators: 1: text, 2: number */ _n( 'List %1$s created and sent to %2$d person in Treasury for approval. When it has it, you will be able to print it.', 'List %1$s created and sent to %2$d people in Treasury for approval. When it has it, you will be able to print it.', $aviso['enviados'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num, $aviso['enviados'] );
			} elseif ( current_user_can( Servicio::CAP_APROBAR ) ) {
				$texto = sprintf( /* translators: %s: text */ __( 'List %s created. There was nobody else in Treasury to notify: you can approve it yourself.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
			} else {
				$texto = sprintf( /* translators: %s: text */ __( 'List %s created, but Treasury could not be notified by email: tell them so they approve it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
			}
			Notices::add( $aviso['enviados'] || current_user_can( Servicio::CAP_APROBAR ) ? 'success' : 'warning', esc_html( $texto ) );
			$this->redirect( add_query_arg( 'lista', (string) $id, $listas ) );
		}

		$l = ComprasTable::obtener( (int) $this->post( 'lista' ) );
		check_admin_referer( 'asde_inventario_compra_' . ( $l['id'] ?? 0 ) );
		if ( ! $l ) {
			Notices::add( 'error', __( 'That shopping list does not belong to your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $listas );
		}
		$num   = ComprasTable::numero( $l );
		$hecho = '';
		$error = __( 'This cannot be done with this list (someone may have changed it in the meantime).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );

		switch ( $accion ) {
			case 'compra_aprobar':
				if ( Servicio::puede_revisar( $l ) && ComprasTable::revisar( $l['id'], true ) ) {
					Logger::log( 'inventario_compra_aprobada', 'inventario_compra', (string) $l['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'List %s approved by Treasury. It can now be printed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) . self::aviso_revision( $l['id'] );
				}
				break;

			case 'compra_rechazar':
				if ( Servicio::puede_revisar( $l ) ) {
					if ( '' === trim( $this->post( 'motivo' ) ) ) {
						$error = __( 'State the reason for the rejection: whoever requested it will see it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						break;
					}
					if ( ComprasTable::revisar( $l['id'], false, $this->post( 'motivo' ) ) ) {
						Logger::log( 'inventario_compra_rechazada', 'inventario_compra', (string) $l['id'] );
						$hecho = sprintf( /* translators: %s: text */ __( 'List %s rejected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) . self::aviso_revision( $l['id'] );
					}
				}
				break;

			case 'compra_comprada':
				// v0.30 (P-66): suma lo comprado al inventario. No se puede marcar dos veces (Servicio::comprar).
				$r = Servicio::comprar( $l );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
					break;
				}
				$hecho = sprintf( /* translators: %s: text */ __( 'List %s marked as bought.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				if ( $r['sumados'] ) {
					$hecho .= ' ' . sprintf( /* translators: %d: number */ _n( 'What was bought has been added to %d item of equipment in the inventory.', 'What was bought has been added to %d items of equipment in the inventory.', count( $r['sumados'] ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $r['sumados'] ) );
				}
				if ( $r['creados'] ) {
					$hecho .= ' ' . sprintf( /* translators: %d: number */ _n( '%d new item of equipment has been registered.', '%d new items of equipment have been registered.', count( $r['creados'] ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $r['creados'] ) );
				}
				break;

			case 'compra_deshacer':
				if ( Servicio::puede_deshacer( $l ) && ComprasTable::deshacer_aprobacion( $l['id'] ) ) {
					Logger::log( 'inventario_compra_aprobacion_deshecha', 'inventario_compra', (string) $l['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'Approval undone: list %s is pending again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'compra_cancelar':
				if ( Servicio::puede_cancelar( $l ) && ComprasTable::cancelar( $l['id'], $this->post( 'motivo' ) ) ) {
					Logger::log( 'inventario_compra_cancelada', 'inventario_compra', (string) $l['id'] );
					$hecho = sprintf( /* translators: %s: text */ __( 'List %s cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			default:
				$error = __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		'' !== $hecho ? Notices::add( 'success', esc_html( $hecho ) ) : Notices::add( 'error', esc_html( $error ) );
		$this->redirect( add_query_arg( 'lista', (string) $l['id'], $listas ) );
	}

	/**
	 * Tras aprobar o rechazar (v0.30, P-65): avisa por email a quien pidió la lista y dice qué ha pasado.
	 *
	 * @return string Frase para añadir al aviso (con un espacio delante), o ''.
	 */
	private static function aviso_revision( int $id ): string {
		$l = ComprasTable::obtener( $id );
		switch ( $l ? AvisoRevision::enviar( $l ) : '' ) {
			case RegistroEmails::ENVIADO:
				return ' ' . __( 'Whoever requested it has been notified by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			case RegistroEmails::SIN_EMAIL:
				return ' ' . __( 'Whoever requested it has no email in the private area: tell them yourself.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			case RegistroEmails::ERROR:
				return ' ' . __( 'The email to whoever requested it could not be sent: tell them yourself.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			default:
				return '';
		}
	}

	/**
	 * Material nuevo del formulario (v0.30): nuevo_desc[i], nuevo_cantidad[i] y nuevo_precio[i].
	 *
	 * @return array<int, array{descripcion:string, cantidad:string, precio:string}> Por orden de fila.
	 */
	private function nuevos(): array {
		$campos = array();
		foreach ( array( 'descripcion' => 'nuevo_desc', 'cantidad' => 'nuevo_cantidad', 'precio' => 'nuevo_precio' ) as $clave => $campo ) {
			$v = isset( $_POST[ $campo ] ) && is_array( $_POST[ $campo ] ) ? wp_unslash( $_POST[ $campo ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce se comprueba antes; cada valor se sanea aquí y se valida en Servicio::lineas
			foreach ( (array) $v as $i => $valor ) {
				if ( is_numeric( $i ) && (int) $i >= 0 && (int) $i <= ComprasTable::MAX_LINEAS && is_scalar( $valor ) ) {
					$campos[ (int) $i ][ $clave ] = sanitize_text_field( (string) $valor );
				}
			}
		}
		ksort( $campos );
		$out = array();
		foreach ( $campos as $fila ) {
			$out[] = array(
				'descripcion' => $fila['descripcion'] ?? '',
				'cantidad'    => $fila['cantidad'] ?? '',
				'precio'      => $fila['precio'] ?? '',
			);
		}
		return $out;
	}

	/** Total para el resumen del listado (con «+ sin precio» si alguna línea no lo tiene). */
	public static function total( array $l ): string {
		return Calculadora::euros( $l['total'] ) . ( ComprasTable::sin_precio( $l ) ? ' ' . __( '+ no price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '' );
	}
}
