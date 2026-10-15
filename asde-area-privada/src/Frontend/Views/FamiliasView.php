<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\Sugerencias;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Familias: agrupar socios (hermanos, padres scouters…) bajo un nº de gestión
 * F684001, con sugerencias automáticas por email compartido en el CRM.
 */
final class FamiliasView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'tesoreria', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		$ronda = $this->ronda();

		try {
			$activas  = $repo->todas( true );
			$familias = FamiliasTable::todas();
			$mapa     = FamiliasTable::mapa_socios();
			$nums     = array_keys( $mapa );
			$por_num  = array();
			foreach ( $activas as $p ) {
				$por_num[ $p->num_socio_asde ] = $p;
			}
			// Miembros que ya no tienen membresía activa (también se muestran).
			$faltan = array_diff( array_map( 'strval', $nums ), array_map( 'strval', array_keys( $por_num ) ) );
			if ( $faltan ) {
				foreach ( $repo->obtener_varias( $faltan ) as $n => $p ) {
					$por_num[ $n ] = $p;
				}
			}
		} catch ( CrmException $e ) {
			return $this->error_crm( 'tesoreria', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		$cfg     = Config::de( $ronda );
		$editar  = (int) $this->get( 'familia' );
		$sin_fam = array_values( array_filter( $activas, static fn( Persona $p ) => ! isset( $mapa[ $p->num_socio_asde ] ) ) );

		if ( $editar && isset( $familias[ $editar ] ) ) {
			$html = $this->tpl(
				'tesoreria/familia-editar',
				array(
					'familia'  => $familias[ $editar ],
					'no_borrar' => FamiliasTable::por_que_no_borrar( $familias[ $editar ] ),
					'personas' => $por_num,
					'sin_fam'  => $sin_fam,
					'cuenta'   => $this->cuenta_familiar( $familias[ $editar ]['miembros'] ),
					'url'      => $this->url( 'familias' ),
					'url_pago' => $this->url( 'tesoreria', array( 'familia' => $editar, 'ronda' => $ronda ) ),
				)
			);
		} else {
			$cuentas = array();
			$borrar  = array(); // v0.35.1 (petición del usuario): «Eliminar» en la lista para las familias sin socios.
			foreach ( $familias as $id => $f ) {
				$c              = $this->cuenta_familiar( $f['miembros'] );
				$cuentas[ $id ] = $c ? $c->user_login : '';
				if ( ! $f['miembros'] ) {
					$borrar[ $id ] = FamiliasTable::por_que_no_borrar( $f );
				}
			}
			// v0.34 (petición del usuario): buscador por nº de familia, cuenta familiar y nombre o nº de socio de sus miembros.
			$q     = mb_strtolower( remove_accents( $this->get( 'q' ) ) );
			$lista = '' === $q ? $familias : array_filter(
				$familias,
				static function ( array $f, int $id ) use ( $por_num, $cuentas, $q ): bool {
					$texto = $f['numero'] . ' ' . $cuentas[ $id ];
					foreach ( $f['miembros'] as $n ) {
						$texto .= ' ' . $n . ' ' . ( isset( $por_num[ $n ] ) ? $por_num[ $n ]->nombre_completo() : '' );
					}
					return str_contains( mb_strtolower( remove_accents( $texto ) ), $q );
				},
				ARRAY_FILTER_USE_BOTH
			);
			$html  = $this->tpl(
				'tesoreria/familias',
				array(
					'familias'    => $lista,
					'total'       => count( $familias ),
					'q'           => $this->get( 'q' ),
					'personas'    => $por_num,
					'cuentas'     => $cuentas,
					'borrar'      => $borrar,
					'sugerencias' => Sugerencias::por_email( $activas, $mapa, $cfg['secciones_pagan'] ),
					'sin_fam'     => array_values( array_filter( $sin_fam, static fn( Persona $p ) => (bool) array_intersect( $p->secciones, $cfg['secciones_pagan'] ) ) ),
					'sin_fam_adultos' => array_values( array_filter( $sin_fam, static fn( Persona $p ) => ! array_intersect( $p->secciones, $cfg['secciones_pagan'] ) ) ),
					'siguiente'   => FamiliasTable::siguiente_numero( \AsdeBalears\AreaPrivada\Domain\Grupo::actual()['numero'] ?? '' ),
					'url'         => $this->url( 'familias' ),
				)
			);
		}
		return $this->layout( 'tesoreria', $this->subnav( 'familias', $ronda ) . $html );
	}

	private function cuenta_familiar( array $miembros ): ?\WP_User {
		foreach ( $miembros as $n ) {
			$c = Usuarios::tutores_de( (string) $n )[0] ?? null;
			if ( $c ) {
				return $c;
			}
		}
		return null;
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_familias' );
		$repo    = PersonaRepository::make();
		$familia = (int) $this->post( 'familia' );
		$volver  = $familia && 'lista' !== $this->post( 'desde' ) ? $this->url( 'familias', array( 'familia' => $familia ) ) : $this->url( 'familias' );

		// Solo socios del grupo con membresía activa.
		$validar = function ( array $nums ) use ( $repo ): array {
			try {
				$ps = $repo ? $repo->obtener_varias( $nums ) : array();
			} catch ( CrmException $e ) {
				return array();
			}
			return array_keys( array_filter( $ps, static fn( Persona $p ) => $p->tiene_membresia_activa() ) );
		};

		switch ( $this->post( 'asde_action' ) ) {
			case 'crear':
				$nums = $validar( $this->post_array( 'socios' ) );
				$r    = $nums ? FamiliasTable::crear( array_map( 'strval', $nums ) ) : new \WP_Error( 'x', asde_t( 'Elige al menos un socio con membresía activa.' ) );
				if ( ! is_wp_error( $r ) ) {
					$f = FamiliasTable::obtener( $r );
					Logger::log( 'familia_creada', 'familia', (string) $r, array( 'socios' => $nums ) );
					Notices::add( 'success', sprintf( asde_t( 'Familia <strong>%s</strong> creada con %d socio(s).' ), esc_html( $f['numero'] ), count( $nums ) ) );
					$volver = $this->url( 'familias', array( 'familia' => $r ) );
				}
				break;

			case 'anadir':
				$nums = $validar( array( $this->post( 'socio' ) ) );
				$r    = $nums ? FamiliasTable::anadir( $familia, (string) $nums[0] ) : new \WP_Error( 'x', asde_t( 'Ese socio no tiene membresía activa en el grupo.' ) );
				if ( ! is_wp_error( $r ) ) {
					Logger::log( 'familia_socio_anadido', 'familia', (string) $familia, array( 'socio' => $nums[0] ) );
					Notices::add( 'success', asde_t( 'Socio añadido a la familia.' ) );
				}
				break;

			case 'quitar':
				FamiliasTable::quitar( $familia, $this->post( 'socio' ) );
				Logger::log( 'familia_socio_quitado', 'familia', (string) $familia, array( 'socio' => $this->post( 'socio' ) ) );
				Notices::add( 'success', asde_t( 'Socio quitado de la familia.' ) );
				$r = true;
				break;

			case 'borrar':
				$r = FamiliasTable::borrar( $familia );
				if ( ! is_wp_error( $r ) ) {
					Logger::log( 'familia_borrada', 'familia', (string) $familia );
					Notices::add( 'success', asde_t( 'Familia eliminada.' ) );
					$volver = $this->url( 'familias' );
				}
				break;

			default:
				$r = new \WP_Error( 'x', asde_t( 'Acción desconocida.' ) );
		}

		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
		}
		$this->redirect( $volver );
	}
}
