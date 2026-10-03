<?php
namespace AsdeBalears\AreaPrivada\Pwa;

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes de la app móvil (wp-admin → Grupos Scouts → App móvil, docs/pwa/, sección 5).
 * Opción `asde_ap_pwa`: {activa, nombre_corto}. Sin guardar, la app está **activa** y con el
 * nombre de siempre: las instalaciones anteriores no cambian (CLAUDE.md, regla 5).
 */
final class Ajustes {

	public const OPCION     = 'asde_ap_pwa';
	public const MAX_NOMBRE = 12; // El nombre corto va bajo el icono: más largo, se corta en el móvil.

	/** @return array{activa:bool, nombre_corto:string, push:bool} */
	public static function datos(): array {
		$d = get_option( self::OPCION, array() );
		$d = is_array( $d ) ? $d : array();
		return array(
			'activa'       => ! array_key_exists( 'activa', $d ) || (bool) $d['activa'],
			'nombre_corto' => self::limpiar_nombre( (string) ( $d['nombre_corto'] ?? '' ) ),
			'push'         => ! empty( $d['push'] ), // Las notificaciones push nacen apagadas (regla 5 del CLAUDE.md).
		);
	}

	/** ¿Están encendidas las notificaciones push? Solo con la app activa. */
	public static function push(): bool {
		$d = self::datos();
		return $d['activa'] && $d['push'];
	}

	public static function activa(): bool {
		return self::datos()['activa'];
	}

	/** Nombre elegido por el grupo, o '' si se usa el de siempre («Área privada», traducido). */
	public static function nombre_corto(): string {
		return self::datos()['nombre_corto'];
	}

	public static function limpiar_nombre( string $nombre ): string {
		$nombre = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $nombre ) ) );
		return trim( mb_substr( $nombre, 0, self::MAX_NOMBRE ) );
	}

	/** @param bool|null $push null = dejar como está. */
	public static function guardar( bool $activa, string $nombre_corto, ?bool $push = null ): void {
		update_option(
			self::OPCION,
			array(
				'activa'       => $activa,
				'nombre_corto' => self::limpiar_nombre( $nombre_corto ),
				'push'         => null === $push ? self::datos()['push'] : $push,
			),
			false
		);
	}

	/** Vuelve al estado de una instalación sin ajustes (activa, nombre de siempre). */
	public static function restablecer(): void {
		delete_option( self::OPCION );
	}
}
