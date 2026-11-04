<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Seguimiento\Catalogo;
use AsdeBalears\AreaPrivada\Seguimiento\Revisiones;

defined( 'ABSPATH' ) || exit;

/**
 * «Mi sección» (doc 65): seguimiento de la progresión personal de las personas de una sección.
 * Cada scouter ve y edita las suyas (asde_seguimiento_editar); Coordinación las ve todas, solo lectura.
 */
final class MiSeccionView extends View {

	public const CAP        = 'asde_seguimiento';
	public const CAP_EDITAR = 'asde_seguimiento_editar';

	/** Días sin revisar a partir de los cuales se avisa (el programa pide una revisión al trimestre). */
	public const DIAS_REVISAR = 90;

	/** @return string[] Secciones que puede ver quien entra. */
	private function secciones(): array {
		$ambito = Ambito::actual();
		return array_values( array_intersect( Catalogo::secciones(), $ambito->vision_global() ? Catalogo::secciones() : $ambito->secciones_scouter() ) );
	}

	/** @return Persona[] Las personas con membresía activa en la sección. */
	private function personas( string $seccion ): array {
		$repo = PersonaRepository::make();
		return $repo ? array_values( array_filter( $repo->todas(), static fn( Persona $p ): bool => in_array( $seccion, $p->secciones, true ) ) ) : array();
	}

	/** La sección pedida si puede verla; si no, la primera suya. */
	private function seccion( array $permitidas ): string {
		$pedida = sanitize_key( $this->get( 'seccion' ) ?: $this->post( 'seccion' ) );
		return in_array( $pedida, $permitidas, true ) ? $pedida : $permitidas[0];
	}

	public function render( string $content = '' ): string {
		$secciones = current_user_can( self::CAP ) ? $this->secciones() : array();
		if ( ! $secciones ) {
			return $this->denegado();
		}
		$seccion   = $this->seccion( $secciones );
		$personas  = $this->personas( $seccion );
		$grupo     = Grupo::numero();
		$revisadas = Revisiones::actuales( $grupo, $seccion );
		foreach ( $personas as $p ) {
			if ( $p->num_socio_asde === $this->get( 'persona' ) ) {
				$h = Revisiones::huella( $grupo, $p->num_socio_asde );
				return $this->layout(
					'mi_seccion',
					$this->tpl(
						'mi-seccion/ficha',
						array(
							'p'         => $p,
							'seccion'   => $seccion,
							'aspectos'  => Catalogo::aspectos( $seccion ),
							'actual'    => $revisadas[ $h ] ?? null,
							'historial' => Revisiones::historial( $grupo, $h, $seccion ),
							'anio'      => Catalogo::anio( $seccion, $p->edad() ),
							'edita'     => current_user_can( self::CAP_EDITAR ),
							'url'       => $this->url( 'mi_seccion', array( 'seccion' => $seccion ) ),
						)
					)
				);
			}
		}
		$filas = array();
		foreach ( $personas as $p ) {
			$r       = $revisadas[ Revisiones::huella( $grupo, $p->num_socio_asde ) ] ?? null;
			$filas[] = array(
				'p'     => $p,
				'r'     => $r,
				'anio'  => Catalogo::anio( $seccion, $p->edad() ),
				'tarde' => ! $r || strtotime( $r['creada'] . ' UTC' ) < time() - self::DIAS_REVISAR * DAY_IN_SECONDS,
			);
		}
		return $this->layout(
			'mi_seccion',
			$this->tpl(
				'mi-seccion/listado',
				array(
					'filas'     => $filas,
					'seccion'   => $seccion,
					'secciones' => $secciones,
					'etapa'     => absint( $this->get( 'etapa' ) ),
					'solo'      => $this->get( 'solo' ),
					'url'       => $this->url( 'mi_seccion' ),
				)
			)
		);
	}

	public function handle_post(): void {
		$secciones = current_user_can( self::CAP_EDITAR ) ? $this->secciones() : array();
		if ( ! $secciones || 'guardar_seguimiento' !== $this->post( 'asde_action' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( 'asde_seguimiento' );
		$seccion = $this->seccion( $secciones );
		$socio   = $this->post( 'persona' );
		$destino = $this->url( 'mi_seccion', array( 'seccion' => $seccion ) );
		foreach ( $this->personas( $seccion ) as $p ) {
			if ( $p->num_socio_asde !== $socio ) {
				continue;
			}
			$aspectos = Catalogo::aspectos( $seccion );
			$grupo    = Grupo::numero();
			$huella   = Revisiones::huella( $grupo, $socio );
			$antes    = Revisiones::actuales( $grupo, $seccion )[ $huella ] ?? null;
			$etapa    = max( 1, min( 3, absint( $this->post( 'etapa' ) ) ) );
			$marcados = $this->post_array( 'estado' );
			// Al cambiar de etapa se empieza de cero: los indicadores son otros.
			$cambia   = $antes && (int) $antes['etapa'] !== $etapa;
			$estados  = array_map( static fn( $i ) => $cambia ? 0 : min( Revisiones::MAX_ESTADO, absint( $marcados[ $i ] ?? 0 ) ), array_keys( $aspectos ) );
			$notas = array_map( static fn( $n ) => mb_substr( sanitize_textarea_field( (string) $n ), 0, Revisiones::MAX_NOTA ), $this->post_array( 'nota' ) );
			Revisiones::guardar( $grupo, $huella, $seccion, $etapa, $estados, '1' === $this->post( 'propuesta' ), mb_substr( $this->post( 'objetivo' ), 0, 200 ), $notas );
			Logger::log( 'seguimiento_guardado', 'seguimiento', $grupo, array( 'seccion' => $seccion, 'campos' => array( 'etapa', 'estados', 'objetivo', 'notas' ) ) );
			Notices::add( 'success', __( 'Review saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$destino = add_query_arg( 'persona', $socio, $destino );
			break;
		}
		$this->redirect( $destino );
	}
}
