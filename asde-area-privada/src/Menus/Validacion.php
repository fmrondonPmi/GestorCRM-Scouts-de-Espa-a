<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Valida el menú de un evento contra la alimentación de quienes van (doc 49, B3). Es la parte sensible.
 *
 * **Regla de oro (doc 08): la salud no se guarda.** Todo se calcula aquí, en vivo, cada vez que se pide: se leen del CRM (en una sola
 * lectura) los campos de alimentación de los asistentes —inscritos activos y equipo—, se pasan a alérgenos del catálogo
 * (SelectorAlergenos::interpretar) y se cruzan con los de cada plato. Nada se cachea ni se guarda.
 *
 * Quién ve qué: con $con_nombres = false (quien edita el menú) solo salen alérgenos y nº de personas; con nombres (quien ya ve la
 * salud del evento y la hoja de cocina) también quiénes. El resultado nunca incluye el texto de la ficha ni diagnósticos, salvo el
 * texto que no se pudo interpretar, y solo con nombres, para revisarlo a mano.
 *
 * Niveles: conflicto (un alérgeno del plato afecta a alguien) > riesgo (solo «puede contener», o una dieta, o un texto sin entender)
 * > datos incompletos (fichas sin revisar o sin rellenar: no se puede asegurar nada) > sin conflictos.
 */
final class Validacion {

	public const CONFLICTO  = 'conflicto';
	public const RIESGO     = 'riesgo';
	public const INCOMPLETO = 'incompleto';
	public const SIN        = 'sin_conflictos';

	/** Campos del CRM que se leen (todos de alimentación). */
	public const CAMPOS = array(
		'alimentacion_alergia_c',
		'alimentacion_alerg_alimentos_c',
		'alimentacion_alerg_obs_c',
		'alergias_c',
		'alergias_obs_c',
		'alimentacion_intolerancia_c',
		'alimentacion_intol_alimentos_c',
		'alimentacion_intol_obs_c',
		'alergias_alimentos_c',
		'alimentacion_regimen_c',
		'alimentacion_regimen_tipo_y__c',
	);

	/** ¿Ve quiénes están afectados? Quien gestiona el evento y ya ve la salud de las fichas (la misma regla del listado de salud). */
	public static function puede_ver_quienes( array $e, \AsdeBalears\AreaPrivada\Auth\Ambito $a ): bool {
		return MenuEvento::puede_ver( $e, $a ) && current_user_can( 'asde_view_health' );
	}

	/**
	 * Confirma un aviso («se prepara una ración sin gluten»): se guarda solo el plato, el alérgeno, quién y la alternativa (de una lista
	 * cerrada). No hace falta ver la salud de nadie para confirmar.
	 *
	 * @return true|\WP_Error
	 */
	public static function confirmar( array $e, \AsdeBalears\AreaPrivada\Auth\Ambito $a, int $plato_id, string $alergeno, string $alternativa ) {
		if ( ! MenuEvento::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'No puedes cambiar el menú de este evento.' ) );
		}
		if ( ! isset( AvisosTable::ALTERNATIVAS[ $alternativa ] ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Elige qué se hace con ese plato.' ) );
		}
		foreach ( MenuEvento::platos( $e ) as $p ) {
			if ( $p['plato']['id'] === $plato_id && in_array( $alergeno, array_merge( $p['alergenos']['contiene'], $p['alergenos']['trazas'] ), true ) ) {
				AvisosTable::confirmar( $e['id'], $plato_id, $alergeno, $alternativa );
				\AsdeBalears\AreaPrivada\Audit\Logger::log( 'menu_aviso_confirmado', 'evento', (string) $e['id'], array( 'alergeno' => $alergeno ) );
				return true;
			}
		}
		return new \WP_Error( 'asde_menus', asde_t( 'Ese plato no lleva ese alérgeno.' ) );
	}

	/** @return array<string, array{rol:string, revisada:bool}> Los asistentes: inscritos activos y equipo, por nº. */
	public static function asistentes( array $e ): array {
		$out = array();
		foreach ( InscripcionesTable::participantes( $e['id'] ) as $f ) {
			if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
				$out[ $f['socio'] ] = array( 'rol' => 'participante', 'revisada' => ! empty( $f['salud_revisada'] ) );
			}
		}
		foreach ( InscripcionesTable::equipo( $e['id'] ) as $f ) {
			$out[ $f['socio'] ] = array( 'rol' => 'equipo', 'revisada' => true );
		}
		return $out;
	}

	private static function si( string $v ): bool {
		return in_array( SelectorAlergenos::clave( $v ), array( 'si', '1', 'true', 'yes', 'y' ), true );
	}

	private static function no( string $v ): bool {
		return in_array( SelectorAlergenos::clave( $v ), array( 'no', '0', 'false' ), true );
	}

	/**
	 * Lo que afecta a una persona, a partir de su ficha.
	 *
	 * @param array<string, string> $c Campos de alimentación de la ficha.
	 * @return array{codigos:string[], dieta:string, completa:bool, revisar:string}
	 *         dieta: '', 'vegana' o 'vegetariana'; completa: ¿hay algo contestado?; revisar: texto que no se entendió (para revisarlo a mano).
	 */
	public static function de_persona( array $c ): array {
		$g = static fn( string $k ): string => trim( (string) ( $c[ $k ] ?? '' ) );
		$alergia = $g( 'alimentacion_alergia_c' );
		$intol   = $g( 'alimentacion_intolerancia_c' );
		$reg     = $g( 'alimentacion_regimen_c' );
		$texto   = implode( ', ', array_filter( array( $g( 'alimentacion_alerg_alimentos_c' ), $g( 'alimentacion_alerg_obs_c' ), $g( 'alergias_c' ), $g( 'alergias_obs_c' ), $g( 'alimentacion_intol_alimentos_c' ), $g( 'alimentacion_intol_obs_c' ), $g( 'alergias_alimentos_c' ) ) ) );
		$contestado = '' !== $alergia || '' !== $intol || '' !== $reg || '' !== $texto;
		$i          = SelectorAlergenos::interpretar( $texto );
		$dieta      = '';
		if ( ! self::no( $reg ) ) {
			$tipo = SelectorAlergenos::clave( $g( 'alimentacion_regimen_tipo_y__c' ) );
			if ( str_contains( $tipo, 'vegan' ) ) {
				$dieta = 'vegana';
			} elseif ( str_contains( $tipo, 'vegetarian' ) ) {
				$dieta = 'vegetariana';
			}
		}
		// «Sí» y un texto que no se entiende → a revisar a mano; «No» sin texto → nada que mirar.
		$revisar = $i['sin_reconocer'] ? implode( ', ', $i['sin_reconocer'] ) : '';
		if ( '' === $revisar && ( self::si( $alergia ) || self::si( $intol ) ) && ! $i['codigos'] ) {
			$revisar = asde_t( '(dice que sí, pero no indica a qué)' );
		}
		return array(
			'codigos'  => $i['codigos'],
			'dieta'    => $dieta,
			'completa' => $contestado,
			'revisar'  => $revisar,
		);
	}

	/**
	 * Valida el menú de un evento.
	 *
	 * @param bool $con_nombres Quién consulta ve a las personas (los nombres solo salen si es true).
	 * @return array{estado:string, nivel:string, momento:string, asistentes:int, platos:array, incompletos:int, revisar:int, nombres_incompletos:string[], textos_revisar:array, afectados:array}
	 *         estado: ok | sin_menu | sin_asistentes | crm (no se pudo leer).
	 */
	public static function evaluar( array $e, bool $con_nombres = false ): array {
		$vacio = array(
			'estado'              => 'ok',
			'nivel'               => self::SIN,
			'momento'             => wp_date( 'd/m/Y H:i' ),
			'asistentes'          => 0,
			'platos'              => array(),
			'incompletos'         => 0,
			'revisar'             => 0,
			'nombres_incompletos' => array(),
			'textos_revisar'      => array(),
			'afectados'           => array(),
		);
		$platos = MenuEvento::platos( $e );
		if ( ! $platos ) {
			return array( 'estado' => 'sin_menu' ) + $vacio;
		}
		$asistentes = self::asistentes( $e );
		$vacio['asistentes'] = count( $asistentes );
		if ( ! $asistentes ) {
			return array( 'estado' => 'sin_asistentes' ) + $vacio;
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return array( 'estado' => 'crm' ) + $vacio;
		}
		try {
			$fichas  = $repo->campos_varias( array_keys( $asistentes ), self::CAMPOS );
			$nombres = $con_nombres ? $repo->obtener_varias( array_keys( $asistentes ) ) : array();
		} catch ( CrmException $ex ) {
			return array( 'estado' => 'crm' ) + $vacio;
		}
		$nombre = static fn( string $n ): string => isset( $nombres[ $n ] ) ? $nombres[ $n ]->nombre_completo() : $n;

		// Qué afecta a cada persona (en memoria, solo durante esta petición).
		$personas    = array();
		$incompletos = array();
		$revisar     = array();
		foreach ( $asistentes as $num => $a ) {
			if ( ! isset( $fichas[ $num ] ) ) {
				$incompletos[] = $num; // No se pudo leer su ficha.
				continue;
			}
			$p = self::de_persona( $fichas[ $num ] );
			if ( ! $p['completa'] || ( 'participante' === $a['rol'] && ! $a['revisada'] ) ) {
				$incompletos[] = $num;
			}
			if ( '' !== $p['revisar'] ) {
				$revisar[ $num ] = $p['revisar'];
			}
			$personas[ $num ] = $p;
		}

		$res = array();
		$afectados = array();
		$hay = array( self::CONFLICTO => false, self::RIESGO => false );
		foreach ( $platos as $pl ) {
			$id   = $pl['plato']['id'];
			$cont = $pl['alergenos']['contiene'];
			$traz = $pl['alergenos']['trazas'];
			$item = array( 'conflictos' => array(), 'riesgos' => array(), 'dieta' => array() );
			foreach ( $personas as $num => $p ) {
				foreach ( array_intersect( $p['codigos'], $cont ) as $cod ) {
					$item['conflictos'][ $cod ][] = $num;
				}
				foreach ( array_intersect( $p['codigos'], array_diff( $traz, $cont ) ) as $cod ) {
					$item['riesgos'][ $cod ][] = $num;
				}
				if ( '' !== $p['dieta'] && $pl['alergenos']['animal'] ) {
					$item['dieta'][ $p['dieta'] ][] = $num;
				}
			}
			$por_cod = $pl['alergenos']['por_alergeno'];
			foreach ( $item['conflictos'] as $cod => $nums ) {
				$hay[ self::CONFLICTO ] = true;
				$item['conflictos'][ $cod ] = array(
					'n'            => count( $nums ),
					'ingredientes' => array_values( array_unique( $por_cod[ $cod ] ?? array() ) ),
					'nombres'      => $con_nombres ? array_map( $nombre, $nums ) : array(),
				);
				$afectados[ $id ][ $cod ] = $con_nombres ? array_map( $nombre, $nums ) : array();
			}
			foreach ( $item['riesgos'] as $cod => $nums ) {
				$hay[ self::RIESGO ] = true;
				$item['riesgos'][ $cod ] = array( 'n' => count( $nums ), 'nombres' => $con_nombres ? array_map( $nombre, $nums ) : array() );
			}
			foreach ( $item['dieta'] as $d => $nums ) {
				$hay[ self::RIESGO ] = true;
				$item['dieta'][ $d ] = array( 'n' => count( $nums ), 'nombres' => $con_nombres ? array_map( $nombre, $nums ) : array() );
			}
			$res[ $id ] = $item;
		}
		return array(
			'estado'              => 'ok',
			'nivel'               => self::nivel( $hay, count( $revisar ), count( $incompletos ) ),
			'momento'             => wp_date( 'd/m/Y H:i' ),
			'asistentes'          => count( $asistentes ),
			'platos'              => $res,
			'incompletos'         => count( $incompletos ),
			'revisar'             => count( $revisar ),
			'nombres_incompletos' => $con_nombres ? array_map( $nombre, $incompletos ) : array(),
			'textos_revisar'      => $con_nombres ? array_combine( array_map( $nombre, array_keys( $revisar ) ), array_values( $revisar ) ) : array(),
			'afectados'           => $afectados,
		);
	}

	/** @param array<string, bool> $hay */
	private static function nivel( array $hay, int $revisar, int $incompletos ): string {
		if ( $hay[ self::CONFLICTO ] ) {
			return self::CONFLICTO;
		}
		if ( $hay[ self::RIESGO ] || $revisar > 0 ) {
			return self::RIESGO;
		}
		return $incompletos > 0 ? self::INCOMPLETO : self::SIN;
	}

	/**
	 * El nivel del menú teniendo en cuenta lo confirmado: un conflicto confirmado (con su alternativa) se da por resuelto; los
	 * que quedan sin confirmar siguen mandando.
	 *
	 * @param array<string, array> $confirmaciones AvisosTable::de_evento()
	 */
	public static function nivel_con_confirmaciones( array $r, array $confirmaciones ): string {
		if ( 'ok' !== $r['estado'] ) {
			return self::INCOMPLETO;
		}
		$sin_confirmar = false;
		$riesgo        = $r['revisar'] > 0;
		foreach ( $r['platos'] as $plato_id => $p ) {
			foreach ( array_keys( $p['conflictos'] ) as $cod ) {
				if ( ! isset( $confirmaciones[ $plato_id . '|' . $cod ] ) ) {
					$sin_confirmar = true;
				}
			}
			foreach ( array_keys( $p['riesgos'] ) as $cod ) {
				$riesgo = $riesgo || ! isset( $confirmaciones[ $plato_id . '|' . $cod ] );
			}
			$riesgo = $riesgo || (bool) $p['dieta'];
		}
		if ( $sin_confirmar ) {
			return self::CONFLICTO;
		}
		if ( $riesgo ) {
			return self::RIESGO;
		}
		return $r['incompletos'] > 0 ? self::INCOMPLETO : self::SIN;
	}
}
