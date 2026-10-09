<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Organización federada de Scouts de España que usa el sistema (opción
 * `asde_ap_organizacion`). Es todo lo que antes era propio de Scouts de Balears:
 *
 * - el NOMBRE, que sale en los textos (pantalla de acceso, coste federativo…);
 * - el CÓDIGO de la organización con el que empieza el nº de socio/a local
 *   (código + nº de grupo + 4 cifras; en Balears, 7 → 76840123);
 * - el COSTE FEDERATIVO por defecto: cuota asociativa, seguro (promedio o tabla
 *   de primas por edad) y otros conceptos. Es con lo que empieza cada ronda en
 *   la Tesorería de los grupos, que lo pueden ajustar.
 *
 * Una instalación anterior a la v0.9 (Scouts de Balears) se migra sola con sus
 * valores de siempre. En un WordPress nuevo hay que elegir la organización.
 */
final class Organizacion {

	public const OPTION = 'asde_ap_organizacion';

	/**
	 * Organizaciones federadas según scout.es (quienes-somos/organizaciones-federadas,
	 * consultado el 2026-09-27): clave => [nombre, territorio, web].
	 */
	public const FEDERADAS = array(
		'andalucia'          => array( 'Scouts de Andalucía', 'Andalucía', 'https://scoutsdeandalucia.org' ),
		'aragon'             => array( 'Scouts de Aragón', 'Aragón', 'https://scoutsdearagon.org' ),
		'asturias'           => array( 'Exploradores del Principado de Asturias', 'Asturias', 'https://asdepa.org' ),
		'balears'            => array( 'Scouts de Balears', 'Baleares', 'https://baleares.scout.es' ),
		'canarias'           => array( 'Scouts-Exploradores de Canarias', 'Canarias', 'https://scoutsdecanarias.es' ),
		'cantabria'          => array( 'Scouts de Cantabria', 'Cantabria', 'https://asdescoutsdecantabria.org' ),
		'castilla-leon'      => array( 'Exploradores de Castilla y León', 'Castilla y León', 'https://scoutcyl.es' ),
		'castilla-la-mancha' => array( 'Scouts de Castilla-La Mancha', 'Castilla-La Mancha', 'https://scoutsclm.org' ),
		'ceuta'              => array( 'Scouts de Ceuta', 'Ceuta', 'https://scoutsdeceuta.scout.es' ),
		'euskadi'            => array( 'Euskadiko Esploratzaileak', 'Euskadi', 'https://esploratzaileak.scout.es' ),
		'extremadura'        => array( 'Scouts de Extremadura', 'Extremadura', 'https://asdeex.org' ),
		'galicia'            => array( 'Scouts de Galicia', 'Galicia', 'https://scoutsdegalicia.org' ),
		'la-rioja'           => array( 'Scouts de La Rioja', 'La Rioja', 'https://scoutsdelarioja.es' ),
		'madrid'             => array( 'Exploradores de Madrid', 'Madrid', 'https://exploradoresdemadrid.org' ),
		'melilla'            => array( 'Scouts de Melilla', 'Melilla', '' ),
		'murcia'             => array( 'Exploradores de Murcia', 'Murcia', 'https://exmu.es' ),
		'valencia'           => array( 'Scouts Valencians', 'Comunidad Valenciana', 'https://scoutsvalencians.org' ),
	);

	/** A quién se aplica cada concepto del coste federativo. */
	public const APLICA = array(
		'asociado' => 'Cada asociado',
		'educando' => 'Cada educando (Castores a Rovers)',
		'adulto'   => 'Cada adulto (scouters y voluntariado)',
		'grupo'    => 'Una vez por grupo',
	);

	/** Máximo de «otros conceptos» (para que el formulario no crezca sin fin). */
	public const MAX_CONCEPTOS = 20;

	private static ?array $cache = null;

	// ---------------------------------------------------------------------
	// Lectura
	// ---------------------------------------------------------------------

	/** Usuario de acceso (v0.14): el nº de socio/a de Scouts de España o el nº local/territorial de siempre. */
	public const IDENTIFICADOR_ASDE  = 'asde';
	public const IDENTIFICADOR_LOCAL = 'local';

	/** @return array{organizacion:string, codigo:string, identificador:string, federacion:array} */
	public static function datos(): array {
		if ( null === self::$cache ) {
			$v           = get_option( self::OPTION, array() );
			$v           = is_array( $v ) ? $v : array();
			$ident       = (string) ( $v['identificador'] ?? '' );
			self::$cache = array(
				'organizacion' => isset( self::FEDERADAS[ (string) ( $v['organizacion'] ?? '' ) ] ) ? (string) $v['organizacion'] : '',
				'codigo'       => self::codigo_valido( (string) ( $v['codigo'] ?? '' ) ) ? (string) $v['codigo'] : '',
				// Las instalaciones anteriores a la v0.14 no lo tienen guardado: siguen con el nº local hasta que se elija otra cosa.
				'identificador' => in_array( $ident, array( self::IDENTIFICADOR_ASDE, self::IDENTIFICADOR_LOCAL ), true ) ? $ident : self::IDENTIFICADOR_LOCAL,
				'federacion'   => self::sanear_federacion( (array) ( $v['federacion'] ?? array() ) ),
			);
		}
		return self::$cache;
	}

	/** Qué número es el usuario de acceso: IDENTIFICADOR_ASDE o IDENTIFICADOR_LOCAL. */
	public static function identificador(): string {
		return self::datos()['identificador'];
	}

	public static function clave(): string {
		return self::datos()['organizacion'];
	}

	/** ¿Se ha elegido organización y código? Sin ellos no se pueden generar números de socio. */
	public static function configurada(): bool {
		return '' !== self::clave() && '' !== self::codigo();
	}

	/** Nombre de la organización federada (el que sale en los textos). */
	public static function nombre(): string {
		return self::FEDERADAS[ self::clave() ][0] ?? '';
	}

	public static function territorio(): string {
		return self::FEDERADAS[ self::clave() ][1] ?? '';
	}

	public static function web(): string {
		return self::FEDERADAS[ self::clave() ][2] ?? '';
	}

	/**
	 * Dónde está publicado el manual de uso de cada organización, si se sabe (v0.26). Es el
	 * valor por defecto de manual(): la administración lo puede cambiar o quitar.
	 */
	public const MANUALES_PUBLICADOS = array(
		'balears' => 'https://baleares.scout.es/manual/',
	);

	/**
	 * Carpeta del manual de uso con capturas publicado, acabada en «/», o '' si no hay (v0.26).
	 * Desde la v0.28 la ayuda del área privada es solo texto y viene en el plugin: este manual (el
	 * zip «manual-de-uso», se sube a mano) es una ayuda extra, enlazada al final de la ayuda.
	 */
	public static function manual(): string {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return array_key_exists( 'manual', $v ) ? (string) $v['manual'] : ( self::MANUALES_PUBLICADOS[ self::clave() ] ?? '' );
	}

	/**
	 * Guarda la dirección del manual publicado: la de la portada (…/manual/index.html) o la de su
	 * carpeta; se guarda la carpeta. Vacía: sin enlaces al manual ni capturas en la ayuda.
	 *
	 * @return true|\WP_Error
	 */
	public static function guardar_manual( string $url ) {
		$url = trim( $url );
		if ( '' !== $url ) {
			$url = esc_url_raw( $url, array( 'https', 'http' ) );
			// Solo el formato (wp_http_validate_url preguntaría al DNS: sin red, o con un dominio aún sin publicar, fallaría).
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
				return new \WP_Error( 'asde_organizacion', asde_t( 'La dirección del manual no es válida: escribe una dirección web completa (https://…).' ) );
			}
			$url = preg_replace( '~/[^/]*\.html?$~i', '/', strtok( $url, '?#' ) );
			$url = rtrim( $url, '/' ) . '/';
		}
		$v           = get_option( self::OPTION, array() );
		$v           = is_array( $v ) ? $v : array();
		$v['manual'] = $url;
		update_option( self::OPTION, $v, false );
		self::$cache = null;
		return true;
	}

	/** Código de la organización en el nº de socio/a local ('' si no está configurado). */
	public static function codigo(): string {
		return self::datos()['codigo'];
	}

	/** Coste federativo por defecto de la organización (con lo que empieza cada ronda). */
	public static function federacion(): array {
		return self::datos()['federacion'];
	}

	/** Código válido: de 1 a 3 cifras, sin empezar por 0 (el 0 inicial se quita al normalizar). */
	public static function codigo_valido( string $codigo ): bool {
		return (bool) preg_match( '/^[1-9]\d{0,2}$/', $codigo );
	}

	// ---------------------------------------------------------------------
	// Escritura
	// ---------------------------------------------------------------------

	/**
	 * @return true|\WP_Error
	 */
	public static function guardar( string $organizacion, string $codigo, array $federacion, ?string $identificador = null ) {
		if ( ! isset( self::FEDERADAS[ $organizacion ] ) ) {
			return new \WP_Error( 'asde_organizacion', asde_t( 'Elige la organización federada de la lista.' ) );
		}
		$codigo = trim( $codigo );
		if ( ! self::codigo_valido( $codigo ) ) {
			return new \WP_Error( 'asde_organizacion', asde_t( 'El código de la organización tiene que ser un número de 1 a 3 cifras que no empiece por 0.' ) );
		}
		if ( null !== $identificador && ! in_array( $identificador, array( self::IDENTIFICADOR_ASDE, self::IDENTIFICADOR_LOCAL ), true ) ) {
			return new \WP_Error( 'asde_organizacion', asde_t( 'Elige qué número es el usuario de acceso.' ) );
		}
		$antes = get_option( self::OPTION, array() );
		update_option(
			self::OPTION,
			array(
				'organizacion'  => $organizacion,
				'codigo'        => $codigo,
				// Sin indicarlo, se conserva el que hubiera (o el nº local en una instalación anterior).
				'identificador' => $identificador ?? self::identificador(),
				'federacion'    => self::sanear_federacion( $federacion ),
			) + ( is_array( $antes ) ? array_intersect_key( $antes, array( 'manual' => true ) ) : array() ), // Lo que se guarda aparte (el manual) se conserva.
			false
		);
		self::$cache = null;
		return true;
	}

	/** Tras cambiar la opción desde fuera (pruebas). */
	public static function olvidar(): void {
		self::$cache = null;
	}

	/**
	 * Instalaciones anteriores a la v0.9 (hechas para Scouts de Balears): se
	 * guardan sus valores de siempre para que nada cambie. En un WordPress nuevo
	 * (sin grupos) no se elige nada: lo elige quien lo instala.
	 */
	public static function migrar(): void {
		if ( false !== get_option( self::OPTION, false ) || ! get_option( 'asde_ap_grupos' ) ) {
			return;
		}
		update_option(
			self::OPTION,
			array(
				'organizacion' => 'balears',
				'codigo'       => '7',
				'identificador' => self::IDENTIFICADOR_LOCAL, // Sus cuentas ya usan el nº local; el cambio se hace desde la configuración.
				'federacion'   => array(
					'cuota_asociativa' => 17.0,
					'seguro_promedio'  => 3.0,
					'edad_corte'       => 14,
					'primas'           => array(
						'menores'  => 1.92,
						'mayores'  => 2.44,
						'scouters' => 6.06,
					),
					'conceptos'        => array(),
				),
			),
			false
		);
		self::$cache = null;
	}

	// ---------------------------------------------------------------------
	// Coste federativo
	// ---------------------------------------------------------------------

	/**
	 * Deja el coste federativo en el formato de siempre (también lo usa la
	 * Tesorería de cada ronda).
	 *
	 * @return array{cuota_asociativa:float, seguro_promedio:float, edad_corte:int, primas:array{menores:float, mayores:float, scouters:float}, conceptos:array<int, array{nombre:string, importe:float, aplica:string}>}
	 */
	public static function sanear_federacion( array $f ): array {
		$euros  = static fn( $v, bool $negativo = false ) => round( $negativo ? (float) str_replace( ',', '.', (string) $v ) : max( 0, (float) str_replace( ',', '.', (string) $v ) ), 2 );
		$primas = (array) ( $f['primas'] ?? array() );
		// Nombres de antes de la v0.9: menores14 / mayores14.
		$primas += array(
			'menores' => $primas['menores14'] ?? 0,
			'mayores' => $primas['mayores14'] ?? 0,
		);
		$conceptos = array();
		foreach ( (array) ( $f['conceptos'] ?? array() ) as $c ) {
			$nombre = sanitize_text_field( (string) ( $c['nombre'] ?? '' ) );
			if ( '' === $nombre || count( $conceptos ) >= self::MAX_CONCEPTOS ) {
				continue;
			}
			$aplica      = (string) ( $c['aplica'] ?? '' );
			$conceptos[] = array(
				'nombre'  => mb_substr( $nombre, 0, 80 ),
				'importe' => max( -99999, min( 99999, $euros( $c['importe'] ?? 0, true ) ) ), // Negativo: descuento.
				'aplica'  => isset( self::APLICA[ $aplica ] ) ? $aplica : 'asociado',
			);
		}
		return array(
			'cuota_asociativa' => $euros( $f['cuota_asociativa'] ?? 0 ),
			'seguro_promedio'  => $euros( $f['seguro_promedio'] ?? 0 ),
			'edad_corte'       => max( 1, min( 30, (int) ( $f['edad_corte'] ?? 14 ) ) ),
			'primas'           => array(
				'menores'  => $euros( $primas['menores'] ),
				'mayores'  => $euros( $primas['mayores'] ),
				'scouters' => $euros( $primas['scouters'] ?? 0 ),
			),
			'conceptos'        => $conceptos,
		);
	}

	/** Categorías del seguro según la edad de corte (p. ej. «Menores de 14 años»). */
	public static function categorias_seguro( int $edad_corte ): array {
		return array(
			'menores'  => sprintf( asde_t( 'Menores de %d años' ), $edad_corte ),
			'mayores'  => sprintf( asde_t( 'Educandos de %d años o más' ), $edad_corte ),
			'scouters' => asde_t( 'Adultos (scouters y voluntariado)' ),
		);
	}
}
