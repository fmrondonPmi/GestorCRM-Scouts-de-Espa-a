<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
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

	/** La camisa y las insignias se siguen pidiendo en el formulario de uniforme; el resto, desde el catálogo. */
	public static function en_catalogo( array $producto ): bool {
		return 'camisa' !== $producto['ref'] && 0 !== strpos( $producto['ref'], 'insignia:' );
	}

	/**
	 * Lo que ve quien compra: los productos visibles para él o ella (el filtro «solo socios» está en la consulta)
	 * con sus variantes activas.
	 *
	 * @return array<int, array{producto:array, variantes:array[]}>
	 */
	public static function catalogo( bool $es_socio ): array {
		$productos = array_values( array_filter( ProductosTable::visibles( $es_socio ), array( self::class, 'en_catalogo' ) ) );
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
			if ( ! $v || ! $p || 1 !== $v['activo'] || ! self::en_catalogo( $p ) || ! ProductosTable::visible_para( $p, $es_socio ) ) {
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
	 * Cancela un pedido (v0.29): si su camisa había salido del inventario y no se ha entregado,
	 * vuelve a él. Lo usan la familia («Mi uniforme») y la gestión.
	 */
	public static function cancelar( array $p, string $motivo ): bool {
		// Primero se marca cancelado (una sola sentencia, solo si seguía en curso): así dos cancelaciones a la vez no devuelven dos veces.
		if ( ! PedidosTable::cancelar( $p['id'], $motivo ) ) {
			return false;
		}
		if ( null === $p['entregado_en'] ) {
			if ( (int) $p['inventario_id'] ) {
				MaterialesTable::devolver_camisa( (int) $p['inventario_id'], (string) ( $p['lineas']['camisa']['modelo'] ?? '' ), (string) ( $p['lineas']['camisa']['talla'] ?? '' ) );
				PedidosTable::quitar_camisa_servida( $p['id'], false );
			}
			self::devolver_insignias_servidas( $p ); // v0.41
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

	// ---------------------------------------------------------------------
	// Acciones
	// ---------------------------------------------------------------------

	/** @return int|\WP_Error Id del pedido. */
	public static function pedir( Ambito $a, string $socio, array $lineas, string $nota, string $forma_pago = '' ) {
		$cfg = Config::de();
		if ( ! $cfg['abierto'] ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Ahora mismo no se aceptan pedidos de uniforme.' ) );
		}
		$p = self::para_quien( $a )[ $socio ] ?? null;
		if ( ! $p ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'No puedes hacer un pedido para esa persona.' ) );
		}
		$forma = self::forma_valida( $forma_pago, $p->tiene_membresia_activa(), false ); // v0.39: opcional en el uniforme.
		if ( is_wp_error( $forma ) ) {
			return $forma;
		}
		if ( $lineas['camisa'] ) {
			// El de la persona para la que es (v0.30: también si lo pide un scouter de su sección).
			$lineas['camisa']['precio'] = self::precio_camisa( $p->num_socio_asde, $p->es_adulto_voluntario(), $cfg );
		}
		$t  = self::total( $lineas, $cfg );
		$id = PedidosTable::crear( $p->num_socio_asde, $p->secciones, $p->es_adulto_voluntario(), $lineas, $t['total'], $t['provisional'], $nota, $forma );
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
		$pedido = PedidosTable::obtener( $id );
		if ( $pedido ) {
			Avisos::enviar( $pedido, Avisos::PEDIDO );
		}
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

	/** ¿Está el pedido en un estado en que se puede servir o devolver del inventario? (En curso y sin pedir al proveedor.) */
	private static function en_estado_de_servir( array $p ): bool {
		return self::puede_inventario() && null === $p['cancelado_en'] && null === $p['entregado_en'] && null === $p['pedido_en'];
	}

	/**
	 * Lo que este pedido tiene de inventario (v0.41): la camisa y las insignias, con las unidades que quedan en el inventario y si ya
	 * salieron de él. Solo para quien puede usar el inventario.
	 *
	 * @return array{camisa: ?array{nombre:string, talla:string, quedan:int, servida:bool}, insignias: array<int, array{nombre:string, cantidad:int, quedan:int, servida:bool}>}
	 */
	public static function inventario_de( array $p ): array {
		$out = array( 'camisa' => null, 'insignias' => array() );
		if ( ! self::puede_inventario() ) {
			return $out;
		}
		$c = $p['lineas']['camisa'];
		if ( $c ) {
			$quedan       = (int) ( MaterialesTable::camisas()[ $c['modelo'] ?? '' ][ $c['talla'] ?? '' ] ?? 0 );
			$out['camisa'] = array(
				'nombre'  => (string) $c['nombre'],
				'talla'   => (string) $c['talla'],
				'quedan'  => $quedan,
				'servida' => (int) $p['inventario_id'] > 0,
			);
		}
		if ( $p['lineas']['insignias'] ) {
			$stock = MaterialesTable::insignias( Config::de() );
			foreach ( $p['lineas']['insignias'] as $n => $i ) {
				$out['insignias'][ $n ] = array(
					'nombre'   => (string) $i['nombre'],
					'cantidad' => (int) $i['cantidad'],
					'quedan'   => (int) ( $stock[ (string) ( $i['id'] ?? '' ) ] ?? 0 ),
					'servida'  => ! empty( $i['inventario'] ),
				);
			}
		}
		return $out;
	}

	/** ¿Hay algo de este pedido que se pueda servir ahora del inventario? (Lo que pide y aún no ha salido de él, y de lo que queda.) */
	public static function hay_en_inventario( array $p ): bool {
		if ( ! self::en_estado_de_servir( $p ) ) {
			return false;
		}
		$d = self::inventario_de( $p );
		if ( $d['camisa'] && ! $d['camisa']['servida'] && $d['camisa']['quedan'] > 0 ) {
			return true;
		}
		foreach ( $d['insignias'] as $i ) {
			if ( ! $i['servida'] && $i['quedan'] >= $i['cantidad'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ¿Se puede servir del inventario algo de este pedido? (Con camisa o insignias sin servir, en curso y aún sin pedir al proveedor.)
	 * Que luego quede en el inventario es otra cosa: servir() lo comprueba.
	 */
	public static function se_puede_servir( array $p ): bool {
		if ( ! self::en_estado_de_servir( $p ) ) {
			return false;
		}
		if ( ! empty( $p['lineas']['camisa'] ) && 0 === (int) $p['inventario_id'] ) {
			return true;
		}
		foreach ( $p['lineas']['insignias'] as $i ) {
			if ( empty( $i['inventario'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** ¿Se puede devolver al inventario algo de este pedido? (Salió de él y aún no se ha entregado.) */
	public static function se_puede_devolver( array $p ): bool {
		if ( ! self::puede_inventario() || null !== $p['entregado_en'] || null !== $p['cancelado_en'] ) {
			return false;
		}
		if ( (int) $p['inventario_id'] > 0 ) {
			return true;
		}
		foreach ( $p['lineas']['insignias'] as $i ) {
			if ( ! empty( $i['inventario'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** ¿Sale todo el pedido del inventario? (La camisa y las insignias servidas y nada del catálogo que se encargue al proveedor.) */
	private static function todo_del_inventario( array $lineas, int $inventario_id ): bool {
		if ( ! empty( $lineas['camisa'] ) && $inventario_id <= 0 ) {
			return false;
		}
		foreach ( $lineas['insignias'] as $i ) {
			if ( empty( $i['inventario'] ) ) {
				return false;
			}
		}
		foreach ( (array) ( $lineas['productos'] ?? array() ) as $l ) {
			if ( ! empty( $l['proveedor'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lo que se pidió sale del inventario (v0.29 la camisa, v0.41 también las insignias): se descuenta lo que quede de cada cosa. Si sale
	 * todo, el pedido no va al proveedor; si solo sale una parte, el resto sigue en «Para el próximo pedido al proveedor».
	 *
	 * @return true|\WP_Error
	 */
	public static function servir( array $p ) {
		if ( ! self::se_puede_servir( $p ) ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Este pedido no se puede servir del inventario.' ) );
		}
		$c         = $p['lineas']['camisa'];
		$lineas    = $p['lineas'];
		$material  = 0;
		$insignias = array();
		if ( $c && 0 === (int) $p['inventario_id'] ) {
			$material = MaterialesTable::descontar_camisa( (string) $c['modelo'], (string) $c['talla'] );
		}
		foreach ( $lineas['insignias'] as $n => $i ) {
			if ( empty( $i['inventario'] ) ) {
				$m = MaterialesTable::descontar_insignia( (string) $i['nombre'], (int) $i['cantidad'] );
				if ( $m ) {
					$lineas['insignias'][ $n ]['inventario'] = $m;
					$insignias[ $n ]                         = $m;
				}
			}
		}
		if ( ! $material && ! $insignias ) {
			if ( $c && 0 === (int) $p['inventario_id'] && ! $lineas['insignias'] ) {
				/* translators: 1: modelo de camisa, 2: talla */
				return new \WP_Error( 'asde_uniforme', sprintf( asde_t( 'No queda ninguna camisa «%1$s» de la talla %2$s en el inventario.' ), $c['nombre'], $c['talla'] ) );
			}
			return new \WP_Error( 'asde_uniforme', asde_t( 'No queda en el inventario nada de lo que se ha pedido.' ) );
		}
		$id_camisa = $material ?: (int) $p['inventario_id'];
		$guardado  = true;
		if ( $material ) {
			$guardado = PedidosTable::servir_camisa( $p['id'], $material, false );
		}
		if ( $guardado && $insignias ) {
			$guardado = PedidosTable::guardar_lineas( $p['id'], $lineas );
		}
		if ( ! $guardado ) {
			if ( $material ) {
				MaterialesTable::devolver_camisa( $material, (string) $c['modelo'], (string) $c['talla'] );
				PedidosTable::quitar_camisa_servida( $p['id'], false );
			}
			foreach ( $insignias as $n => $m ) {
				MaterialesTable::devolver_insignia( $m, (string) $lineas['insignias'][ $n ]['nombre'], (int) $lineas['insignias'][ $n ]['cantidad'] );
			}
			return new \WP_Error( 'asde_uniforme', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		if ( self::todo_del_inventario( $lineas, $id_camisa ) ) {
			PedidosTable::incluir_del_inventario( $p['id'] );
		}
		Logger::log(
			'uniforme_servido_inventario',
			'uniforme',
			(string) $p['id'],
			array(
				'material'  => $material,
				'insignias' => count( $insignias ),
			)
		);
		return true;
	}

	/** Lo servido vuelve al inventario (y el pedido, si constaba como incluido «del inventario», deja de constarlo). @return true|\WP_Error */
	public static function devolver( array $p ) {
		if ( ! self::se_puede_devolver( $p ) ) {
			return new \WP_Error( 'asde_uniforme', asde_t( 'Nada de este pedido se puede devolver al inventario.' ) );
		}
		$material = 0;
		if ( (int) $p['inventario_id'] > 0 ) {
			$c        = (array) $p['lineas']['camisa'];
			$material = MaterialesTable::devolver_camisa( (int) $p['inventario_id'], (string) ( $c['modelo'] ?? '' ), (string) ( $c['talla'] ?? '' ) );
			PedidosTable::quitar_camisa_servida( $p['id'], false );
		}
		$n = self::devolver_insignias_servidas( $p );
		if ( PedidosTable::REF_INVENTARIO === $p['pedido_ref'] ) {
			PedidosTable::quitar_inclusion_inventario( $p['id'] );
		}
		Logger::log(
			'uniforme_devuelto_inventario',
			'uniforme',
			(string) $p['id'],
			array(
				'material'  => $material,
				'insignias' => $n,
			)
		);
		return true;
	}

	/** Devuelve al inventario las insignias del pedido que habían salido de él y lo apunta en el pedido. @return int Cuántas líneas. */
	private static function devolver_insignias_servidas( array $p ): int {
		$lineas = $p['lineas'];
		$n      = 0;
		foreach ( $lineas['insignias'] as $k => $i ) {
			if ( ! empty( $i['inventario'] ) ) {
				MaterialesTable::devolver_insignia( (int) $i['inventario'], (string) $i['nombre'], (int) $i['cantidad'] );
				unset( $lineas['insignias'][ $k ]['inventario'] );
				++$n;
			}
		}
		if ( $n ) {
			PedidosTable::guardar_lineas( $p['id'], $lineas );
		}
		return $n;
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
			// La camisa que sale del inventario (v0.29) no se pide al proveedor.
			if ( $c && empty( $p['inventario_id'] ) ) {
				$camisas[ $c['modelo'] ]['nombre']                   ??= $c['nombre'];
				$camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] = ( $camisas[ $c['modelo'] ]['tallas'][ $c['talla'] ] ?? 0 ) + 1;
				++$n;
			}
			foreach ( $p['lineas']['insignias'] as $i ) {
				if ( ! empty( $i['inventario'] ) ) {
					continue; // v0.41: sale del inventario del grupo, no se pide al proveedor.
				}
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
