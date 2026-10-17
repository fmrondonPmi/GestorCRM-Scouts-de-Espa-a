<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio as Inventario;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;

defined( 'ABSPATH' ) || exit;

/**
 * El material de un evento (v0.42, doc 43 apartado 5): se reserva del inventario del grupo, avisa si otro evento que coincide en
 * fechas se lleva lo mismo y no alcanza, anota cuándo sale y cómo vuelve (con lo que falta) y, si algo no vuelve, permite descontarlo
 * del inventario con un paso aparte y explícito. Cada tipo de evento puede tener su material habitual.
 *
 * Quien lo gestiona es quien gestiona el evento y puede usar el inventario. Nada de personas: solo el id de la cuenta.
 */
final class Material {

	/** Máximo de cada línea y de lo escrito en el material habitual. */
	public const MAX_UNIDADES = 99999;
	public const MAX_HABITUAL = 3000;

	public static function puede_ver( array $e, Ambito $a ): bool {
		return Servicio::en_gestion( $e, $a ) && current_user_can( Inventario::CAP );
	}

	public static function puede_editar( array $e, Ambito $a ): bool {
		return self::puede_ver( $e, $a ) && null === $e['cerrado_en'] && EventosTable::CANCELADO !== $e['estado'];
	}

	/**
	 * Las líneas del evento con el estado del inventario: lo que hay, lo que se llevan otros eventos que coinciden en fechas y lo que
	 * falta (el aviso de conflicto).
	 *
	 * @return array[] Cada una: la línea más existencias, otros, disponible, faltan, baja (¿ya no está en el inventario?), sin_devolver.
	 */
	public static function lineas( array $e ): array {
		$otros = MaterialTable::reservado_por_otros( $e );
		$todos = MaterialesTable::todos( true );
		$out   = array();
		foreach ( MaterialTable::de_evento( $e['id'] ) as $l ) {
			$m          = $todos[ $l['material_id'] ] ?? null;
			$existencias = $m && null === $m['baja_en'] ? (int) $m['cantidad'] : 0;
			$ocupadas    = (int) ( $otros[ $l['material_id'] ] ?? 0 );
			$disponible  = max( 0, $existencias - $ocupadas );
			$sin_devolver = null !== $l['devuelta_en'] ? max( 0, $l['cantidad'] - (int) $l['devueltas'] ) : 0;
			$out[]       = $l + array(
				'existencias'  => $existencias,
				'otros'        => $ocupadas,
				'disponible'   => $disponible,
				'faltan'       => null === $l['devuelta_en'] ? max( 0, $l['cantidad'] - $disponible ) : 0,
				'baja'         => ! $m || null !== $m['baja_en'],
				'sin_devolver' => $sin_devolver,
			);
		}
		return $out;
	}

	/** El material del inventario que se puede reservar (sin las bajas y con existencias). @return array[] */
	public static function reservable(): array {
		return array_values( array_filter( MaterialesTable::todos(), static fn( $m ) => $m['cantidad'] > 0 ) );
	}

	/** @return true|\WP_Error */
	public static function anadir( array $e, Ambito $a, int $material_id, int $cantidad ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_material', asde_t( 'No puedes cambiar el material de este evento.' ) );
		}
		$m = MaterialesTable::obtener( $material_id );
		if ( ! $m || null !== $m['baja_en'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'Ese material ya no está en el inventario.' ) );
		}
		if ( $cantidad < 1 || $cantidad > self::MAX_UNIDADES ) {
			return new \WP_Error( 'asde_material', asde_t( 'La cantidad tiene que ser un número entero mayor que cero.' ) );
		}
		if ( MaterialTable::contar( $e['id'] ) >= MaterialTable::MAX ) {
			return new \WP_Error( 'asde_material', asde_t( 'El evento ya lleva demasiado material.' ) );
		}
		MaterialTable::reservar( $e['id'], $material_id, $m['descripcion'], $cantidad );
		Logger::log( 'evento_material_anadido', 'evento', (string) $e['id'], array( 'material' => $material_id, 'cantidad' => $cantidad ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cambiar_cantidad( array $e, Ambito $a, int $id, int $cantidad ) {
		$l = MaterialTable::obtener( $id );
		if ( ! self::puede_editar( $e, $a ) || ! $l || $l['evento_id'] !== $e['id'] || null !== $l['salida_en'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'No se puede cambiar: el material ya ha salido o no puedes cambiar el del evento.' ) );
		}
		if ( $cantidad < 1 || $cantidad > self::MAX_UNIDADES ) {
			return new \WP_Error( 'asde_material', asde_t( 'La cantidad tiene que ser un número entero mayor que cero.' ) );
		}
		MaterialTable::actualizar( $id, array( 'cantidad' => $cantidad ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function quitar( array $e, Ambito $a, int $id ) {
		$l = MaterialTable::obtener( $id );
		if ( ! self::puede_editar( $e, $a ) || ! $l || $l['evento_id'] !== $e['id'] || null !== $l['salida_en'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'No se puede quitar: el material ya ha salido o no puedes cambiar el del evento.' ) );
		}
		MaterialTable::quitar( $id, $e['id'] );
		return true;
	}

	/**
	 * Carga el material habitual de este tipo de evento (lo que el grupo ha escrito en la configuración, «cantidad; descripción» por línea):
	 * cada línea se busca en el inventario por su nombre. Lo que no se encuentra se devuelve para decirlo.
	 *
	 * @return array{anadidas:int, no_encontradas:string[]}|\WP_Error
	 */
	public static function cargar_habitual( array $e, Ambito $a ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_material', asde_t( 'No puedes cambiar el material de este evento.' ) );
		}
		$texto = (string) ( Config::de()['material_por_tipo'][ $e['tipo'] ] ?? '' );
		if ( '' === trim( $texto ) ) {
			return new \WP_Error( 'asde_material', asde_t( 'Este tipo de evento no tiene material habitual: se escribe en la configuración de eventos.' ) );
		}
		$por_nombre = array();
		foreach ( MaterialesTable::todos() as $m ) {
			$por_nombre[ TiposTable::clave( $m['descripcion'] ) ] ??= $m;
		}
		$anadidas = 0;
		$faltan   = array();
		foreach ( self::partir_habitual( $texto ) as $l ) {
			$m = $por_nombre[ TiposTable::clave( $l['descripcion'] ) ] ?? null;
			if ( ! $m ) {
				$faltan[] = $l['descripcion'];
				continue;
			}
			if ( MaterialTable::contar( $e['id'] ) >= MaterialTable::MAX ) {
				break;
			}
			MaterialTable::reservar( $e['id'], $m['id'], $m['descripcion'], $l['cantidad'] );
			++$anadidas;
		}
		Logger::log( 'evento_material_habitual', 'evento', (string) $e['id'], array( 'anadidas' => $anadidas ) );
		return array( 'anadidas' => $anadidas, 'no_encontradas' => $faltan );
	}

	/** @return array<int, array{cantidad:int, descripcion:string}> Las líneas válidas del material habitual («4; Tienda canadiense»). */
	public static function partir_habitual( string $texto ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $texto ) as $linea ) {
			if ( ! preg_match( '/^\s*(\d{1,5})\s*[;:x×]\s*(.+?)\s*$/u', $linea, $m ) || (int) $m[1] < 1 ) {
				continue;
			}
			$out[] = array( 'cantidad' => (int) $m[1], 'descripcion' => mb_substr( $m[2], 0, 255 ) );
		}
		return $out;
	}

	/**
	 * Marca la salida del material (una línea o, con 0, todas las que aún no han salido): quién y cuándo.
	 *
	 * @return int|\WP_Error Líneas marcadas.
	 */
	public static function salida( array $e, Ambito $a, int $id = 0 ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_material', asde_t( 'No puedes cambiar el material de este evento.' ) );
		}
		$n = 0;
		foreach ( MaterialTable::de_evento( $e['id'] ) as $l ) {
			if ( ( 0 === $id || $l['id'] === $id ) && null === $l['salida_en'] ) {
				MaterialTable::actualizar( $l['id'], array( 'salida_en' => current_time( 'mysql', true ), 'salida_por' => get_current_user_id() ) );
				++$n;
			}
		}
		if ( ! $n ) {
			return new \WP_Error( 'asde_material', asde_t( 'No hay material pendiente de sacar.' ) );
		}
		Logger::log( 'evento_material_salida', 'evento', (string) $e['id'], array( 'lineas' => $n ) );
		return $n;
	}

	/**
	 * Anota la devolución de una línea: cuántas unidades vuelven y una nota si algo falta o está roto. Se puede corregir mientras no se
	 * hayan descontado del inventario las que faltan.
	 *
	 * @return true|\WP_Error
	 */
	public static function devolver( array $e, Ambito $a, int $id, int $devueltas, string $nota ) {
		$l = MaterialTable::obtener( $id );
		if ( ! self::puede_editar( $e, $a ) || ! $l || $l['evento_id'] !== $e['id'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'No puedes cambiar el material de este evento.' ) );
		}
		if ( null === $l['salida_en'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'Ese material todavía no ha salido.' ) );
		}
		if ( $l['descontado'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'Ya se descontó del inventario lo que faltaba: no se puede cambiar la devolución.' ) );
		}
		if ( $devueltas < 0 || $devueltas > $l['cantidad'] ) {
			return new \WP_Error( 'asde_material', sprintf( asde_t( 'Las unidades devueltas van de 0 a %d.' ), $l['cantidad'] ) );
		}
		MaterialTable::actualizar(
			$id,
			array(
				'devueltas'    => $devueltas,
				'devuelta_en'  => current_time( 'mysql', true ),
				'devuelta_por' => get_current_user_id(),
				'incidencia'   => mb_substr( sanitize_text_field( $nota ), 0, 200 ),
			)
		);
		Logger::log( 'evento_material_devuelto', 'evento', (string) $e['id'], array( 'material' => $l['material_id'], 'faltan' => $l['cantidad'] - $devueltas ) );
		return true;
	}

	/**
	 * Lo que no volvió se descuenta del inventario (una sola vez, con un paso explícito): el inventario deja de contar con ello.
	 *
	 * @return int|\WP_Error Unidades descontadas.
	 */
	public static function descontar_faltantes( array $e, Ambito $a, int $id ) {
		$l = MaterialTable::obtener( $id );
		if ( ! self::puede_editar( $e, $a ) || ! $l || $l['evento_id'] !== $e['id'] || null === $l['devuelta_en'] || $l['descontado'] ) {
			return new \WP_Error( 'asde_material', asde_t( 'No hay nada que descontar del inventario en esa línea.' ) );
		}
		$faltan = max( 0, $l['cantidad'] - (int) $l['devueltas'] );
		if ( ! $faltan ) {
			return new \WP_Error( 'asde_material', asde_t( 'No hay nada que descontar del inventario en esa línea.' ) );
		}
		$quitadas = MaterialesTable::restar( $l['material_id'], $faltan );
		MaterialTable::actualizar( $id, array( 'descontado' => 1 ) );
		Logger::log( 'evento_material_descontado', 'evento', (string) $e['id'], array( 'material' => $l['material_id'], 'unidades' => $quitadas ) );
		return $quitadas;
	}

	/** Resumen para el evento: líneas, sin sacar, por devolver y con incidencias. @return array{lineas:int, sin_sacar:int, por_devolver:int, con_faltas:int} */
	public static function resumen( array $e ): array {
		$r = array( 'lineas' => 0, 'sin_sacar' => 0, 'por_devolver' => 0, 'con_faltas' => 0 );
		foreach ( MaterialTable::de_evento( $e['id'] ) as $l ) {
			++$r['lineas'];
			if ( null === $l['salida_en'] ) {
				++$r['sin_sacar'];
			} elseif ( null === $l['devuelta_en'] ) {
				++$r['por_devolver'];
			} elseif ( (int) $l['devueltas'] < $l['cantidad'] ) {
				++$r['con_faltas'];
			}
		}
		return $r;
	}
}
