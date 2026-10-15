<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\Servicio as Eventos;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

/**
 * Menú del área privada, separado por tipo de usuario (v0.15, doc 28).
 *
 * - **Personal** («Mi espacio» o «Mi familia»): lo que hace cada persona o familia
 *   con lo suyo: su ficha, sus eventos (v0.35), sus pagos y su uniforme.
 * - **Gestión** («Gestión del grupo»): lo de scouters, Secretaría, Coordinación y
 *   Tesorería: personas, usuarios, solicitudes, cambios, informes, eventos (v0.35),
 *   tesorería y la gestión de uniformes.
 *
 * No hay un «tipo de cuenta» aparte: cada entrada sale según las capabilities de
 * siempre. Una familia solo ve el grupo personal; un scouter, los dos (sus cosas
 * y las del grupo); alguien sin ficha (p. ej. un administrador), solo el de gestión.
 */
final class Menu {

	public const PERSONAL = 'personal';
	public const GESTION  = 'gestion';

	/**
	 * Entradas por grupo, sin contar «Inicio». Los grupos vacíos no salen.
	 *
	 * @return array<string, array{titulo:string, items:array<string, string>}> clave de grupo => datos
	 */
	public static function grupos( ?Ambito $ambito ): array {
		$personal = array();
		$gestion  = array();

		// --- Lo personal / familiar ---
		if ( $ambito && ( $ambito->persona || $ambito->representados ) ) {
			$personal['ficha'] = $ambito->persona ? asde_t( 'Mis datos' ) : asde_t( 'Mi familia' );
			if ( Inscripciones::en_menu( $ambito ) ) {
				$n                       = array_sum( Inscripciones::pendientes( $ambito ) );
				$personal['mis_eventos'] = asde_t( 'Mis eventos' ) . ( $n ? ' (' . $n . ')' : '' );
			}
			if ( current_user_can( MisPagosView::CAP ) ) {
				$personal['pagos'] = asde_t( 'Mis pagos' );
			}
		}
		$gestiona   = current_user_can( Servicio::CAP_GESTION );
		$pide       = $ambito && current_user_can( Servicio::CAP_PEDIR ) && Servicio::propios( $ambito );
		$pendientes = $ambito && ( $pide || $gestiona ) ? Servicio::pendientes( $ambito ) : array();
		if ( $pide ) {
			$n                    = (int) $pendientes['confirmar'];
			$personal['uniforme'] = ( $gestiona ? asde_t( 'Mi uniforme' ) : asde_t( 'Uniforme' ) ) . ( $n ? ' (' . $n . ')' : '' );
		}

		// --- Gestión del grupo ---
		if ( current_user_can( 'asde_manage_users' ) ) {
			$gestion['personas'] = asde_t( 'Personas' );
		}
		if ( $ambito && current_user_can( 'asde_aprobar_solicitudes' ) ) {
			$solicitudes          = Solicitudes::contar_pendientes( $ambito );
			$gestion['solicitudes'] = asde_t( 'Solicitudes' ) . ( $solicitudes ? ' (' . $solicitudes . ')' : '' );
		}
		if ( current_user_can( 'asde_approve_changes' ) ) {
			$cambios            = $ambito && $ambito->vision_global() ? Cambios::contar_pendientes() : 0;
			$gestion['cambios'] = asde_t( 'Cambios' ) . ( $cambios ? ' (' . $cambios . ')' : '' );
		}
		if ( current_user_can( 'asde_reports' ) ) {
			$gestion['informes'] = asde_t( 'Informes' );
		}
		if ( $ambito && Eventos::puede_entrar() ) {
			$n                  = Eventos::pendientes( $ambito )['validar'];
			$gestion['eventos'] = asde_t( 'Eventos' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( $ambito && $gestiona ) {
			$n                    = (int) ( $pendientes['pago'] + $pendientes['pedido'] + $pendientes['entrega'] );
			$gestion['uniformes'] = asde_t( 'Gestión de uniformes' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP ) ) {
			// v0.19 (doc 32): a Tesorería se le cuentan las listas de compra que esperan su visto bueno.
			$n                     = current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP_APROBAR ) ? \AsdeBalears\AreaPrivada\Inventario\ComprasTable::contar_pendientes() : 0;
			$gestion['inventario'] = asde_t( 'Inventario' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( 'asde_lista_espera' ) ) {
			// v0.32 (doc 39): se cuentan las confirmadas que nadie ha contactado todavía.
			$n                       = \AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::contar_sin_contactar();
			$gestion['lista_espera'] = asde_t( 'Lista de espera' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
			$gestion['tesoreria'] = asde_t( 'Tesorería' );
		}
		if ( current_user_can( 'asde_manage_users' ) ) {
			$gestion['usuarios'] = asde_t( 'Usuarios' );
		}

		$grupos = array();
		if ( $personal ) {
			$grupos[ self::PERSONAL ] = array(
				'titulo' => $ambito && $ambito->persona ? asde_t( 'Mi espacio' ) : asde_t( 'Mi familia' ),
				'items'  => $personal,
			);
		}
		if ( $gestion ) {
			$grupos[ self::GESTION ] = array(
				'titulo' => asde_t( 'Gestión del grupo' ),
				'items'  => $gestion,
			);
		}
		return $grupos;
	}

	/** Todas las pestañas en una sola lista (con «Inicio» delante): la que usaba el menú antes de separarlo. @return array<string, string> */
	public static function plano( ?Ambito $ambito ): array {
		$items = array( 'area' => asde_t( 'Inicio' ) );
		foreach ( self::grupos( $ambito ) as $grupo ) {
			$items += $grupo['items'];
		}
		return $items;
	}
}
