<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Audit\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inventario de material del grupo y listas de compra (petición del usuario,
 * 2026-10-05, doc 32).
 *
 * - Entran los scouters (con o sin sección) y Secretaría, Coordinación y
 *   Tesorería: ven y editan el material y los tipos y crean listas de compra.
 * - Una lista de compra nace pendiente y se avisa por email a Tesorería; hasta
 *   que Tesorería le da el visto bueno no se puede descargar el PDF para imprimirla.
 * - v0.30 (doc 37): la lista puede llevar material nuevo (que no está en el
 *   inventario); quien la pidió recibe un email cuando Tesorería la aprueba o la
 *   rechaza; y una lista aprobada se marca como comprada, lo que suma lo comprado
 *   al inventario (y da de alta el material nuevo).
 */
final class Servicio {

	public const CAP         = 'asde_inventario';
	public const CAP_APROBAR = 'asde_inventario_aprobar';

	/**
	 * Cantidad de referencia del inventario (5): por debajo, el material lleva la etiqueta
	 * amarilla de «pocas unidades»; desde ella, al añadirlo a una lista de compra se pregunta
	 * si de verdad hace falta comprar más (P-62, v0.30: antes se preguntaba al revés).
	 */
	public const UMBRAL = 5;

	public static function umbral(): int {
		/**
		 * Cantidad de referencia del inventario: por debajo, «pocas unidades»; desde ella, al
		 * añadir el material a una lista de compra se pregunta si hace falta comprar más.
		 * Con 0 no se avisa nunca.
		 *
		 * @param int $umbral Por defecto, 5.
		 */
		return max( 0, (int) apply_filters( 'asde_ap_inventario_umbral', self::UMBRAL ) );
	}

	public static function pocas_unidades( int $cantidad ): bool {
		return $cantidad < self::umbral();
	}

	/** ¿Ya hay bastantes? (P-62) Entonces, al añadirlo a una lista de compra, se pregunta «¿seguro?». */
	public static function ya_hay_bastantes( int $cantidad ): bool {
		return self::umbral() > 0 && $cantidad >= self::umbral();
	}

	/**
	 * Líneas de una lista a partir del formulario «Nueva lista».
	 *
	 * @param array<int|string, string> $cantidades id de material => cantidad a comprar.
	 * @param array<int|string, string> $precios    id de material => precio estimado ('' = sin precio).
	 * @param array[]                   $nuevos     v0.30: material nuevo, que no está en el inventario
	 *                                              (cada uno: descripcion, cantidad, precio). Las filas en
	 *                                              blanco se saltan.
	 * @return array[]|\WP_Error
	 */
	public static function lineas( array $cantidades, array $precios, array $nuevos = array() ) {
		$materiales = MaterialesTable::todos();
		$tipos      = TiposTable::todos();
		$lineas     = array();
		foreach ( $cantidades as $id => $cantidad ) {
			$id       = (int) $id;
			$cantidad = trim( (string) $cantidad );
			if ( '' === $cantidad || '0' === $cantidad ) {
				continue; // Quitado de la lista.
			}
			$m = $materiales[ $id ] ?? null;
			if ( ! $m ) {
				return new \WP_Error( 'asde_inventario', asde_t( 'Algún material de la lista ya no está en el inventario. Vuelve a prepararla.' ) );
			}
			if ( ! preg_match( '/^\d{1,5}$/', $cantidad ) ) {
				/* translators: %s: descripción del material */
				return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'La cantidad a comprar de «%s» no es válida.' ), $m['descripcion'] ) );
			}
			$precio = MaterialesTable::euros( (string) ( $precios[ $id ] ?? '' ) );
			if ( false === $precio ) {
				/* translators: %s: descripción del material */
				return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'El precio de «%s» no es válido (por ejemplo: 12,50).' ), $m['descripcion'] ) );
			}
			$lineas[] = array(
				'material'      => $id,
				'descripcion'   => $m['descripcion'],
				'tipo'          => $tipos[ $m['tipo'] ]['nombre'] ?? '',
				'num_serie'     => $m['num_serie'],
				'en_inventario' => $m['cantidad'],
				'cantidad'      => (int) $cantidad,
				'precio'        => $precio,
			);
		}
		// v0.30 (P-63): material nuevo, escrito a mano. Una fila sin descripción ni precio está en blanco.
		$n = 0;
		foreach ( $nuevos as $nuevo ) {
			++$n;
			$descripcion = mb_substr( trim( sanitize_text_field( (string) ( $nuevo['descripcion'] ?? '' ) ) ), 0, 255 );
			$cantidad    = trim( (string) ( $nuevo['cantidad'] ?? '' ) );
			$texto       = trim( (string) ( $nuevo['precio'] ?? '' ) );
			if ( '' === $descripcion && '' === $texto ) {
				continue;
			}
			if ( '' === $descripcion ) {
				/* translators: %d: nº de la línea de material nuevo */
				return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'Escribe qué material nuevo hay que comprar en la línea %d (o deja en blanco su precio).' ), $n ) );
			}
			if ( '' === $cantidad || '0' === $cantidad ) {
				continue; // Quitado de la lista, como el material del inventario con 0.
			}
			if ( ! preg_match( '/^\d{1,5}$/', $cantidad ) ) {
				/* translators: %s: descripción del material */
				return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'La cantidad a comprar de «%s» no es válida.' ), $descripcion ) );
			}
			$precio = MaterialesTable::euros( $texto );
			if ( false === $precio ) {
				/* translators: %s: descripción del material */
				return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'El precio de «%s» no es válido (por ejemplo: 12,50).' ), $descripcion ) );
			}
			$lineas[] = array(
				'material'      => 0, // 0: material nuevo (se da de alta al marcar la lista como comprada).
				'descripcion'   => $descripcion,
				'tipo'          => '',
				'num_serie'     => '',
				'en_inventario' => 0,
				'cantidad'      => (int) $cantidad,
				'precio'        => $precio,
			);
		}
		if ( ! $lineas ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'La lista está vacía: indica cuántas unidades quieres comprar de algún material.' ) );
		}
		if ( count( $lineas ) > ComprasTable::MAX_LINEAS ) {
			/* translators: %d: número máximo de líneas */
			return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'Una lista puede tener como mucho %d materiales.' ), ComprasTable::MAX_LINEAS ) );
		}
		return $lineas;
	}

	// ---------------------------------------------------------------------
	// Quién puede hacer qué con una lista
	// ---------------------------------------------------------------------

	public static function puede_revisar( array $l ): bool {
		return current_user_can( self::CAP_APROBAR ) && ComprasTable::PENDIENTE === $l['estado'];
	}

	public static function puede_deshacer( array $l ): bool {
		return current_user_can( self::CAP_APROBAR ) && ComprasTable::APROBADA === $l['estado'] && ! $l['impresiones'];
	}

	/** Quien la creó, mientras está pendiente; Tesorería, mientras no se haya impreso. */
	public static function puede_cancelar( array $l ): bool {
		if ( $l['impresiones'] ) {
			return false;
		}
		if ( current_user_can( self::CAP_APROBAR ) ) {
			return in_array( $l['estado'], array( ComprasTable::PENDIENTE, ComprasTable::APROBADA ), true );
		}
		return ComprasTable::PENDIENTE === $l['estado'] && get_current_user_id() === $l['creado_por'];
	}

	/** Sin el visto bueno de Tesorería no se imprime (una comprada lo tuvo: se sigue pudiendo imprimir). */
	public static function puede_imprimir( array $l ): bool {
		return current_user_can( self::CAP ) && in_array( $l['estado'], array( ComprasTable::APROBADA, ComprasTable::COMPRADA ), true );
	}

	/** «Marcar como comprada» (v0.30, P-66): cualquiera que entra en el inventario, con una lista aprobada. */
	public static function puede_marcar_comprada( array $l ): bool {
		return current_user_can( self::CAP ) && ComprasTable::APROBADA === $l['estado'];
	}

	/**
	 * Marca una lista aprobada como comprada y suma lo comprado al inventario (v0.30, P-66).
	 *
	 * - Primero cambia el estado, de forma atómica (solo si sigue aprobada): dos personas a
	 *   la vez, o el mismo botón dos veces, no suman dos veces.
	 * - Cada línea del inventario suma su cantidad a su material. Si el material se dio de
	 *   baja mientras tanto (o ya no se puede sumar), se da de alta uno nuevo con los datos
	 *   de la línea; el material nuevo de la lista (P-63) se da de alta con esa cantidad.
	 * - Los materiales dados de alta quedan anotados en la línea («alta»), para enlazarlos.
	 *
	 * @return array{sumados:int[], creados:int[]}|\WP_Error Ids de los materiales.
	 */
	public static function comprar( array $l ) {
		if ( ! self::puede_marcar_comprada( $l ) || ! ComprasTable::marcar_comprada( $l['id'] ) ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Esta lista no se puede marcar como comprada: tiene que tener el visto bueno de Tesorería y no estar ya comprada.' ) );
		}
		$res    = array(
			'sumados' => array(),
			'creados' => array(),
		);
		$num    = ComprasTable::numero( $l );
		$lineas = $l['lineas'];
		foreach ( $lineas as $i => $linea ) {
			$cantidad = (int) $linea['cantidad'];
			if ( $cantidad <= 0 ) {
				continue;
			}
			$m = $linea['material'] ? MaterialesTable::obtener( (int) $linea['material'] ) : null;
			if ( $m && null === $m['baja_en'] && MaterialesTable::sumar( $m['id'], $cantidad ) ) {
				$res['sumados'][] = $m['id'];
				continue;
			}
			$tipo = $m ? $m['tipo'] : ( '' !== $linea['tipo'] ? TiposTable::por_nombre( $linea['tipo'] ) : 0 );
			$alta = MaterialesTable::crear(
				array(
					'descripcion' => mb_substr( $linea['descripcion'], 0, 255 ),
					'tipo'        => $tipo,
					'cantidad'    => min( $cantidad, MaterialesTable::MAX_CANTIDAD ),
					'precio'      => $linea['precio'] ?? ( $m['precio'] ?? null ),
					'anio'        => (int) wp_date( 'Y' ),
					'num_serie'   => '',
					// Lo que se guarda va en castellano (como los motivos y los conceptos).
					'notas'       => sprintf( 'Comprado con la lista de compra %s.', $num ),
					'modelo'      => $m ? $m['modelo'] : '',
					'talla'       => $m ? $m['talla'] : '',
				)
			);
			if ( $alta ) {
				$res['creados'][]     = $alta;
				$lineas[ $i ]['alta'] = $alta;
			}
		}
		if ( $res['creados'] ) {
			ComprasTable::guardar_lineas( $l['id'], $lineas );
		}
		Logger::log(
			'inventario_compra_comprada',
			'inventario_compra',
			(string) $l['id'],
			array(
				'sumados' => $res['sumados'],
				'creados' => $res['creados'],
			)
		);
		return $res;
	}

	/** @var array<int, string> Nombres ya buscados en esta petición. */
	private static array $nombres = array();

	/**
	 * Nombre de quien hizo algo: el de su ficha del CRM (la cuenta solo guarda el nº de
	 * socio como nombre) o, si no tiene ficha o el CRM no responde, su usuario. '' si la
	 * cuenta ya no existe. La ficha sale de la instantánea (doc 26): no añade consultas.
	 */
	public static function usuario( int $id ): string {
		if ( isset( self::$nombres[ $id ] ) ) {
			return self::$nombres[ $id ];
		}
		$u = $id ? get_userdata( $id ) : false;
		if ( ! $u ) {
			return self::$nombres[ $id ] = '';
		}
		$nombre = (string) $u->display_name;
		$socio  = \AsdeBalears\AreaPrivada\Usuarios\Usuarios::socio( $u->ID );
		$repo   = '' !== $socio ? \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make() : null;
		if ( $repo ) {
			try {
				$p      = $repo->obtener( $socio );
				$nombre = $p ? $p->nombre_completo() : $nombre;
			} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
				// CRM caído: se queda con el usuario.
			}
		}
		return self::$nombres[ $id ] = $nombre;
	}
}
