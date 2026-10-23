<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Módulos del área privada que se pueden activar o desactivar por grupo
 * (v0.20; wp-admin → Grupos Scouts → Módulos, Admin\ModulosPage).
 *
 * - Por defecto, todo activo: así una instalación anterior no cambia al actualizar.
 *   Solo se guarda lo desactivado (opción asde_ap_modulos: nº de grupo => [módulo => false]).
 * - Desactivar no borra datos: al activarlo de nuevo, todo está como estaba.
 * - El corte se hace en un solo sitio: a quien trabaja en un grupo con un módulo
 *   apagado se le quitan las capabilities de ese módulo (filtro user_has_cap), y con
 *   ellas desaparecen el menú, las tarjetas del inicio, las pantallas y las acciones.
 *   Banco y Domiciliaciones (que comparten la capability de configurar Tesorería)
 *   y los formularios públicos (pedir el acceso, alta de scouters) preguntan aquí.
 * - v0.32 (regla 5): los módulos de POR_DEFECTO_APAGADOS nacen apagados; solo están activos
 *   en los grupos donde se han encendido (se guarda [módulo => true]).
 */
final class Modulos {

	private const OPCION = 'asde_ap_modulos';

	public const TESORERIA     = 'tesoreria';
	public const BANCO         = 'banco';
	public const SEPA          = 'sepa';
	public const TIENDA        = 'tienda';
	public const INVENTARIO    = 'inventario';
	public const INFORMES      = 'informes';
	public const SOLICITUDES   = 'solicitudes';
	public const ALTA_SCOUTERS = 'alta_scouters';
	public const LISTA_ESPERA  = 'lista_espera';
	public const EVENTOS       = 'eventos';
	public const REINTEGROS    = 'reintegros';
	public const PRESUPUESTOS  = 'presupuestos';

	/** Módulos que nacen apagados (v0.32): cambian lo que ve el grupo y se encienden a mano. */
	public const POR_DEFECTO_APAGADOS = array( self::LISTA_ESPERA, self::EVENTOS, self::REINTEGROS );

	/** módulo => [nombre, qué es, capabilities que se quitan si está apagado, módulo del que depende] */
	public const LISTA = array(
		self::TESORERIA     => array( 'Tesorería', 'Control de pagos, familias, cuotas y coste federativo.', array( 'asde_tesoreria_acceso', 'asde_tesoreria_config', 'asde_sepa_autorizar' ), '' ),
		self::BANCO         => array( 'Banco', 'Importar los movimientos de la cuenta, conciliarlos y tipificar los gastos.', array(), self::TESORERIA ),
		self::SEPA          => array( 'Domiciliaciones SEPA', 'Autorizaciones de las familias y ficheros de adeudos para el banco.', array( 'asde_sepa_autorizar' ), self::TESORERIA ),
		self::TIENDA        => array( 'Tienda', 'Productos del grupo (uniforme, insignias y más): pedidos, pago y entrega.', array( 'asde_tienda_comprar', 'asde_tienda_gestion', 'asde_tienda_pago', 'asde_tienda_pedido', 'asde_tienda_entrega', 'asde_tienda_admin' ), '' ),
		self::INVENTARIO    => array( 'Inventario', 'Material del grupo, tipos y listas de compra.', array( 'asde_inventario', 'asde_inventario_aprobar' ), '' ),
		self::INFORMES      => array( 'Informes', 'Listados con las columnas que se elijan.', array( 'asde_reports' ), '' ),
		self::SOLICITUDES   => array( 'Solicitudes de acceso', 'El enlace del grupo para pedir el acceso y su revisión.', array( 'asde_aprobar_solicitudes' ), '' ),
		self::ALTA_SCOUTERS => array( 'Alta de scouters', 'El enlace para que los scouters se den de alta.', array(), '' ),
		self::LISTA_ESPERA  => array( 'Lista de espera', 'El enlace para apuntarse a la lista de espera, la lista para el equipo y el paso al CRM.', array( 'asde_lista_espera', 'asde_lista_espera_alta' ), '' ),
		// v0.35 (doc 43): apagado por defecto, como todo módulo nuevo (regla 5).
		self::EVENTOS       => array( 'Eventos', 'Salidas, acampadas y otras actividades: equipo dirigente, presupuesto, inscripciones, «Mis eventos» y cobros.', array( 'asde_eventos_gestion', 'asde_menus_recetas', 'asde_eventos_grupo', 'asde_eventos_economia', 'asde_eventos_config', 'asde_eventos_inscribir', 'asde_eventos_material' ), '' ),
		// v0.54 (doc 57): solo añade una pestaña a Tesorería (encendido por defecto).
		self::PRESUPUESTOS  => array( 'Presupuestos', 'Reparto de las cuotas entre el grupo y las secciones y presupuesto de cada una.', array( 'asde_presupuestos_seccion' ), self::TESORERIA ),
		// v0.47 (doc 53): apagado por defecto, como todo módulo nuevo (regla 5).
		self::REINTEGROS    => array( 'Reintegros', 'Los scouters piden que les devuelvan un gasto (con el justificante y, si procede, un evento); Tesorería lo aprueba y Tesorería o Coordinación lo marcan pagado.', array( 'asde_reintegros', 'asde_reintegros_aprobar', 'asde_reintegros_pagar' ), '' ),
	);

	/** @var array<string, string[]> nº de grupo => capabilities quitadas (por petición). */
	private static array $quitadas = array();

	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'quitar_caps' ), 20, 4 );
	}

	/** ¿Está activo este módulo en el grupo? (por defecto, el grupo en el que se trabaja). */
	public static function activo( string $modulo, string $grupo = '' ): bool {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		if ( ! isset( self::LISTA[ $modulo ] ) ) {
			return true;
		}
		$padre = self::LISTA[ $modulo ][3];
		if ( '' !== $padre && ! self::activo( $padre, $grupo ) ) {
			return false;
		}
		return self::encendido( $modulo, $grupo );
	}

	/** ¿Está marcado en el grupo? (sin mirar el módulo del que depende). */
	public static function encendido( string $modulo, string $grupo ): bool {
		$guardado = self::apagados()[ $grupo ] ?? array();
		if ( in_array( $modulo, self::POR_DEFECTO_APAGADOS, true ) ) {
			return true === ( $guardado[ $modulo ] ?? null );
		}
		return ! array_key_exists( $modulo, $guardado );
	}

	/** @return array<string, array<string, bool>> nº de grupo => [módulo apagado => false, módulo apagado por defecto y encendido => true] */
	public static function apagados(): array {
		$g = get_option( self::OPCION, array() );
		return is_array( $g ) ? $g : array();
	}

	/**
	 * Guarda los módulos activos de un grupo (solo se guarda lo que no es el valor por defecto).
	 *
	 * @param string[] $activos
	 * @return string[] Los que quedan apagados.
	 */
	public static function guardar( string $grupo, array $activos ): array {
		$todos = self::apagados();
		$off   = array_values( array_diff( array_keys( self::LISTA ), $activos ) );
		$fila  = array();
		foreach ( array_keys( self::LISTA ) as $m ) {
			$apagado_antes = in_array( $m, self::POR_DEFECTO_APAGADOS, true );
			$encendido     = in_array( $m, $activos, true );
			if ( $apagado_antes && $encendido ) {
				$fila[ $m ] = true;
			} elseif ( ! $apagado_antes && ! $encendido ) {
				$fila[ $m ] = false;
			}
		}
		if ( $fila ) {
			$todos[ $grupo ] = $fila;
		} else {
			unset( $todos[ $grupo ] );
		}
		update_option( self::OPCION, $todos, false );
		self::$quitadas = array();
		return $off;
	}

	public static function olvidar(): void {
		self::$quitadas = array();
	}

	/** @return string[] Capabilities que no tiene nadie del grupo (por sus módulos apagados). */
	public static function caps_quitadas( string $grupo ): array {
		if ( isset( self::$quitadas[ $grupo ] ) ) {
			return self::$quitadas[ $grupo ];
		}
		$caps = array();
		foreach ( self::LISTA as $m => $def ) {
			if ( ! self::activo( $m, $grupo ) ) {
				$caps = array_merge( $caps, $def[2] );
			}
		}
		// «Mis pagos» enseña lo de Tesorería, los pagos del uniforme y (v0.35) los de los eventos: se va si se apagan los tres.
		if ( ! self::activo( self::TESORERIA, $grupo ) && ! self::activo( self::TIENDA, $grupo ) && ! self::activo( self::EVENTOS, $grupo ) ) {
			$caps[] = 'asde_mis_pagos';
		}
		return self::$quitadas[ $grupo ] = array_values( array_unique( $caps ) );
	}

	/**
	 * Filtro user_has_cap: quita las capabilities de los módulos apagados en el grupo
	 * de esa persona (el suyo o, para un administrador, el que eligió al entrar).
	 *
	 * @param array<string, bool> $todas
	 * @return array<string, bool>
	 */
	public static function quitar_caps( $todas, $pedidas, $args, $user ) {
		// Con módulos apagados por defecto (POR_DEFECTO_APAGADOS) siempre hay algo que quitar (v0.32): no se mira si hay
		// alguno apagado antes de seguir.
		if ( ! is_array( $todas ) || ! $user instanceof \WP_User || ! $user->ID ) {
			return $todas;
		}
		$grupo = $user->ID === get_current_user_id() ? Grupo::numero() : (string) ( Grupos::de_usuario( $user )['numero'] ?? '' );
		if ( '' === $grupo ) {
			return $todas;
		}
		foreach ( self::caps_quitadas( $grupo ) as $cap ) {
			unset( $todas[ $cap ] );
		}
		return $todas;
	}
}
