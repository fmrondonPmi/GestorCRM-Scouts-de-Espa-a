<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Grupo Scout en el que se trabaja en esta petición:
 * - el grupo "padre" del usuario conectado;
 * - para administradores de WordPress, el que eligieron al entrar;
 * - en tareas automáticas o pruebas, el que se fuerce (o el primero).
 * Todo el área privada (socios, usuarios, familias, pagos, cuotas, informes)
 * se limita a este grupo.
 */
final class Grupo {

	public const META_CONTEXTO = 'asde_grupo_contexto';

	private static ?array $forzado = null;

	/** @return array{id:string, numero:string, nombre:string}|null */
	public static function actual(): ?array {
		if ( null !== self::$forzado ) {
			return self::$forzado;
		}
		$user = wp_get_current_user();
		if ( $user->exists() ) {
			$g = Grupos::de_usuario( $user );
			if ( $g ) {
				return $g;
			}
			$ctx = (string) get_user_meta( $user->ID, self::META_CONTEXTO, true );
			if ( '' !== $ctx && ( $g = Grupos::obtener( $ctx ) ) ) {
				return $g;
			}
		}
		$todos = Grupos::todos();
		return $todos ? reset( $todos ) : null;
	}

	/** Trabaja en un grupo concreto (tareas automáticas, pruebas). */
	public static function forzar( ?array $grupo ): void {
		self::$forzado = $grupo;
	}

	/**
	 * Ejecuta $fn en el grupo indicado y restaura el anterior (p. ej. enviar la
	 * recuperación de contraseña, sin sesión, desde el grupo del destinatario).
	 */
	public static function con( ?array $grupo, callable $fn ) {
		$antes         = self::$forzado;
		self::$forzado = $grupo ?? $antes;
		try {
			return $fn();
		} finally {
			self::$forzado = $antes;
		}
	}

	public static function id(): string {
		return self::actual()['id'] ?? '';
	}

	public static function numero(): string {
		return self::actual()['numero'] ?? '';
	}

	public static function nombre(): string {
		return self::actual()['nombre'] ?? get_bloginfo( 'name' );
	}
}
