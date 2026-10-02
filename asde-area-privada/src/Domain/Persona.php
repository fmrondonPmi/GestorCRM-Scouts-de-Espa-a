<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Persona del CRM (Contacts) con los campos que necesita el área privada.
 * Vive solo en memoria durante la petición: nada de esto se guarda en WordPress.
 * No contiene datos de salud (esos los lee el módulo de informes bajo permiso).
 */
final class Persona {

	/**
	 * @param string   $num_socio_asde      Nº de socio/a Scouts de España (numerosocio_c): clave estable del CRM.
	 * @param string   $num_socio_local_crm Nº local/territorial tal cual está en el CRM (sin normalizar).
	 * @param string[] $secciones           Slugs de las secciones con membresía activa.
	 * @param array<int, array{nombre:string, apellidos:string, email:string}> $tutores Tutor 1 y 2 de la ficha.
	 */
	public function __construct(
		public string $id,
		public string $num_socio_asde,
		public string $num_socio_local_crm,
		public string $nombre,
		public string $apellidos,
		public string $fecha_nacimiento,
		public string $email,
		public string $movil,
		public array $secciones,
		public string $cargo,
		public array $tutores
	) {}

	public static function from_crm( array $entry ): self {
		$f = $entry['fields'];

		// "Secciones actuales" (multienum: ^Scouts^,^Rovers^) refleja las membresías activas.
		$secciones = array();
		foreach ( explode( ',', (string) ( $f['stic_relationship_type_c'] ?? '' ) ) as $raw ) {
			$slug = Secciones::from_crm( trim( $raw, '^ ' ) );
			if ( $slug ) {
				$secciones[] = $slug;
			}
		}

		$tutores = array();
		foreach ( array( 1, 2 ) as $n ) {
			$t = array(
				'nombre'    => trim( (string) ( $f[ "tutor{$n}_nombre_c" ] ?? '' ) ),
				'apellidos' => trim( (string) ( $f[ "tutor{$n}_apellidos_c" ] ?? '' ) ),
				'email'     => self::email( (string) ( $f[ "tutor{$n}_email_c" ] ?? '' ) ),
			);
			if ( '' !== implode( '', $t ) ) {
				$tutores[ $n ] = $t;
			}
		}

		return new self(
			(string) $entry['id'],
			trim( (string) ( $f['numerosocio_c'] ?? '' ) ),
			trim( (string) ( $f['numsocioantiguo_enficha_c'] ?? '' ) ),
			trim( (string) ( $f['first_name'] ?? '' ) ),
			trim( (string) ( $f['last_name'] ?? '' ) ),
			(string) ( $f['birthdate'] ?? '' ),
			self::email( (string) ( $f['email1'] ?? '' ) ),
			trim( (string) ( $f['phone_mobile'] ?? '' ) ),
			array_values( array_unique( $secciones ) ),
			(string) ( $f['cargoeducativo_c'] ?? '' ),
			$tutores
		);
	}

	public function nombre_completo(): string {
		return trim( $this->nombre . ' ' . $this->apellidos );
	}

	public function edad(): ?int {
		$nacimiento = $this->fecha_nacimiento ? date_create( $this->fecha_nacimiento, wp_timezone() ) : false;
		if ( ! $nacimiento ) {
			return null;
		}
		return (int) date_diff( $nacimiento, date_create( 'today', wp_timezone() ) )->y;
	}

	public function es_menor(): bool {
		$edad = $this->edad();
		return null !== $edad && $edad < 18;
	}

	public function tiene_membresia_activa(): bool {
		return (bool) $this->secciones;
	}

	/** Primera sección educativa activa (Castores…Rovers), o null. */
	public function seccion_educativa(): ?string {
		foreach ( $this->secciones as $slug ) {
			if ( Secciones::is_educativa( $slug ) ) {
				return $slug;
			}
		}
		return null;
	}

	public function es_participante(): bool {
		return null !== $this->seccion_educativa();
	}

	/** Membresía activa como Scouter o Voluntariado de apoyo. */
	public function es_adulto_voluntario(): bool {
		return (bool) array_filter( $this->secciones, array( Secciones::class, 'is_adulta' ) );
	}

	/**
	 * Emails de la ficha por campo del CRM (solo los que tienen valor).
	 *
	 * @return array<string, string> campo => email
	 */
	public function emails_por_campo(): array {
		$out = array(
			'email1'         => $this->email,
			'tutor1_email_c' => $this->tutores[1]['email'] ?? '',
			'tutor2_email_c' => $this->tutores[2]['email'] ?? '',
		);
		return array_filter( $out );
	}

	/** @return string[] */
	public function emails(): array {
		return array_values( array_unique( $this->emails_por_campo() ) );
	}

	private static function email( string $value ): string {
		$value = strtolower( trim( $value ) );
		return is_email( $value ) ? $value : '';
	}
}
