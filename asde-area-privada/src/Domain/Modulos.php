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
 */
final class Modulos {

	private const OPCION = 'asde_ap_modulos';

	public const TESORERIA     = 'tesoreria';
	public const BANCO         = 'banco';
	public const SEPA          = 'sepa';
	public const UNIFORMES     = 'uniformes';
	public const INVENTARIO    = 'inventario';
	public const INFORMES      = 'informes';
	public const SOLICITUDES   = 'solicitudes';
	public const ALTA_SCOUTERS = 'alta_scouters';

	/** módulo => [nombre, qué es, capabilities que se quitan si está apagado, módulo del que depende] */
	public const LISTA = array(
		self::TESORERIA     => array( 'Tesorería', 'Control de pagos, familias, cuotas y coste federativo.', array( 'asde_tesoreria_acceso', 'asde_tesoreria_config', 'asde_sepa_autorizar' ), '' ),
		self::BANCO         => array( 'Banco', 'Importar los movimientos de la cuenta, conciliarlos y tipificar los gastos.', array(), self::TESORERIA ),
		self::SEPA          => array( 'Domiciliaciones SEPA', 'Autorizaciones de las familias y ficheros de adeudos para el banco.', array( 'asde_sepa_autorizar' ), self::TESORERIA ),
		self::UNIFORMES     => array( 'Uniformes', 'Pedidos de la camisa y las insignias, su pago y su entrega.', array( 'asde_uniformes_pedir', 'asde_uniformes_gestion', 'asde_uniformes_pago', 'asde_uniformes_pedido', 'asde_uniformes_entrega', 'asde_uniformes_config' ), '' ),
		self::INVENTARIO    => array( 'Inventario', 'Material del grupo, tipos y listas de compra.', array( 'asde_inventario', 'asde_inventario_aprobar' ), '' ),
		self::INFORMES      => array( 'Informes', 'Listados con las columnas que se elijan.', array( 'asde_reports' ), '' ),
		self::SOLICITUDES   => array( 'Solicitudes de acceso', 'El enlace del grupo para pedir el acceso y su revisión.', array( 'asde_aprobar_solicitudes' ), '' ),
		self::ALTA_SCOUTERS => array( 'Alta de scouters', 'El enlace para que los scouters se den de alta.', array(), '' ),
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
		$apagados = self::apagados();
		return empty( $apagados[ $grupo ] ) || ! array_key_exists( $modulo, $apagados[ $grupo ] );
	}

	/** @return array<string, array<string, bool>> nº de grupo => [módulo apagado => false] */
	public static function apagados(): array {
		$g = get_option( self::OPCION, array() );
		return is_array( $g ) ? $g : array();
	}

	/**
	 * Guarda los módulos activos de un grupo (solo se guardan los apagados).
	 *
	 * @param string[] $activos
	 * @return string[] Los que quedan apagados.
	 */
	public static function guardar( string $grupo, array $activos ): array {
		$todos = self::apagados();
		$off   = array_values( array_diff( array_keys( self::LISTA ), $activos ) );
		if ( $off ) {
			$todos[ $grupo ] = array_fill_keys( $off, false );
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
		// «Mis pagos» enseña lo de Tesorería y los pagos del uniforme: se va si se apagan los dos.
		if ( ! self::activo( self::TESORERIA, $grupo ) && ! self::activo( self::UNIFORMES, $grupo ) ) {
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
		if ( ! is_array( $todas ) || ! self::apagados() || ! $user instanceof \WP_User || ! $user->ID ) {
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
