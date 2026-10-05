<?php
namespace AsdeBalears\AreaPrivada\Inventario;

defined( 'ABSPATH' ) || exit;

/**
 * Inventario de material del grupo y listas de compra (petición del usuario,
 * 2026-10-05, doc 32).
 *
 * - Entran los scouters (con o sin sección) y Secretaría, Coordinación y
 *   Tesorería: ven y editan el material y los tipos y crean listas de compra.
 * - Una lista de compra nace pendiente y se avisa por email a Tesorería; hasta
 *   que Tesorería le da el visto bueno no se puede descargar el PDF para imprimirla.
 */
final class Servicio {

	public const CAP         = 'asde_inventario';
	public const CAP_APROBAR = 'asde_inventario_aprobar';

	/** Por debajo de esta cantidad, al añadir un material a la lista sale el aviso «¿seguro?». */
	public const UMBRAL = 5;

	public static function umbral(): int {
		/**
		 * Cantidad a partir de la cual ya no se avisa al añadir un material a una lista de compra.
		 *
		 * @param int $umbral Por defecto, 5.
		 */
		return max( 0, (int) apply_filters( 'asde_ap_inventario_umbral', self::UMBRAL ) );
	}

	public static function pocas_unidades( int $cantidad ): bool {
		return $cantidad < self::umbral();
	}

	/**
	 * Líneas de una lista a partir del formulario «Nueva lista».
	 *
	 * @param array<int|string, string> $cantidades id de material => cantidad a comprar.
	 * @param array<int|string, string> $precios    id de material => precio estimado ('' = sin precio).
	 * @return array[]|\WP_Error
	 */
	public static function lineas( array $cantidades, array $precios ) {
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

	/** Sin el visto bueno de Tesorería no se imprime. */
	public static function puede_imprimir( array $l ): bool {
		return current_user_can( self::CAP ) && ComprasTable::APROBADA === $l['estado'];
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
