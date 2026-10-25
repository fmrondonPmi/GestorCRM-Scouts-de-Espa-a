<?php
namespace AsdeBalears\AreaPrivada\Accesos;

use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo de permisos que se pueden repartir desde «Gestión de accesos» (v0.53, doc 56).
 *
 * El permiso («Permiso» del modelo) es una capability `asde_*` de siempre: el código de cada una
 * decide qué hace, así que el catálogo vive en el código y no en la base de datos. Aquí se
 * dice, para cada una, a qué módulo pertenece, si es de «ver» o de «acción», qué permiso hay que
 * tener antes y si es sensible. Lo que no está aquí (identidad, familia, Oficina…) no se reparte.
 *
 * - `requiere`: lista de permisos de los que basta **uno** para poder tener este (toda acción
 *   exige antes el de ver). Vacía en los de «ver».
 * - `sensible`: abre datos de salud, dinero o cuentas de acceso; lo conceden los tres roles de
 *   gestión (Coordinación, Secretaría y Tesorería), pero se marca para que se note.
 */
final class Catalogo {

	public const VER    = 'ver';
	public const ACCION = 'accion';

	/** Capability que da entrada a «Gestión de accesos»; no se reparte (solo la traen los tres roles de gestión). */
	public const CAP_GESTION = 'asde_accesos';

	/** Roles cuyos permisos se editan en la matriz (los de gestión; familias, Oficina y administradores quedan fuera). */
	public const ROLES = array( Roles::SCOUTER, Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA, Roles::INTENDENTE );

	/** Roles cuyas personas pueden recibir permisos sueltos. */
	public const ROLES_DESTINO = self::ROLES;

	/** módulo => [nombre] en el orden en que se enseñan. */
	public const MODULOS = array(
		'grupo'        => 'Datos del grupo',
		'personas'     => 'Personas y fichas',
		'usuarios'     => 'Usuarios y solicitudes',
		'informes'     => 'Informes',
		'tesoreria'    => 'Tesorería',
		'tienda'       => 'Tienda',
		'inventario'   => 'Inventario',
		'lista_espera' => 'Lista de espera',
		'eventos'      => 'Eventos',
		'reintegros'   => 'Reintegros',
	);

	/**
	 * permiso => [módulo, tipo, qué da, sensible, requiere (uno de)]
	 *
	 * @return array<string, array{0:string, 1:string, 2:string, 3:bool, 4:string[]}>
	 */
	private static function lista(): array {
		$ver_personas = array( 'asde_view_all', 'asde_view_section' );
		return array(
			'asde_grupo_datos'          => array( 'grupo', self::ACCION, 'Cambiar los datos del grupo (nombre, CIF, dirección y provincia)', false, array() ),
			'asde_view_section'         => array( 'personas', self::VER, 'Ver las fichas de las personas de su sección', false, array() ),
			'asde_view_all'             => array( 'personas', self::VER, 'Ver las fichas de todo el grupo', true, array() ),
			'asde_edit_section_contact' => array( 'personas', self::ACCION, 'Editar los datos de contacto de su sección', false, array( 'asde_view_section' ) ),
			'asde_edit_all'             => array( 'personas', self::ACCION, 'Editar las fichas de todo el grupo', true, array( 'asde_view_all' ) ),
			'asde_approve_changes'      => array( 'personas', self::ACCION, 'Aprobar los cambios que proponen las familias', false, $ver_personas ),
			'asde_view_health'          => array( 'personas', self::ACCION, 'Ver los datos de salud', true, $ver_personas ),
			'asde_annotate_health'      => array( 'personas', self::ACCION, 'Anotar en los datos de salud', true, array( 'asde_view_health' ) ),
			'asde_crm_sync'             => array( 'personas', self::ACCION, 'Sincronizar con el CRM', true, array( 'asde_view_all' ) ),

			'asde_manage_users'         => array( 'usuarios', self::VER, 'Ver Personas y Usuarios y dar acceso al área', true, array() ),
			'asde_aprobar_solicitudes'  => array( 'usuarios', self::ACCION, 'Aprobar o rechazar solicitudes de acceso', true, $ver_personas ),

			'asde_reports'              => array( 'informes', self::VER, 'Sacar informes del grupo', false, array() ),

			'asde_tesoreria_acceso'     => array( 'tesoreria', self::VER, 'Ver Tesorería (familias, pagos y banco)', false, array() ),
			'asde_presupuestos_seccion' => array( 'tesoreria', self::ACCION, 'Preparar el presupuesto de sus secciones', false, array() ),
			'asde_tesoreria_config'     => array( 'tesoreria', self::ACCION, 'Cambiar cuotas, plazos y coste federativo', true, array( 'asde_tesoreria_acceso' ) ),
			// v0.59 (doc 61): la caja de sus secciones y, con el segundo, todas las cajas y el libro mayor.
			'asde_caja'                 => array( 'tesoreria', self::ACCION, 'Llevar la caja de efectivo de sus secciones', true, array( 'asde_tesoreria_acceso' ) ),
			'asde_caja_todas'           => array( 'tesoreria', self::ACCION, 'Llevar todas las cajas de efectivo y ver el libro mayor', true, array( 'asde_tesoreria_acceso' ) ),

			'asde_tienda_comprar'       => array( 'tienda', self::VER, 'Comprar en la tienda', false, array() ),
			'asde_tienda_gestion'       => array( 'tienda', self::VER, 'Ver la gestión de la tienda', false, array() ),
			'asde_tienda_entrega'       => array( 'tienda', self::ACCION, 'Entregar pedidos', false, array( 'asde_tienda_gestion' ) ),
			'asde_tienda_pedido'        => array( 'tienda', self::ACCION, 'Hacer el pedido de camisas al proveedor', false, array( 'asde_tienda_gestion' ) ),
			'asde_tienda_pago'          => array( 'tienda', self::ACCION, 'Marcar pagados los pedidos', true, array( 'asde_tienda_gestion' ) ),
			'asde_tienda_admin'         => array( 'tienda', self::ACCION, 'Administrar productos y configuración de la tienda', true, array( 'asde_tienda_gestion' ) ),

			'asde_inventario'           => array( 'inventario', self::VER, 'Ver y editar el inventario de material', false, array() ),
			'asde_inventario_aprobar'   => array( 'inventario', self::ACCION, 'Dar el visto bueno a las listas de compra', true, array( 'asde_inventario' ) ),

			'asde_lista_espera'         => array( 'lista_espera', self::VER, 'Ver la lista de espera y contactar', false, array() ),
			'asde_lista_espera_alta'    => array( 'lista_espera', self::ACCION, 'Asignar sección, dar de alta en el CRM y borrar', true, array( 'asde_lista_espera' ) ),

			'asde_eventos_inscribir'    => array( 'eventos', self::VER, 'Ver «Mis eventos» e inscribirse', false, array() ),
			'asde_eventos_gestion'      => array( 'eventos', self::ACCION, 'Crear y gestionar los eventos de sus secciones', false, array( 'asde_eventos_inscribir' ) ),
			'asde_eventos_grupo'        => array( 'eventos', self::ACCION, 'Gestionar los eventos del grupo y los externos', false, array( 'asde_eventos_inscribir' ) ),
			'asde_eventos_material'     => array( 'eventos', self::ACCION, 'Gestionar el material de los eventos', false, array( 'asde_eventos_inscribir' ) ),
			'asde_menus_recetas'        => array( 'eventos', self::ACCION, 'Editar el recetario de los menús', false, array( 'asde_eventos_inscribir' ) ),
			'asde_eventos_economia'     => array( 'eventos', self::ACCION, 'Validar presupuestos y llevar las cuentas', true, array( 'asde_eventos_inscribir' ) ),
			'asde_eventos_config'       => array( 'eventos', self::ACCION, 'Configurar los eventos (márgenes y plantillas)', true, array( 'asde_eventos_inscribir' ) ),

			// Reintegros no tiene un «ver» aparte: pedir es lo básico y aprobar/pagar se apoyan en cualquiera de los tres.
			'asde_reintegros'           => array( 'reintegros', self::VER, 'Pedir el reintegro de un gasto', false, array() ),
			'asde_reintegros_aprobar'   => array( 'reintegros', self::ACCION, 'Aprobar reintegros', true, array( 'asde_reintegros', 'asde_reintegros_pagar' ) ),
			'asde_reintegros_pagar'     => array( 'reintegros', self::ACCION, 'Marcar pagados los reintegros', true, array( 'asde_reintegros', 'asde_reintegros_aprobar' ) ),
		);
	}

	/** @return string[] Todos los permisos que se pueden repartir, en el orden del catálogo. */
	public static function todos(): array {
		return array_keys( self::lista() );
	}

	public static function existe( string $permiso ): bool {
		return isset( self::lista()[ $permiso ] );
	}

	public static function modulo( string $permiso ): string {
		return self::lista()[ $permiso ][0] ?? '';
	}

	public static function tipo( string $permiso ): string {
		return self::lista()[ $permiso ][1] ?? '';
	}

	/** Lo que da, ya traducido. */
	public static function descripcion( string $permiso ): string {
		$d = self::lista()[ $permiso ][2] ?? '';
		return '' === $d ? $permiso : asde_t( $d );
	}

	public static function sensible( string $permiso ): bool {
		return (bool) ( self::lista()[ $permiso ][3] ?? false );
	}

	/** @return string[] Permisos de los que hace falta uno para tener este. */
	public static function requiere( string $permiso ): array {
		return self::lista()[ $permiso ][4] ?? array();
	}

	/** @return string[] Permisos del módulo, «ver» primero. */
	public static function del_modulo( string $modulo ): array {
		$out = array_keys( array_filter( self::lista(), static fn( array $d ) => $d[0] === $modulo ) );
		usort( $out, static fn( string $a, string $b ) => ( self::VER === self::tipo( $b ) ) <=> ( self::VER === self::tipo( $a ) ) );
		return $out;
	}

	/** @return string[] Los que dependen de este (lo piden y este es su única vía). */
	public static function dependientes( string $permiso ): array {
		return array_values( array_filter( self::todos(), static fn( string $p ) => in_array( $permiso, self::requiere( $p ), true ) ) );
	}

	/**
	 * ¿Cumple este conjunto de permisos lo que pide `$permiso`?
	 *
	 * @param array<string, bool> $tiene permiso => true
	 */
	public static function cumple_requisitos( string $permiso, array $tiene ): bool {
		$req = self::requiere( $permiso );
		if ( ! $req ) {
			return true;
		}
		foreach ( $req as $r ) {
			if ( ! empty( $tiene[ $r ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Quita de un conjunto los permisos cuyo requisito ya no se cumple (se repite hasta que no cambia nada:
	 * quitar «ver» quita sus acciones, y las que se apoyaban en ellas).
	 *
	 * @param array<string, bool> $tiene
	 * @return array<string, bool>
	 */
	public static function depurar( array $tiene ): array {
		do {
			$cambio = false;
			foreach ( array_keys( array_filter( $tiene ) ) as $p ) {
				if ( self::existe( $p ) && ! self::cumple_requisitos( $p, $tiene ) ) {
					unset( $tiene[ $p ] );
					$cambio = true;
				}
			}
		} while ( $cambio );
		return $tiene;
	}

	/** Descripción de lo que hay que tener antes (para los mensajes). */
	public static function texto_requisitos( string $permiso ): string {
		return implode( ' ' . asde_t( 'o' ) . ' ', array_map( static fn( string $p ) => '«' . self::descripcion( $p ) . '»', self::requiere( $permiso ) ) );
	}

	/** Textos que se traducen al mostrarlos (los recoge Idiomas\Textos::extras()). @return string[] */
	public static function textos(): array {
		return array_merge( array_values( self::MODULOS ), array_column( array_values( self::lista() ), 2 ) );
	}

	/** Lo que el navegador necesita para el mismo cálculo (assets/js/accesos.js). @return array<string, string[]> permiso => requiere */
	public static function para_js(): array {
		$out = array();
		foreach ( self::todos() as $p ) {
			$out[ $p ] = self::requiere( $p );
		}
		return $out;
	}
}
