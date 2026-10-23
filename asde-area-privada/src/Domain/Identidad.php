<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Socios\SociosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Qué número es el USUARIO DE ACCESO (v0.14, doc 27).
 *
 * Se elige en wp-admin → Grupos Scouts → Organización federada (Domain\Organizacion):
 *
 * - nº de socio/a de Scouts de España (numerosocio_c): es la clave estable de la
 *   persona, siempre existe y es único (4–6 cifras). Ejemplo: 118241.
 * - nº de socio/a local/territorial (numsocioantiguo_enficha_c): código de la
 *   organización + grupo + 4 cifras (76840397), con las incidencias que se
 *   vigilan en Socios\Numeracion. Era el usuario hasta la v0.12.
 *
 * En los dos casos las cuentas familiares son «nº del menor + T» (y «+ T2» el
 * segundo tutor/a). Al entrar se aceptan los dos números: si no existe un usuario
 * con lo escrito, se busca la cuenta con el otro número de esa misma persona
 * (tabla de equivalencias), así que nadie se queda fuera mientras se migra.
 *
 * El nº territorial se sigue vigilando (análisis, correcciones, tareas) sea cual
 * sea el modo: este solo decide cuál es el usuario.
 */
final class Identidad {

	/** Un nº de socio/a (de cualquier tipo) con el sufijo de familia opcional: base y sufijo. */
	private const PATRON_LOGIN = '/^(\d{4,12})(T[2-9]?)?$/';

	public static function modo(): string {
		return Organizacion::identificador();
	}

	public static function es_asde(): bool {
		return Organizacion::IDENTIFICADOR_ASDE === self::modo();
	}

	/** «nº de socio/a de Scouts de España» o «nº de socio/a local», para los textos. */
	public static function etiqueta(): string {
		return self::es_asde() ? asde_t( 'nº de socio/a de Scouts de España' ) : asde_t( 'nº de socio/a local' );
	}

	/** Etiqueta del campo de la pantalla de acceso. */
	public static function etiqueta_campo(): string {
		return self::es_asde() ? asde_t( 'Nº de socio/a (Scouts de España) o email' ) : asde_t( 'Nº de socio/a o email' );
	}

	/** Usuario de ejemplo de una persona (p. ej. 118241 o 76840390). */
	public static function ejemplo(): string {
		if ( self::es_asde() ) {
			return '118241';
		}
		$grupo = (string) ( array_key_first( Grupos::todos() ) ?? '' );
		return '' !== $grupo && NumeroSocio::configurado( $grupo ) ? NumeroSocio::formatear( $grupo, 390 ) : '';
	}

	/** Usuario de ejemplo de una cuenta familiar (p. ej. 118241T). */
	public static function ejemplo_tutor(): string {
		$e = self::ejemplo();
		return '' !== $e ? NumeroSocio::usuario_tutor( $e ) : '';
	}

	/**
	 * Usuario (sin sufijo) de una persona: su nº de Scouts de España o, en modo
	 * local, el nº local ya comprobado (Numeracion::de). null si no hay (modo local
	 * sin número utilizable).
	 */
	public static function usuario_de( Persona $p, ?string $numero_local ): ?string {
		return self::es_asde() ? $p->num_socio_asde : $numero_local;
	}

	/**
	 * Un nº de socio/a base (de una cuenta ya creada) pasado al tipo de número que
	 * es ahora el usuario, con la tabla de equivalencias. Si no se puede, el mismo.
	 */
	public static function convertir( string $base ): string {
		$otro = self::es_asde() ? SociosTable::asde_de( $base ) : SociosTable::local_de( $base );
		return null !== $otro && '' !== $otro ? $otro : $base;
	}

	/**
	 * Escrito por la persona o guardado → formato de usuario: sin espacios, puntos ni
	 * guiones, sin ceros delante y con la T en mayúscula (118241, 118241T, 76840390T2).
	 * null si no parece un nº de socio/a.
	 */
	public static function normalizar_numero( string $valor ): ?string {
		$v = str_replace( array( ' ', '.', '-' ), '', trim( $valor ) );
		if ( preg_match( '/^0*(\d{4,12})(t[2-9]?)?$/i', $v, $m ) ) {
			$m[2] ??= '';
			return $m[1] . strtoupper( $m[2] );
		}
		return null;
	}

	/** @return array{0:string, 1:string}|null Base y sufijo («T», «T2»…, o '') de un usuario ya normalizado. */
	public static function partes( string $login ): ?array {
		return preg_match( self::PATRON_LOGIN, $login, $m ) ? array( $m[1], $m[2] ?? '' ) : null;
	}

	/**
	 * Usuario existente al que corresponde lo escrito. Se prueba tal cual; si no hay
	 * ninguno, con el otro número de esa persona (el nº local si se escribió el de
	 * Scouts de España y al revés). Si no se encuentra nada, lo escrito sin cambios.
	 */
	public static function resolver( string $login ): string {
		$partes = self::partes( $login );
		if ( ! $partes || username_exists( $login ) ) {
			return $login;
		}
		list( $base, $sufijo ) = $partes;
		// v0.56: el mismo nº local con y sin el 0 inicial de la provincia (076840390 / 76840390) es la misma persona.
		foreach ( array_merge( array( $base ), NumeroSocio::otras_formas( $base ) ) as $forma ) {
			foreach ( array_unique( array_filter( array( $forma === $base ? '' : $forma, SociosTable::asde_de( $forma ), SociosTable::local_de( $forma ) ) ) ) as $otro ) {
				if ( username_exists( $otro . $sufijo ) ) {
					return $otro . $sufijo;
				}
			}
		}
		return $login;
	}
}
