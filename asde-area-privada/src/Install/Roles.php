<?php
namespace AsdeBalears\AreaPrivada\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Roles y capabilities del área privada (docs/02-roles-y-permisos.md).
 *
 * Una capability por sí sola no basta: cada acción se comprueba además
 * contra el ámbito (sección, representados, persona propia).
 */
final class Roles {

	public const SCOUTER      = 'asde_scouter';
	public const SECRETARIA   = 'asde_secretaria';
	public const COORDINACION = 'asde_coordinacion';
	public const TESORERIA    = 'asde_tesoreria';
	public const TUTOR        = 'asde_tutor';
	public const PARTICIPANTE = 'asde_participante';
	public const INTENDENTE   = 'asde_intendente';
	public const OFICINA      = 'asde_oficina'; // v0.50 (doc 50): oficina federal, solo lectura de todos los grupos.

	public const ALL = array( self::SCOUTER, self::SECRETARIA, self::COORDINACION, self::TESORERIA, self::TUTOR, self::PARTICIPANTE, self::INTENDENTE, self::OFICINA );

	/** @return array<string, array{0:string, 1:array<string,bool>}> rol => [etiqueta, capabilities] */
	public static function definitions(): array {
		$base = array(
			'read'                     => true,
			'asde_access_area_privada' => true,
		);
		return array(
			self::SCOUTER      => array(
				'Scouter / Voluntario',
				$base + array(
					'asde_view_self'            => true,
					'asde_edit_self_contact'    => true,
					'asde_view_section'         => true,
					'asde_edit_section_contact' => true,
					'asde_approve_changes'      => true,
					'asde_aprobar_solicitudes' => true, // Solicitudes de acceso (v0.10): un scouter, las de sus secciones.
					'asde_view_health'          => true, // Solo lectura y solo su sección (2026-09-26).
					'asde_reports'              => true,
					'asde_tesoreria_acceso' => true,
					// Uniformes (v0.11): pedir, ver los de sus secciones y entregarlos.
					'asde_tienda_comprar'   => true,
					'asde_tienda_gestion' => true,
					'asde_tienda_entrega' => true,
					'asde_mis_pagos'         => true,
					'asde_inventario'        => true, // Inventario de material (v0.19, doc 32).
					'asde_lista_espera'      => true, // Lista de espera (v0.32, doc 39): ver, contactar y cambiar el estado.
					'asde_eventos_inscribir' => true, // Eventos (v0.35, doc 43): «Mis eventos».
					// Crea y gestiona los eventos de sus secciones y aquellos en cuyo equipo está.
					'asde_eventos_gestion'   => true,
					'asde_menus_recetas'     => true, // Recetario de los menús (v0.41, doc 49).
					'asde_reintegros'        => true, // Reintegros (v0.47, doc 53): pedir el reintegro de un gasto.
					'asde_presupuestos_seccion' => true, // Presupuestos (v0.55, doc 57): prepara el de sus secciones.
					'asde_caja'              => true, // Caja (v0.59, doc 61): ve y lleva la caja de sus secciones (P-210c).
				),
			),
			self::SECRETARIA   => array(
				'Secretaría',
				$base + array(
					'asde_view_self'       => true,
					'asde_view_all'        => true,
					'asde_edit_all'        => true,
					'asde_view_health'     => true,
					'asde_approve_changes' => true,
					'asde_aprobar_solicitudes' => true, // Solicitudes de acceso (v0.10): todas las del grupo.
					'asde_manage_users'    => true,
					'asde_reports'         => true,
					'asde_tesoreria_acceso' => true,
					'asde_tesoreria_config' => true,
					'asde_tienda_comprar'   => true,
					'asde_tienda_gestion' => true,
					'asde_tienda_entrega' => true,
					'asde_tienda_admin'   => true, // v0.39 (doc 48): Secretaría administra la tienda como Coordinación y Tesorería.
					'asde_mis_pagos'         => true,
					'asde_inventario'        => true,
					'asde_lista_espera'      => true,
					'asde_lista_espera_alta' => true, // Asignar sección, dar de alta en el CRM y borrar (v0.32).
					'asde_eventos_inscribir' => true,
					'asde_eventos_gestion'   => true, // Los de sección de todo el grupo.
					'asde_menus_recetas'     => true,
					'asde_presupuestos_seccion' => true, // Presupuestos (v0.55, doc 57).
					'asde_accesos'           => true, // Gestión de accesos (v0.53, doc 56).
					'asde_caja'              => true, // Caja (v0.59, doc 61): todas las cajas y el libro mayor.
					'asde_caja_todas'        => true,
				),
			),
			self::COORDINACION => array(
				'Coordinación',
				$base + array(
					'asde_view_self'       => true,
					'asde_view_all'        => true,
					'asde_view_health'     => true,
					'asde_annotate_health' => true,
					'asde_approve_changes' => true,
					'asde_aprobar_solicitudes' => true, // Solicitudes de acceso (v0.10): todas las del grupo.
					'asde_crm_sync'        => true,
					'asde_manage_users'    => true,
					'asde_reports'         => true,
					'asde_tesoreria_acceso' => true,
					'asde_tesoreria_config' => true,
					// Uniformes: Coordinación valida que están en el pedido del grupo.
					'asde_tienda_comprar'   => true,
					'asde_tienda_gestion' => true,
					'asde_tienda_entrega' => true,
					'asde_tienda_pedido'  => true,
					'asde_tienda_admin'  => true,
					'asde_mis_pagos'         => true,
					'asde_inventario'        => true,
					'asde_lista_espera'      => true,
					'asde_lista_espera_alta' => true,
					'asde_eventos_inscribir' => true,
					// Eventos: además, los de grupo y los externos, y la configuración.
					'asde_eventos_gestion'   => true,
					'asde_menus_recetas'     => true,
					'asde_eventos_grupo'     => true,
					'asde_reintegros'        => true, // Reintegros (v0.47): pide los suyos y marca pagados los de todos.
					'asde_reintegros_pagar'  => true,
					'asde_eventos_config'    => true,
					'asde_presupuestos_seccion' => true, // Presupuestos (v0.55, doc 57).
					'asde_grupo_datos'       => true, // Datos del grupo (v0.56, doc 58): los edita Coordinación.
					'asde_accesos'           => true, // Gestión de accesos (v0.53, doc 56).
					'asde_caja'              => true, // Caja (v0.59, doc 61): todas las cajas y el libro mayor.
					'asde_caja_todas'        => true,
				),
			),
			self::OFICINA      => array(
				'Oficina',
				$base + array(
					'asde_multigrupo'   => true, // Elige el grupo que consulta y lee con la credencial de la oficina.
					'asde_solo_lectura' => true, // Ninguna escritura: la bloquea el Router (fase 3).
					'asde_manage_users' => true, // Solo ver Personas y Usuarios.
					'asde_reports'      => true,
					'asde_view_health'  => true,
				),
			),
			self::TESORERIA    => array(
				'Tesorería',
				$base + array(
					'asde_view_self'        => true,
					'asde_reports'          => true, // v0.37.1: informes de pagos de todo el grupo (P-167); solo identidad y pagos, ver Catalogo::permitidas().
					'asde_tesoreria_acceso' => true,
					'asde_tesoreria_config' => true,
					// Uniformes: Tesorería valida el pago.
					'asde_tienda_comprar'   => true,
					'asde_tienda_gestion' => true,
					'asde_tienda_entrega' => true,
					'asde_tienda_pago'    => true,
					'asde_tienda_admin'  => true,
					'asde_mis_pagos'         => true,
					// Inventario: Tesorería da el visto bueno a las listas de compra (sin él no se imprimen).
					'asde_inventario'         => true,
					'asde_inventario_aprobar' => true,
					'asde_lista_espera'       => true,
					'asde_eventos_inscribir'  => true,
					// Eventos: valida el presupuesto (el precio sale de él) y configura el margen.
					'asde_eventos_economia'   => true,
					'asde_reintegros_aprobar' => true, // Reintegros (v0.47): aprueba y marca pagados.
					'asde_reintegros_pagar'   => true,
					'asde_eventos_config'     => true,
					'asde_presupuestos_seccion' => true, // Presupuestos (v0.55, doc 57).
					'asde_accesos'            => true, // Gestión de accesos (v0.53, doc 56).
					'asde_caja'               => true, // Caja (v0.59, doc 61): todas las cajas y el libro mayor.
					'asde_caja_todas'         => true,
				),
			),
			self::TUTOR        => array(
				'Tutor / Padre / Madre',
				$base + array(
					'asde_view_wards'           => true,
					'asde_propose_ward_changes' => true,
					'asde_tienda_comprar'      => true,
					'asde_mis_pagos'            => true,
					'asde_sepa_autorizar'       => true, // v0.12: firma la autorización de domiciliación SEPA de su familia.
					'asde_eventos_inscribir'    => true, // v0.35: «Mis eventos» (inscribir a sus menores).
				),
			),
			self::PARTICIPANTE => array(
				'Participante',
				$base + array(
					'asde_view_self'         => true,
					'asde_edit_self_contact' => true,
					'asde_add_self_notes'    => true,
					'asde_tienda_comprar'   => true,
					'asde_mis_pagos'         => true,
					'asde_sepa_autorizar'    => true, // Solo si es mayor de edad (Sepa\Mandatos::puede_firmar).
					'asde_eventos_inscribir' => true,
				),
			),
			// v0.43: el Intendente es quien responde del material del grupo (cargo «Responsable de Material» del CRM). Es una responsabilidad de un scouter o de un voluntario sin sección.
			self::INTENDENTE   => array(
				'Intendente (responsable de material)',
				$base + array(
					'asde_view_self'         => true,
					'asde_edit_self_contact' => true,
					'asde_tienda_comprar'    => true,
					'asde_mis_pagos'         => true,
					'asde_inventario'        => true,
					'asde_eventos_inscribir' => true,
					'asde_eventos_material'  => true, // Ve todos los eventos y gestiona su material (pestañas Resumen y Material).
				),
			),
		);
	}

	public static function label( string $role ): string {
		// La definición (con su etiqueta en castellano) es lo que se guarda en WordPress; aquí se traduce al mostrarla.
		$etiqueta = self::definitions()[ $role ][0] ?? $role;
		return asde_t( $etiqueta );
	}

	/** @return string[] Todas las capabilities asde_*. */
	public static function all_caps(): array {
		$caps = array();
		foreach ( self::definitions() as $def ) {
			$caps = array_merge( $caps, array_keys( $def[1] ) );
		}
		return array_values( array_unique( array_filter( $caps, static fn( $c ) => str_starts_with( $c, 'asde_' ) ) ) );
	}

	/** Crea o sincroniza los roles sin quitárselos a los usuarios que ya los tienen. */
	public static function install(): void {
		foreach ( self::definitions() as $slug => list( $label, $caps ) ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				add_role( $slug, $label, $caps );
				continue;
			}
			foreach ( $caps as $cap => $grant ) {
				$role->add_cap( $cap, $grant );
			}
			foreach ( array_keys( $role->capabilities ) as $cap ) {
				if ( str_starts_with( $cap, 'asde_' ) && ! isset( $caps[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		// Lo que los gestores han cambiado en la matriz de «Gestión de accesos» (v0.53) manda sobre los de fábrica.
		\AsdeBalears\AreaPrivada\Accesos\PermisosRol::aplicar();

		// Los administradores de WordPress pueden hacerlo todo en el área privada.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
