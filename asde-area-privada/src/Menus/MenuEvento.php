<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio as Eventos;

defined( 'ABSPATH' ) || exit;

/**
 * El menú de un evento (doc 49, B2): qué se come cada día, la lista de la compra y lo que cuesta.
 *
 * Lo edita quien gestiona el evento. No toca datos de personas: los comensales son un nº (los inscritos activos y el equipo, o el
 * que se ponga a mano). Los alérgenos que se enseñan son los de las recetas, no los de los asistentes (eso es la fase B3).
 */
final class MenuEvento {

	/** Días como mucho de un menú (un campamento largo). */
	public const MAX_DIAS = 45;

	/** Partida del presupuesto que se propone con el coste del menú. */
	public const CONCEPTO_PARTIDA = 'Alimentación (menú del evento)';

	public static function puede_editar( array $e, Ambito $a ): bool {
		return Eventos::en_gestion( $e, $a ) && null === $e['cerrado_en'];
	}

	public static function puede_ver( array $e, Ambito $a ): bool {
		return Eventos::en_gestion( $e, $a );
	}

	/** @return string[] Los días del evento (Y-m-d), del inicio al fin, hasta MAX_DIAS. */
	public static function dias( array $e ): array {
		$ini = substr( (string) $e['inicio'], 0, 10 );
		$fin = substr( (string) ( $e['fin'] ?: $e['inicio'] ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ini ) || $fin < $ini ) {
			return array();
		}
		$dias = array();
		$t    = strtotime( $ini . ' 12:00 UTC' );
		while ( gmdate( 'Y-m-d', $t ) <= $fin && count( $dias ) < self::MAX_DIAS ) {
			$dias[] = gmdate( 'Y-m-d', $t );
			$t     += DAY_IN_SECONDS;
		}
		return $dias;
	}

	/** Comensales por defecto: los inscritos activos y el equipo del evento. */
	public static function comensales( array $e ): int {
		$inscritos = count( array_filter( InscripcionesTable::participantes( $e['id'] ), static fn( $f ) => in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) );
		return $inscritos + count( InscripcionesTable::equipo( $e['id'] ) );
	}

	/** @return true|int|\WP_Error Id del plato. */
	public static function anadir( array $e, Ambito $a, string $dia, string $comida, int $receta_id, string $comensales = '' ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'No puedes cambiar el menú de este evento.' ) );
		}
		if ( ! in_array( $dia, self::dias( $e ), true ) || ! isset( MenuTable::COMIDAS[ $comida ] ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Elige un día del evento y una comida.' ) );
		}
		$receta = RecetasTable::obtener( $receta_id );
		if ( ! $receta || ! $receta['activa'] ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Elige una receta del recetario.' ) );
		}
		$com = '' === trim( $comensales ) ? null : (int) $comensales;
		if ( null !== $com && ( $com < 1 || $com > 5000 ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Los comensales tienen que estar entre 1 y 5000.' ) );
		}
		if ( MenuTable::contar( $e['id'] ) >= MenuTable::MAX ) {
			return new \WP_Error( 'asde_menus', asde_t( 'El menú ya tiene demasiados platos.' ) );
		}
		$id = MenuTable::anadir( $e['id'], $dia, $comida, $receta_id, $com );
		Logger::log( 'menu_comida_anadida', 'evento', (string) $e['id'], array( 'comida' => $comida ) );
		return $id;
	}

	/** @return true|\WP_Error */
	public static function quitar( array $e, Ambito $a, int $id ) {
		if ( ! self::puede_editar( $e, $a ) || ! MenuTable::quitar( $id, $e['id'] ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'No puedes cambiar el menú de este evento.' ) );
		}
		AvisosTable::borrar_plato( $id ); // Sus confirmaciones ya no valen.
		Logger::log( 'menu_comida_quitada', 'evento', (string) $e['id'] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cambiar_comensales( array $e, Ambito $a, int $id, string $comensales ) {
		$com = '' === trim( $comensales ) ? null : (int) $comensales;
		if ( ! self::puede_editar( $e, $a ) || ! MenuTable::obtener( $id ) || ( null !== $com && ( $com < 1 || $com > 5000 ) ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Los comensales tienen que estar entre 1 y 5000.' ) );
		}
		MenuTable::comensales( $id, $e['id'], $com );
		return true;
	}

	/**
	 * Los platos del menú con su receta, sus líneas, los comensales que cuentan y las cantidades escaladas.
	 *
	 * @return array[] Cada uno: plato (fila), receta, lineas (con 'base' y 'familia'), comensales.
	 */
	public static function platos( array $e, string $dia = '' ): array {
		$por_defecto = self::comensales( $e );
		$out         = array();
		foreach ( MenuTable::de_evento( $e['id'] ) as $p ) {
			if ( '' !== $dia && $p['dia'] !== $dia ) {
				continue;
			}
			$receta = RecetasTable::obtener( $p['receta_id'] );
			if ( ! $receta ) {
				continue;
			}
			$com   = $p['comensales'] ?? max( 1, $por_defecto );
			$lineas = RecetasTable::lineas( $receta['id'] );
			$out[] = array(
				'plato'      => $p,
				'receta'     => $receta,
				'lineas'     => $lineas,
				'comensales' => $com,
				'alergenos'  => Recetario::alergenos( $lineas ),
			);
		}
		return $out;
	}

	/**
	 * La lista de la compra: lo escalado de cada ingrediente, sumado por ingrediente y por familia de unidad (g con kg, ml con l; las
	 * unidades, cucharadas y pizcas no se convierten ni se mezclan). Con coste, si el ingrediente lo tiene.
	 *
	 * @return array{filas:array[], total:float, sin_coste:string[]} filas: nombre, cantidad, unidad, texto, coste (null si no se sabe)
	 */
	public static function compra( array $e, string $dia = '' ): array {
		$acum = array();
		foreach ( self::platos( $e, $dia ) as $p ) {
			foreach ( $p['lineas'] as $l ) {
				$i = $l['ingrediente'];
				if ( ! $i ) {
					continue;
				}
				list( $familia, $base ) = Escalado::a_base( Escalado::escalar( $l['cantidad'], $p['receta']['porciones'], $p['comensales'] ), $l['unidad'] );
				$k = $i['id'] . '|' . $familia;
				if ( ! isset( $acum[ $k ] ) ) {
					$acum[ $k ] = array( 'ingrediente' => $i, 'familia' => $familia, 'base' => 0.0 );
				}
				$acum[ $k ]['base'] += $base;
			}
		}
		$filas     = array();
		$total     = 0.0;
		$sin_coste = array();
		foreach ( $acum as $a ) {
			$i                  = $a['ingrediente'];
			list( $c, $unidad ) = Escalado::legible( $a['base'], $a['familia'] );
			$nombre             = IngredientesTable::COMUN === $i['grupo'] ? asde_t( $i['nombre'] ) : $i['nombre'];
			$coste              = null;
			// El coste es por la unidad habitual del ingrediente: solo se calcula si es de la misma familia.
			$u_ing = Escalado::UNIDADES[ $i['unidad'] ] ?? null;
			if ( null !== $i['coste'] && $u_ing && $u_ing[1] === $a['familia'] ) {
				$coste  = round( $a['base'] / $u_ing[2] * $i['coste'], 2 );
				$total += $coste;
			} else {
				$sin_coste[] = $nombre;
			}
			$filas[] = array(
				'nombre'   => $nombre,
				'cantidad' => $c,
				'unidad'   => $unidad,
				'texto'    => Escalado::texto( $c, $unidad ),
				'coste'    => $coste,
			);
		}
		usort( $filas, static fn( $x, $y ) => strcoll( remove_accents( $x['nombre'] ), remove_accents( $y['nombre'] ) ) );
		return array(
			'filas'     => $filas,
			'total'     => round( $total, 2 ),
			'sin_coste' => array_values( array_unique( $sin_coste ) ),
		);
	}

	/** Coste del menú por día (solo lo que tiene coste). @return array<string, float> día => euros */
	public static function coste_por_dia( array $e ): array {
		$out = array();
		foreach ( self::dias( $e ) as $d ) {
			$c = self::compra( $e, $d );
			if ( $c['filas'] ) {
				$out[ $d ] = $c['total'];
			}
		}
		return $out;
	}

	/**
	 * Propone el coste del menú como partida «Alimentación» del presupuesto (nunca cambia nada sola: lo pide una persona).
	 *
	 * @return int|\WP_Error Id de la partida.
	 */
	public static function proponer_partida( array $e, Ambito $a ) {
		$compra = self::compra( $e );
		if ( $compra['total'] <= 0 ) {
			return new \WP_Error( 'asde_menus', asde_t( 'El menú todavía no tiene coste: anota el coste de los ingredientes y añade platos.' ) );
		}
		foreach ( PartidasTable::previstas( $e['id'] ) as $p ) {
			if ( asde_t( self::CONCEPTO_PARTIDA ) === $p['concepto'] || self::CONCEPTO_PARTIDA === $p['concepto'] ) {
				return new \WP_Error( 'asde_menus', asde_t( 'Ya hay una partida del menú en el presupuesto: quítala antes de proponer otra.' ) );
			}
		}
		return Eventos::anadir_partida( $e, $a, PartidasTable::GASTO, asde_t( self::CONCEPTO_PARTIDA ), 'total', '', $compra['total'] );
	}

	/** CSV de la lista de la compra (con la marca de orden de bytes de Excel). */
	public static function csv( array $e, string $dia = '' ): string {
		$c    = self::compra( $e, $dia );
		$filas = array( array( asde_t( 'Ingrediente' ), asde_t( 'Cantidad' ), asde_t( 'Unidad' ), asde_t( 'Coste' ) ) );
		foreach ( $c['filas'] as $f ) {
			$filas[] = array( $f['nombre'], number_format( $f['cantidad'], 2, ',', '' ), Escalado::nombre( $f['unidad'] ), null === $f['coste'] ? '' : number_format( $f['coste'], 2, ',', '' ) );
		}
		$csv = '';
		foreach ( $filas as $f ) {
			$csv .= implode( ';', array_map( static fn( $x ) => '"' . str_replace( '"', '""', \AsdeBalears\AreaPrivada\Informes\Generador::celda_segura( (string) $x ) ) . '"', $f ) ) . "\r\n";
		}
		return "\xEF\xBB\xBF" . $csv;
	}
}
