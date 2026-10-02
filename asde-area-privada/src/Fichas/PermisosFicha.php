<?php
namespace AsdeBalears\AreaPrivada\Fichas;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Qué puede hacer un usuario con cada campo de la ficha de una persona
 * (matriz de docs/02, aplicada al ámbito de docs/12):
 *
 *   EDITAR    se guarda directamente en el CRM
 *   PROPONER  entra en la cola de cambios y lo aprueba otra persona
 *   VER       solo lectura
 *
 * - Secretaría y administradores: editan todo.
 * - Scouter: contacto y tutores de los socios de sus secciones.
 * - Coordinación: anota en los campos de texto de salud y alimentación.
 * - Cada persona: su email y sus teléfonos.
 * - Cuenta familiar: propone cambios en la ficha de sus menores (salvo membresía).
 * Además, para editar o proponer hace falta que el usuario del CRM del grupo
 * pueda editar fichas y que el campo no sea de solo lectura.
 */
final class PermisosFicha {

	public const EDITAR   = 'editar';
	public const PROPONER = 'proponer';
	public const VER      = 'ver';

	public function __construct( private Ambito $a, private Persona $p ) {}

	public function es_propia(): bool {
		return $this->a->persona && $this->a->persona->num_socio_asde === $this->p->num_socio_asde;
	}

	public function es_representado(): bool {
		return isset( $this->a->representados[ $this->p->num_socio_asde ] ) && in_array( Roles::TUTOR, $this->a->roles_vigentes(), true );
	}

	public function de_su_seccion(): bool {
		return (bool) array_intersect( $this->a->secciones_scouter(), $this->p->secciones );
	}

	public function puede_ver(): bool {
		return $this->a->vision_global() || $this->es_propia() || $this->es_representado() || $this->de_su_seccion();
	}

	public function ve_salud(): bool {
		if ( $this->a->vision_global() ) {
			return true;
		}
		return $this->es_representado() || ( $this->a->user->has_cap( 'asde_view_health' ) && $this->de_su_seccion() );
	}

	public function ve_campo( string $campo ): bool {
		$def = CamposFicha::todos()[ $campo ] ?? null;
		if ( ! $def ) {
			return false;
		}
		// Formación y certificado de delitos sexuales: solo en fichas de adultos.
		if ( in_array( $campo, CamposFicha::SOLO_ADULTOS, true ) && null !== $this->p->edad() && $this->p->edad() < 18 ) {
			return false;
		}
		// El cargo, solo con membresía de Scouter o de Voluntariado.
		if ( in_array( $campo, CamposFicha::SOLO_VOLUNTARIOS, true ) && ! array_intersect( Secciones::ADULTAS, $this->p->secciones ) ) {
			return false;
		}
		return match ( $def[2] ) {
			'salud'  => $this->ve_salud(),
			'global' => $this->a->vision_global(),
			default  => true,
		};
	}

	/** Acción permitida por el rol (sin tener en cuenta el CRM). */
	public function accion_rol( string $campo ): string {
		$def = CamposFicha::todos()[ $campo ] ?? null;
		if ( ! $def || isset( CamposFicha::SOLO_LECTURA[ $campo ] ) || ! $this->ve_campo( $campo ) ) {
			return self::VER;
		}
		$grupo = $def[1];
		$user  = $this->a->user;

		if ( $this->a->es_admin() || ( $user->has_cap( 'asde_edit_all' ) && $this->a->vision_global() ) ) {
			return self::EDITAR;
		}
		if ( $this->de_su_seccion() && $user->has_cap( 'asde_edit_section_contact' ) && in_array( $grupo, array( 'contacto', 'tutores' ), true ) ) {
			return self::EDITAR;
		}
		if ( in_array( $grupo, array( 'salud', 'alimentacion' ), true ) && $user->has_cap( 'asde_annotate_health' ) && $this->a->vision_global() && self::es_texto_libre( $campo ) ) {
			return self::EDITAR;
		}
		if ( $this->es_propia() && $user->has_cap( 'asde_edit_self_contact' ) && in_array( $campo, CamposFicha::CONTACTO_PROPIO, true ) ) {
			return self::EDITAR;
		}
		if ( $this->es_representado() && $user->has_cap( 'asde_propose_ward_changes' ) && 'membresia' !== $grupo ) {
			return self::PROPONER;
		}
		return self::VER;
	}

	/**
	 * Acción final para un campo (rol + CRM) y, si es solo lectura, el motivo.
	 *
	 * @param array $meta Metadatos del campo en el CRM (Crm\Metadatos)
	 * @return array{0:string, 1:string} [acción, motivo de solo lectura]
	 */
	public function accion( string $campo, array $meta ): array {
		if ( isset( CamposFicha::SOLO_LECTURA[ $campo ] ) ) {
			return array( self::VER, CamposFicha::SOLO_LECTURA[ $campo ] );
		}
		$accion = $this->accion_rol( $campo );
		if ( self::VER === $accion ) {
			return array( self::VER, 'Tu rol no permite cambiar este dato.' );
		}
		if ( ! Escritura::disponible() ) {
			return array( self::VER, 'El usuario del CRM del grupo no tiene permiso para editar fichas.' );
		}
		if ( ! $meta || ! empty( $meta['calculado'] ) || 'multienum' === ( $meta['tipo'] ?? '' ) || ! self::tipo_editable( (string) ( $meta['tipo'] ?? '' ) ) ) {
			return array( self::VER, 'Este campo no se puede editar desde la web.' );
		}
		return array( $accion, '' );
	}

	public static function tipo_editable( string $tipo ): bool {
		return in_array( $tipo, array( 'varchar', 'name', 'phone', 'email', 'url', 'text', 'date', 'bool', 'enum', 'dynamicenum', 'radioenum', 'int' ), true );
	}

	/** Campos de salud de texto libre (observaciones), los que puede anotar Coordinación. */
	public static function es_texto_libre( string $campo ): bool {
		static $libres = null;
		if ( null === $libres ) {
			$libres = array();
			foreach ( \AsdeBalears\AreaPrivada\Informes\Catalogo::columnas() as $col ) {
				if ( 'salud' === $col[1] && 'texto' === $col[3] ) {
					$libres[] = $col[2][0];
				}
			}
		}
		return in_array( $campo, $libres, true );
	}
}
