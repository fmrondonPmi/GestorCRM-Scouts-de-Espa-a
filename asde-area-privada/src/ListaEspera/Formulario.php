<?php
namespace AsdeBalears\AreaPrivada\ListaEspera;

use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que se pide al apuntarse a la lista de espera y cómo se valida (v0.32, doc 39).
 * Lo mínimo para dar de alta después en el CRM (Crm\Altas::limpiar_persona): nombre,
 * apellidos, nacimiento, documento desde los 14 años (P-82) y, por debajo de 18, la persona
 * de contacto como tutor/a 1 (P-88, P-125). El género lo pone Secretaría al dar de alta.
 */
final class Formulario {

	public const CAMPOS = array( 'nombre', 'apellidos', 'nacimiento', 'documento', 'contacto_nombre', 'contacto_apellidos', 'telefono', 'email', 'observaciones', 'acepto', 'declaro' );

	public const MAX_OBSERVACIONES = 1000;

	/**
	 * @param array<string, string> $in Lo enviado (sin barras).
	 * @return array{datos:array<string, string>, errores:string[]}
	 */
	public static function validar( array $in ): array {
		$t = static fn( string $c, int $max ): string => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $in[ $c ] ?? '' ) ) ) ), 0, $max );
		$d = array(
			'nombre'             => $t( 'nombre', 60 ),
			'apellidos'          => $t( 'apellidos', 100 ),
			'nacimiento'         => $t( 'nacimiento', 10 ),
			'documento'          => $t( 'documento', 30 ),
			'contacto_nombre'    => $t( 'contacto_nombre', 60 ),
			'contacto_apellidos' => $t( 'contacto_apellidos', 100 ),
			'telefono'           => (string) preg_replace( '/[^0-9+]/', '', (string) ( $in['telefono'] ?? '' ) ),
			'email'              => EmailCuenta::normalizar( (string) ( $in['email'] ?? '' ) ),
			'observaciones'      => mb_substr( trim( sanitize_textarea_field( (string) ( $in['observaciones'] ?? '' ) ) ), 0, self::MAX_OBSERVACIONES ),
		);
		$e = array();
		if ( '' === $d['nombre'] ) {
			$e[] = asde_t( 'Falta el nombre.' );
		}
		if ( '' === $d['apellidos'] ) {
			$e[] = asde_t( 'Faltan los apellidos.' );
		}
		$f    = \DateTime::createFromFormat( '!Y-m-d', $d['nacimiento'], wp_timezone() );
		$edad = null;
		if ( ! $f || $f->format( 'Y-m-d' ) !== $d['nacimiento'] || $d['nacimiento'] > wp_date( 'Y-m-d' ) || $d['nacimiento'] < '1900-01-01' ) {
			$e[] = asde_t( 'La fecha de nacimiento no es válida.' );
		} else {
			$edad = (int) $f->diff( new \DateTime( 'today', wp_timezone() ) )->y;
		}
		if ( '' !== $d['documento'] ) {
			$v = Duplicados::validar( $d['documento'] );
			if ( $v['ok'] ) {
				$d['documento'] = $v['documento'];
			} else {
				$e[] = $v['error'];
			}
		} elseif ( null !== $edad && Duplicados::documento_obligatorio( $d['nacimiento'] ) ) {
			/* translators: %d: edad */
			$e[] = sprintf( asde_t( 'El documento es obligatorio a partir de los %d años.' ), Duplicados::EDAD_DOCUMENTO );
		}
		if ( null !== $edad && $edad < Inscripciones::EDAD_ADULTA && ( '' === $d['contacto_nombre'] || '' === $d['contacto_apellidos'] ) ) {
			/* translators: %d: edad */
			$e[] = sprintf( asde_t( 'Por debajo de %d años, escribe el nombre y los apellidos de la madre, el padre o el tutor/a.' ), Inscripciones::EDAD_ADULTA );
		}
		$digitos = strlen( (string) preg_replace( '/\D/', '', $d['telefono'] ) );
		if ( $digitos < 9 || $digitos > 15 ) {
			$e[] = asde_t( 'Escribe un teléfono válido (al menos 9 cifras).' );
		}
		if ( ! is_email( $d['email'] ) ) {
			$e[] = asde_t( 'Escribe un email válido.' );
		}
		if ( '1' !== (string) ( $in['acepto'] ?? '' ) ) {
			$e[] = asde_t( 'Para apuntarte tienes que aceptar el tratamiento de los datos.' );
		}
		if ( '1' !== (string) ( $in['declaro'] ?? '' ) ) {
			$e[] = asde_t( 'Para apuntarte tienes que marcar la declaración de que los datos son veraces y aceptar las condiciones del grupo.' );
		}
		return array(
			'datos'   => $d,
			'errores' => $e,
		);
	}
}
