<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

/**
 * Pedidos de la tienda (doc 48 y doc 52).
 *
 * - Cualquier asociado compra para él o ella, o una familia para sus menores; quien no
 *   tiene sesión compra desde la tienda pública.
 * - Tesorería valida el pago (efectivo, ingreso en cuenta o Bizum).
 * - Coordinación valida que se ha incluido en el pedido del grupo al proveedor.
 * - El grupo la marca como entregada y el socio/a (o su familia) confirma que
 *   la ha recibido.
 *
 * Quién gestiona: Tesorería y Coordinación (y Secretaría, administradores) ven
 * todos los pedidos del grupo; un scouter, los de socios de sus secciones que no
 * son adultos, y los suyos.
 *
 * v0.44 (doc 52, T1): el uniforme es una venta más. La camisa y las insignias son productos
 * normales, con las existencias de la tienda: ya no hay reglas de precio propias (primera
 * camisa gratis), ni salen del Inventario general, ni un scouter pide para su sección. Los
 * pedidos hechos antes (con `camisa` e `insignias` en sus líneas) se siguen viendo.
 */
final class Servicio {

	public const CAP_PEDIR   = 'asde_tienda_comprar';
	public const CAP_GESTION = 'asde_tienda_gestion';
	public const CAP_PAGO    = 'asde_tienda_pago';
	public const CAP_PEDIDO  = 'asde_tienda_pedido';
	public const CAP_ENTREGA = 'asde_tienda_entrega';
	public const CAP_CONFIG  = 'asde_tienda_admin';
	/** v0.39 (doc 48): quien administra la tienda (productos, existencias y configuración): Coordinación, Tesorería y Secretaría. */
	public const CAP_ADMIN   = 'asde_tienda_admin';

	// ---------------------------------------------------------------------
	// Qué se pide y cuánto cuesta
	// ---------------------------------------------------------------------

	/**
	 * Importe del pedido. Las insignias que se pidieron con el precio «por
	 * confirmar» toman el precio de hoy, si ya lo tienen.
	 *
	 * @return array{total:float, provisional:bool}
	 */
	public static function total( array $lineas, array $cfg ): array {
		$total       = (float) ( $lineas['camisa']['precio'] ?? 0 );
		$provisional = false;
		foreach ( (array) ( $lineas['productos'] ?? array() ) as $l ) {
			if ( null === ( $l['precio'] ?? null ) ) {
				$provisional = true; // v0.39: un producto «por confirmar»; Tesorería cobra el importe real.
				continue;
			}
			$total += (int) $l['cantidad'] * (float) $l['precio'];
		}
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
		foreach ( (array) ( $lineas['productos'] ?? array() ) as $l ) {
			$partes[] = $l['nombre'] . ( '' !== (string) ( $l['etiqueta'] ?? '' ) && 'Única' !== $l['etiqueta'] ? ' (' . $l['etiqueta'] . ')' : '' ) . ( (int) $l['cantidad'] > 1 ? ' ×' . (int) $l['cantidad'] : '' );
		}
		return implode( ' · ', $partes );
	}

	// ---------------------------------------------------------------------
	// La tienda (v0.39, doc 48, fase 3): catálogo, carrito y pago
	// ---------------------------------------------------------------------

	/**
	 * Lo que ve quien compra: los productos visibles para él o ella (el filtro «solo socios» está en la consulta)
	 * con sus variantes activas.
	 *
	 * @return array<int, array{producto:array, variantes:array[]}>
	 */
	public static function catalogo( bool $es_socio ): array {
		$productos = ProductosTable::visibles( $es_socio );
		$variantes = VariantesTable::de_productos( array_column( $productos, 'id' ) );
		$out       = array();
		foreach ( $productos as $p ) {
			$activas = array_values( array_filter( $variantes[ $p['id'] ] ?? array(), static fn( array $v ) => 1 === $v['activo'] ) );
			if ( $activas ) {
				$out[] = array(
					'producto'  => $p,
					'variantes' => $activas,
				);
			}
		}
		return $out;
	}

	/** Precio de una variante: el suyo, si lo tiene; si no, el del producto (con el de socio, si procede). null = por confirmar. */
	public static function precio_variante( array $producto, array $variante, bool $es_socio ): ?float {
		return null !== $variante['precio'] ? $variante['precio'] : ProductosTable::precio_para( $producto, $es_socio );
	}

	/** Unidades de un producto que ya tiene pedidas esta persona (pedidos no cancelados). */
	public static function ya_pedidas( string $socio, int $producto ): int {
		$n = 0;
		foreach ( PedidosTable::de_socios( array( $socio ) ) as $p ) {
			if ( null !== $p['cancelado_en'] ) {
				continue;
			}
			foreach ( $p['lineas']['productos'] as $l ) {
				if ( (int) $l['producto'] === $producto ) {
					$n += (int) $l['cantidad'];
				}
			}
		}
		return $n;
	}

	/**
	 * Líneas del pedido del catálogo a partir de las cantidades del formulario, con los precios del servidor
	 * (nada de precios que vengan del navegador). Comprueba la visibilidad, las variantes activas, el límite por
	 * persona y que queden unidades; todavía no las reserva.
	 *
	 * @param array<int|string, int|string> $cantidades id de variante => cantidad.
	 * @return array[]|\WP_Error
	 */
	public static function lineas_tienda( bool $es_socio, array $cantidades, string $socio ) {
		$lineas = array();
		foreach ( $cantidades as $id => $cantidad ) {
			$cantidad = (int) $cantidad;
			if ( $cantidad <= 0 ) {
				continue;
			}
			$v = VariantesTable::obtener( (int) $id );
			$p = $v ? ProductosTable::obtener( $v['producto'] ) : null;
			if ( ! $v || ! $p || 1 !== $v['activo'] || ! ProductosTable::visible_para( $p, $es_socio ) ) {
				return new \WP_Error( 'asde_tienda', asde_t( 'Alguno de los productos elegidos ya no está disponible. Revisa tu selección.' ) );
			}
			if ( $cantidad > ProductosTable::MAX_LIMITE ) {
				return new \WP_Error( 'asde_tienda', asde_t( 'La cantidad no es válida.' ) );
			}
			if ( $p['limite_persona'] > 0 && ( '' === $socio ? 0 : self::ya_pedidas( $socio, $p['id'] ) ) + $cantidad > $p['limite_persona'] ) {
				/* translators: 1: producto, 2: máximo por persona */
				return new \WP_Error( 'asde_tienda', sprintf( asde_t( 'De «%1$s» solo se pueden pedir %2$d por persona.' ), $p['nombre'], $p['limite_persona'] ) );
			}
			if ( ! VariantesTable::hay( $v, $cantidad ) ) {
				/* translators: %s: producto */
				return new \WP_Error( 'asde_tienda', sprintf( asde_t( 'No quedan unidades suficientes de «%s».' ), $p['nombre'] ) );
			}
			$lineas[] = array(
				'producto'  => $p['id'],
				'variante'  => $v['id'],
				'nombre'    => $p['nombre'],
				'etiqueta'  => $v['etiqueta'],
				'cantidad'  => $cantidad,
				'precio'    => self::precio_variante( $p, $v, $es_socio ),
				'proveedor' => (int) $p['requiere_proveedor'],
				'reservado' => false,
			);
		}
		if ( ! $lineas ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Elige al menos un producto.' ) );
		}
		return $lineas;
	}

	/**
	 * Forma de pago elegida: transferencia o Bizum para cualquiera; «en físico» (a Tesorería) solo para socios/as.
	 *
	 * @return string|\WP_Error La forma, o '' si no se ha elegido y no hace falta.
	 */
	public static function forma_valida( string $forma, bool $es_socio, bool $obligatoria ) {
		if ( '' === $forma ) {
			return $obligatoria ? new \WP_Error( 'asde_tienda', asde_t( 'Elige cómo vas a pagar.' ) ) : '';
		}
		if ( ! isset( PedidosTable::FORMAS[ $forma ] ) ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Elige cómo vas a pagar.' ) );
		}
		if ( 'fisico' === $forma && ! $es_socio ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Pagar en físico a Tesorería solo está disponible para socios y socias.' ) );
		}
		return $forma;
	}

	/**
	 * Compra del catálogo: reserva las existencias (una sentencia por variante, sin vender dos veces lo mismo) y
	 * crea el pedido. Si algo falla, devuelve lo reservado.
	 *
	 * @param array<int|string, int|string> $cantidades id de variante => cantidad.
	 * @return int|\WP_Error Id del pedido.
	 */
	public static function comprar( Ambito $a, string $socio, array $cantidades, string $nota, string $forma_pago ) {
		$cfg = Config::de();
		if ( ! $cfg['abierto'] ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Ahora mismo no se aceptan pedidos.' ) );
		}
		$persona = self::para_quien( $a )[ $socio ] ?? null;
		if ( ! $persona ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'No puedes hacer un pedido para esa persona.' ) );
		}
		$es_socio = $persona->tiene_membresia_activa();
		$forma    = self::forma_valida( $forma_pago, $es_socio, true );
		if ( is_wp_error( $forma ) ) {
			return $forma;
		}
		$productos = self::lineas_tienda( $es_socio, $cantidades, $socio );
		if ( is_wp_error( $productos ) ) {
			return $productos;
		}
		return self::registrar( $productos, $persona, array(), $nota, $forma, $cfg, ! isset( self::propios( $a )[ $socio ] ) );
	}

	/**
	 * Compra sin sesión (fase 4, P-174): quien compra deja nombre, email y teléfono (se guardan cifrados) y paga por
	 * transferencia o Bizum; «en físico» y lo «solo socios» no existen para él o ella.
	 *
	 * @param array{nombre:string, email:string, telefono:string, idioma:string} $datos
	 * @param array<int|string, int|string>                                      $cantidades id de variante => cantidad.
	 * @return int|\WP_Error Id del pedido.
	 */
	public static function comprar_invitado( array $datos, array $cantidades, string $nota, string $forma_pago ) {
		$cfg = Config::de();
		if ( ! $cfg['abierto'] || ! $cfg['publica'] ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Ahora mismo no se aceptan pedidos.' ) );
		}
		$nombre   = mb_substr( trim( sanitize_text_field( (string) ( $datos['nombre'] ?? '' ) ) ), 0, 80 );
		$email    = sanitize_email( (string) ( $datos['email'] ?? '' ) );
		$telefono = preg_replace( '/[^0-9+ ]/', '', (string) ( $datos['telefono'] ?? '' ) );
		if ( mb_strlen( $nombre ) < 2 || ! is_email( $email ) ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Escribe tu nombre y un email válido: ahí te mandamos el pedido.' ) );
		}
		$forma = self::forma_valida( $forma_pago, false, true );
		if ( is_wp_error( $forma ) ) {
			return $forma;
		}
		$productos = self::lineas_tienda( false, $cantidades, '' );
		if ( is_wp_error( $productos ) ) {
			return $productos;
		}
		$invitado = array(
			'nombre'   => $nombre,
			'email'    => $email,
			'telefono' => mb_substr( trim( (string) $telefono ), 0, 20 ),
			'idioma'   => Idioma::es_valido( $datos['idioma'] ?? null ) ? (string) $datos['idioma'] : Idioma::actual(),
		);
		return self::registrar( $productos, null, $invitado, $nota, $forma, $cfg, false );
	}

	/**
	 * Reserva las existencias y crea el pedido (de un socio/a o de alguien sin sesión). Si algo falla, devuelve lo reservado.
	 *
	 * @param array[]      $productos Líneas ya validadas (lineas_tienda).
	 * @param array<string, mixed> $invitado Datos de quien compra sin sesión (vacío si es una persona del CRM).
	 * @return int|\WP_Error
	 */
	private static function registrar( array $productos, ?Persona $persona, array $invitado, string $nota, string $forma, array $cfg, bool $para_otros ) {
		$hechas = array();
		foreach ( $productos as $i => $l ) {
			if ( ! VariantesTable::reservar( $l['variante'], $l['cantidad'] ) ) {
				self::devolver_lineas( $hechas );
				/* translators: %s: producto */
				return new \WP_Error( 'asde_tienda', sprintf( asde_t( 'No quedan unidades suficientes de «%s».' ), $l['nombre'] ) );
			}
			$productos[ $i ]['reservado'] = true;
			$hechas[]                     = $productos[ $i ];
		}
		$lineas = array(
			'camisa'    => null,
			'insignias' => array(),
			'productos' => $productos,
		);
		$t      = self::total( $lineas, $cfg );
		$id     = PedidosTable::crear( $persona ? $persona->num_socio_asde : '', $persona ? $persona->secciones : array(), $persona ? $persona->es_adulto_voluntario() : false, $lineas, $t['total'], $t['provisional'], $nota, $forma, $invitado );
		if ( ! $id ) {
			self::devolver_lineas( $hechas );
			return new \WP_Error( 'asde_tienda', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		// Lo que sale de las existencias no va al proveedor: queda como incluido.
		if ( ! array_filter( $productos, static fn( array $l ) => ! empty( $l['proveedor'] ) ) ) {
			PedidosTable::marcar_pedido( array( $id ), PedidosTable::REF_TIENDA );
		}
		// Sin nada que pagar (todo a 0 €): queda pagado «sin coste».
		if ( 0.0 === (float) $t['total'] && ! $t['provisional'] ) {
			PedidosTable::marcar_pago( $id, wp_date( 'Y-m-d' ), '', 0.0, PedidosTable::REF_GRATIS );
		}
		Logger::log(
			'tienda_pedido',
			'tienda',
			(string) $id,
			array(
				'lineas'     => count( $productos ),
				'total'      => $t['total'],
				'forma_pago' => $forma,
				'para_otros' => $para_otros,
				'invitado'   => (bool) $invitado,
			)
		);
		$pedido = PedidosTable::obtener( $id );
		if ( $pedido ) {
			Avisos::enviar( $pedido, Avisos::PEDIDO );
		}
		return $id;
	}

	/** Nombre de quien hizo el pedido para las listas de gestión: la persona del CRM, o el invitado. */
	public static function comprador( array $p, ?Persona $persona = null ): string {
		if ( $persona ) {
			return $persona->nombre_completo();
		}
		if ( '' === $p['socio'] ) {
			$i = PedidosTable::invitado( $p );
			return $i ? sprintf( asde_t( '%s (sin sesión)' ), (string) $i['nombre'] ) : asde_t( 'Compra sin sesión' );
		}
		return sprintf( asde_t( 'Nº %s' ), $p['socio'] );
	}
	/**
	 * Cancela las compras sin sesión que llevan más de «días sin pagar» días sin pagarse (P-179, 14 por defecto; 0 = nunca) y
	 * devuelve sus existencias. Solo las de invitados: las de los socios las decide la fase 5. Lo llama el mantenimiento diario.
	 *
	 * @return int Pedidos cancelados.
	 */
	public static function cancelar_invitados_sin_pagar(): int {
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::TIENDA, \AsdeBalears\AreaPrivada\Domain\Grupo::numero() ) ) {
			return 0;
		}
		$dias = Config::de()['dias_sin_pagar'];
		if ( $dias < 1 ) {
			return 0;
		}
		$n = 0;
		foreach ( PedidosTable::invitados_sin_pagar( $dias, \AsdeBalears\AreaPrivada\Domain\Grupo::numero() ) as $p ) {
			if ( self::cancelar( $p, 'Sin pagar en ' . $dias . ' días' ) ) {
				Logger::log( 'tienda_cancelado', 'tienda', (string) $p['id'], array( 'por' => 'caducidad' ) );
				Avisos::enviar( $p, Avisos::CANCELADO ); // v0.40: se le dice que se ha cancelado.
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Pedidos de la tienda de socios y familias sin pagar (v0.40, fase 5): a los «días para recordar» se les manda un recordatorio
	 * (una sola vez) y a los «días para cancelar» se cancelan solos y lo que pidieron vuelve a las existencias. 0 = no se hace.
	 * Lo llama el mantenimiento diario, dentro del grupo.
	 *
	 * @return array{recordados:int, cancelados:int}
	 */
	public static function recordar_y_cancelar_socios(): array {
		$out = array(
			'recordados' => 0,
			'cancelados' => 0,
		);
		$grupo = \AsdeBalears\AreaPrivada\Domain\Grupo::numero();
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::TIENDA, $grupo ) ) {
			return $out;
		}
		$cfg = Config::de();
		if ( $cfg['dias_cancelar_socios'] > 0 ) {
			foreach ( PedidosTable::socios_sin_pagar( $cfg['dias_cancelar_socios'], $grupo ) as $p ) {
				if ( self::cancelar( $p, 'Sin pagar en ' . $cfg['dias_cancelar_socios'] . ' días' ) ) {
					Logger::log( 'tienda_cancelado', 'tienda', (string) $p['id'], array( 'por' => 'caducidad' ) );
					Avisos::enviar( $p, Avisos::CANCELADO );
					++$out['cancelados'];
				}
			}
		}
		// El recordatorio, solo si llega antes de la cancelación (si no, el pedido ya no existe o se cancelaría el mismo día).
		if ( $cfg['dias_aviso_socios'] > 0 && ( 0 === $cfg['dias_cancelar_socios'] || $cfg['dias_aviso_socios'] < $cfg['dias_cancelar_socios'] ) ) {
			foreach ( PedidosTable::socios_sin_pagar( $cfg['dias_aviso_socios'], $grupo ) as $p ) {
				if ( PedidosTable::marcar_aviso( $p['id'] ) ) {
					Avisos::enviar( $p, Avisos::RECORDATORIO );
					++$out['recordados'];
				}
			}
		}
		return $out;
	}

	/** Devuelve a las existencias lo que se había reservado en estas líneas. @param array[] $lineas */
	public static function devolver_lineas( array $lineas ): void {
		foreach ( $lineas as $l ) {
			if ( ! empty( $l['reservado'] ) ) {
				VariantesTable::devolver( (int) $l['variante'], (int) $l['cantidad'] );
			}
		}
	}

	/**
	 * Cómo pagar este pedido, con los datos del grupo: una frase por cada dato que tenga el grupo configurado.
	 *
	 * @return string[]
	 */
	public static function instrucciones_pago( array $p, array $cfg ): array {
		$num = PedidosTable::numero( $p );
		switch ( $p['forma_pago'] ) {
			case 'transferencia':
				/* translators: 1: IBAN, 2: nº de pedido */
				return '' !== $cfg['iban'] ? array( sprintf( asde_t( 'Transferencia a la cuenta %1$s, indicando como concepto el nº de pedido %2$s.' ), $cfg['iban'], $num ) ) : array( sprintf( asde_t( 'Transferencia a la cuenta del grupo, indicando como concepto el nº de pedido %s.' ), $num ) );
			case 'bizum':
				/* translators: 1: teléfono de Bizum, 2: nº de pedido */
				return '' !== $cfg['bizum'] ? array( sprintf( asde_t( 'Bizum al %1$s, indicando como concepto el nº de pedido %2$s.' ), $cfg['bizum'], $num ) ) : array( sprintf( asde_t( 'Bizum al teléfono del grupo, indicando como concepto el nº de pedido %s.' ), $num ) );
			case 'fisico':
				/* translators: %s: nº de pedido */
				return array( sprintf( asde_t( 'Entrega el importe en físico a Tesorería, indicando el nº de pedido %s.' ), $num ) );
		}
		return array();
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
	 * Para quién puede comprar esta cuenta: la propia persona y sus menores (v0.44: un scouter ya no
	 * pide para los de su sección; compra como cualquier persona).
	 *
	 * @return array<string, Persona> nº => persona.
	 */
	public static function para_quien( Ambito $a ): array {
		return self::propios( $a );
	}

	public static function es_propio( array $p, Ambito $a ): bool {
		return ( $a->persona && $a->persona->num_socio_asde === $p['socio'] ) || isset( $a->representados[ $p['socio'] ] );
	}

	/** Los informes de ventas (v0.40): Tesorería, Coordinación y la administración de la tienda. */
	public static function puede_informes(): bool {
		return current_user_can( self::CAP_PAGO ) || current_user_can( self::CAP_PEDIDO ) || current_user_can( self::CAP_ADMIN );
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
		return ( self::es_propio( $p, $a ) || self::lo_pidio( $p ) ) && null === $p['cancelado_en'] && null === $p['pago_en'] && ( null === $p['pedido_en'] || PedidosTable::REF_TIENDA === $p['pedido_ref'] ) && null === $p['entregado_en'];
	}

	/**
	 * Cancela un pedido: si no se ha entregado, lo del catálogo vuelve a las existencias de la
	 * tienda. Lo usan la familia («Mis pedidos»), la compra sin sesión y la gestión.
	 */
	public static function cancelar( array $p, string $motivo ): bool {
		// Primero se marca cancelado (una sola sentencia, solo si seguía en curso): así dos cancelaciones a la vez no devuelven dos veces.
		if ( ! PedidosTable::cancelar( $p['id'], $motivo ) ) {
			return false;
		}
		if ( null === $p['entregado_en'] ) {
			self::devolver_lineas( $p['lineas']['productos'] ); // v0.39: lo del catálogo vuelve a las existencias.
		}
		return true;
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
		$productos = array();
		$n         = 0;
		foreach ( $cfg['modelos'] as $id => $m ) {
			$camisas[ $id ] = array(
				'nombre' => $m['nombre'],
				'tallas' => array_fill_keys( $m['tallas'], 0 ),
			);
		}
		foreach ( $pedidos as $p ) {
			$c = $p['lineas']['camisa'];
			// Pedidos anteriores a la v0.44, con la camisa en sus líneas.
			if ( $c ) {
				$camisas[ $c['modelo'] ]['nombre']                   ??= $c['nombre'];
				$camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] = ( $camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] ?? 0 ) + 1;
				++$n;
			}
			foreach ( $p['lineas']['insignias'] as $i ) {
				$insignias[ $i['id'] ]['nombre']   = $i['nombre'];
				$insignias[ $i['id'] ]['cantidad'] = ( $insignias[ $i['id'] ]['cantidad'] ?? 0 ) + (int) $i['cantidad'];
			}
			// v0.39: lo del catálogo que se encarga al proveedor.
			foreach ( $p['lineas']['productos'] as $l ) {
				if ( empty( $l['proveedor'] ) ) {
					continue;
				}
				$k                                = $l['producto'] . '|' . $l['variante'];
				$productos[ $k ]['nombre']        = $l['nombre'] . ( 'Única' !== $l['etiqueta'] ? ' (' . $l['etiqueta'] . ')' : '' );
				$productos[ $k ]['cantidad']      = ( $productos[ $k ]['cantidad'] ?? 0 ) + (int) $l['cantidad'];
			}
		}
		return array(
			'camisas'       => $camisas,
			'insignias'     => $insignias,
			'productos'     => array_values( $productos ),
			'total_camisas' => $n,
		);
	}
}
