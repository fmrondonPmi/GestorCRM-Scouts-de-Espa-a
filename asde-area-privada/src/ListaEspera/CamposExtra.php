<?php
namespace AsdeBalears\AreaPrivada\ListaEspera;

use AsdeBalears\AreaPrivada\Crm\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Preguntas propias de cada grupo en el formulario de la lista de espera (v0.38, doc 46).
 *
 * - Secretaría o Coordinación definen, por grupo, los campos que se añaden a los obligatorios
 *   del formulario: etiqueta (obligatoria), tipo y si hay que rellenarlo. Las definiciones no
 *   son datos personales: van en una opción de WordPress.
 * - Las respuestas de cada inscripción van cifradas en la columna `extra_enc` con una clave que
 *   sale del email de quien se apunta, de la sal de su fila y de la clave del sitio
 *   (`Crypto::encrypt_contexto`). Junto a cada respuesta se guarda la etiqueta que tenía la
 *   pregunta al contestarla, así que editar o quitar un campo después no cambia lo ya contestado.
 *   Al borrar la inscripción desaparecen las respuestas y también el email y la sal con los que
 *   se descifrarían.
 */
final class CamposExtra {

	public const OPCION = 'asde_ap_espera_campos';

	public const OPCION_DECLARACION = 'asde_ap_espera_declaracion';
	public const MAX_DECLARACION    = 500;

	public const MAX_CAMPOS   = 12;
	public const MAX_ETIQUETA = 80;
	public const MAX_RESPUESTA = 1000;

	public const TEXTO   = 'texto';
	public const PARRAFO = 'parrafo';
	public const NUMERO  = 'numero';
	public const FECHA   = 'fecha';
	public const CASILLA = 'casilla';

	public const TIPOS = array(
		self::TEXTO   => 'Texto corto',
		self::PARRAFO => 'Texto largo',
		self::NUMERO  => 'Número',
		self::FECHA   => 'Fecha',
		self::CASILLA => 'Casilla (sí / no)',
	);

	/** Texto de la casilla de declaración que el grupo ha escrito ('' = el de por defecto). */
	public static function declaracion_propia( string $numero ): string {
		$todo = get_option( self::OPCION_DECLARACION, array() );
		return is_array( $todo ) && is_string( $todo[ $numero ] ?? null ) ? $todo[ $numero ] : '';
	}

	/** Texto de la casilla de declaración que verá quien se apunta: el del grupo o, si no hay, el de por defecto. */
	public static function declaracion( string $numero ): string {
		$propio = self::declaracion_propia( $numero );
		return '' !== $propio ? $propio : asde_t( 'Declaro que todos los datos que he introducido son veraces y acepto las condiciones del grupo scout.' );
	}

	/** Guarda el texto de la declaración del grupo; vacío o igual al de por defecto = volver al de por defecto. */
	public static function guardar_declaracion( string $numero, string $texto ): void {
		$texto = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $texto ) ) ), 0, self::MAX_DECLARACION );
		$todo  = get_option( self::OPCION_DECLARACION, array() );
		$todo  = is_array( $todo ) ? $todo : array();
		if ( '' === $texto || asde_t( 'Declaro que todos los datos que he introducido son veraces y acepto las condiciones del grupo scout.' ) === $texto ) {
			unset( $todo[ $numero ] );
		} else {
			$todo[ $numero ] = $texto;
		}
		update_option( self::OPCION_DECLARACION, $todo, false );
	}

	/** @return array[] Campos del grupo: id, etiqueta, tipo, obligatorio. */
	public static function del_grupo( string $numero ): array {
		$todo = get_option( self::OPCION, array() );
		$lista = is_array( $todo ) && is_array( $todo[ $numero ] ?? null ) ? $todo[ $numero ] : array();
		$out   = array();
		foreach ( $lista as $c ) {
			if ( is_array( $c ) && ! empty( $c['id'] ) && ! empty( $c['etiqueta'] ) && isset( self::TIPOS[ $c['tipo'] ?? '' ] ) ) {
				$out[] = array(
					'id'          => (string) $c['id'],
					'etiqueta'    => (string) $c['etiqueta'],
					'tipo'        => (string) $c['tipo'],
					'obligatorio' => ! empty( $c['obligatorio'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Valida lo que envía el editor de campos y lo guarda.
	 *
	 * @param array[] $filas Cada una: id ('' = nueva), etiqueta, tipo, obligatorio (bool), quitar (bool).
	 * @return string[] Errores (si hay, no se guarda nada).
	 */
	public static function guardar( string $numero, array $filas ): array {
		$actuales = array_column( self::del_grupo( $numero ), null, 'id' );
		$nuevos   = array();
		$errores  = array();
		foreach ( $filas as $f ) {
			$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $f['id'] ?? '' ) ) );
			if ( ! empty( $f['quitar'] ) ) {
				continue;
			}
			$etiqueta = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $f['etiqueta'] ?? '' ) ) ) ), 0, self::MAX_ETIQUETA );
			$tipo     = (string) ( $f['tipo'] ?? '' );
			if ( '' === $id && '' === $etiqueta ) {
				continue; // Fila nueva sin rellenar.
			}
			if ( '' === $etiqueta ) {
				$errores[] = asde_t( 'Cada campo necesita su etiqueta: es el texto que verá quien se apunta.' );
				break;
			}
			if ( ! isset( self::TIPOS[ $tipo ] ) ) {
				$errores[] = asde_t( 'Elige el tipo de cada campo.' );
				break;
			}
			if ( '' === $id || ! isset( $actuales[ $id ] ) ) {
				$id = strtolower( wp_generate_password( 8, false, false ) );
			}
			$nuevos[] = array(
				'id'          => $id,
				'etiqueta'    => $etiqueta,
				'tipo'        => $tipo,
				'obligatorio' => ! empty( $f['obligatorio'] ),
			);
		}
		if ( ! $errores && count( $nuevos ) > self::MAX_CAMPOS ) {
			/* translators: %d: máximo de campos */
			$errores[] = sprintf( asde_t( 'Como máximo, %d campos propios.' ), self::MAX_CAMPOS );
		}
		if ( ! $errores ) {
			$todo = get_option( self::OPCION, array() );
			$todo = is_array( $todo ) ? $todo : array();
			if ( $nuevos ) {
				$todo[ $numero ] = $nuevos;
			} else {
				unset( $todo[ $numero ] );
			}
			update_option( self::OPCION, $todo, false );
		}
		return $errores;
	}

	/**
	 * Respuestas del formulario público.
	 *
	 * @param array[]               $campos Definiciones del grupo.
	 * @param array<string, string> $in     Lo enviado (campos `extra_<id>`).
	 * @return array{respuestas:array[], errores:string[]}
	 */
	public static function validar( array $campos, array $in ): array {
		$resp = array();
		$err  = array();
		foreach ( $campos as $c ) {
			$bruto = (string) ( $in[ 'extra_' . $c['id'] ] ?? '' );
			switch ( $c['tipo'] ) {
				case self::CASILLA:
					$v = '1' === $bruto ? asde_t( 'Sí' ) : '';
					break;
				case self::PARRAFO:
					$v = mb_substr( trim( sanitize_textarea_field( $bruto ) ), 0, self::MAX_RESPUESTA );
					break;
				case self::NUMERO:
					$v = trim( $bruto );
					if ( '' !== $v && ! preg_match( '/^-?\d{1,15}([.,]\d{1,6})?$/', $v ) ) {
						/* translators: %s: etiqueta del campo */
						$err[] = sprintf( asde_t( '«%s» tiene que ser un número.' ), $c['etiqueta'] );
						continue 2;
					}
					break;
				case self::FECHA:
					$v = trim( $bruto );
					$f = \DateTime::createFromFormat( '!Y-m-d', $v, wp_timezone() );
					if ( '' !== $v && ( ! $f || $f->format( 'Y-m-d' ) !== $v ) ) {
						/* translators: %s: etiqueta del campo */
						$err[] = sprintf( asde_t( '«%s» no es una fecha válida.' ), $c['etiqueta'] );
						continue 2;
					}
					break;
				default:
					$v = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $bruto ) ) ), 0, 200 );
			}
			if ( '' === $v ) {
				if ( $c['obligatorio'] ) {
					/* translators: %s: etiqueta del campo */
					$err[] = sprintf( asde_t( 'Falta rellenar «%s».' ), $c['etiqueta'] );
				}
				continue;
			}
			$resp[] = array(
				'id'       => $c['id'],
				'etiqueta' => $c['etiqueta'],
				'tipo'     => $c['tipo'],
				'valor'    => $v,
			);
		}
		return array(
			'respuestas' => $resp,
			'errores'    => $err,
		);
	}

	/** Clave de las respuestas de una inscripción: su email y su sal (y la clave del sitio). */
	private static function contexto( string $email, string $sal ): string {
		return 'espera-extra|' . strtolower( trim( $email ) ) . '|' . $sal;
	}

	public static function cifrar( array $respuestas, string $email, string $sal ): ?string {
		return $respuestas ? Crypto::encrypt_contexto( (string) wp_json_encode( $respuestas ), self::contexto( $email, $sal ) ) : null;
	}

	/** @return array[] */
	public static function descifrar( ?string $guardado, string $email, string $sal ): array {
		if ( null === $guardado || '' === $guardado ) {
			return array();
		}
		$json = Crypto::decrypt_contexto( $guardado, self::contexto( $email, $sal ) );
		$a    = null !== $json ? json_decode( $json, true ) : null;
		return is_array( $a ) ? array_values( array_filter( $a, static fn( $r ) => is_array( $r ) && isset( $r['etiqueta'], $r['valor'] ) ) ) : array();
	}
}
