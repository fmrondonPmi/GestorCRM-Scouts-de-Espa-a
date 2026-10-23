<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Presupuestos\EstadosTable;
use AsdeBalears\AreaPrivada\Presupuestos\GastosRealesTable;
use AsdeBalears\AreaPrivada\Presupuestos\PartidasTable;
use AsdeBalears\AreaPrivada\Presupuestos\Reparto;
use AsdeBalears\AreaPrivada\Presupuestos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Presupuestos (v0.54 y v0.55, doc 57): de la cuota anual al dinero de cada sección, y el
 * presupuesto de cada sección y del grupo con sus partidas.
 *
 * - «Resumen» (solo Secretaría, Coordinación y Tesorería): el reparto en euros, los porcentajes,
 *   las categorías de gasto y el estado de cada presupuesto.
 * - Una sección o el grupo: gastos (categoría × cuántos × importe), ingresos (el proyecto de
 *   recaudación y otros), saldo y estado (borrador → enviado → aprobado).
 * Un scouter solo ve las secciones que le tocan, con el dinero que reciben, no el desglose del grupo.
 */
final class PresupuestosView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! Servicio::puede_entrar() ) {
			return $this->denegado();
		}
		$ronda    = $this->ronda();
		$cfg      = Config::de( $ronda );
		$ambito   = Ambito::actual();
		$secs     = Servicio::secciones_para( $ambito );
		$es_config = current_user_can( Servicio::CAP_CONFIG );
		$vista    = $this->get( 'vista' );
		if ( 'resumen' !== $vista && ! in_array( $vista, $secs, true ) ) {
			$vista = $es_config ? 'resumen' : (string) ( $secs[0] ?? '' );
		}
		if ( 'resumen' === $vista && ! $es_config ) {
			$vista = (string) ( $secs[0] ?? '' );
		}
		if ( '' === $vista ) {
			return $this->denegado( asde_t( 'No tienes ninguna sección asignada para preparar su presupuesto.' ) );
		}

		$repo    = PersonaRepository::make();
		$reparto = null;
		$error   = '';
		if ( $repo ) {
			try {
				list( $filas, $personas ) = TesoreriaView::estados( $repo, $ronda );
				$reparto                  = Reparto::calcular( $repo->todas( true ), $filas, $personas, $cfg );
			} catch ( CrmException $e ) {
				$error = asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage();
			}
		}

		$vars = array(
			'ronda'     => $ronda,
			'cfg'       => $cfg,
			'reparto'   => $reparto,
			'error'     => $error,
			'url'       => $this->url( 'presupuestos' ),
			'vista'     => $vista,
			'secciones' => $secs,
			'es_config' => $es_config,
		);

		if ( 'resumen' === $vista ) {
			$estados = array();
			foreach ( $secs as $s ) {
				$partidas   = PartidasTable::de( $ronda, $s );
				$disponible = $reparto ? ( Servicio::GRUPO === $s ? $reparto['grupo']['disponible'] : $reparto['secciones'][ $s ]['a_seccion'] ) : 0.0;
				$estados[ $s ] = array(
					'estado'    => EstadosTable::de( $ronda, $s )['estado'],
					'resultado' => Servicio::resultado( $disponible, $partidas ),
					'ejecucion' => Servicio::ejecucion( $partidas, GastosRealesTable::por_partida( $ronda, $s ) ),
				);
			}
			$vars['presupuestos'] = $estados;
			$cuerpo               = $this->tpl( 'tesoreria/presupuestos', $vars );
		} else {
			$edicion = Servicio::error_edicion( $ronda, $vista, $ambito );
			if ( '' === $edicion ) {
				Servicio::asegurar_proyecto( $ronda, $vista ); // El objetivo de recaudación del proyecto se crea solo.
			}
			$partidas   = PartidasTable::de( $ronda, $vista );
			$por_partida = GastosRealesTable::por_partida( $ronda, $vista );
			$disponible = $reparto ? ( Servicio::GRUPO === $vista ? $reparto['grupo']['disponible'] : $reparto['secciones'][ $vista ]['a_seccion'] ) : 0.0;
			$vars      += array(
				'partidas'   => $partidas,
				'estado'     => EstadosTable::de( $ronda, $vista ),
				'resultado'  => Servicio::resultado( $disponible, $partidas ),
				'por_partida' => $por_partida,
				'ejecucion'  => Servicio::ejecucion( $partidas, $por_partida ),
				'gastos_reales' => GastosRealesTable::de( $ronda, $vista ),
				'hoy'        => current_time( 'Y-m-d' ),
				'editable'   => '' === $edicion,
				'categorias' => $cfg['presupuesto']['categorias'],
				'hay_previa' => (bool) PartidasTable::de( Servicio::ronda_anterior( $ronda ), $vista ),
				'vacio'      => ! Servicio::tiene_partidas( $ronda, $vista ),
				'pct'        => Servicio::GRUPO === $vista ? null : Config::pct_grupo( $cfg, $vista ),
			);
			$cuerpo     = $this->tpl( 'tesoreria/presupuesto-seccion', $vars );
		}

		return $this->layout( 'tesoreria', $this->subnav( 'presupuestos', $ronda ) . $this->tpl( 'tesoreria/presupuestos-selector', $vars ) . $cuerpo );
	}

	public function handle_post(): void {
		if ( ! Servicio::puede_entrar() ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar los presupuestos.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_presupuestos' );
		$ronda   = $this->ronda();
		$ambito  = Ambito::actual();
		$accion  = $this->post( 'asde_action' );
		$seccion = $this->post( 'seccion' );
		$vista   = 'resumen';
		$error   = '';
		$ok      = '';

		if ( 'guardar_presupuestos' === $accion ) {
			if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
				wp_die( esc_html__( 'No tienes permiso para cambiar el reparto.', 'asde-area-privada' ), 403 );
			}
			$antes = Config::de( $ronda )['presupuesto'];
			Config::guardar( $ronda, array( 'presupuesto' => (array) ( wp_unslash( $_POST )['presupuesto'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$despues = Config::de( $ronda )['presupuesto'];
			if ( $antes !== $despues ) {
				Logger::log( 'presupuestos_ajustes_guardados', 'ronda', $ronda . ' · % grupo ' . $antes['pct_grupo'] . ' → ' . $despues['pct_grupo'] );
			}
			$ok = sprintf( asde_t( 'Reparto de la ronda %s guardado.' ), esc_html( $ronda ) );
		} else {
			$vista = $seccion;
			switch ( $accion ) {
				case 'partida_nueva':
					$error = Servicio::anadir_partida( $ronda, $seccion, $ambito, $this->post( 'tipo' ), $this->post( 'categoria' ), $this->post( 'concepto' ), $this->post( 'veces' ), $this->post( 'importe' ), $this->post( 'nota' ) );
					$ok    = asde_t( 'Partida añadida.' );
					break;
				case 'partida_quitar':
					$error = Servicio::quitar_partida( $ronda, $seccion, $ambito, (int) $this->post( 'id' ) );
					$ok    = asde_t( 'Partida quitada.' );
					break;
				case 'gasto_nuevo':
					$error = Servicio::anadir_gasto_real( $ronda, $seccion, $ambito, (int) $this->post( 'partida_id' ), $this->post( 'fecha' ), $this->post( 'concepto' ), $this->post( 'importe' ) );
					$ok    = asde_t( 'Gasto anotado.' );
					break;
				case 'gasto_quitar':
					$error = Servicio::quitar_gasto_real( $ronda, $seccion, $ambito, (int) $this->post( 'id' ) );
					$ok    = asde_t( 'Gasto quitado.' );
					break;
				case 'proyecto':
					$error = Servicio::fijar_proyecto( $ronda, $seccion, $ambito, $this->post( 'importe' ) );
					$ok    = asde_t( 'Objetivo de recaudación guardado.' );
					break;
				case 'enviar':
					$error = Servicio::enviar( $ronda, $seccion, $ambito );
					$ok    = asde_t( 'Presupuesto enviado a Tesorería.' );
					break;
				case 'aprobar':
					$error = Servicio::aprobar( $ronda, $seccion );
					$ok    = asde_t( 'Presupuesto aprobado.' );
					break;
				case 'reabrir':
					$error = Servicio::reabrir( $ronda, $seccion, $this->post( 'motivo' ) );
					$ok    = asde_t( 'Presupuesto reabierto: vuelve a ser un borrador.' );
					break;
				case 'copiar':
					$error = Servicio::copiar_anterior( $ronda, $seccion, $ambito );
					$ok    = asde_t( 'Partidas copiadas de la ronda anterior.' );
					break;
				default:
					$error = asde_t( 'Acción desconocida.' );
			}
		}

		Notices::add( '' === $error ? 'success' : 'error', '' === $error ? $ok : $error );
		$this->redirect( $this->url( 'presupuestos', array( 'ronda' => $ronda, 'vista' => $vista ) ) );
	}
}
