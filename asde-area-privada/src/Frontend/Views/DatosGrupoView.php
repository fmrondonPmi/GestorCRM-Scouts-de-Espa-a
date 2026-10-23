<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\DatosGrupo;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Socios\Numeracion;

defined( 'ABSPATH' ) || exit;

/**
 * Datos del grupo (v0.56, doc 58): nombre, denominación fiscal, CIF, dirección, código postal,
 * población, provincia, email de contacto y nº de grupo, en un solo sitio. Los ve todo el que
 * entra al área privada; los cambia el equipo de Coordinación (asde_grupo_datos). La provincia,
 * el nº de grupo y 4 cifras incrementales forman el nº de socio/a local del grupo.
 */
final class DatosGrupoView extends View {

	public const CAP_EDITAR = 'asde_grupo_datos';

	public function render( string $content = '' ): string {
		if ( ! is_user_logged_in() ) {
			return $this->denegado();
		}
		$numero = Grupo::numero();
		if ( '' === $numero || ! Grupos::por_numero( $numero ) ) {
			return $this->denegado( asde_t( 'No se ha encontrado el grupo.' ) );
		}
		$d = DatosGrupo::de( $numero );
		return $this->layout(
			'grupo_datos',
			$this->tpl(
				'grupo/datos',
				array(
					'd'        => $d,
					'editable' => current_user_can( self::CAP_EDITAR ),
					'faltan'   => DatosGrupo::faltan( $numero ),
					'ejemplo'  => NumeroSocio::configurado( $numero ) ? NumeroSocio::formatear( $numero, 1 ) : '',
					'url'      => $this->url( 'grupo_datos' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_EDITAR ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar los datos del grupo.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_grupo_datos' );
		$numero = Grupo::numero();
		$antes  = DatosGrupo::de( $numero );
		$nuevos = array(
			'nombre'         => $this->post( 'nombre' ),
			'denominacion'   => $this->post( 'denominacion' ),
			'cif'            => $this->post( 'cif' ),
			'sede'           => $this->post( 'sede' ),
			'cp'             => $this->post( 'cp' ),
			'poblacion'      => $this->post( 'poblacion' ),
			'provincia'      => $this->post( 'provincia' ),
			'email_contacto' => $this->post( 'email_contacto' ),
		);

		// Cambiar la provincia de un grupo que ya tenía la suya cambia el principio de todos sus números de socio/a.
		$cambia_provincia = '' !== $antes['provincia'] && $nuevos['provincia'] !== $antes['provincia'];
		if ( $cambia_provincia && '1' !== $this->post( 'confirmar_provincia' ) ) {
			Notices::add( 'error', asde_t( 'No se ha guardado: para cambiar la provincia marca la casilla de confirmación (los números de socio/a del CRM con la provincia anterior dejarán de reconocerse como del grupo).' ) );
			$this->redirect( $this->url( 'grupo_datos' ) );
		}

		$r = Grupos::guardar_datos( $numero, $nuevos );
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', $r->get_error_message() );
			$this->redirect( $this->url( 'grupo_datos' ) );
		}
		if ( $cambia_provincia ) {
			Numeracion::olvidar_cache(); // El análisis de los números usaba la provincia anterior.
		}
		$despues = DatosGrupo::de( $numero );
		$cambios = array_keys( array_filter( $despues, static fn( $v, $k ) => ( $antes[ $k ] ?? '' ) !== $v, ARRAY_FILTER_USE_BOTH ) );
		Logger::log( 'grupo_datos_guardados', 'grupo', $numero, array( 'campos' => $cambios ) );
		Notices::add( 'success', asde_t( 'Datos del grupo guardados.' ) );
		$this->redirect( $this->url( 'grupo_datos' ) );
	}
}
