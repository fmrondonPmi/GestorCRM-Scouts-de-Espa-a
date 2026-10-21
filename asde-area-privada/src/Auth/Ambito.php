<?php
namespace AsdeBalears\AreaPrivada\Auth;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
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
			$this->error_crm = asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' );
			return;
		}
		try {
			$socio = Usuarios::socio( $this->user->ID );
			$reps  = Usuarios::representados( $this->user->ID );
			$todas = $repo->obtener_varias( array_filter( array_merge( array( $socio ), $reps ) ) );

			$this->persona = $socio ? ( $todas[ $socio ] ?? null ) : null;
			foreach ( $reps as $n ) {
				$p = $todas[ $n ] ?? null;
				if ( $p && $p->es_participante() && ! ( null !== $p->edad() && $p->edad() >= 18 ) ) {
					$this->representados[ $n ] = $p;
				} else {
					$this->retirados[] = $n;
				}
			}
		} catch ( CrmException $e ) {
			$this->error_crm = asde_t( 'No se ha podido consultar el CRM. Inténtalo de nuevo en unos minutos.' );
		}
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
			return asde_t( 'Tu usuario no tiene acceso al área privada. Si crees que es un error, contacta con la Secretaría del grupo.' );
		}
		if ( in_array( Roles::TUTOR, $roles, true ) && ! $this->representados && count( $roles ) === 1 ) {
			return asde_t( 'Tu cuenta familiar ya no tiene participantes a su cargo (han cumplido 18 años o ya no tienen membresía activa).' );
		}
		if ( '' === Usuarios::socio( $this->user->ID ) ) {
			return asde_t( 'Tu usuario no está vinculado a ninguna ficha del CRM. Pide a la Secretaría del grupo que revise tu alta.' );
		}
		if ( ! $this->persona ) {
			return asde_t( 'No se encuentra tu ficha en el CRM del grupo. Contacta con la Secretaría.' );
		}
		return asde_t( 'Tu membresía no está activa en el CRM del grupo. Contacta con la Secretaría.' );
	}

	/** Nombre para saludar. */
	public function nombre(): string {
		if ( $this->persona ) {
			return $this->persona->nombre;
		}
		if ( $this->representados ) {
			return sprintf( asde_t( 'familia de %s' ), Contacto::lista( array_map( static fn( Persona $p ) => $p->nombre, $this->representados ) ) );
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
