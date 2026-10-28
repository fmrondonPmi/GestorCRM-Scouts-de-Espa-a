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
			$e[] = __( 'The first name is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( '' === $d['apellidos'] ) {
			$e[] = __( 'The surname is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		$f    = \DateTime::createFromFormat( '!Y-m-d', $d['nacimiento'], wp_timezone() );
		$edad = null;
		if ( ! $f || $f->format( 'Y-m-d' ) !== $d['nacimiento'] || $d['nacimiento'] > wp_date( 'Y-m-d' ) || $d['nacimiento'] < '1900-01-01' ) {
			$e[] = __( 'The date of birth is not valid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
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
			$e[] = sprintf( /* translators: %d: number */ __( 'The document is required from age %d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Duplicados::EDAD_DOCUMENTO );
		}
		if ( null !== $edad && $edad < Inscripciones::EDAD_ADULTA && ( '' === $d['contacto_nombre'] || '' === $d['contacto_apellidos'] ) ) {
			/* translators: %d: edad */
			$e[] = sprintf( /* translators: %d: number */ __( 'Under %d years of age, enter the first name and surname of the mother, father or guardian.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::EDAD_ADULTA );
		}
		$digitos = strlen( (string) preg_replace( '/\D/', '', $d['telefono'] ) );
		if ( $digitos < 9 || $digitos > 15 ) {
			$e[] = __( 'Enter a valid phone number (at least 9 digits).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( ! is_email( $d['email'] ) ) {
			$e[] = __( 'Enter a valid email address.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( '1' !== (string) ( $in['acepto'] ?? '' ) ) {
			$e[] = __( 'To sign up you must accept the processing of your data.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( '1' !== (string) ( $in['declaro'] ?? '' ) ) {
			$e[] = __( 'To sign up you must tick the declaration that the data is truthful and accept the group\'s conditions.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		return array(
			'datos'   => $d,
			'errores' => $e,
		);
	}
}
