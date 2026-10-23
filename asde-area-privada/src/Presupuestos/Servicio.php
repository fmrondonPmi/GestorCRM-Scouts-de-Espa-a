<?php
namespace AsdeBalears\AreaPrivada\Presupuestos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Reglas de los presupuestos de sección y de grupo (v0.55, doc 57 §5 y §8).
 *
 * - Un scouter prepara el presupuesto de SUS secciones; Secretaría, Coordinación y Tesorería
 *   (asde_tesoreria_config) las de todas y el del grupo, y son quienes aprueban y reabren.
 * - Solo se edita en borrador: «enviado» y «aprobado» quedan bloqueados hasta que Tesorería los reabre.
 * - Los métodos que cambian algo devuelven el texto del error, o '' si todo fue bien.
 */
final class Servicio {

	public const CAP_SECCION = 'asde_presupuestos_seccion';
	public const CAP_CONFIG  = 'asde_tesoreria_config';
	public const GRUPO       = 'grupo';

	public const MAX_VECES   = 999;
	public const MAX_IMPORTE = 1000000.0;

	/** ¿Puede ver o abrir los presupuestos? */
	public static function puede_entrar(): bool {
		return Modulos::activo( Modulos::PRESUPUESTOS ) && ( current_user_can( self::CAP_CONFIG ) || current_user_can( self::CAP_SECCION ) );
	}

	/** @return string[] Presupuestos que puede abrir: las secciones que le tocan y, si es de coordinación, el del grupo. */
	public static function secciones_para( Ambito $a ): array {
		if ( ! self::puede_entrar() ) {
			return array();
		}
		if ( current_user_can( self::CAP_CONFIG ) ) {
			return array_merge( Secciones::EDUCATIVAS, array( self::GRUPO ) );
		}
		return array_values( array_intersect( Secciones::EDUCATIVAS, $a->secciones_scouter() ) );
	}

	public static function es_ambito_valido( string $seccion ): bool {
		return self::GRUPO === $seccion || in_array( $seccion, Secciones::EDUCATIVAS, true );
	}

	public static function nombre( string $seccion ): string {
		return self::GRUPO === $seccion ? asde_t( 'Grupo' ) : Secciones::nombre( $seccion );
	}

	/** Texto de por qué no se puede cambiar el presupuesto, o '' si se puede. */
	public static function error_edicion( string $ronda, string $seccion, Ambito $a ): string {
		if ( ! Config::es_ronda( $ronda ) || ! self::es_ambito_valido( $seccion ) || ! in_array( $seccion, self::secciones_para( $a ), true ) ) {
			return asde_t( 'No tienes permiso para cambiar este presupuesto.' );
		}
		if ( EstadosTable::BORRADOR !== EstadosTable::de( $ronda, $seccion )['estado'] ) {
			return asde_t( 'El presupuesto está enviado o aprobado: Tesorería tiene que reabrirlo para cambiarlo.' );
		}
		return '';
	}

	/** Crea la partida de ingreso del proyecto de la sección (objetivo de recaudación), una sola vez. */
	public static function asegurar_proyecto( string $ronda, string $seccion ): void {
		if ( ! in_array( $seccion, Secciones::EDUCATIVAS, true ) ) {
			return;
		}
		foreach ( PartidasTable::de( $ronda, $seccion ) as $p ) {
			if ( PartidasTable::PROYECTO === $p['categoria'] ) {
				return;
			}
		}
		$nombre = (string) ( Config::de( $ronda )['presupuesto']['proyectos'][ $seccion ] ?? '' );
		PartidasTable::anadir( $ronda, $seccion, PartidasTable::INGRESO, PartidasTable::PROYECTO, $nombre, 1, 0.0 );
	}

	/**
	 * Cuentas de un presupuesto.
	 *
	 * @param array[] $partidas
	 * @return array{gastos:float, ingresos:float, recaudacion:float, disponible:float, saldo:float, sin_recaudacion:float}
	 */
	public static function resultado( float $disponible, array $partidas ): array {
		$gastos      = 0.0;
		$ingresos    = 0.0;
		$recaudacion = 0.0;
		foreach ( $partidas as $p ) {
			if ( PartidasTable::INGRESO === $p['tipo'] ) {
				$ingresos += $p['total'];
				if ( PartidasTable::PROYECTO === $p['categoria'] ) {
					$recaudacion += $p['total'];
				}
			} else {
				$gastos += $p['total'];
			}
		}
		$saldo = round( $disponible + $ingresos - $gastos, 2 );
		return array(
			'gastos'          => round( $gastos, 2 ),
			'ingresos'        => round( $ingresos, 2 ),
			'recaudacion'     => round( $recaudacion, 2 ),
			'disponible'      => round( $disponible, 2 ),
			'saldo'           => $saldo,
			// Lo que quedaría si no se recaudara nada del proyecto (la recaudación es un objetivo, no dinero cobrado).
			'sin_recaudacion' => round( $saldo - $recaudacion, 2 ),
		);
	}

	public static function numero( $v ): float {
		return (float) str_replace( ',', '.', trim( (string) $v ) );
	}

	/** ¿Es un importe válido (un número, sin pasar del máximo; cero solo si $cero_vale)? Una sola regla para partidas, proyecto y gastos (doc 59, RC-PRE-5). */
	public static function importe_valido( $v, bool $cero_vale ): bool {
		$n = self::numero( $v );
		return is_numeric( str_replace( ',', '.', trim( (string) $v ) ) ) && ( $cero_vale ? $n >= 0 : $n > 0 ) && $n <= self::MAX_IMPORTE;
	}

	/** Añade una partida. @return string Error, o '' si se añadió. */
	public static function anadir_partida( string $ronda, string $seccion, Ambito $a, string $tipo, string $categoria, string $concepto, $veces, $importe, string $nota = '' ): string {
		$error = self::error_edicion( $ronda, $seccion, $a );
		if ( '' !== $error ) {
			return $error;
		}
		$tipo      = PartidasTable::INGRESO === $tipo ? PartidasTable::INGRESO : PartidasTable::GASTO;
		$categoria = trim( $categoria );
		$concepto  = trim( sanitize_text_field( $concepto ) );
		$veces_n   = (int) $veces;
		$importe_n = self::numero( $importe );

		if ( $veces_n < 1 || $veces_n > self::MAX_VECES ) {
			return sprintf( asde_t( '«¿Cuántos?» tiene que ser un número entre 1 y %d.' ), self::MAX_VECES );
		}
		if ( ! self::importe_valido( $importe, true ) ) {
			return asde_t( 'El importe tiene que ser un número positivo.' );
		}
		if ( PartidasTable::GASTO === $tipo ) {
			if ( ! in_array( $categoria, Config::de( $ronda )['presupuesto']['categorias'], true ) ) {
				return asde_t( 'Elige una categoría de la lista.' );
			}
			$concepto = '' !== $concepto ? $concepto : $categoria;
		} else {
			if ( '' === $concepto ) {
				return asde_t( 'Escribe en qué consiste el ingreso.' );
			}
			$categoria = '' !== $categoria && '_' !== $categoria[0] ? $categoria : asde_t( 'Otros ingresos' );
		}

		PartidasTable::anadir( $ronda, $seccion, $tipo, $categoria, $concepto, $veces_n, $importe_n, $nota );
		Logger::log( 'presupuesto_partida_anadida', 'presupuesto', $seccion . ' ' . $ronda, array( 'tipo' => $tipo ) );
		return '';
	}

	/** Pone el objetivo de recaudación del proyecto de la sección. @return string Error, o ''. */
	public static function fijar_proyecto( string $ronda, string $seccion, Ambito $a, $importe ): string {
		$error = self::error_edicion( $ronda, $seccion, $a );
		if ( '' !== $error ) {
			return $error;
		}
		$importe_n = self::numero( $importe );
		if ( ! self::importe_valido( $importe, true ) ) {
			return asde_t( 'El importe tiene que ser un número positivo.' );
		}
		self::asegurar_proyecto( $ronda, $seccion );
		foreach ( PartidasTable::de( $ronda, $seccion ) as $p ) {
			if ( PartidasTable::PROYECTO === $p['categoria'] ) {
				PartidasTable::cambiar_importe( $p['id'], $ronda, $seccion, 1, $importe_n );
				Logger::log( 'presupuesto_proyecto_fijado', 'presupuesto', $seccion . ' ' . $ronda );
				return '';
			}
		}
		return asde_t( 'Esta sección no tiene proyecto de recaudación.' );
	}

	/** @return string Error, o ''. */
	public static function quitar_partida( string $ronda, string $seccion, Ambito $a, int $id ): string {
		$error = self::error_edicion( $ronda, $seccion, $a );
		if ( '' !== $error ) {
			return $error;
		}
		foreach ( PartidasTable::de( $ronda, $seccion ) as $p ) {
			if ( $p['id'] === $id ) {
				if ( PartidasTable::PROYECTO === $p['categoria'] ) {
					return asde_t( 'El proyecto de recaudación no se quita: pon su objetivo a 0 si no lo necesitas.' );
				}
				PartidasTable::quitar( $id, $ronda, $seccion );
				GastosRealesTable::borrar_de_partida( $id ); // Sin partida, sus gastos reales no cuelgan de nada.
				Logger::log( 'presupuesto_partida_quitada', 'presupuesto', $seccion . ' ' . $ronda );
				return '';
			}
		}
		return asde_t( 'Esa partida no existe.' );
	}

	/** ¿Puede ver y anotar el gasto real de este presupuesto? (en cualquier estado: gastar no cambia lo presupuestado). */
	public static function puede_anotar_gasto( string $ronda, string $seccion, Ambito $a ): bool {
		return Config::es_ronda( $ronda ) && self::es_ambito_valido( $seccion ) && in_array( $seccion, self::secciones_para( $a ), true );
	}

	/**
	 * Anota un gasto real contra una partida de gasto: «presupuestado / gastado / queda».
	 *
	 * @return string Error, o ''.
	 */
	public static function anadir_gasto_real( string $ronda, string $seccion, Ambito $a, int $partida_id, string $fecha, string $concepto, $importe ): string {
		if ( ! self::puede_anotar_gasto( $ronda, $seccion, $a ) ) {
			return asde_t( 'No tienes permiso para cambiar este presupuesto.' );
		}
		$partida = null;
		foreach ( PartidasTable::de( $ronda, $seccion ) as $p ) {
			if ( $p['id'] === $partida_id && PartidasTable::GASTO === $p['tipo'] ) {
				$partida = $p;
			}
		}
		if ( ! $partida ) {
			return asde_t( 'Elige una partida de gasto del presupuesto.' );
		}
		$fecha_ok = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) && false !== strtotime( $fecha ) && gmdate( 'Y-m-d', (int) strtotime( $fecha ) ) === $fecha;
		if ( ! $fecha_ok ) {
			return asde_t( 'La fecha no es válida.' );
		}
		$importe_n = self::numero( $importe );
		if ( ! self::importe_valido( $importe, false ) ) {
			return asde_t( 'El importe tiene que ser un número mayor que cero.' );
		}
		$concepto = trim( sanitize_text_field( $concepto ) );
		GastosRealesTable::anadir( $ronda, $seccion, $partida_id, $fecha, '' !== $concepto ? $concepto : $partida['concepto'], $importe_n );
		Logger::log( 'presupuesto_gasto_anotado', 'presupuesto', $seccion . ' ' . $ronda );
		return '';
	}

	/** @return string Error, o ''. */
	public static function quitar_gasto_real( string $ronda, string $seccion, Ambito $a, int $id ): string {
		if ( ! self::puede_anotar_gasto( $ronda, $seccion, $a ) ) {
			return asde_t( 'No tienes permiso para cambiar este presupuesto.' );
		}
		if ( ! GastosRealesTable::quitar( $id, $ronda, $seccion ) ) {
			return asde_t( 'Ese gasto no existe.' );
		}
		Logger::log( 'presupuesto_gasto_quitado', 'presupuesto', $seccion . ' ' . $ronda );
		return '';
	}

	/**
	 * Presupuestado, gastado de verdad y lo que queda, en total (solo cuentan las partidas de gasto).
	 *
	 * @param array[]           $partidas
	 * @param array<int, float> $por_partida GastosRealesTable::por_partida()
	 * @return array{presupuestado:float, gastado:float, queda:float}
	 */
	public static function ejecucion( array $partidas, array $por_partida ): array {
		$presupuestado = 0.0;
		$gastado       = 0.0;
		foreach ( $partidas as $p ) {
			if ( PartidasTable::GASTO === $p['tipo'] ) {
				$presupuestado += $p['total'];
				$gastado       += $por_partida[ $p['id'] ] ?? 0.0;
			}
		}
		return array(
			'presupuestado' => round( $presupuestado, 2 ),
			'gastado'       => round( $gastado, 2 ),
			'queda'         => round( $presupuestado - $gastado, 2 ),
		);
	}

	/** La sección da su presupuesto por terminado. @return string Error, o ''. */
	public static function enviar( string $ronda, string $seccion, Ambito $a ): string {
		$error = self::error_edicion( $ronda, $seccion, $a );
		if ( '' !== $error ) {
			return $error;
		}
		EstadosTable::poner( $ronda, $seccion, EstadosTable::ENVIADO );
		Logger::log( 'presupuesto_enviado', 'presupuesto', $seccion . ' ' . $ronda );
		return '';
	}

	/** Tesorería, Secretaría o Coordinación lo aprueban (queda bloqueado). @return string Error, o ''. */
	public static function aprobar( string $ronda, string $seccion ): string {
		if ( ! self::puede_entrar() || ! current_user_can( self::CAP_CONFIG ) || ! self::es_ambito_valido( $seccion ) || ! Config::es_ronda( $ronda ) ) {
			return asde_t( 'No tienes permiso para aprobar este presupuesto.' );
		}
		if ( EstadosTable::APROBADO === EstadosTable::de( $ronda, $seccion )['estado'] ) {
			return asde_t( 'El presupuesto ya está aprobado.' );
		}
		EstadosTable::poner( $ronda, $seccion, EstadosTable::APROBADO );
		Logger::log( 'presupuesto_aprobado', 'presupuesto', $seccion . ' ' . $ronda );
		return '';
	}

	/** Devuelve un presupuesto enviado o aprobado a borrador, con el motivo en el registro. @return string Error, o ''. */
	public static function reabrir( string $ronda, string $seccion, string $motivo ): string {
		if ( ! self::puede_entrar() || ! current_user_can( self::CAP_CONFIG ) || ! self::es_ambito_valido( $seccion ) || ! Config::es_ronda( $ronda ) ) {
			return asde_t( 'No tienes permiso para reabrir este presupuesto.' );
		}
		$motivo = trim( sanitize_text_field( $motivo ) );
		if ( '' === $motivo ) {
			return asde_t( 'Escribe el motivo para reabrirlo.' );
		}
		if ( EstadosTable::BORRADOR === EstadosTable::de( $ronda, $seccion )['estado'] ) {
			return asde_t( 'El presupuesto ya es un borrador.' );
		}
		EstadosTable::poner( $ronda, $seccion, EstadosTable::BORRADOR );
		Logger::log( 'presupuesto_reabierto', 'presupuesto', $seccion . ' ' . $ronda, array( 'motivo' => mb_substr( $motivo, 0, 200 ) ) );
		return '';
	}

	/** Ronda anterior a otra (2026-2027 → 2025-2026). */
	public static function ronda_anterior( string $ronda ): string {
		$ini = (int) substr( $ronda, 0, 4 ) - 1;
		return $ini . '-' . ( $ini + 1 );
	}

	/** ¿Tiene ya partidas propias? (la del proyecto, que se crea sola, no cuenta). */
	public static function tiene_partidas( string $ronda, string $seccion ): bool {
		foreach ( PartidasTable::de( $ronda, $seccion ) as $p ) {
			if ( PartidasTable::PROYECTO !== $p['categoria'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Copia las partidas (y el objetivo del proyecto) de la ronda anterior: nunca los estados.
	 *
	 * @return string Error, o ''.
	 */
	public static function copiar_anterior( string $ronda, string $seccion, Ambito $a ): string {
		$error = self::error_edicion( $ronda, $seccion, $a );
		if ( '' !== $error ) {
			return $error;
		}
		if ( self::tiene_partidas( $ronda, $seccion ) ) {
			return asde_t( 'Este presupuesto ya tiene partidas: solo se puede copiar sobre uno vacío.' );
		}
		$anterior = PartidasTable::de( self::ronda_anterior( $ronda ), $seccion );
		if ( ! $anterior ) {
			return asde_t( 'No hay presupuesto de la ronda anterior para copiar.' );
		}
		self::asegurar_proyecto( $ronda, $seccion );
		$actuales = PartidasTable::de( $ronda, $seccion );
		foreach ( $anterior as $p ) {
			if ( PartidasTable::PROYECTO === $p['categoria'] ) {
				foreach ( $actuales as $act ) {
					if ( PartidasTable::PROYECTO === $act['categoria'] ) {
						PartidasTable::cambiar_importe( $act['id'], $ronda, $seccion, 1, $p['importe'] );
					}
				}
				continue;
			}
			PartidasTable::anadir( $ronda, $seccion, $p['tipo'], $p['categoria'], $p['concepto'], $p['veces'], $p['importe'], $p['nota'] );
		}
		Logger::log( 'presupuesto_copiado', 'presupuesto', $seccion . ' ' . $ronda );
		return '';
	}
}
