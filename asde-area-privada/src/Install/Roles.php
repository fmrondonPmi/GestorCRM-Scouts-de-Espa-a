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

	public const ALL = array( self::SCOUTER, self::SECRETARIA, self::COORDINACION, self::TESORERIA, self::TUTOR, self::PARTICIPANTE );

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
					'asde_edit_section_events'  => true,
					'asde_approve_changes'      => true,
					'asde_aprobar_solicitudes' => true, // Solicitudes de acceso (v0.10): un scouter, las de sus secciones.
					'asde_view_health'          => true, // Solo lectura y solo su sección (2026-09-26).
					'asde_reports'              => true,
					'asde_tesoreria_acceso' => true,
					// Uniformes (v0.11): pedir, ver los de sus secciones y entregarlos.
					'asde_uniformes_pedir'   => true,
					'asde_uniformes_gestion' => true,
					'asde_uniformes_entrega' => true,
					'asde_mis_pagos'         => true,
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
					'asde_uniformes_pedir'   => true,
					'asde_uniformes_gestion' => true,
					'asde_uniformes_entrega' => true,
					'asde_mis_pagos'         => true,
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
					'asde_uniformes_pedir'   => true,
					'asde_uniformes_gestion' => true,
					'asde_uniformes_entrega' => true,
					'asde_uniformes_pedido'  => true,
					'asde_uniformes_config'  => true,
					'asde_mis_pagos'         => true,
				),
			),
			self::TESORERIA    => array(
				'Tesorería',
				$base + array(
					'asde_view_self'        => true,
					'asde_tesoreria_acceso' => true,
					'asde_tesoreria_config' => true,
					// Uniformes: Tesorería valida el pago.
					'asde_uniformes_pedir'   => true,
					'asde_uniformes_gestion' => true,
					'asde_uniformes_entrega' => true,
					'asde_uniformes_pago'    => true,
					'asde_uniformes_config'  => true,
					'asde_mis_pagos'         => true,
				),
			),
			self::TUTOR        => array(
				'Tutor / Padre / Madre',
				$base + array(
					'asde_view_wards'           => true,
					'asde_propose_ward_changes' => true,
					'asde_uniformes_pedir'      => true,
					'asde_mis_pagos'            => true,
					'asde_sepa_autorizar'       => true, // v0.12: firma la autorización de domiciliación SEPA de su familia.
				),
			),
			self::PARTICIPANTE => array(
				'Participante',
				$base + array(
					'asde_view_self'         => true,
					'asde_edit_self_contact' => true,
					'asde_add_self_notes'    => true,
					'asde_uniformes_pedir'   => true,
					'asde_mis_pagos'         => true,
					'asde_sepa_autorizar'    => true, // Solo si es mayor de edad (Sepa\Mandatos::puede_firmar).
				),
			),
		);
	}

	public static function label( string $role ): string {
		return self::definitions()[ $role ][0] ?? $role;
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

		// Los administradores de WordPress pueden hacerlo todo en el área privada.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
