<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Conciliación de los ingresos del banco con las familias:
 * - sugerencia por la referencia de familia en el concepto (F684001, «F-684-001»…);
 * - o por el ordenante, si un pago anterior suyo ya se concilió con una familia.
 * Siempre la confirma una persona. Al conciliar se crea el pago en el control de
 * pagos de Tesorería (origen «banco»); al deshacer, ese pago se anula.
 */
final class Conciliacion {

	/** Referencia de familia del grupo en un texto: F + nº de grupo + 3 cifras. */
	public static function referencia( string $texto, string $grupo ): ?string {
		if ( preg_match_all( '/(?<![A-Z0-9])F[\s\-.]?(\d{3})[\s\-.]?(\d{3})(?!\d)/i', $texto, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $x[1] === $grupo ) {
					return 'F' . $x[1] . $x[2];
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string, array> $familias nº de familia => familia (FamiliasTable::todas())
	 * @return array{familia:array, motivo:string, fiable:bool}|null
	 */
	public static function sugerencia( array $mov, array $familias ): ?array {
		if ( $mov['importe'] <= 0 ) {
			return null;
		}
		$ref = self::referencia( implode( ' ', array( $mov['concepto'], $mov['referencia1'], $mov['referencia2'], $mov['documento'] ) ), Grupo::numero() );
		if ( $ref && isset( $familias[ $ref ] ) ) {
			return array(
				'familia' => $familias[ $ref ],
				'motivo'  => sprintf( asde_t( 'Referencia %s en el concepto' ), $ref ),
				'fiable'  => true,
			);
		}
		$id = BancoTable::familia_por_ordenante( (string) ( $mov['ordenante_hmac'] ?? '' ) );
		if ( $id ) {
			foreach ( $familias as $f ) {
				if ( $f['id'] === $id ) {
					return array(
						'familia' => $f,
						'motivo'  => asde_t( 'Mismo ordenante que un pago ya conciliado' ),
						'fiable'  => false,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Ingresos del banco por conciliar, para elegir uno al registrar el pago de una
	 * familia (v0.20): primero los sugeridos para ella (su referencia en el concepto,
	 * el mismo ordenante que otro pago suyo o, desde la v0.20.1, el nombre de uno de
	 * sus socios o tutores) y después los demás, los más recientes antes.
	 *
	 * @param string[] $claves Nombres de los socios y tutores de la familia (claves())
	 * @return array[] Cada movimiento con 'sugerido' (bool), 'motivo' (por qué, o '')
	 *                 y 'texto' (normalizado, para el buscador: coincide()).
	 */
	public static function pendientes_para( array $familia, array $claves = array() ): array {
		$movs = BancoTable::movimientos(
			array(
				'estado' => BancoTable::PENDIENTE,
				'tipo'   => 'ingresos',
			)
		);
		foreach ( $movs as &$m ) {
			$m['texto']    = self::normalizar( implode( ' ', array( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ), Calculadora::euros( $m['importe'] ), $m['ordenante'], $m['concepto'], $m['referencia1'], $m['referencia2'], $m['documento'] ) ) );
			$sug           = self::sugerencia( $m, array( $familia['numero'] => $familia ) );
			$m['motivo']   = $sug ? $sug['motivo'] : '';
			foreach ( $sug ? array() : $claves as $clave ) {
				// Todas las palabras de la clave (nombre y primer apellido), enteras.
				if ( ! array_diff( explode( ' ', self::normalizar( $clave ) ), explode( ' ', $m['texto'] ) ) ) {
					/* translators: %s: nombre y apellido */
					$m['motivo'] = sprintf( asde_t( 'Lleva el nombre «%s»' ), $clave );
					break;
				}
			}
			$m['sugerido'] = '' !== $m['motivo'];
		}
		unset( $m );
		usort( $movs, static fn( $a, $b ) => array( (int) $b['sugerido'], $b['fecha'], (int) $b['id'] ) <=> array( (int) $a['sugerido'], $a['fecha'], (int) $a['id'] ) );
		return $movs;
	}

	/**
	 * Nombre y primer apellido de los socios de la familia y de sus tutores (los de la
	 * ficha), sin repetir: el ordenante de una transferencia suele ser un tutor y el
	 * concepto suele llevar el nombre del socio (v0.20.1).
	 *
	 * @param Persona[] $personas
	 * @return string[]
	 */
	public static function claves( array $personas ): array {
		$claves = array();
		foreach ( $personas as $p ) {
			$gente = array_merge( array( array( 'nombre' => $p->nombre, 'apellidos' => $p->apellidos ) ), array_values( $p->tutores ) );
			foreach ( $gente as $g ) {
				$clave = trim( self::primera_palabra( (string) ( $g['nombre'] ?? '' ) ) . ' ' . self::primera_palabra( (string) ( $g['apellidos'] ?? '' ) ) );
				if ( str_contains( $clave, ' ' ) ) {
					$claves[ self::normalizar( $clave ) ] = $clave;
				}
			}
		}
		return array_values( $claves );
	}

	/** La primera palabra que cuenta de un nombre o unos apellidos («de la Fuente» → «Fuente»), con mayúscula inicial. */
	private static function primera_palabra( string $texto ): string {
		foreach ( preg_split( '/[\s\-]+/u', trim( $texto ) ) as $palabra ) {
			if ( mb_strlen( $palabra ) > 1 && ! in_array( self::normalizar( $palabra ), array( 'de', 'del', 'la', 'las', 'los', 'el', 'y', 'i', 'da', 'das', 'do', 'dos', 'san', 'santa' ), true ) ) {
				return mb_convert_case( mb_strtolower( $palabra ), MB_CASE_TITLE );
			}
		}
		return '';
	}

	/** Texto para buscar: minúsculas, sin acentos y solo letras y cifras separadas por un espacio. */
	public static function normalizar( string $texto ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $texto ) ) ) );
	}

	/** ¿Está cada palabra buscada (o un trozo de ella) en el texto normalizado? Sin búsqueda, sí. */
	public static function coincide( string $texto, string $busqueda ): bool {
		foreach ( array_filter( explode( ' ', self::normalizar( $busqueda ) ), 'strlen' ) as $palabra ) {
			if ( ! str_contains( $texto, $palabra ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return int|\WP_Error Id del pago creado en Tesorería. */
	public static function conciliar( int $mov_id, int $familia_id, string $ronda = '' ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no existe.' ) );
		}
		if ( BancoTable::PENDIENTE !== $m['estado'] ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento ya está revisado.' ) );
		}
		if ( $m['importe'] <= 0 ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Solo se concilian ingresos con las familias.' ) );
		}
		$f = FamiliasTable::obtener( $familia_id );
		if ( ! $f ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Esa familia no existe.' ) );
		}
		$ronda = Config::es_ronda( $ronda ) ? $ronda : Config::ronda_de_fecha( $m['fecha'] );
		$pago  = MovimientosTable::registrar(
			$familia_id,
			$ronda,
			MovimientosTable::PAGO,
			$m['fecha'],
			$m['importe'],
			'transferencia',
			$m['documento'] ?: $f['numero'],
			sprintf( 'Banco: movimiento del %s', wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ),
			'banco'
		);
		if ( is_wp_error( $pago ) ) {
			return $pago;
		}
		BancoTable::marcar( $mov_id, BancoTable::CONCILIADO, $familia_id, $pago );
		Logger::log(
			'banco_conciliado',
			'banco',
			(string) $mov_id,
			array(
				'familia' => $f['numero'],
				'pago'    => $pago,
				'ronda'   => $ronda,
			)
		);
		return $pago;
	}

	/**
	 * No es una cuota (gasto, donativo, subvención…): queda revisado, sin familia, con
	 * su tipo (v0.20) y una nota. También cambia el tipo de uno ya revisado.
	 *
	 * @return true|\WP_Error
	 */
	public static function ignorar( int $mov_id, int $tipo_id, string $nota = '', int $evento_id = 0, int $familia_id = 0 ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m || ! in_array( $m['estado'], array( BancoTable::PENDIENTE, BancoTable::IGNORADO ), true ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no se puede marcar como revisado (puede que ya esté conciliado con una familia).' ) );
		}
		$tipo = TiposMovimiento::obtener( $tipo_id );
		if ( ! $tipo ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Elige el tipo de gasto o de ingreso.' ) );
		}
		if ( ! TiposMovimiento::encaja( $tipo, $m['importe'] ) ) {
			return new \WP_Error(
				'asde_banco',
				$m['importe'] < 0
					/* translators: %s: nombre del tipo */
					? sprintf( asde_t( '«%s» es un tipo de ingreso y este movimiento es un gasto.' ), $tipo['nombre'] )
					/* translators: %s: nombre del tipo */
					: sprintf( asde_t( '«%s» es un tipo de gasto y este movimiento es un ingreso.' ), $tipo['nombre'] )
			);
		}
		if ( $evento_id && ! \AsdeBalears\AreaPrivada\Eventos\EventosTable::obtener( $evento_id ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'El evento elegido ya no existe: elige otro.' ) );
		}
		if ( $familia_id && ! FamiliasTable::obtener( $familia_id ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'La familia elegida ya no existe: elige otra.' ) );
		}
		$antes = $m['estado'];
		BancoTable::revisar( $mov_id, $tipo_id, $nota, $evento_id, $familia_id );
		Logger::log(
			BancoTable::IGNORADO === $antes ? 'banco_tipo_cambiado' : 'banco_revisado',
			'banco',
			(string) $mov_id,
			array(
				'tipo'    => $tipo_id,
				'evento'  => $evento_id,
				'familia' => $familia_id,
			)
		);
		return true;
	}

	/** Vuelve a pendiente; si estaba conciliado, anula el pago que se creó. */
	public static function deshacer( int $mov_id ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m || BancoTable::PENDIENTE === $m['estado'] ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no está revisado.' ) );
		}
		if ( BancoTable::CONCILIADO === $m['estado'] && $m['pago_id'] ) {
			MovimientosTable::anular( (int) $m['pago_id'], 'Se deshizo la conciliación con el banco.' ); // Lo que se guarda va en castellano.
		}
		BancoTable::marcar( $mov_id, BancoTable::PENDIENTE );
		Logger::log( 'banco_deshecho', 'banco', (string) $mov_id, array( 'pago_anulado' => (int) $m['pago_id'] ) );
		return true;
	}
}
