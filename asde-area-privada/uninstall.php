<?php
/**
 * Desinstalación: borra la configuración (también los grupos y sus credenciales
 * del CRM), los roles, la tarea diaria y el elemento "Acceso socios" del menú.
 * Se conservan, a propósito, los usuarios, las páginas y las tablas
 * ({prefix}asde_auditoria, asde_socios, asde_informes, asde_familias,
 * asde_familia_miembros, asde_movimientos, asde_emails, asde_banco_lotes, asde_cambios,
 * asde_banco_movimientos, asde_solicitudes, asde_uniformes, asde_sepa_mandatos, asde_sepa_remesas)
 * y el email cifrado de cada cuenta. Las autorizaciones SEPA y las remesas se conservan (cifradas): son
 * el justificante de los cobros; quien no las necesite puede borrar esas dos tablas a mano.
 * La configuración de uniformes de cada grupo (asde_ap_uniformes_<grupo>) se borra;
 * sus imágenes se quedan en la biblioteca de medios.
 * De las solicitudes de acceso sí se borra lo que escribió quien pedía.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'asde_ap_mantenimiento_diario' );
wp_clear_scheduled_hook( 'asde_ap_emails_iniciales' );
wp_unschedule_hook( 'asde_ap_tarea_numeracion' );

// Correcciones de números en segundo plano (estado y cerrojo de cada grupo) y su diagnóstico.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'asde\\_ap\\_tarea\\_%' OR option_name LIKE 'asde\\_diag\\_sp%'" ); // phpcs:ignore WordPress.DB
wp_unschedule_hook( 'asde_diag_sp_cron' );

// Solicitudes de acceso: fuera lo escrito por quien pedía (cifrado); queda el resultado.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'asde_solicitudes' ) ) ) { // phpcs:ignore WordPress.DB
	$wpdb->query( "UPDATE {$wpdb->prefix}asde_solicitudes SET datos_enc = NULL" ); // phpcs:ignore WordPress.DB
}

$asde_menu_item = (int) get_option( 'asde_ap_menu_item' );
if ( $asde_menu_item && 'nav_menu_item' === get_post_type( $asde_menu_item ) ) {
	wp_delete_post( $asde_menu_item, true );
}

$asde_grupos = get_option( 'asde_ap_grupos' );
foreach ( is_array( $asde_grupos ) ? $asde_grupos : array() as $asde_grupo ) {
	delete_transient( 'asde_ap_numeracion_' . $asde_grupo['numero'] );
	delete_transient( 'asde_ap_snap_' . $asde_grupo['numero'] ); // Instantánea cifrada de las fichas (v0.12.3).
	wp_clear_scheduled_hook( 'asde_ap_instantanea_refrescar', array( (string) $asde_grupo['numero'] ) );
	delete_option( 'asde_ap_snaplock_' . $asde_grupo['numero'] ); // Cerrojo de la lectura de fichas.
	delete_option( 'asde_ap_uniformes_' . $asde_grupo['numero'] );
	delete_option( 'asde_ap_sepa_' . $asde_grupo['numero'] ); // Datos del acreedor SEPA (el IBAN, cifrado).
}
delete_transient( 'asde_ap_numeracion' );
delete_transient( 'asde_ap_grupos_reintento' );

foreach ( array( 'asde_ap_crm', 'asde_ap_crm_last_test', 'asde_ap_version', 'asde_ap_grupo', 'asde_ap_grupos', 'asde_ap_pages', 'asde_ap_menu_item', 'asde_ap_wordfence_config', 'asde_ap_tesoreria', 'asde_ap_banco_csv', 'asde_ap_colores', 'asde_ap_idioma', 'asde_ap_etiquetas', 'asde_ap_enlace_scouters' ) as $option ) {
	delete_option( $option );
}
delete_metadata( 'user', 0, 'asde_ap_idioma', '', true ); // Idioma elegido por cada persona (v0.15).

foreach ( array( 'asde_scouter', 'asde_secretaria', 'asde_coordinacion', 'asde_tesoreria', 'asde_tutor', 'asde_participante' ) as $role ) {
	remove_role( $role );
}

$admin = get_role( 'administrator' );
if ( $admin ) {
	foreach ( array_keys( $admin->capabilities ) as $cap ) {
		if ( str_starts_with( $cap, 'asde_' ) ) {
			$admin->remove_cap( $cap );
		}
	}
}
