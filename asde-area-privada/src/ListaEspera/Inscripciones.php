<?php
namespace AsdeBalears\AreaPrivada\ListaEspera;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia del plugin ({prefix}asde_lista_espera): no hay función de WordPress para ella y los datos cambian en cada petición, no se cachean.

/**
 * Lista de espera (v0.32, doc 39): personas que aún no son del grupo y quieren entrar.
 *
 * - Se apuntan con el enlace del grupo (Frontend\Views\InscripcionEsperaView) y confirman
 *   su email; las no confirmadas en DIAS_CONFIRMAR días se borran.
 * - Todo lo escrito va cifrado (datos_enc) y el historial de contactos también
 *   (historial_enc). En claro solo el grupo, el estado, la sección asignada, fechas y dos
 *   huellas (HMAC) para encontrar el email y los repetidos sin descifrar.
 * - Los datos se guardan MESES meses desde la confirmación o la última renovación; la tarea
 *   diaria avisa DIAS_AVISO días antes y borra las caducadas.
 * - Al darla de alta en el CRM (Personas → Alta de socio/a) la fila se borra en el momento.
 */
final class Inscripciones {

	public const SIN_CONFIRMAR  = 'sin_confirmar';
	public const EN_ESPERA      = 'en_espera';
	public const CONTACTADA     = 'contactada';
	public const PLAZA_OFRECIDA = 'plaza_ofrecida';
	public const DESCARTADA     = 'descartada';

	public const ESTADOS = array(
		self::SIN_CONFIRMAR  => 'Sin confirmar el email',
		self::EN_ESPERA      => 'En espera',
		self::CONTACTADA     => 'Contactada',
		self::PLAZA_OFRECIDA => 'Plaza ofrecida',
		self::DESCARTADA     => 'Descartada',
	);

	/** Estados que puede poner el equipo a mano (el de sin confirmar lo quita solo el email). */
	public const ESTADOS_ELEGIBLES = array( self::EN_ESPERA, self::CONTACTADA, self::PLAZA_OFRECIDA, self::DESCARTADA );

	/** Cómo se contactó. */
	public const POR_EMAIL    = 'email';
	public const POR_TELEFONO = 'telefono';
	public const MEDIOS       = array(
		self::POR_EMAIL    => 'Email',
		self::POR_TELEFONO => 'Teléfono',
	);

	public const DIAS_CONFIRMAR = 7;
	public const MESES          = 12;
	public const DIAS_AVISO     = 30;

	/** Edad desde la que se piden los datos de la persona de contacto (como el tutor/a 1 del alta, P-88). */
	public const EDAD_ADULTA = 18;

	/** Campos de lo escrito (cifrados). */
	public const CAMPOS = array( 'nombre', 'apellidos', 'nacimiento', 'documento', 'contacto_nombre', 'contacto_apellidos', 'telefono', 'email', 'observaciones', 'idioma' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_lista_espera';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				estado varchar(16) NOT NULL DEFAULT 'sin_confirmar',
				datos_enc text NULL,
				historial_enc mediumtext NULL,
				extra_enc mediumtext NULL,
				email_huella varchar(64) NOT NULL DEFAULT '',
				persona_huella varchar(64) NOT NULL DEFAULT '',
				seccion varchar(20) NOT NULL DEFAULT '',
				sal varchar(32) NOT NULL DEFAULT '',
				contacto_medio varchar(10) NOT NULL DEFAULT '',
				contactada_en datetime NULL,
				creada datetime NOT NULL,
				confirmada_en datetime NULL,
				caduca datetime NULL,
				avisada tinyint(1) NOT NULL DEFAULT 0,
				actualizada datetime NULL,
				PRIMARY KEY  (id),
				KEY grupo_estado (grupo, estado),
				KEY email_huella (email_huella),
				KEY caduca (caduca)
			) $charset;"
		);
	}

	/** Huella de la persona en un grupo: su documento o, sin él, su nombre y su nacimiento. */
	public static function huella_persona( string $grupo, array $d ): string {
		$doc = (string) ( $d['documento'] ?? '' );
		$id  = '' !== $doc
			? 'doc:' . $doc
			: 'nom:' . strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', remove_accents( ( $d['nombre'] ?? '' ) . ( $d['apellidos'] ?? '' ) ) ) ) . ':' . ( $d['nacimiento'] ?? '' );
		return Crypto::hmac( 'espera|' . $grupo . '|' . $id );
	}

	/**
	 * Apunta a una persona (sin confirmar).
	 *
	 * @param array<string, string> $d     Lo escrito (CAMPOS), ya validado.
	 * @param array[]               $extra Respuestas a los campos propios del grupo (CamposExtra::validar).
	 */
	public static function crear( string $grupo, array $d, array $extra = array() ): int {
		global $wpdb;
		$d   = array_intersect_key( $d, array_flip( self::CAMPOS ) );
		$sal = wp_generate_password( 32, false, false );
		$wpdb->insert(
			self::table(),
			array(
				'grupo'          => $grupo,
				'estado'         => self::SIN_CONFIRMAR,
				'datos_enc'      => Crypto::encrypt( (string) wp_json_encode( $d ) ),
				'email_huella'   => EmailCuenta::huella( (string) ( $d['email'] ?? '' ) ),
				'persona_huella' => self::huella_persona( $grupo, $d ),
				'sal'            => $sal,
				'extra_enc'      => CamposExtra::cifrar( $extra, (string) ( $d['email'] ?? '' ), $sal ),
				'creada'         => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			Logger::log( 'lista_espera_inscripcion', 'lista_espera', (string) $id, array( 'grupo' => $grupo ) );
		}
		return $id;
	}

	/** Inscripción de la misma persona y con el mismo email en el grupo (para no repetirla, P-129). */
	public static function repetida( string $grupo, array $d ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND persona_huella = %s AND email_huella = %s ORDER BY id DESC LIMIT 1', self::table(), $grupo, self::huella_persona( $grupo, $d ), EmailCuenta::huella( (string) ( $d['email'] ?? '' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** Del grupo en el que se trabaja (pantallas del equipo). */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** De cualquier grupo (enlaces personales: el token lo comprueba quien llama). */
	public static function por_id( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Todas las del email, de todos los grupos (para reenviar los enlaces). */
	public static function por_email( string $email ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email_huella = %s ORDER BY grupo, id', self::table(), EmailCuenta::huella( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** @return array[] Del grupo en el que se trabaja, por orden de llegada (la confirmación). */
	public static function listado(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY COALESCE( confirmada_en, creada ), id', self::table(), Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$lista = array_map( array( self::class, 'preparar' ), $filas );
		// Repetidas (P-129): la misma persona apuntada con otro email.
		$cuenta = array_count_values( array_column( $lista, 'persona_huella' ) );
		foreach ( $lista as &$f ) {
			$f['repetida'] = ( $cuenta[ $f['persona_huella'] ] ?? 0 ) > 1;
		}
		unset( $f );
		return $lista;
	}

	/** Confirmadas en espera y sin contactar todavía (para el menú y el inicio). */
	public static function contar_sin_contactar(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s AND estado = %s', self::table(), Grupo::numero(), self::EN_ESPERA ) ); // phpcs:ignore WordPress.DB
	}

	/** El email se ha confirmado: entra en la lista y empieza a contar el plazo. */
	public static function confirmar( int $id ): bool {
		global $wpdb;
		$ahora = current_time( 'mysql', true );
		$ok    = (bool) $wpdb->update(
			self::table(),
			array(
				'estado'        => self::EN_ESPERA,
				'confirmada_en' => $ahora,
				'caduca'        => self::dentro_de_meses( $ahora ),
				'actualizada'   => $ahora,
			),
			array(
				'id'     => $id,
				'estado' => self::SIN_CONFIRMAR,
			)
		);
		if ( $ok ) {
			Logger::log( 'lista_espera_confirmada', 'lista_espera', (string) $id );
		}
		return $ok;
	}

	/** «Mantener mis datos»: MESES meses más desde hoy (y otro aviso cuando toque). */
	public static function renovar( int $id ): bool {
		global $wpdb;
		$ahora = current_time( 'mysql', true );
		$ok    = false !== $wpdb->update(
			self::table(),
			array(
				'caduca'      => self::dentro_de_meses( $ahora ),
				'avisada'     => 0,
				'actualizada' => $ahora,
			),
			array( 'id' => $id )
		);
		if ( $ok ) {
			Logger::log( 'lista_espera_renovada', 'lista_espera', (string) $id );
		}
		return $ok;
	}

	public static function cambiar_estado( array $fila, string $estado, string $nota = '' ): bool {
		if ( ! in_array( $estado, self::ESTADOS_ELEGIBLES, true ) || self::SIN_CONFIRMAR === $fila['estado'] ) {
			return false;
		}
		return self::guardar(
			$fila,
			array( 'estado' => $estado ),
			array(
				'tipo'  => 'estado',
				'valor' => $estado,
				'nota'  => $nota,
			)
		);
	}

	/** Sección elegida por Secretaría o Coordinación ('' = la de su edad). */
	public static function asignar_seccion( array $fila, string $seccion ): bool {
		if ( '' !== $seccion && ! Secciones::exists( $seccion ) ) {
			return false;
		}
		return self::guardar(
			$fila,
			array( 'seccion' => $seccion ),
			array(
				'tipo'  => 'seccion',
				'valor' => $seccion,
			)
		);
	}

	/**
	 * Contacto con la familia: un email enviado desde la web o una llamada (o un email propio)
	 * que se apunta a mano. Si estaba «En espera», pasa a «Contactada».
	 */
	public static function registrar_contacto( array $fila, string $medio, string $nota = '', string $asunto = '' ): bool {
		if ( ! isset( self::MEDIOS[ $medio ] ) || self::SIN_CONFIRMAR === $fila['estado'] ) {
			return false;
		}
		$cambios = array(
			'contacto_medio' => $medio,
			'contactada_en'  => current_time( 'mysql', true ),
		);
		if ( self::EN_ESPERA === $fila['estado'] ) {
			$cambios['estado'] = self::CONTACTADA;
		}
		return self::guardar(
			$fila,
			$cambios,
			array(
				'tipo'   => $medio,
				'asunto' => $asunto,
				'nota'   => $nota,
			)
		);
	}

	/** Borra la inscripción entera (alta, petición de la persona, el equipo o la caducidad). */
	public static function borrar( int $id, string $motivo, array $detalles = array() ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->delete( self::table(), array( 'id' => $id ) );
		if ( $ok ) {
			Logger::log( 'lista_espera_borrada', 'lista_espera', (string) $id, array( 'motivo' => $motivo ) + $detalles );
		}
		return $ok;
	}

	/**
	 * Tarea diaria: borra las no confirmadas a tiempo y las caducadas, y avisa a las que
	 * caducan pronto (una vez por periodo).
	 *
	 * @param callable|null $avisar fn( array $fila ): bool — por defecto Avisos::caducidad().
	 * @return array{borradas:int, avisadas:int}
	 */
	public static function mantenimiento( ?callable $avisar = null ): array {
		global $wpdb;
		$avisar  = $avisar ?? array( Avisos::class, 'caducidad' );
		$ahora   = current_time( 'mysql', true );
		$borrar  = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE ( estado = %s AND creada < %s ) OR ( caduca IS NOT NULL AND caduca < %s )', self::table(), self::SIN_CONFIRMAR, gmdate( 'Y-m-d H:i:s', time() - self::DIAS_CONFIRMAR * DAY_IN_SECONDS ), $ahora ) ); // phpcs:ignore WordPress.DB
		$borradas = 0;
		foreach ( $borrar as $id ) {
			$borradas += self::borrar( (int) $id, 'caducada' ) ? 1 : 0;
		}
		$filas    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE estado <> %s AND avisada = 0 AND caduca IS NOT NULL AND caduca < %s', self::table(), self::SIN_CONFIRMAR, gmdate( 'Y-m-d H:i:s', time() + self::DIAS_AVISO * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$avisadas = 0;
		foreach ( $filas as $f ) {
			$f = self::preparar( $f );
			// Se marca aunque el email falle: no se reintenta cada día (queda en el registro de emails).
			$wpdb->update( self::table(), array( 'avisada' => 1 ), array( 'id' => $f['id'] ) );
			$avisadas += Grupo::con( Grupos::por_numero( (string) $f['grupo'] ), static fn() => (bool) $avisar( $f ) ) ? 1 : 0;
		}
		return array(
			'borradas' => $borradas,
			'avisadas' => $avisadas,
		);
	}

	/**
	 * Lo que pide el «Alta de socio/a» (Crm\Altas::CAMPOS_PERSONA): por debajo de 18 años,
	 * el contacto va como tutor/a 1; si no, como el email y el móvil de la persona.
	 *
	 * @return array<string, string>
	 */
	public static function datos_alta( array $fila ): array {
		$d     = $fila['datos'];
		$menor = null !== $fila['edad'] && $fila['edad'] < self::EDAD_ADULTA;
		$out   = array(
			'first_name'                   => $d['nombre'],
			'last_name'                    => $d['apellidos'],
			'birthdate'                    => $d['nacimiento'],
			'stic_identification_number_c' => $d['documento'],
		);
		if ( $menor ) {
			$out += array(
				'tutor1_nombre_c'    => $d['contacto_nombre'],
				'tutor1_apellidos_c' => $d['contacto_apellidos'],
				'tutor1_email_c'     => $d['email'],
				'tutor1_movil_c'     => $d['telefono'],
			);
		} else {
			$out += array(
				'email1'       => $d['email'],
				'phone_mobile' => $d['telefono'],
			);
		}
		return array_filter( $out, static fn( $v ) => '' !== (string) $v );
	}

	/** Guarda cambios del equipo y deja constancia en el historial (cifrado). */
	private static function guardar( array $fila, array $cambios, array $evento ): bool {
		global $wpdb;
		$historial   = $fila['historial'];
		$historial[] = array(
			'fecha' => current_time( 'mysql', true ),
			'user'  => get_current_user_id(),
		) + array_map( static fn( $v ) => mb_substr( (string) $v, 0, 5000 ), $evento );
		$cambios['historial_enc'] = Crypto::encrypt( (string) wp_json_encode( array_slice( $historial, -200 ) ) );
		$cambios['actualizada']   = current_time( 'mysql', true );
		$ok                       = false !== $wpdb->update(
			self::table(),
			$cambios,
			array(
				'id'    => $fila['id'],
				'grupo' => $fila['grupo'],
			)
		);
		if ( $ok ) {
			Logger::log( 'lista_espera_' . $evento['tipo'], 'lista_espera', (string) $fila['id'], array_values( array_intersect( array( 'estado', 'seccion', 'contacto_medio' ), array_keys( $cambios ) ) ) );
		}
		return $ok;
	}

	private static function dentro_de_meses( string $desde ): string {
		$f = date_create( $desde, new \DateTimeZone( 'UTC' ) );
		return $f ? $f->modify( '+' . self::MESES . ' months' )->format( 'Y-m-d H:i:s' ) : $desde;
	}

	private static function preparar( array $fila ): array {
		$datos         = null !== $fila['datos_enc'] ? json_decode( (string) Crypto::decrypt( (string) $fila['datos_enc'] ), true ) : null;
		$historial     = null !== $fila['historial_enc'] ? json_decode( (string) Crypto::decrypt( (string) $fila['historial_enc'] ), true ) : null;
		$fila['datos'] = array_merge( array_fill_keys( self::CAMPOS, '' ), is_array( $datos ) ? array_map( 'strval', $datos ) : array() );
		unset( $fila['datos_enc'], $fila['historial_enc'] );
		$fila['historial'] = is_array( $historial ) ? $historial : array();
		$fila['extra']     = CamposExtra::descifrar( $fila['extra_enc'] ?? null, $fila['datos']['email'], (string) $fila['sal'] );
		unset( $fila['extra_enc'] );
		$fila['id']        = (int) $fila['id'];
		$fila['avisada']   = (bool) (int) $fila['avisada'];
		$nac               = \DateTime::createFromFormat( '!Y-m-d', $fila['datos']['nacimiento'], wp_timezone() );
		$fila['edad']      = $nac ? (int) $nac->diff( new \DateTime( 'today', wp_timezone() ) )->y : null;
		$fila['seccion_edad']  = Secciones::por_edad( $fila['datos']['nacimiento'] );
		$fila['seccion_final'] = '' !== $fila['seccion'] ? $fila['seccion'] : $fila['seccion_edad'];
		return $fila;
	}
}
