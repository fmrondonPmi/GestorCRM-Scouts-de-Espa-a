<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\Servicio as Eventos;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
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
			$personal['ficha'] = $ambito->persona ? __( 'My details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'My family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			if ( Inscripciones::en_menu( $ambito ) ) {
				$n                       = array_sum( Inscripciones::pendientes( $ambito ) );
				$personal['mis_eventos'] = __( 'My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
			}
			if ( current_user_can( MisPagosView::CAP ) ) {
				$personal['pagos'] = __( 'My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
		}
		$gestiona   = current_user_can( Servicio::CAP_GESTION );
		$pide       = $ambito && current_user_can( Servicio::CAP_PEDIR ) && Servicio::propios( $ambito );
		$pendientes = $ambito && ( $pide || $gestiona ) ? Servicio::pendientes( $ambito ) : array();
		if ( $pide ) {
			$n                    = (int) $pendientes['confirmar'];
			$personal['tienda_pedidos'] = ( $gestiona ? __( 'My orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ( $n ? ' (' . $n . ')' : '' );
		}

		// --- Gestión del grupo ---
		if ( current_user_can( 'asde_manage_users' ) ) {
			$gestion['personas'] = __( 'People', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( $ambito && current_user_can( 'asde_aprobar_solicitudes' ) ) {
			$solicitudes          = Solicitudes::contar_pendientes( $ambito );
			$gestion['solicitudes'] = __( 'Requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $solicitudes ? ' (' . $solicitudes . ')' : '' );
		}
		if ( current_user_can( 'asde_approve_changes' ) ) {
			$cambios            = $ambito && $ambito->vision_global() ? Cambios::contar_pendientes() : 0;
			$gestion['cambios'] = __( 'Changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $cambios ? ' (' . $cambios . ')' : '' );
		}
		if ( current_user_can( 'asde_reports' ) ) {
			$gestion['informes'] = __( 'Reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( $ambito && Eventos::puede_entrar() ) {
			$n                  = Eventos::pendientes( $ambito )['validar'];
			$gestion['eventos'] = __( 'Events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( $ambito && $gestiona ) {
			$n                    = (int) ( $pendientes['pago'] + $pendientes['pedido'] + $pendientes['entrega'] );
			$gestion['tienda_gestion'] = __( 'Shop management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP ) ) {
			// v0.19 (doc 32): a Tesorería se le cuentan las listas de compra que esperan su visto bueno.
			$n                     = current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP_APROBAR ) ? \AsdeBalears\AreaPrivada\Inventario\ComprasTable::contar_pendientes() : 0;
			$gestion['inventario'] = __( 'Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( \AsdeBalears\AreaPrivada\Reintegros\Servicio::CAP_PEDIR ) || \AsdeBalears\AreaPrivada\Reintegros\Servicio::ve_todos() ) {
			// v0.47 (doc 53): a quien aprueba o paga se le cuentan los que esperan.
			$n                    = \AsdeBalears\AreaPrivada\Reintegros\Servicio::pendientes();
			$gestion['reintegros'] = __( 'Reimbursements', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( 'asde_lista_espera' ) ) {
			// v0.32 (doc 39): se cuentan las confirmadas que nadie ha contactado todavía.
			$n                       = \AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::contar_sin_contactar();
			$gestion['lista_espera'] = __( 'Waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $n ? ' (' . $n . ')' : '' );
		}
		if ( current_user_can( 'asde_seguimiento' ) ) {
			$gestion['mi_seccion'] = __( 'My section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); // v0.63 (doc 65).
		}
		if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
			$gestion['tesoreria'] = __( 'Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( current_user_can( 'asde_manage_users' ) ) {
			$gestion['usuarios'] = __( 'Users', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( current_user_can( \AsdeBalears\AreaPrivada\Accesos\Catalogo::CAP_GESTION ) ) {
			$gestion['accesos'] = __( 'Access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); // v0.53 (doc 56).
		}

		// v0.56 (doc 58): «Datos del grupo» lo ve todo el mundo; quien los cambia (Coordinación) lo tiene en la gestión.
		if ( $ambito && $ambito->es_oficina() ) {
			// La oficina federal consulta personas, informes y usuarios: no lleva los datos de un grupo.
		} elseif ( current_user_can( \AsdeBalears\AreaPrivada\Frontend\Views\DatosGrupoView::CAP_EDITAR ) && ( $gestion || ! $personal ) ) {
			$gestion['grupo_datos'] = __( 'Group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( $personal ) {
			$personal['grupo_datos'] = __( 'Group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( $gestion ) {
			$gestion['grupo_datos'] = __( 'Group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}

		$grupos = array();
		if ( $personal ) {
			$grupos[ self::PERSONAL ] = array(
				'titulo' => $ambito && $ambito->persona ? __( 'My space', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'My family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'items'  => $personal,
			);
		}
		if ( $gestion ) {
			$grupos[ self::GESTION ] = array(
				'titulo' => __( 'Group management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'items'  => $gestion,
			);
		}
		return $grupos;
	}

	/** Todas las pestañas en una sola lista (con «Inicio» delante): la que usaba el menú antes de separarlo. @return array<string, string> */
	public static function plano( ?Ambito $ambito ): array {
		$items = array( 'area' => __( 'Home', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		foreach ( self::grupos( $ambito ) as $grupo ) {
			$items += $grupo['items'];
		}
		return $items;
	}
}
