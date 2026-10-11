<?php
namespace AsdeBalears\AreaPrivada\Uniformes;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

/**
 * Pedidos de uniforme (petición del usuario, 2026-09-29).
 *
 * - Cualquier asociado pide la camisa scout (y/o insignias) para él o ella, o
 *   una familia para sus menores. Scouters incluidos.
 * - Tesorería valida el pago (efectivo o ingreso en cuenta).
 * - Coordinación valida que se ha incluido en el pedido del grupo al proveedor.
 * - El grupo la marca como entregada y el socio/a (o su familia) confirma que
 *   la ha recibido.
 *
 * Quién gestiona: Tesorería y Coordinación (y Secretaría, administradores) ven
 * todos los pedidos del grupo; un scouter, los de socios de sus secciones que no
 * son adultos, y los suyos.
 *
 * v0.30 (P-48): un scouter puede pedir también para cualquier socio/a con membresía
 * activa de sus secciones (en «Mi uniforme»); el precio de la camisa es el de la persona
 * para la que es (precio_camisa()).
 */
final class Servicio {

	/** v0.30: con más personas que estas, «¿Para quién?» es un desplegable agrupado por sección. */
	public const MAX_BOTONES_PARA = 5;

	public const CAP_PEDIR   = 'asde_uniformes_pedir';
	public const CAP_GESTION = 'asde_uniformes_gestion';
	public const CAP_PAGO    = 'asde_uniformes_pago';
	public const CAP_PEDIDO  = 'asde_uniformes_pedido';
	public const CAP_ENTREGA = 'asde_uniformes_entrega';
	public const CAP_CONFIG  = 'asde_uniformes_config';

	// ---------------------------------------------------------------------
	// Qué se pide y cuánto cuesta
	// ---------------------------------------------------------------------

	/**
	 * Líneas del pedido a partir del formulario, con los precios de hoy.
	 *
	 * @param string              $modelo_talla «modelo|talla» o '' (sin camisa).
	 * @param array<string, int>  $cantidades   id de insignia => cantidad.
	 * @return array{camisa:?array, insignias:array}|\WP_Error
	 */
	public static function lineas( array $cfg, string $modelo_talla, array $cantidades ) {
		$camisa = null;
		if ( '' !== $modelo_talla ) {
			list( $modelo, $talla ) = array_pad( explode( '|', $modelo_talla, 2 ), 2, '' );
			if ( ! Config::talla_valida( $cfg, $modelo, $talla ) ) {
				return new \WP_Error( 'asde_uniforme', asde_t( 'Elige el modelo y la talla de la camisa.' ) );
			}
			$camisa = array(
				'modelo'   => $modelo,
				'nombre'   => $cfg['modelos'][ $modelo ]['nombre'],
				'talla'    => $talla,
				'especial' => Config::es_especial( $cfg, $talla ),
				'precio'   => $cfg['precio_camisa'],
			);
		}
		$insignias = array();
		$activas   = Config::insignias_activas( $cfg );
		foreach ( $cantidades as $id => $cantidad ) {
			$cantidad = (int) $cantidad;
			if ( $cantidad <= 0 ) {
				continue;
			}
			if ( ! isset( $activas[ $id ] ) || $cantidad > Config::MAX_INSIGNIAS ) {
				return new \WP_Error( 'asde_uniforme', sprintf( asde_t( 'Revisa las insignias: alguna no está disponible o la cantidad no es válida (máximo %d).' ), Config::MAX_INSIGNIAS ) );
			}
			$insignias[] = array(
				'id'       => (string) $id,
				'nombre'   => $activas[ $id ]['nombre'],
				'cantidad' => $cantidad,
				'precio'   => $activas[ $id ]['precio'],
			);
		}
		if ( ! $camisa && ! $insignias ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Elige la camisa, alguna insignia o las dos cosas.' ) );
		}
		return array(
			'camisa'    => $camisa,
			'insignias' => $insignias,
		);
	}

	/**
	 * Importe del pedido. Las insignias que se pidieron con el precio «por
	 * confirmar» toman el precio de hoy, si ya lo tienen.
	 *
	 * @return array{total:float, provisional:bool}
	 */
	public static function total( array $lineas, array $cfg ): array {
		$total       = (float) ( $lineas['camisa']['precio'] ?? 0 );
		$provisional = false;
		foreach ( $lineas['insignias'] as $i ) {
			$precio = $i['precio'] ?? ( $cfg['insignias'][ $i['id'] ]['precio'] ?? null );
			if ( null === $precio ) {
				$provisional = true;
				continue;
			}
			$total += (int) $i['cantidad'] * (float) $precio;
		}
		return array(
			'total'       => round( $total, 2 ),
			'provisional' => $provisional,
		);
	}

	/** «Camisa masculina, talla M · Insignia de grupo ×2». */
	public static function descripcion( array $lineas ): string {
		$partes = array();
		if ( $lineas['camisa'] ) {
			$partes[] = sprintf( '%s, talla %s%s', $lineas['camisa']['nombre'], $lineas['camisa']['talla'], ! empty( $lineas['camisa']['especial'] ) ? ' (especial)' : '' );
		}
		foreach ( $lineas['insignias'] as $i ) {
			$partes[] = $i['nombre'] . ( (int) $i['cantidad'] > 1 ? ' ×' . (int) $i['cantidad'] : '' );
		}
		return implode( ' · ', $partes );
	}

	public static function importe( array $p ): string {
		return Calculadora::euros( $p['total'] ) . ( $p['provisional'] ? asde_t( ' + insignias (precio por confirmar)' ) : '' );
	}

	// ---------------------------------------------------------------------
	// Quién
	// ---------------------------------------------------------------------

	/**
	 * La propia persona y sus menores (sin consultar el CRM: ya están en el ámbito). Basta para
	 * saber si la cuenta pide uniforme (el menú y las pestañas).
	 *
	 * @return array<string, Persona>
	 */
	public static function propios( Ambito $a ): array {
		$out = array();
		if ( $a->persona && $a->socio_activo() ) {
			$out[ $a->persona->num_socio_asde ] = $a->persona;
		}
		foreach ( $a->representados as $n => $p ) {
			$out[ (string) $n ] = $p;
		}
		return $out;
	}

	/**
	 * Para quién puede pedir esta cuenta: la propia persona y sus menores y, si es scouter
	 * (v0.30, P-48), los socios/as con membresía activa de sus secciones.
	 *
	 * @return array<string, Persona> nº => persona (primero los propios; después, por sección y apellidos).
	 */
	public static function para_quien( Ambito $a ): array {
		return self::propios( $a ) + self::de_mis_secciones( $a );
	}

	/**
	 * Socios/as con membresía activa de las secciones del scouter (v0.30, P-48). Salen de
	 * PersonaRepository::todas(), que se sirve de la instantánea (doc 26): una lectura para
	 * todo el grupo, nunca una consulta al CRM por persona.
	 *
	 * @return array<string, Persona>
	 */
	public static function de_mis_secciones( Ambito $a ): array {
		$mias = $a->secciones_scouter();
		$repo = $mias ? PersonaRepository::make() : null;
		if ( ! $repo ) {
			return array();
		}
		try {
			$todas = $repo->todas();
		} catch ( CrmException $e ) {
			return array(); // CRM caído: puede seguir pidiendo para los suyos.
		}
		$out = array();
		foreach ( $todas as $p ) {
			if ( $p->tiene_membresia_activa() && array_intersect( $p->secciones, $mias ) ) {
				$out[ $p->num_socio_asde ] = $p;
			}
		}
		return $out;
	}

	/**
	 * «¿Para quién?» agrupado (v0.30): «Tú», «Tu familia» y una sección del scouter para cada
	 * una de las demás personas (la primera de sus secciones que lleva el scouter), en el orden
	 * de las secciones.
	 *
	 * @param array<string, Persona> $para Lo que devuelve para_quien().
	 * @return array<int, array{etiqueta:string, personas:array<string, Persona>}>
	 */
	public static function agrupar_para_quien( Ambito $a, array $para ): array {
		$mias   = $a->secciones_scouter();
		$propia = array();
		$fam    = array();
		$secc   = array();
		foreach ( $para as $n => $p ) {
			$n = (string) $n;
			if ( $a->persona && $a->persona->num_socio_asde === $n ) {
				$propia[ $n ] = $p;
			} elseif ( isset( $a->representados[ $n ] ) ) {
				$fam[ $n ] = $p;
			} else {
				$s                = (string) ( array_values( array_intersect( $p->secciones, $mias ) )[0] ?? '' );
				$secc[ $s ][ $n ] = $p;
			}
		}
		$orden = array_flip( array_keys( Secciones::all() ) );
		uksort( $secc, static fn( $x, $y ) => ( $orden[ $x ] ?? 99 ) <=> ( $orden[ $y ] ?? 99 ) );
		$out = array();
		if ( $propia ) {
			$out[] = array(
				'etiqueta' => asde_t( 'Tú' ),
				'personas' => $propia,
			);
		}
		if ( $fam ) {
			$out[] = array(
				'etiqueta' => asde_t( 'Tu familia' ),
				'personas' => $fam,
			);
		}
		foreach ( $secc as $s => $personas ) {
			$out[] = array(
				'etiqueta' => '' !== $s ? Secciones::nombre( $s ) : asde_t( 'Sin sección' ),
				'personas' => $personas,
			);
		}
		return $out;
	}

	public static function es_propio( array $p, Ambito $a ): bool {
		return ( $a->persona && $a->persona->num_socio_asde === $p['socio'] ) || isset( $a->representados[ $p['socio'] ] );
	}

	/** ¿Lo pidió esta cuenta? (v0.30: un scouter para alguien de su sección.) */
	public static function lo_pidio( array $p ): bool {
		return get_current_user_id() > 0 && get_current_user_id() === (int) $p['creado_por'];
	}

	/** ¿Ve todos los pedidos del grupo? Tesorería, Coordinación, Secretaría y administradores. */
	public static function vision_global( Ambito $a ): bool {
		return $a->vision_global() || current_user_can( self::CAP_PAGO ) || current_user_can( self::CAP_PEDIDO );
	}

	/** ¿Lo gestiona? Global, o un scouter con los de sus secciones que no son adultos, y los suyos. */
	public static function en_gestion( array $p, Ambito $a ): bool {
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			return false;
		}
		if ( self::vision_global( $a ) || self::es_propio( $p, $a ) ) {
			return true;
		}
		return ! $p['adulto'] && (bool) array_intersect( $p['secciones'], $a->secciones_scouter() );
	}

	public static function puede_pagar( array $p ): bool {
		return current_user_can( self::CAP_PAGO ) && null === $p['cancelado_en'];
	}

	public static function puede_pedir( array $p ): bool {
		return current_user_can( self::CAP_PEDIDO ) && null === $p['cancelado_en'];
	}

	public static function puede_entregar( array $p, Ambito $a ): bool {
		return current_user_can( self::CAP_ENTREGA ) && null === $p['cancelado_en'] && self::en_gestion( $p, $a );
	}

	/** El socio/a o su familia (o quien lo pidió, v0.30) cancela el suyo mientras nadie haya validado nada. */
	public static function puede_cancelar_propio( array $p, Ambito $a ): bool {
		return ( self::es_propio( $p, $a ) || self::lo_pidio( $p ) ) && null === $p['cancelado_en'] && null === $p['pago_en'] && null === $p['pedido_en'] && null === $p['entregado_en'];
	}

	/**
	 * Cancela un pedido (v0.29): si su camisa había salido del inventario y no se ha entregado,
	 * vuelve a él. Lo usan la familia («Mi uniforme») y la gestión.
	 */
	public static function cancelar( array $p, string $motivo ): bool {
		if ( (int) $p['inventario_id'] && null === $p['entregado_en'] ) {
			MaterialesTable::devolver_camisa( (int) $p['inventario_id'], (string) ( $p['lineas']['camisa']['modelo'] ?? '' ), (string) ( $p['lineas']['camisa']['talla'] ?? '' ) );
			PedidosTable::quitar_camisa_servida( $p['id'], false );
		}
		return PedidosTable::cancelar( $p['id'], $motivo );
	}

	/** Tesorería o Coordinación cancelan cualquiera hasta que se entregue. */
	public static function puede_cancelar_gestion( array $p ): bool {
		return ( current_user_can( self::CAP_PAGO ) || current_user_can( self::CAP_PEDIDO ) ) && null === $p['cancelado_en'] && null === $p['entregado_en'];
	}

	/** Confirmar que se ha recibido: solo el socio/a o su familia, cuando el grupo la ha entregado. */
	public static function puede_confirmar( array $p, Ambito $a ): bool {
		return self::es_propio( $p, $a ) && null === $p['cancelado_en'] && null !== $p['entregado_en'] && null === $p['recibido_en'];
	}

	/** @return array[] Pedidos que gestiona este ámbito. */
	public static function de_gestion( Ambito $a ): array {
		return array_values( array_filter( PedidosTable::todos(), static fn( array $p ) => self::en_gestion( $p, $a ) ) );
	}

	/**
	 * Lo que le toca hacer (para las pestañas y el inicio), sin consultar el CRM.
	 *
	 * @return array{pago:int, pedido:int, entrega:int, confirmar:int}
	 */
	public static function pendientes( Ambito $a ): array {
		$out = array(
			'pago'      => 0,
			'pedido'    => 0,
			'entrega'   => 0,
			'confirmar' => 0,
		);
		$pagar   = current_user_can( self::CAP_PAGO );
		$pedir   = current_user_can( self::CAP_PEDIDO );
		$entrega = current_user_can( self::CAP_ENTREGA );
		foreach ( PedidosTable::todos() as $p ) {
			if ( null !== $p['cancelado_en'] ) {
				continue;
			}
			if ( self::puede_confirmar( $p, $a ) ) {
				++$out['confirmar'];
			}
			if ( ! self::en_gestion( $p, $a ) ) {
				continue;
			}
			$out['pago']    += (int) ( $pagar && null === $p['pago_en'] );
			$out['pedido']  += (int) ( $pedir && null === $p['pedido_en'] && null === $p['entregado_en'] );
			$out['entrega'] += (int) ( $entrega && null !== $p['pedido_en'] && null === $p['entregado_en'] );
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Acciones
	// ---------------------------------------------------------------------

	/** @return int|\WP_Error Id del pedido. */
	public static function pedir( Ambito $a, string $socio, array $lineas, string $nota ) {
		$cfg = Config::de();
		if ( ! $cfg['abierto'] ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Ahora mismo no se aceptan pedidos de uniforme.' ) );
		}
		$p = self::para_quien( $a )[ $socio ] ?? null;
		if ( ! $p ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'No puedes hacer un pedido para esa persona.' ) );
		}
		if ( $lineas['camisa'] ) {
			// El de la persona para la que es (v0.30: también si lo pide un scouter de su sección).
			$lineas['camisa']['precio'] = self::precio_camisa( $p->num_socio_asde, $p->es_adulto_voluntario(), $cfg );
		}
		$t  = self::total( $lineas, $cfg );
		$id = PedidosTable::crear( $p->num_socio_asde, $p->secciones, $p->es_adulto_voluntario(), $lineas, $t['total'], $t['provisional'], $nota );
		if ( ! $id ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		// La primera camisa de un scouter, sin nada más que pagar: queda pagada «sin coste».
		if ( $lineas['camisa'] && 0.0 === (float) $lineas['camisa']['precio'] && 0.0 === (float) $t['total'] && ! $t['provisional'] ) {
			PedidosTable::marcar_pago( $id, wp_date( 'Y-m-d' ), '', 0.0, PedidosTable::REF_SIN_COSTE );
		}
		Logger::log(
			'uniforme_pedido',
			'uniforme',
			(string) $id,
			array(
				'camisa'     => (bool) $lineas['camisa'],
				'insignias'  => count( $lineas['insignias'] ),
				'total'      => $t['total'],
				'para_otros' => ! isset( self::propios( $a )[ $socio ] ), // v0.30: un scouter para alguien de su sección.
			)
		);
		return $id;
	}

	// ---------------------------------------------------------------------
	// Camisas del inventario (v0.29)
	// ---------------------------------------------------------------------

	/**
	 * ¿Puede servir y asignar camisas del inventario? Coordinación (incluye los pedidos en el pedido
	 * al proveedor) y Tesorería (valida los pagos), decisión del usuario (P-100), si tienen el
	 * inventario (el módulo activo en el grupo).
	 */
	public static function puede_inventario(): bool {
		return ( current_user_can( self::CAP_PEDIDO ) || current_user_can( self::CAP_PAGO ) ) && current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP );
	}

	/** ¿Se puede servir del inventario la camisa de este pedido? (Con camisa, en curso y aún sin pedir al proveedor.) */
	public static function se_puede_servir( array $p ): bool {
		return self::puede_inventario() && null === $p['cancelado_en'] && null === $p['entregado_en'] && null === $p['pedido_en']
			&& ! empty( $p['lineas']['camisa'] ) && 0 === (int) $p['inventario_id'];
	}

	/** ¿Se puede devolver al inventario la camisa de este pedido? (Salió de él y aún no se ha entregado.) */
	public static function se_puede_devolver( array $p ): bool {
		return self::puede_inventario() && (int) $p['inventario_id'] > 0 && null === $p['entregado_en'];
	}

	/** La camisa del pedido sale del inventario: se descuenta una de su modelo y talla. @return true|\WP_Error */
	public static function servir( array $p ) {
		if ( ! self::se_puede_servir( $p ) ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'La camisa de este pedido no se puede servir del inventario.' ) );
		}
		$c        = $p['lineas']['camisa'];
		$material = MaterialesTable::descontar_camisa( (string) $c['modelo'], (string) $c['talla'] );
		if ( ! $material ) {
			/* translators: 1: modelo de camisa, 2: talla */
			return new \WP_Error( 'asde_uniforme', sprintf( asde_t( 'No queda ninguna camisa «%1$s» de la talla %2$s en el inventario.' ), $c['nombre'], $c['talla'] ) );
		}
		if ( ! PedidosTable::servir_camisa( $p['id'], $material, ! $p['lineas']['insignias'] ) ) {
			MaterialesTable::devolver_camisa( $material, (string) $c['modelo'], (string) $c['talla'] );
			return new \WP_Error( 'asde_uniforme', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		Logger::log( 'uniforme_servido_inventario', 'uniforme', (string) $p['id'], array( 'material' => $material ) );
		return true;
	}

	/** La camisa vuelve al inventario (y el pedido, si solo era la camisa, deja de constar como incluido). @return true|\WP_Error */
	public static function devolver( array $p ) {
		if ( ! self::se_puede_devolver( $p ) ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'La camisa de este pedido no se puede devolver al inventario.' ) );
		}
		$c        = (array) $p['lineas']['camisa'];
		$material = MaterialesTable::devolver_camisa( (int) $p['inventario_id'], (string) ( $c['modelo'] ?? '' ), (string) ( $c['talla'] ?? '' ) );
		PedidosTable::quitar_camisa_servida( $p['id'], PedidosTable::REF_INVENTARIO === $p['pedido_ref'] );
		Logger::log( 'uniforme_devuelto_inventario', 'uniforme', (string) $p['id'], array( 'material' => $material ) );
		return true;
	}

	/**
	 * Asigna una camisa del inventario a quien no la ha pedido: crea su pedido, ya servido. El
	 * precio, el de siempre (precio_camisa(): la primera de un scouter, gratis, y queda pagada
	 * «sin coste»); lo demás lo valida Tesorería como siempre.
	 *
	 * @return int|\WP_Error Id del pedido.
	 */
	public static function asignar( Persona $persona, string $modelo, string $talla, string $nota ) {
		$cfg   = Config::de();
		$talla = Config::talla_de( $cfg, $modelo, $talla );
		if ( ! self::puede_inventario() ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Servir y asignar camisas del inventario lo hacen Coordinación y Tesorería.' ) );
		}
		if ( '' === $talla ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Elige el modelo y la talla de la camisa.' ) );
		}
		$material = MaterialesTable::descontar_camisa( $modelo, $talla );
		if ( ! $material ) {
			/* translators: 1: modelo de camisa, 2: talla */
			return new \WP_Error( 'asde_uniforme', sprintf( asde_t( 'No queda ninguna camisa «%1$s» de la talla %2$s en el inventario.' ), $cfg['modelos'][ $modelo ]['nombre'], $talla ) );
		}
		$importe = self::precio_camisa( $persona->num_socio_asde, $persona->es_adulto_voluntario(), $cfg );
		$lineas  = array(
			'camisa'    => array(
				'modelo'   => $modelo,
				'nombre'   => $cfg['modelos'][ $modelo ]['nombre'],
				'talla'    => $talla,
				'especial' => Config::es_especial( $cfg, $talla ),
				'precio'   => $importe,
			),
			'insignias' => array(),
		);
		$id = PedidosTable::crear( $persona->num_socio_asde, $persona->secciones, $persona->es_adulto_voluntario(), $lineas, $importe, false, $nota );
		if ( ! $id ) {
			MaterialesTable::devolver_camisa( $material, $modelo, $talla );
			return new \WP_Error( 'asde_uniforme', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		PedidosTable::servir_camisa( $id, $material, true );
		if ( 0.0 === $importe ) {
			PedidosTable::marcar_pago( $id, wp_date( 'Y-m-d' ), '', 0.0, PedidosTable::REF_SIN_COSTE );
		}
		Logger::log(
			'uniforme_asignado_inventario',
			'uniforme',
			(string) $id,
			array(
				'material' => $material,
				'importe'  => $importe,
			)
		);
		return $id;
	}

	/**
	 * Precio de la camisa para esta persona (v0.29, petición del usuario): el de la configuración
	 * (25 € por defecto) para los asociados; a un scouter (o voluntario adulto) la primera es gratis
	 * y desde la segunda paga el precio. Cuentan sus pedidos con camisa que no se han cancelado.
	 */
	public static function precio_camisa( string $socio, bool $adulto, array $cfg, int $salvo = 0 ): float {
		if ( $adulto && ! empty( $cfg['primera_gratis'] ) && ! self::tiene_camisa( $socio, $salvo ) ) {
			return 0.0;
		}
		return (float) $cfg['precio_camisa'];
	}

	/** ¿Tiene ya algún pedido con camisa (no cancelado)? $salvo: un pedido que no cuenta. */
	public static function tiene_camisa( string $socio, int $salvo = 0 ): bool {
		return self::con_camisa( PedidosTable::de_socios( array( $socio ) ), $socio, $salvo );
	}

	/** ¿Hay, entre estos pedidos, alguno con camisa (no cancelado) de este socio/a? */
	private static function con_camisa( array $pedidos, string $socio, int $salvo = 0 ): bool {
		foreach ( $pedidos as $p ) {
			if ( $socio === (string) $p['socio'] && $p['id'] !== $salvo && null === $p['cancelado_en'] && ! empty( $p['lineas']['camisa'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Precio de la camisa para cada persona de «¿Para quién?» (v0.30), con los pedidos de todas
	 * ya leídos (una consulta para todas, no una por persona). Misma regla que precio_camisa().
	 *
	 * @param array<string, Persona> $para
	 * @param array[]                $pedidos Los de esas personas (PedidosTable::de_socios).
	 * @return array<string, float>
	 */
	public static function precios_camisa( array $para, array $cfg, array $pedidos ): array {
		$out = array();
		foreach ( $para as $n => $persona ) {
			$n         = (string) $n;
			$out[ $n ] = $persona->es_adulto_voluntario() && ! empty( $cfg['primera_gratis'] ) && ! self::con_camisa( $pedidos, $n ) ? 0.0 : (float) $cfg['precio_camisa'];
		}
		return $out;
	}

	/** Importe que Tesorería propone al validar el pago: el total con los precios de hoy. */
	public static function importe_a_cobrar( array $p ): float {
		return self::total( $p['lineas'], Config::de() )['total'];
	}

	/**
	 * Resumen para el pedido al proveedor: camisas por modelo y talla, e insignias.
	 *
	 * @param array[] $pedidos
	 * @return array{camisas: array<string, array{nombre:string, tallas: array<string, int>}>, insignias: array<string, array{nombre:string, cantidad:int}>, total_camisas:int}
	 */
	public static function resumen( array $pedidos, array $cfg ): array {
		$camisas   = array();
		$insignias = array();
		$n         = 0;
		foreach ( $cfg['modelos'] as $id => $m ) {
			$camisas[ $id ] = array(
				'nombre' => $m['nombre'],
				'tallas' => array_fill_keys( $m['tallas'], 0 ),
			);
		}
		foreach ( $pedidos as $p ) {
			$c = $p['lineas']['camisa'];
			// La camisa que sale del inventario (v0.29) no se pide al proveedor.
			if ( $c && empty( $p['inventario_id'] ) ) {
				$camisas[ $c['modelo'] ]['nombre']                   ??= $c['nombre'];
				$camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] = ( $camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] ?? 0 ) + 1;
				++$n;
			}
			foreach ( $p['lineas']['insignias'] as $i ) {
				$insignias[ $i['id'] ]['nombre']   = $i['nombre'];
				$insignias[ $i['id'] ]['cantidad'] = ( $insignias[ $i['id'] ]['cantidad'] ?? 0 ) + (int) $i['cantidad'];
			}
		}
		return array(
			'camisas'       => $camisas,
			'insignias'     => $insignias,
			'total_camisas' => $n,
		);
	}
}
