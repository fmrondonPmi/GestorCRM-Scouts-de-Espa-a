<?php
namespace AsdeBalears\AreaPrivada\Auth;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Ámbito de un usuario en esta petición, calculado desde el CRM (sin caché
 * persistente: nada personal se guarda en WordPress).
 *
 * - Roles de socio (participante, scouter, secretaría, coordinación): solo valen
 *   si su ficha tiene membresía activa.
 * - Cuenta familiar (tutor): solo ve a menores con membresía activa y menores de
 *   18 años. Al cumplir 18 solo accede la propia persona.
 * - Administradores de WordPress: acceso completo.
 */
final class Ambito {

	/** @var array<int, self> */
	private static array $cache = array();

	public ?Persona $persona = null;

	/** @var array<string, Persona> Menores representados válidos (nº Scouts de España => Persona). */
	public array $representados = array();

	/**
	 * v0.64.5: menores de otra cuenta familiar de la misma persona (la misma cuenta de email), para quien entra con su
	 * cuenta de scouter u otra y no con la familiar. Solo se usa para las inscripciones de «Mis eventos»; no se guarda.
	 *
	 * @var array<string, Persona>
	 */
	public array $vinculados = array();

	/** @var string[] Representados que ya no cumplen (mayores de edad, sin membresía o fuera del grupo). */
	public array $retirados = array();

	public string $error_crm = '';

	private function __construct( public \WP_User $user ) {}

	public static function actual(): self {
		return self::de( wp_get_current_user() );
	}

	/** Descarta el ámbito calculado (p. ej. tras cambiar sus vínculos en la misma petición). */
	public static function olvidar( int $user_id ): void {
		unset( self::$cache[ $user_id ] );
	}

	public static function de( \WP_User $user ): self {
		if ( isset( self::$cache[ $user->ID ] ) ) {
			return self::$cache[ $user->ID ];
		}
		$a = new self( $user );
		$a->cargar();
		return self::$cache[ $user->ID ] = $a;
	}

	private function cargar(): void {
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			$this->error_crm = __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		try {
			$socio = Usuarios::socio( $this->user->ID );
			$reps  = Usuarios::representados( $this->user->ID );
			// v0.63.2: una cuenta familiar representa también a los demás menores de su familia de Tesorería
			// (hermanos que Secretaría ya agrupó, aunque no compartan email en el CRM).
			$extra = $reps && in_array( Roles::TUTOR, (array) $this->user->roles, true ) ? Usuarios::companeros_de_familia( $reps ) : array();
			$vinc  = in_array( Roles::TUTOR, (array) $this->user->roles, true ) ? array() : $this->representados_de_cuentas_hermanas();
			$todas = $repo->obtener_varias( array_filter( array_merge( array( $socio ), $reps, $extra, $vinc ) ) );

			$this->persona = $socio ? ( $todas[ $socio ] ?? null ) : null;
			foreach ( $reps as $n ) {
				$p = $todas[ $n ] ?? null;
				if ( $p && $p->es_participante() && ! ( null !== $p->edad() && $p->edad() >= 18 ) ) {
					$this->representados[ $n ] = $p;
				} else {
					$this->retirados[] = $n;
				}
			}
			foreach ( $vinc as $n ) {
				$p = $todas[ $n ] ?? null;
				if ( $p && $p->es_participante() && ! ( null !== $p->edad() && $p->edad() >= 18 ) ) {
					$this->vinculados[ $n ] = $p;
				}
			}
			foreach ( $extra as $n ) {
				$p = $todas[ $n ] ?? null;
				// Solo menores con membresía activa: los adultos de una familia (padres scouters) no se añaden.
				if ( $p && $p->es_participante() && ! ( null !== $p->edad() && $p->edad() >= 18 ) ) {
					Usuarios::anadir_meta_multiple( $this->user->ID, Usuarios::META_REPRESENTADO, array( $n ) );
					$this->representados[ $n ] = $p;
					Logger::log( 'tutela_ampliada_familia', 'user', (string) $this->user->ID, array( 'socio' => $n ) );
				}
			}
		} catch ( CrmException $e ) {
			$this->error_crm = __( 'The CRM could not be queried. Try again in a few minutes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
	}

	/**
	 * Menores que representan las otras cuentas familiares con el mismo email que esta cuenta (p. ej. la de scouter y la
	 * familiar de la misma persona), del grupo actual y activas. @return string[] Nº Scouts de España.
	 */
	private function representados_de_cuentas_hermanas(): array {
		$email = EmailCuenta::leer( $this->user->ID );
		if ( null === $email ) {
			return array();
		}
		$nums = array();
		foreach ( EmailCuenta::usuarios( $email ) as $u ) {
			if ( $u->ID === $this->user->ID || ! in_array( Roles::TUTOR, (array) $u->roles, true ) || ! Usuarios::del_grupo_actual( $u ) || Usuarios::DESACTIVADO === Usuarios::estado( $u ) ) {
				continue;
			}
			$nums = array_merge( $nums, Usuarios::representados( $u->ID ) );
		}
		return array_values( array_diff( array_unique( $nums ), Usuarios::representados( $this->user->ID ) ) );
	}

	/** ¿Representa a esta persona (menor) en las inscripciones? La cuenta familiar, o la misma persona con otra cuenta suya. */
	public function representa( string $num ): bool {
		return isset( $this->representados[ $num ] ) || isset( $this->vinculados[ $num ] );
	}

	public function es_admin(): bool {
		return $this->user->has_cap( 'manage_options' );
	}

	/** ¿Tiene una ficha propia con membresía activa? */
	public function socio_activo(): bool {
		return $this->persona && $this->persona->tiene_membresia_activa();
	}

	/** Roles del área privada que están vigentes ahora mismo según el CRM. @return string[] */
	public function roles_vigentes(): array {
		$vigentes = array();
		foreach ( Usuarios::roles_asde( $this->user ) as $rol ) {
			if ( Roles::TUTOR === $rol ? (bool) $this->representados : $this->socio_activo() ) {
				$vigentes[] = $rol;
			}
		}
		return $vigentes;
	}

	/** v0.50 (doc 50): la oficina federal entra sin ficha, solo en una web de la oficina. */
	public function es_oficina(): bool {
		return Organizacion::es_oficina() && in_array( Roles::OFICINA, Usuarios::roles_asde( $this->user ), true );
	}

	public function puede_acceder(): bool {
		return $this->es_admin() || $this->es_oficina() || (bool) $this->roles_vigentes();
	}

	public function motivo(): string {
		if ( $this->error_crm ) {
			return $this->error_crm;
		}
		$roles = Usuarios::roles_asde( $this->user );
		if ( ! $roles ) {
			return __( 'Your user does not have access to the private area. If you think this is a mistake, contact the group\'s Secretary\'s office.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( in_array( Roles::TUTOR, $roles, true ) && ! $this->representados && count( $roles ) === 1 ) {
			return __( 'Your family account no longer has any participants in its care (they have turned 18 or no longer have an active membership).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( '' === Usuarios::socio( $this->user->ID ) ) {
			return __( 'Your user is not linked to any CRM record. Ask the group\'s Secretary\'s office to check your registration.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( ! $this->persona ) {
			return __( 'Your record cannot be found in the group\'s CRM. Contact the Secretary\'s office.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		return __( 'Your membership is not active in the group\'s CRM. Contact the Secretary\'s office.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
	}

	/** Nombre para saludar. */
	public function nombre(): string {
		if ( $this->persona ) {
			return $this->persona->nombre;
		}
		if ( $this->representados ) {
			return sprintf( /* translators: %s: text */ __( '%s family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Contacto::lista( array_map( static fn( Persona $p ) => $p->nombre, $this->representados ) ) );
		}
		return $this->user->display_name;
	}

	/** Secciones que puede ver como scouter (asignadas en el alta). @return string[] */
	public function secciones_scouter(): array {
		return in_array( Roles::SCOUTER, $this->roles_vigentes(), true ) ? Usuarios::secciones( $this->user->ID ) : array();
	}

	/** Visión global de todo el grupo (Secretaría, Coordinación, administradores). */
	public function vision_global(): bool {
		return $this->es_admin() || $this->es_oficina() || (bool) array_intersect( array( Roles::SECRETARIA, Roles::COORDINACION ), $this->roles_vigentes() );
	}
}
