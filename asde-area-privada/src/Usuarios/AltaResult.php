<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

defined( 'ABSPATH' ) || exit;

final class AltaResult {

	public const CREADO      = 'creado';
	public const ACTUALIZADO = 'actualizado';

	/** @param string[] $errores */
	public function __construct(
		public bool $ok,
		public ?\WP_User $usuario = null,
		public string $accion = '',
		public bool $invitacion_enviada = false,
		public array $errores = array()
	) {}

	public static function error( string ...$errores ): self {
		return new self( false, null, '', false, $errores );
	}
}
