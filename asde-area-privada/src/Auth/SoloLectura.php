<?php
namespace AsdeBalears\AreaPrivada\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Cuentas de solo lectura (v0.50, doc 50 fase 3): la oficina federal consulta los grupos pero no cambia nada.
 *
 * Es una capa más, la central, además de que el rol Oficina no tiene ninguna capability de edición y de que
 * la credencial del CRM debe ser de solo lectura: el Router rechaza todo envío de formulario de una cuenta de
 * solo lectura salvo la lista blanca (cosas que no cambian datos del grupo), y `Crm\Escritura` se niega a escribir.
 */
final class SoloLectura {

	public const CAPABILITY = 'asde_solo_lectura';

	/** Acciones (`asde_action`) que sí puede enviar: elegir el grupo que consulta. */
	public const PERMITIDAS = array( 'oficina_cambiar_grupo' );

	/** ¿El usuario actual es de solo lectura? */
	public static function activo(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}

	/** ¿Se rechaza esta acción? */
	public static function bloquea( string $accion ): bool {
		return self::activo() && ! in_array( $accion, self::PERMITIDAS, true );
	}
}
