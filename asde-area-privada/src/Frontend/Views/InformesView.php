<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Informes\Informe;
use AsdeBalears\AreaPrivada\Informes\InformesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Generador de informes: listado de informes guardados, editor (columnas +
 * filtros) y resultado (tabla, CSV, impresión).
 */
final class InformesView extends View {

	private const CAP = 'asde_reports';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$ambito = Ambito::actual();

		if ( '' !== $this->get( 'nuevo' ) || '' !== $this->get( 'editar' ) ) {
			return $this->layout( 'informes', $this->editor( $ambito ) );
		}
		if ( '' !== $this->get( 'informe' ) || '1' === $this->get( 'ver' ) ) {
			return $this->layout( 'informes', $this->resultado( $ambito ) );
		}
		return $this->layout( 'informes', $this->lista() );
	}

	private function lista(): string {
		return $this->tpl(
			'informes/lista',
			array(
				'informes' => InformesTable::visibles( get_current_user_id() ),
				'user'     => wp_get_current_user(),
				'url'      => $this->url( 'informes' ),
			)
		);
	}

	private function editor( Ambito $ambito ): string {
		$informe = null;
		if ( '' !== $this->get( 'editar' ) ) {
			$informe = $this->cargar( (int) $this->get( 'editar' ) );
			if ( ! $informe || ! $informe->puede_editar( wp_get_current_user() ) ) {
				return $this->aviso_error( asde_t( 'No puedes editar ese informe.' ) ) . $this->lista();
			}
		} elseif ( '' !== $this->get( 'desde' ) ) {
			$base    = $this->cargar( (int) $this->get( 'desde' ) );
			$informe = $base ? new Informe( 0, $base->nombre . ' (copia)', $base->descripcion, $base->columnas, $base->filtros, get_current_user_id(), false ) : null;
		} elseif ( isset( $_GET['cols'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Desde un informe improvisado ("Editar / guardar"): se conservan columnas y filtros.
			$informe = $this->informe_de_la_peticion();
			if ( 'Informe' === $informe->nombre ) {
				$informe->nombre = '';
			}
		}
		return $this->tpl(
			'informes/editor',
			array(
				'informe'    => $informe ?: new Informe( 0, '', '', array( 'nombre', 'apellidos', 'seccion', 'edad' ), array(), get_current_user_id(), false ),
				'permitidas' => Catalogo::permitidas( $ambito ),
				'global'     => $ambito->vision_global(),
				'mis_secc'   => $ambito->secciones_scouter(),
				'salud'      => current_user_can( 'asde_view_health' ),
				'url'        => $this->url( 'informes' ),
			)
		);
	}

	private function resultado( Ambito $ambito ): string {
		$informe = $this->informe_de_la_peticion();
		if ( ! $informe ) {
			return $this->aviso_error( asde_t( 'Ese informe no existe o no está compartido contigo.' ) ) . $this->lista();
		}
		$crm = ClientFactory::reader();
		if ( ! $crm ) {
			return $this->aviso_error( asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		try {
			$res = ( new Generador( $crm ) )->ejecutar( $informe, $ambito );
		} catch ( \InvalidArgumentException $e ) {
			return $this->aviso_error( $e->getMessage() ) . $this->editor_desde( $informe, $ambito );
		} catch ( CrmException $e ) {
			return $this->aviso_error( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		Logger::log(
			'informe_ejecutado',
			'informe',
			(string) $informe->id,
			array(
				'columnas' => array_keys( $res['columnas'] ),
				'filas'    => count( $res['filas'] ),
				'salud'    => $res['salud'],
			)
		);

		$args = array_merge( $this->args_de( $informe ), array( 'formato' => 'csv', '_wpnonce' => wp_create_nonce( 'asde_informe_csv' ) ) );
		return $this->tpl(
			'informes/resultado',
			array(
				'informe'   => $informe,
				'res'       => $res,
				'url'       => $this->url( 'informes' ),
				'url_csv'   => $this->url( 'informes', $args ),
				'url_edit'  => $informe->id && $informe->puede_editar( wp_get_current_user() )
					? $this->url( 'informes', array( 'editar' => $informe->id ) )
					: $this->url( 'informes', array_merge( array( 'nuevo' => 1 ), $this->args_de( $informe, false ) ) ),
				'global'    => $ambito->vision_global(),
			)
		);
	}

	/** Descarga CSV (se llama antes de pintar la página). */
	public function exportar(): void {
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_informe_csv' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado. Vuelve a generar el informe.', 'asde-area-privada' ), 403 );
		}
		$informe = $this->informe_de_la_peticion();
		$crm     = ClientFactory::reader();
		if ( ! $informe || ! $crm ) {
			wp_die( esc_html__( 'Informe no disponible.', 'asde-area-privada' ), 404 );
		}
		try {
			$res = ( new Generador( $crm ) )->ejecutar( $informe, Ambito::actual() );
		} catch ( \InvalidArgumentException | CrmException $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		Logger::log(
			'informe_exportado',
			'informe',
			(string) $informe->id,
			array(
				'columnas' => array_keys( $res['columnas'] ),
				'filas'    => count( $res['filas'] ),
				'salud'    => $res['salud'],
			)
		);
		Generador::csv( $informe->nombre, $res );
		exit;
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$user = wp_get_current_user();

		switch ( $this->post( 'asde_action' ) ) {
			case 'guardar':
				check_admin_referer( 'asde_informe_guardar' );
				$id      = (int) $this->post( 'id' );
				$previo  = $id ? $this->cargar( $id ) : null;
				if ( $id && ( ! $previo || ! $previo->puede_editar( $user ) ) ) {
					Notices::add( 'error', asde_t( 'No puedes editar ese informe.' ) );
					$this->redirect( $this->url( 'informes' ) );
				}
				$informe = new Informe(
					$id,
					sanitize_text_field( $this->post( 'nombre' ) ),
					sanitize_text_field( $this->post( 'descripcion' ) ),
					$this->post_array( 'cols' ),
					$this->filtros_de( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification
					$previo ? $previo->autor_id : $user->ID,
					'1' === $this->post( 'compartido' )
				);
				if ( '' === $informe->nombre || ! $informe->columnas ) {
					Notices::add( 'error', asde_t( 'Para guardar el informe ponle un nombre y elige al menos una columna.' ) );
					$this->redirect( $this->url( 'informes', array_merge( array( 'nuevo' => 1 ), $this->args_de( $informe, false ) ) ) );
				}
				$nuevo_id = InformesTable::guardar( $informe );
				Logger::log( $id ? 'informe_modificado' : 'informe_creado', 'informe', (string) $nuevo_id );
				Notices::add( 'success', sprintf( asde_t( 'Informe «%s» guardado.' ), esc_html( $informe->nombre ) ) );
				$this->redirect( $this->url( 'informes', array( 'informe' => $nuevo_id ) ) );
				break;

			case 'borrar':
				$id = (int) $this->post( 'id' );
				check_admin_referer( 'asde_informe_borrar_' . $id );
				$informe = $this->cargar( $id );
				if ( $informe && $informe->puede_editar( $user ) ) {
					InformesTable::borrar( $id );
					Logger::log( 'informe_borrado', 'informe', (string) $id );
					Notices::add( 'success', sprintf( asde_t( 'Informe «%s» borrado.' ), esc_html( $informe->nombre ) ) );
				} else {
					Notices::add( 'error', asde_t( 'No puedes borrar ese informe.' ) );
				}
				$this->redirect( $this->url( 'informes' ) );
				break;
		}
	}

	/** Informe guardado (?informe=ID) o improvisado desde los parámetros (?ver=1&cols[]=…). */
	private function informe_de_la_peticion(): ?Informe {
		if ( '' !== $this->get( 'informe' ) ) {
			return $this->cargar( (int) $this->get( 'informe' ) );
		}
		$cols = isset( $_GET['cols'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['cols'] ) ) : array(); // phpcs:ignore WordPress.Security
		return new Informe( 0, sanitize_text_field( $this->get( 'nombre' ) ) ?: 'Informe', '', $cols, $this->filtros_de( $_GET ), get_current_user_id(), false ); // phpcs:ignore WordPress.Security
	}

	/** Solo informes predefinidos, compartidos o propios. */
	private function cargar( int $id ): ?Informe {
		$i = InformesTable::obtener( $id );
		return ( $i && ( $i->sistema || $i->compartido || $i->autor_id === get_current_user_id() ) ) ? $i : null;
	}

	private function filtros_de( array $src ): array {
		$src = wp_unslash( $src );
		return Informe::limpiar_filtros(
			array(
				'secciones' => (array) ( $src['secciones'] ?? array() ),
				'edad_min'  => $src['edad_min'] ?? null,
				'edad_max'  => $src['edad_max'] ?? null,
				'salud'     => ! empty( $src['salud'] ),
				'orden'     => (string) ( $src['orden'] ?? '' ),
			)
		);
	}

	/** Parámetros GET que reproducen un informe. */
	private function args_de( Informe $i, bool $con_id = true ): array {
		if ( $con_id && $i->id ) {
			return array( 'informe' => $i->id );
		}
		return array_filter(
			array(
				'ver'       => 1,
				'nombre'    => $i->nombre,
				'cols'      => $i->columnas,
				'secciones' => $i->filtros['secciones'],
				'edad_min'  => $i->filtros['edad_min'],
				'edad_max'  => $i->filtros['edad_max'],
				'salud'     => $i->filtros['salud'] ? 1 : null,
				'orden'     => $i->filtros['orden'],
			),
			static fn( $v ) => null !== $v && array() !== $v && '' !== $v
		);
	}

	private function editor_desde( Informe $informe, Ambito $ambito ): string {
		return $this->tpl(
			'informes/editor',
			array(
				'informe'    => $informe,
				'permitidas' => Catalogo::permitidas( $ambito ),
				'global'     => $ambito->vision_global(),
				'mis_secc'   => $ambito->secciones_scouter(),
				'salud'      => current_user_can( 'asde_view_health' ),
				'url'        => $this->url( 'informes' ),
			)
		);
	}

	private function aviso_error( string $mensaje ): string {
		return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', esc_html( $mensaje ) ) ) ) );
	}
}
