<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Accesos\Auditoria;
use AsdeBalears\AreaPrivada\Accesos\Catalogo;
use AsdeBalears\AreaPrivada\Accesos\Concesiones;
use AsdeBalears\AreaPrivada\Accesos\PermisosRol;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Listado;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión de accesos (v0.53, doc 56): Coordinación, Secretaría y Tesorería deciden qué puede hacer cada rol y
 * conceden permisos sueltos a otras personas de gestión, con o sin fecha de caducidad.
 *
 * Tres hojas: la matriz rol × permiso, los accesos de cada persona (lo que trae su rol y lo extra) y el historial.
 * Los permisos efectivos los calcula `Accesos\Concesiones` (filtro de `user_has_cap`): aquí solo se reparten.
 */
final class AccesosView extends View {

	private const VISTAS = array(
		'matriz'    => 'Permisos por rol',
		'personas'  => 'Accesos por persona',
		'historial' => 'Historial',
	);

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Catalogo::CAP_GESTION ) ) {
			return $this->denegado();
		}
		Concesiones::caducar_vencidas(); // Por si la tarea diaria aún no ha corrido: lo vencido se anota antes de enseñarlo.
		$vista = isset( self::VISTAS[ $this->get( 'vista' ) ] ) ? $this->get( 'vista' ) : 'matriz';
		switch ( $vista ) {
			case 'personas':
				$html = $this->personas();
				break;
			case 'historial':
				$html = $this->historial();
				break;
			default:
				$html = $this->matriz();
		}
		return $this->layout( 'accesos', $this->hojas( $vista ) . $html );
	}

	private function hojas( string $activa ): string {
		$out = '<nav class="asde-hojas" aria-label="' . esc_attr( asde_a( 'Gestión de accesos' ) ) . '"><ul class="asde-hojas__lista">';
		foreach ( self::VISTAS as $clave => $nombre ) {
			$out .= '<li class="asde-hojas__item"><a class="asde-hojas__pestana' . ( $clave === $activa ? ' is-active' : '' ) . '" href="' . esc_url( $this->url( 'accesos', array( 'vista' => $clave ) ) ) . '"' . ( $clave === $activa ? ' aria-current="page"' : '' ) . '>' . esc_html( asde_t( $nombre ) ) . '</a></li>';
		}
		return $out . '</ul></nav>';
	}

	// ---------------------------------------------------------------- matriz

	private function matriz(): string {
		$cambios = PermisosRol::cambios();
		$roles   = array();
		foreach ( Catalogo::ROLES as $rol ) {
			$roles[ $rol ] = array(
				'nombre'  => Roles::label( $rol ),
				'tiene'   => PermisosRol::de_rol( $rol, $cambios ),
				'fabrica' => PermisosRol::de_fabrica( $rol ),
			);
		}
		$yo = wp_get_current_user();
		return $this->tpl(
			'accesos/matriz',
			array(
				'roles'    => $roles,
				'modulos'  => Catalogo::MODULOS,
				'catalogo' => Catalogo::para_js(),
				'mis'      => $yo->has_cap( 'manage_options' ) ? array() : array_values( array_intersect( Catalogo::ROLES, (array) $yo->roles ) ),
				'url'      => $this->url( 'accesos', array( 'vista' => 'matriz' ) ),
			)
		);
	}

	// --------------------------------------------------------------- personas

	/** @return \WP_User[] Las personas del grupo que pueden recibir permisos sueltos. */
	private function destinos(): array {
		return array_values( array_filter( Listado::usuarios(), array( Concesiones::class, 'es_destino' ) ) );
	}

	/**
	 * Nombres (del CRM, en la instantánea cifrada) de unas cuentas.
	 *
	 * @param int[] $ids
	 * @return array<int, string> user_id => nombre
	 */
	private function nombres( array $ids ): array {
		$repo   = PersonaRepository::make();
		$socios = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $uid ) {
			$socio = $uid ? Usuarios::socio( $uid ) : '';
			if ( '' !== $socio ) {
				$socios[ $uid ] = $socio;
			}
		}
		if ( ! $repo || ! $socios ) {
			return array();
		}
		try {
			$personas = $repo->obtener_varias( array_values( $socios ) );
		} catch ( CrmException $e ) {
			return array();
		}
		$out = array();
		foreach ( $socios as $uid => $socio ) {
			if ( isset( $personas[ $socio ] ) ) {
				$out[ $uid ] = $personas[ $socio ]->nombre_completo();
			}
		}
		return $out;
	}

	private function personas(): string {
		$id = (int) $this->get( 'usuario' );
		if ( $id ) {
			$user = get_userdata( $id );
			if ( ! $user || ! Concesiones::es_destino( $user ) ) {
				return $this->denegado( asde_t( 'Esa persona no está entre las que reciben permisos sueltos.' ) );
			}
			return $this->persona( $user );
		}
		$users   = $this->destinos();
		$nombres = $this->nombres( wp_list_pluck( $users, 'ID' ) );
		$buscar  = mb_strtolower( $this->get( 'buscar' ) );
		$rol     = in_array( $this->get( 'rol' ), Catalogo::ROLES_DESTINO, true ) ? $this->get( 'rol' ) : '';
		$filas   = array();
		$extras  = Concesiones::contar_por_persona();
		foreach ( $users as $u ) {
			$nombre = $nombres[ $u->ID ] ?? '';
			if ( '' !== $buscar && false === mb_strpos( mb_strtolower( $nombre . ' ' . $u->user_login ), $buscar ) ) {
				continue;
			}
			if ( '' !== $rol && ! in_array( $rol, (array) $u->roles, true ) ) {
				continue;
			}
			$filas[] = array(
				'user'   => $u,
				'nombre' => $nombre,
				'roles'  => implode( ', ', array_map( array( Roles::class, 'label' ), array_values( array_intersect( Catalogo::ROLES_DESTINO, (array) $u->roles ) ) ) ),
				'extras' => $extras[ $u->ID ] ?? 0,
			);
		}
		$tabla  = new Tabla(
			array(
				'nombre' => static fn( $f ) => '' !== $f['nombre'] ? $f['nombre'] : $f['user']->user_login,
				'rol'    => static fn( $f ) => $f['roles'],
				'extras' => static fn( $f ) => $f['extras'],
			),
			'nombre'
		);
		$pagina = $tabla->aplicar( $filas );
		return $this->tpl(
			'accesos/personas',
			array(
				'filas'  => $pagina,
				'tabla'  => $tabla,
				'buscar' => $this->get( 'buscar' ),
				'rol'    => $rol,
				'roles'  => array_combine( Catalogo::ROLES_DESTINO, array_map( array( Roles::class, 'label' ), Catalogo::ROLES_DESTINO ) ),
				'url'    => $this->url( 'accesos', array( 'vista' => 'personas' ) ),
			)
		);
	}

	private function persona( \WP_User $user ): string {
		$yo     = wp_get_current_user();
		$mapa   = Concesiones::mapa( $user );
		$quien  = array_unique( array_merge( array( $user->ID ), array_column( $mapa, 'por' ) ) );
		$nombre = $this->nombres( $quien );
		$tiene  = array_map( '__return_true', $mapa );
		$sin    = array(); // permiso => lo que le falta antes
		foreach ( Catalogo::todos() as $p ) {
			if ( ! isset( $mapa[ $p ] ) && ! Catalogo::cumple_requisitos( $p, $tiene ) ) {
				$sin[ $p ] = Catalogo::texto_requisitos( $p );
			}
		}
		return $this->tpl(
			'accesos/persona',
			array(
				'user'    => $user,
				'nombre'  => $nombre[ $user->ID ] ?? '',
				'roles'   => implode( ', ', array_map( array( Roles::class, 'label' ), array_values( array_intersect( Catalogo::ROLES_DESTINO, (array) $user->roles ) ) ) ),
				'mapa'    => $mapa,
				'nombres' => $nombre,
				'sin'     => $sin,
				'soy_yo'  => $yo->ID === $user->ID,
				'modulos' => Catalogo::MODULOS,
				'hoy'     => wp_date( 'Y-m-d' ),
				'url'     => $this->url( 'accesos', array( 'vista' => 'personas', 'usuario' => (string) $user->ID ) ),
				'volver'  => $this->url( 'accesos', array( 'vista' => 'personas' ) ),
			)
		);
	}

	// -------------------------------------------------------------- historial

	private function historial(): string {
		$accion  = isset( Auditoria::ACCIONES[ $this->get( 'accion' ) ] ) ? $this->get( 'accion' ) : '';
		$objetivo = (int) $this->get( 'persona' );
		$permiso = Catalogo::existe( $this->get( 'permiso' ) ) ? $this->get( 'permiso' ) : '';
		$periodo = in_array( $this->get( 'periodo' ), array( '30', '365' ), true ) ? (int) $this->get( 'periodo' ) : 0;
		$filtros = array(
			'accion'   => $accion,
			'objetivo' => $objetivo,
			'permiso'  => $permiso,
			'desde'    => $periodo ? wp_date( 'Y-m-d', strtotime( '-' . $periodo . ' days' ) ) : '',
		);
		$todas   = Auditoria::listado();
		$filas   = Auditoria::listado( $filtros );
		// Personas con algo en el historial (para el desplegable) y sus nombres.
		$ids     = array();
		foreach ( $todas as $f ) {
			$ids[] = (int) $f['objetivo'];
		}
		$ids     = array_values( array_unique( array_filter( $ids ) ) );
		$todos_n = array_unique( array_merge( $ids, array_map( fn( $f ) => (int) $f['actor'], $filas ) ) );
		$nombres = $this->nombres( $todos_n );
		$cuenta  = static function ( int $uid ) use ( $nombres ): string {
			if ( ! $uid ) {
				return asde_t( 'Automático' );
			}
			$u = get_userdata( $uid );
			if ( ! $u ) {
				/* translators: %d: número de cuenta */
				return sprintf( asde_t( 'Cuenta borrada (%d)' ), $uid );
			}
			return isset( $nombres[ $uid ] ) ? $nombres[ $uid ] . ' (' . $u->user_login . ')' : $u->user_login;
		};
		$tabla   = new Tabla(
			array(
				'fecha'   => static fn( $f ) => $f['creado'],
				'actor'   => static fn( $f ) => $cuenta( (int) $f['actor'] ),
				'accion'  => static fn( $f ) => asde_t( Auditoria::ACCIONES[ $f['accion'] ] ?? $f['accion'] ),
				'persona' => static fn( $f ) => (int) $f['objetivo'] ? $cuenta( (int) $f['objetivo'] ) : Roles::label( $f['rol'] ),
				'permiso' => static fn( $f ) => Catalogo::existe( $f['permiso'] ) ? Catalogo::descripcion( $f['permiso'] ) : $f['permiso'],
			),
			'fecha',
			'desc'
		);
		$total   = count( $filas );
		$pagina  = $tabla->aplicar( $filas );
		$opciones_personas = array();
		foreach ( $ids as $uid ) {
			$opciones_personas[ $uid ] = $cuenta( $uid );
		}
		asort( $opciones_personas );
		return $this->tpl(
			'accesos/historial',
			array(
				'filas'    => $pagina,
				'total'    => $total,
				'tabla'    => $tabla,
				'cuenta'   => $cuenta,
				'accion'   => $accion,
				'persona'  => $objetivo,
				'permiso'  => $permiso,
				'periodo'  => $periodo,
				'personas' => $opciones_personas,
				'url'      => $this->url( 'accesos', array( 'vista' => 'historial' ) ),
			)
		);
	}

	// -------------------------------------------------------------------- POST

	public function handle_post(): void {
		if ( ! current_user_can( Catalogo::CAP_GESTION ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		$yo     = wp_get_current_user();

		if ( 'accesos_matriz' === $accion ) {
			check_admin_referer( 'asde_accesos_matriz' );
			$enviado  = isset( $_POST['perm'] ) && is_array( $_POST['perm'] ) ? wp_unslash( $_POST['perm'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce se ha comprobado arriba; solo se mira si cada casilla está marcada y los códigos se validan contra el catálogo
			$deseado  = array();
			foreach ( array_intersect( Catalogo::ROLES, $this->post_array( 'roles' ) ) as $rol ) { // Solo los roles que el formulario enseñaba: lo no enviado no se toca.
				foreach ( Catalogo::todos() as $p ) {
					$deseado[ $rol ][ $p ] = ! empty( $enviado[ $rol ][ $p ] );
				}
			}
			$r = PermisosRol::guardar( $deseado, $yo );
			foreach ( $r['avisos'] as $a ) {
				Notices::add( 'warning', esc_html( $a ) );
			}
			Notices::add( 'success', $r['cambios'] ? esc_html( sprintf( asde_t( 'Permisos guardados: %d cambios.' ), $r['cambios'] ) ) : esc_html( asde_t( 'No había nada que cambiar.' ) ) );
			$this->redirect( $this->url( 'accesos', array( 'vista' => 'matriz' ) ) );
		}

		if ( 'accesos_restaurar' === $accion ) {
			check_admin_referer( 'asde_accesos_restaurar' );
			$n = PermisosRol::restaurar( $this->post( 'rol' ), $yo );
			if ( $n < 0 ) {
				Notices::add( 'error', esc_html( asde_t( 'No puedes restaurar tu propio rol: volvería a darte permisos que se habían quitado. Que lo haga otra persona.' ) ) );
			} else {
				Notices::add( 'success', $n ? esc_html( asde_t( 'Rol restaurado a los permisos de fábrica.' ) ) : esc_html( asde_t( 'Ese rol ya tenía los permisos de fábrica.' ) ) );
			}
			$this->redirect( $this->url( 'accesos', array( 'vista' => 'matriz' ) ) );
		}

		$uid    = (int) $this->post( 'usuario' );
		$volver = $this->url( 'accesos', array( 'vista' => 'personas', 'usuario' => (string) $uid ) );

		if ( 'accesos_conceder' === $accion ) {
			check_admin_referer( 'asde_accesos_conceder_' . $uid );
			$caduca = 'fecha' === $this->post( 'caduca_tipo' ) ? $this->post( 'caduca' ) : null;
			if ( 'fecha' === $this->post( 'caduca_tipo' ) && '' === (string) $caduca ) {
				Notices::add( 'error', esc_html( asde_t( 'Elige la fecha hasta la que vale el permiso, o «Sin caducidad».' ) ) );
				$this->redirect( $volver );
			}
			$r = Concesiones::conceder( $yo, $uid, $this->post( 'permiso' ), $caduca, $this->post( 'motivo' ) );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( asde_t( 'Permiso concedido.' ) ) );
			$this->redirect( $volver );
		}

		if ( 'accesos_retirar' === $accion ) {
			$id = (int) $this->post( 'concesion' );
			check_admin_referer( 'asde_accesos_retirar_' . $id );
			$r = Concesiones::revocar( $yo, $id, $this->post( 'motivo' ) );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( asde_t( 'Permiso retirado.' ) ) );
			$this->redirect( $volver );
		}

		Notices::add( 'error', asde_t( 'Petición no válida.' ) );
		$this->redirect( $this->url( 'accesos' ) );
	}
}
