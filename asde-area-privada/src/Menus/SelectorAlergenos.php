<?php
namespace AsdeBalears\AreaPrivada\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * El selector de alérgenos de la ficha de salud (v0.41, doc 49, B3) y el intérprete de lo que se guardó.
 *
 * En el CRM el campo sigue siendo un texto; la ficha lo enseña como una ventana con pestañas y casillas (y alérgenos
 * escritos a mano) y guarda el texto separado por comas, con las etiquetas en castellano. Para cruzarlo con un menú, interpretar()
 * lo pasa a alérgenos del catálogo (Alergenos); lo que no reconoce se devuelve para revisarlo a mano.
 */
final class SelectorAlergenos {

	/** Los campos de la ficha que se eligen con el selector, y la pestaña con la que se abre cada uno. */
	public const CAMPOS = array(
		'alimentacion_alerg_alimentos_c' => 'alergenos',
		'alimentacion_intol_alimentos_c' => 'intolerancias',
	);

	/** Longitud máxima de un alérgeno escrito a mano. */
	public const MAX_PROPIO = 60;

	/**
	 * Lo que se puede marcar, por pestañas: etiqueta (en castellano: es lo que se guarda en el CRM) => alérgenos del catálogo que
	 * implica (vacío: no es uno de los 14, solo se anota).
	 */
	public const PESTANAS = array(
		'alergenos'     => array(
			'titulo' => 'Los 14 alérgenos',
			'items'  => array(
				'Gluten'            => array( 'gluten' ),
				'Crustáceos'        => array( 'crustaceos' ),
				'Huevos'            => array( 'huevos' ),
				'Pescado'           => array( 'pescado' ),
				'Cacahuetes'        => array( 'cacahuetes' ),
				'Soja'              => array( 'soja' ),
				'Leche y lácteos'   => array( 'lacteos' ),
				'Frutos de cáscara' => array( 'frutos_cascara' ),
				'Apio'              => array( 'apio' ),
				'Mostaza'           => array( 'mostaza' ),
				'Sésamo'            => array( 'sesamo' ),
				'Sulfitos'          => array( 'sulfitos' ),
				'Altramuces'        => array( 'altramuces' ),
				'Moluscos'          => array( 'moluscos' ),
			),
		),
		'alimentos'     => array(
			'titulo' => 'Alimentos',
			'items'  => array(
				'Marisco'           => array( 'crustaceos', 'moluscos' ),
				'Gambas'            => array( 'crustaceos' ),
				'Mejillones'        => array( 'moluscos' ),
				'Calamar y pulpo'   => array( 'moluscos' ),
				'Pescado azul'      => array( 'pescado' ),
				'Pescado blanco'    => array( 'pescado' ),
				'Trigo'             => array( 'gluten' ),
				'Cebada'            => array( 'gluten' ),
				'Centeno'           => array( 'gluten' ),
				'Avena'             => array( 'gluten' ),
				'Frutos secos'      => array( 'frutos_cascara' ),
				'Almendra'          => array( 'frutos_cascara' ),
				'Nuez'              => array( 'frutos_cascara' ),
				'Avellana'          => array( 'frutos_cascara' ),
				'Anacardo'          => array( 'frutos_cascara' ),
				'Pistacho'          => array( 'frutos_cascara' ),
				'Legumbres'         => array(),
				'Garbanzos'         => array(),
				'Lentejas'          => array(),
				'Guisantes'         => array(),
				'Kiwi'              => array(),
				'Melocotón'         => array(),
				'Fresa'             => array(),
				'Plátano'           => array(),
				'Aguacate'          => array(),
				'Cítricos'          => array(),
				'Tomate'            => array(),
				'Maíz'              => array(),
				'Chocolate y cacao' => array(),
				'Miel'              => array(),
				'Carne de cerdo'    => array(),
				'Carne de ternera'  => array(),
				'Pollo'             => array(),
			),
		),
		'intolerancias' => array(
			'titulo' => 'Intolerancias',
			'items'  => array(
				'Lactosa'                => array( 'lacteos' ),
				'Celiaquía'              => array( 'gluten' ),
				'Sensibilidad al gluten' => array( 'gluten' ),
				'Fructosa'               => array(),
				'Sorbitol'               => array(),
				'Histamina'              => array(),
				'Cafeína'                => array(),
			),
		),
	);

	/** Palabras (en castellano y catalán) que, escritas a mano en la ficha, indican un alérgeno. */
	private const PALABRAS = array(
		'gluten'         => array( 'gluten', 'trigo', 'blat', 'cebada', 'ordi', 'centeno', 'segol', 'avena', 'civada', 'espelta', 'celiac', 'harina', 'farina' ),
		'crustaceos'     => array( 'crustace', 'marisco', 'marisc', 'gamba', 'langostino', 'llagostino', 'cangrejo', 'cranc', 'bogavante', 'escamarlan', 'cigala' ),
		'huevos'         => array( 'huevo', 'ou', 'ous' ),
		'pescado'        => array( 'pescado', 'peix', 'merluza', 'lluc', 'atun', 'tonyina', 'salmon', 'bacalao', 'bacalla', 'sardina', 'anchoa' ),
		'cacahuetes'     => array( 'cacahuete', 'cacauet', 'mani' ),
		'soja'           => array( 'soja' ),
		'lacteos'        => array( 'lacteo', 'lactic', 'leche', 'llet', 'lactosa', 'queso', 'formatge', 'yogur', 'iogurt', 'nata', 'mantequilla', 'mantega' ),
		'frutos_cascara' => array( 'fruto seco', 'frutos secos', 'fruit sec', 'fruits sec', 'frutos de cascara', 'nuez', 'nueces', 'nou', 'nous', 'almendra', 'ametlla', 'avellana', 'anacardo', 'anacard', 'pistacho', 'pistatx', 'pacana' ),
		'apio'           => array( 'apio', 'api' ),
		'mostaza'        => array( 'mostaza', 'mostassa' ),
		'sesamo'         => array( 'sesamo', 'sesam' ),
		'sulfitos'       => array( 'sulfito', 'sulfit' ),
		'altramuces'     => array( 'altramuz', 'altramuces', 'tramus', 'lupino' ),
		'moluscos'       => array( 'molusco', 'mollusc', 'marisco', 'marisc', 'mejillon', 'musclo', 'calamar', 'pulpo', 'pop', 'almeja', 'sepia', 'caracol' ),
	);

	/** Texto para comparar: minúsculas, sin acentos y solo letras y cifras separadas por un espacio. */
	public static function clave( string $texto ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $texto ) ) ) );
	}

	/** @return string[] Las partes de lo escrito (separadas por coma, punto y coma, barra o salto de línea), sin vacías ni repetidas. */
	public static function partes( string $texto ): array {
		$out   = array();
		$vistas = array();
		foreach ( (array) preg_split( '/[,;\/\n\r]+/u', $texto ) as $p ) {
			$p = trim( (string) $p );
			$k = self::clave( $p );
			if ( '' !== $k && ! isset( $vistas[ $k ] ) ) {
				$vistas[ $k ] = true;
				$out[]        = $p;
			}
		}
		return $out;
	}

	/**
	 * Lo que escribió o eligió una persona, pasado a alérgenos del catálogo. Primero busca la etiqueta exacta del selector y, si no, las
	 * palabras de cada alérgeno. Lo que no reconoce no se descarta: se devuelve para que alguien lo mire a mano.
	 *
	 * @return array{codigos:string[], sin_reconocer:string[]}
	 */
	public static function interpretar( string $texto ): array {
		$exactas = array();
		foreach ( self::PESTANAS as $p ) {
			foreach ( $p['items'] as $etiqueta => $codigos ) {
				$exactas[ self::clave( $etiqueta ) ] = $codigos;
			}
		}
		$codigos = array();
		$dudosas = array();
		foreach ( self::partes( $texto ) as $parte ) {
			$k = self::clave( $parte );
			if ( isset( $exactas[ $k ] ) ) {
				$codigos[] = $exactas[ $k ];
				continue;
			}
			$hallados = array();
			foreach ( self::PALABRAS as $codigo => $palabras ) {
				foreach ( $palabras as $w ) {
					$w = self::clave( $w );
					// Las palabras cortas (ou, nou, api, pop, mani) solo cuentan enteras; el resto, como raíz.
					$coincide = strlen( $w ) <= 4 ? (bool) preg_match( '/(^| )' . preg_quote( $w, '/' ) . '( |$)/', $k ) : str_contains( $k, $w );
					if ( $coincide ) {
						$hallados[] = $codigo;
						break;
					}
				}
			}
			if ( $hallados ) {
				$codigos[] = $hallados;
			} else {
				$dudosas[] = $parte;
			}
		}
		return array(
			'codigos'       => Alergenos::unir( $codigos ),
			'sin_reconocer' => $dudosas,
		);
	}

	/** Un alérgeno escrito a mano, limpio: sin separadores ni saltos y con un máximo de longitud. */
	public static function limpiar_propio( string $texto ): string {
		$t = trim( (string) preg_replace( '/[,;\/\r\n]+/u', ' ', wp_strip_all_tags( $texto ) ) );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $t ) ), 0, self::MAX_PROPIO );
	}

	/** Los textos de la ventana (se traducen al pintarla). @return string[] */
	public static function textos(): array {
		$t = array();
		foreach ( self::PESTANAS as $p ) {
			$t[] = $p['titulo'];
			$t   = array_merge( $t, array_keys( $p['items'] ) );
		}
		return array_values( array_unique( $t ) );
	}
}
