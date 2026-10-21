<?php
namespace AsdeBalears\AreaPrivada\Sepa;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Datos del acreedor para las domiciliaciones SEPA de cada grupo (opción
 * asde_ap_sepa_<grupo>): nombre, identificador del acreedor (ICS), su IBAN
 * (cifrado) y dirección, y cómo se avisa a las familias.
 *
 * Sin estos datos no se ofrece la autorización a las familias ni se puede
 * generar ningún fichero.
 */
final class Config {

	private const OPTION = 'asde_ap_sepa_';

	/** Días de antelación con los que se avisa a la familia de cada cargo (los 14 que fija la norma se pueden reducir si se pacta en el mandato). */
	public const DIAS_AVISO_DEFECTO = 5;

	/** @return array{activo:bool, nombre:string, ics:string, iban:string, bic:string, calle:string, cp:string, poblacion:string, provincia:string, dias_aviso:int, concepto:string} */
	public static function defaults(): array {
		return array(
			'activo'     => false,
			'nombre'     => '',
			'ics'        => '',
			'iban'       => '',
			'bic'        => '',
			'calle'      => '',
			'cp'         => '',
			'poblacion'  => '',
			'provincia'  => '',
			'dias_aviso' => self::DIAS_AVISO_DEFECTO,
			'concepto'   => 'Cuota {grupo} {ronda}',
		);
	}

	/** Configuración del grupo, con el IBAN descifrado ('' si no hay o no se puede descifrar). */
	public static function de( string $grupo = '' ): array {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		$c     = (array) get_option( self::OPTION . $grupo, array() );
		$d     = self::defaults();
		$cfg   = array_merge( $d, array_intersect_key( $c, $d ) );

		$cfg['activo']     = (bool) $cfg['activo'];
		// v0.50 (doc 50, fase 6): si el grupo dio su denominación fiscal y su sede al darse de alta, son el acreedor y la dirección por defecto.
		$datos_grupo = \AsdeBalears\AreaPrivada\Domain\Grupos::por_numero( $grupo );
		if ( '' === trim( (string) $cfg['nombre'] ) && '' !== (string) ( $datos_grupo['denominacion'] ?? '' ) ) {
			$cfg['nombre'] = (string) $datos_grupo['denominacion'];
		}
		if ( '' === trim( (string) $cfg['calle'] ) && '' !== (string) ( $datos_grupo['sede'] ?? '' ) ) {
			$cfg['calle'] = (string) $datos_grupo['sede'];
		}
		$cfg['nombre']     = mb_substr( (string) $cfg['nombre'], 0, 70 );
		$cfg['ics']        = self::normalizar_ics( (string) $cfg['ics'] );
		$cfg['iban']       = isset( $c['iban_enc'] ) && '' !== $c['iban_enc'] ? Iban::normalizar( (string) Crypto::decrypt( (string) $c['iban_enc'] ) ) : '';
		$cfg['bic']        = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $cfg['bic'] ) );
		$cfg['cp']         = mb_substr( (string) $cfg['cp'], 0, 10 );
		$cfg['dias_aviso'] = max( 1, min( 30, (int) $cfg['dias_aviso'] ) );
		$cfg['concepto']   = '' !== trim( (string) $cfg['concepto'] ) ? mb_substr( (string) $cfg['concepto'], 0, 90 ) : $d['concepto'];
		return $cfg;
	}

	/** Guarda la configuración (el IBAN, cifrado). El IBAN vacío conserva el guardado. */
	public static function guardar( array $cfg, string $grupo = '' ): void {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		$antes = (array) get_option( self::OPTION . $grupo, array() );
		$d     = self::defaults();
		$out   = array_intersect_key( $cfg, $d );
		unset( $out['iban'] );
		$iban = Iban::normalizar( (string) ( $cfg['iban'] ?? '' ) );
		if ( '' !== $iban ) {
			$out['iban_enc'] = Crypto::encrypt( $iban );
		} elseif ( isset( $antes['iban_enc'] ) ) {
			$out['iban_enc'] = $antes['iban_enc'];
		}
		update_option( self::OPTION . $grupo, $out, false );
	}

	public static function borrar( string $grupo ): void {
		delete_option( self::OPTION . $grupo );
	}

	public static function normalizar_ics( string $ics ): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $ics ) );
	}

	/**
	 * Lo que falta o está mal para poder domiciliar (vacío = todo listo).
	 *
	 * @return string[]
	 */
	public static function errores( array $cfg ): array {
		$e = array();
		if ( '' === trim( Texto::limpiar( $cfg['nombre'] ) ) ) {
			$e[] = asde_t( 'Falta el nombre del acreedor (el del grupo o la asociación que cobra).' );
		}
		if ( '' === $cfg['ics'] ) {
			$e[] = asde_t( 'Falta el identificador del acreedor (ICS). Lo da el banco al contratar las domiciliaciones.' );
		} elseif ( ! self::ics_formato( $cfg['ics'] ) ) {
			$e[] = asde_t( 'El identificador del acreedor no tiene el formato correcto (en España, ES + 2 cifras de control + 3 caracteres + NIF: 16 caracteres, p. ej. ES12ZZZG12345678).' );
		}
		$err = Iban::error( $cfg['iban'] );
		if ( '' !== $err ) {
			$e[] = asde_t( 'IBAN del acreedor: ' ) . $err;
		}
		if ( '' !== $cfg['bic'] && ! preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $cfg['bic'] ) ) {
			$e[] = asde_t( 'El BIC no es válido (8 u 11 caracteres). Puedes dejarlo vacío.' );
		}
		return $e;
	}

	/** ¿Tiene forma de identificador de acreedor? (2 letras de país, 2 cifras, 3 caracteres y el identificador nacional; 35 como mucho.) */
	public static function ics_formato( string $ics ): bool {
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', $ics ) ) {
			return false;
		}
		return 'ES' !== substr( $ics, 0, 2 ) || 16 === strlen( $ics );
	}

	/**
	 * ¿Cuadran las cifras de control del ICS? (Identificador nacional + país + «00», módulo 97; el código de negocio, posiciones 5 a 7, no cuenta.)
	 */
	public static function ics_control_correcto( string $ics ): bool {
		if ( ! self::ics_formato( $ics ) ) {
			return false;
		}
		$control = 98 - Iban::resto97( substr( $ics, 7 ) . substr( $ics, 0, 2 ) . '00' );
		return sprintf( '%02d', $control ) === substr( $ics, 2, 2 );
	}

	/** ¿Está todo para ofrecer la autorización y generar ficheros? */
	public static function lista( array $cfg ): bool {
		return $cfg['activo'] && ! self::errores( $cfg );
	}

	/** El concepto de la remesa con {grupo} y {ronda} sustituidos (ya en el juego de caracteres SEPA). */
	public static function concepto( array $cfg, string $ronda ): string {
		return Texto::limpiar( strtr( $cfg['concepto'], array( '{grupo}' => Grupo::nombre(), '{ronda}' => $ronda ) ), 140 );
	}
}
