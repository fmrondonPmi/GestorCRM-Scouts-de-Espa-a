<?php
namespace AsdeBalears\AreaPrivada\Sepa;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Autorizaciones (mandatos) de adeudo directo SEPA ({prefix}asde_sepa_mandatos).
 *
 * Una autorización es de una familia de Tesorería (la que paga la cuota) y la
 * firma quien tiene la cuenta: una cuenta familiar (madre, padre o tutor/a) o el
 * propio asociado, si es mayor de edad. Solo hay una vigente por familia: al
 * firmar otra, la anterior queda revocada («sustituida»).
 *
 * Qué se guarda y cómo:
 * - v0.33 (P-131, decisión del usuario): la **cuenta** (IBAN, titular y entidad) se guarda
 *   en la ficha del CRM de cada miembro de la familia (CuentaCrm), no aquí. La web guarda
 *   la **autorización**: la dirección del deudor y el lugar de la firma, en JSON cifrado
 *   (libsodium, con una clave ligada al id del mandato y a la clave del sitio, que no está
 *   en la base de datos), y en claro el IBAN enmascarado (ES91 **** … 1332) y su huella
 *   (HMAC), para enseñarlo y comprobar antes de cobrar que la cuenta del CRM es la
 *   autorizada. Las autorizaciones de antes de la v0.33 llevaban también titular e IBAN
 *   cifrados (cuenta_local()); «Pasar las cuentas al CRM» (pasar_al_crm()) los mueve;
 * - quién firmó, cuándo, y una huella de la IP (nunca la IP);
 * - la referencia única del mandato (M684-000012), la fecha de la firma y si ya
 *   se ha usado en una remesa (la primera vez es FRST y después RCUR).
 *
 * La norma SEPA pide conservar el mandato mientras esté vigente y 14 meses
 * después del último adeudo: los revocados se vacían pasado ese tiempo (purgar()).
 */
final class Mandatos {

	public const ACTIVO   = 'activo';
	public const REVOCADO = 'revocado';

	/** Versión del texto legal aceptado (por si algún día cambia). */
	public const TEXTO_VERSION = 'v1';

	/** Meses que se conservan los datos de un mandato revocado. */
	public const MESES_CONSERVACION = 14;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_sepa_mandatos';
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
				familia_id bigint(20) unsigned NOT NULL,
				referencia varchar(35) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'activo',
				iban_mask varchar(45) NOT NULL DEFAULT '',
				iban_huella varchar(64) NOT NULL DEFAULT '',
				datos_enc text NULL,
				texto_version varchar(10) NOT NULL DEFAULT 'v1',
				firmado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				firmado_en datetime NOT NULL,
				fecha_firma date NOT NULL,
				firma_huella varchar(64) NOT NULL DEFAULT '',
				usado_en datetime NULL,
				revocado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revocado_en datetime NULL,
				motivo varchar(120) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo_estado (grupo, estado),
				KEY familia_id (familia_id)
			) $charset;"
		);
	}

	/** Referencia única del mandato: M + grupo + - + id de 6 cifras (M684-000012). */
	public static function referencia( string $grupo, int $id ): string {
		return sprintf( 'M%s-%06d', $grupo, $id );
	}

	// ---------------------------------------------------------------------
	// Texto legal
	// ---------------------------------------------------------------------

	/**
	 * Texto de la autorización (orden de domiciliación de adeudo directo SEPA Core).
	 *
	 * @param array $cfg Configuración del acreedor (Config::de).
	 * @return string[] Párrafos.
	 */
	public static function texto( array $cfg ): array {
		$acreedor = '' !== $cfg['nombre'] ? $cfg['nombre'] : Grupo::nombre();
		return array(
			sprintf( asde_t( 'Mediante la firma de esta orden de domiciliación, el deudor autoriza (A) a %s a enviar instrucciones a la entidad del deudor para adeudar su cuenta y (B) a la entidad para efectuar los adeudos en su cuenta siguiendo las instrucciones de %s.' ), $acreedor, $acreedor ),
			asde_t( 'Como parte de sus derechos, el deudor está legitimado al reembolso por su entidad en los términos y condiciones del contrato suscrito con la misma. La solicitud de reembolso deberá efectuarse dentro de las ocho semanas que siguen a la fecha de adeudo en cuenta. Puede obtener información adicional sobre sus derechos en su entidad financiera.' ),
			sprintf( asde_t( 'Esta autorización sirve para cobrar la cuota y otros pagos del grupo (por ejemplo, las cuotas de cada ronda). Tipo de pago: recurrente. %s comunicará al deudor la fecha y el importe de cada adeudo con al menos %d días de antelación (por email y en «Mis pagos»).' ), $acreedor, (int) $cfg['dias_aviso'] ),
			asde_t( 'El deudor puede anular esta autorización cuando quiera desde «Mis pagos» o avisando a la Tesorería del grupo.' ),
		);
	}

	// ---------------------------------------------------------------------
	// Quién puede firmar
	// ---------------------------------------------------------------------

	/**
	 * ¿Puede este usuario firmar (o anular) la autorización de esa familia?
	 * Las cuentas familiares, por los menores que representan; el propio asociado,
	 * solo si es mayor de edad. @return true|string true o el motivo por el que no.
	 */
	public static function puede_firmar( Ambito $a, array $familia ) {
		if ( ! user_can( $a->user, 'asde_sepa_autorizar' ) ) {
			return asde_t( 'Tu cuenta no puede firmar autorizaciones de domiciliación.' );
		}
		if ( array_intersect( $familia['miembros'], array_map( 'strval', array_keys( $a->representados ) ) ) ) {
			return true; // Cuenta familiar: madre, padre o tutor/a de alguno de los menores de la familia.
		}
		$p = $a->persona;
		if ( $p && in_array( $p->num_socio_asde, $familia['miembros'], true ) ) {
			return null !== $p->edad() && $p->edad() >= 18 ? true : asde_t( 'La autorización tiene que firmarla tu madre, padre o tutor/a desde su cuenta: por ser menor de edad no puedes firmarla tú.' );
		}
		return asde_t( 'Esa familia no es la tuya.' );
	}

	// ---------------------------------------------------------------------
	// Alta, consulta y baja
	// ---------------------------------------------------------------------

	/**
	 * Valida y limpia lo escrito en el formulario.
	 *
	 * @return array{datos:array, errores:string[]}
	 */
	public static function validar( array $in ): array {
		$err   = array();
		$texto = static fn( $v, int $max ) => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $v ) ) ), 0, $max );
		$d     = array(
			'titular'   => $texto( $in['titular'] ?? '', 70 ),
			'iban'      => Iban::normalizar( (string) ( $in['iban'] ?? '' ) ),
			'entidad'   => $texto( $in['entidad'] ?? '', 70 ),
			'calle'     => $texto( $in['calle'] ?? '', 70 ),
			'cp'        => $texto( $in['cp'] ?? '', 10 ),
			'poblacion' => $texto( $in['poblacion'] ?? '', 50 ),
			'provincia' => $texto( $in['provincia'] ?? '', 40 ),
			'pais'      => $texto( $in['pais'] ?? 'España', 40 ) ?: 'España',
			'lugar'     => $texto( $in['lugar'] ?? '', 50 ),
		);
		if ( mb_strlen( $d['titular'] ) < 3 || ! str_contains( $d['titular'], ' ' ) ) {
			$err[] = asde_t( 'Escribe el nombre y los apellidos del titular de la cuenta.' );
		}
		$e = Iban::error( $d['iban'] );
		if ( '' !== $e ) {
			$err[] = $e;
		} elseif ( strlen( $d['iban'] ) > CuentaCrm::MAX_IBAN ) {
			/* translators: %d: caracteres */
			$err[] = sprintf( asde_t( 'La ficha del CRM solo admite cuentas de hasta %d caracteres (las españolas tienen 24): usa una cuenta española.' ), CuentaCrm::MAX_IBAN );
		}
		if ( '' === $d['calle'] || '' === $d['cp'] || '' === $d['poblacion'] ) {
			$err[] = asde_t( 'Escribe la dirección del titular (calle, código postal y población).' );
		}
		if ( '' === $d['lugar'] ) {
			$d['lugar'] = $d['poblacion'];
		}
		return array(
			'datos'   => $d,
			'errores' => $err,
		);
	}

	/**
	 * Firma una autorización nueva para la familia (y revoca la vigente, si la hay).
	 *
	 * @param array $datos Salida de validar()['datos'].
	 * @return int|\WP_Error Id del mandato.
	 */
	public static function crear( int $familia_id, array $datos, int $user_id ) {
		global $wpdb;
		$familia = FamiliasTable::obtener( $familia_id );
		if ( ! $familia ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'Esa familia no existe.' ) );
		}
		$cfg = Config::de();
		if ( ! Config::lista( $cfg ) ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'El grupo aún no tiene activadas las domiciliaciones SEPA.' ) );
		}
		// v0.33: primero la cuenta en las fichas del CRM; si el CRM no la guarda, no hay autorización.
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'La conexión con el CRM no está disponible.' ) );
		}
		try {
			$guardada = CuentaCrm::guardar( $repo, $familia['miembros'], $datos );
		} catch ( CrmException $e ) {
			$guardada = new \WP_Error( 'asde_sepa', asde_t( 'El CRM no responde: inténtalo más tarde.' ) );
		}
		if ( is_wp_error( $guardada ) ) {
			return $guardada;
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$hoy = current_time( 'Y-m-d' );
		$wpdb->insert(
			self::table(),
			array(
				'grupo'         => Grupo::numero(),
				'familia_id'    => $familia_id,
				'estado'        => self::ACTIVO,
				'iban_mask'     => Iban::enmascarar( $datos['iban'] ),
				'iban_huella'   => CuentaCrm::huella( $datos['iban'] ),
				'texto_version' => self::TEXTO_VERSION,
				'firmado_por'   => $user_id,
				'firmado_en'    => current_time( 'mysql', true ),
				'fecha_firma'   => $hoy,
				'firma_huella'  => '' !== $ip ? Crypto::hmac( 'sepa|ip|' . $ip ) : '',
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'No se ha podido guardar la autorización. Inténtalo de nuevo.' ) );
		}
		$wpdb->update(
			self::table(),
			array(
				'referencia' => self::referencia( Grupo::numero(), $id ),
				// Sin la cuenta (está en el CRM): solo la dirección del deudor y el lugar de la firma.
				'datos_enc'  => Crypto::encrypt_contexto( (string) wp_json_encode( array_diff_key( $datos, array_flip( array( 'iban', 'titular', 'entidad' ) ) ) ), 'sepa|mandato|' . $id ),
			),
			array( 'id' => $id )
		);
		// Una sola vigente por familia: la anterior queda sustituida.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET estado = %s, revocado_por = %d, revocado_en = %s, motivo = %s WHERE familia_id = %d AND estado = %s AND id <> %d', self::table(), self::REVOCADO, $user_id, current_time( 'mysql', true ), 'Sustituida por una autorización nueva', $familia_id, self::ACTIVO, $id ) ); // phpcs:ignore WordPress.DB
		Logger::log( 'sepa_mandato_firmado', 'familia', (string) $familia_id, array( 'mandato' => $id ) );
		return $id;
	}

	/**
	 * Cuenta guardada en la propia autorización (las firmadas antes de la v0.33), o null.
	 *
	 * @return array{iban:string, titular:string, entidad:string}|null
	 */
	public static function cuenta_local( array $m ): ?array {
		return '' !== (string) $m['datos']['iban'] ? array(
			'iban'    => (string) $m['datos']['iban'],
			'titular' => (string) $m['datos']['titular'],
			'entidad' => '',
		) : null;
	}

	/** ¿Quedan autorizaciones del grupo con la cuenta guardada en la web (de antes de la v0.33)? */
	public static function hay_cuentas_locales(): bool {
		global $wpdb;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND datos_enc IS NOT NULL', self::table(), Grupo::numero() ), ARRAY_A ) as $fila ) { // phpcs:ignore WordPress.DB
			if ( null !== self::cuenta_local( self::preparar( $fila ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * «Pasar las cuentas al CRM» (v0.33): la cuenta de cada autorización vigente de antes de la
	 * v0.33 se escribe en las fichas de su familia y se borra de la web; de las anuladas solo se
	 * borra (no se escriben en el CRM).
	 *
	 * @return array{pasadas:int, borradas:int, errores:string[]}
	 */
	public static function pasar_al_crm( PersonaRepository $repo ): array {
		global $wpdb;
		$res   = array(
			'pasadas'  => 0,
			'borradas' => 0,
			'errores'  => array(),
		);
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND datos_enc IS NOT NULL', self::table(), Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $filas as $fila ) {
			$m      = self::preparar( $fila );
			$cuenta = self::cuenta_local( $m );
			if ( null === $cuenta ) {
				continue;
			}
			if ( self::ACTIVO === $m['estado'] ) {
				$familia = FamiliasTable::obtener( $m['familia_id'] );
				try {
					$r = $familia ? CuentaCrm::guardar( $repo, $familia['miembros'], $cuenta ) : new \WP_Error( 'asde_sepa', asde_t( 'Esa familia no existe.' ) );
				} catch ( CrmException $e ) {
					$r = new \WP_Error( 'asde_sepa', $e->getMessage() );
				}
				if ( is_wp_error( $r ) ) {
					/* translators: 1: referencia de la autorización, 2: motivo */
					$res['errores'][] = sprintf( asde_t( '%1$s: %2$s' ), $m['referencia'], $r->get_error_message() );
					continue;
				}
				++$res['pasadas'];
			} else {
				++$res['borradas'];
			}
			$wpdb->update(
				self::table(),
				array( 'datos_enc' => Crypto::encrypt_contexto( (string) wp_json_encode( array_diff_key( $m['datos'], array_flip( array( 'iban', 'titular', 'entidad' ) ) ) ), 'sepa|mandato|' . $m['id'] ) ),
				array( 'id' => $m['id'] )
			);
		}
		Logger::log( 'sepa_cuentas_al_crm', 'grupo', Grupo::numero(), array( 'pasadas' => $res['pasadas'], 'borradas' => $res['borradas'], 'errores' => count( $res['errores'] ) ) );
		return $res;
	}

	/** Del grupo actual. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::preparar( $r ) : null;
	}

	/** La autorización vigente de una familia, o null. */
	public static function activo_de_familia( int $familia_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE familia_id = %d AND grupo = %s AND estado = %s ORDER BY id DESC LIMIT 1', self::table(), $familia_id, Grupo::numero(), self::ACTIVO ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::preparar( $r ) : null;
	}

	/** @return array<int, array> familia_id => autorización vigente (todas las del grupo). */
	public static function activos(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND estado = %s ORDER BY id', self::table(), Grupo::numero(), self::ACTIVO ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['familia_id'] ] = self::preparar( $r );
		}
		return $out;
	}

	/** ¿Tiene la familia alguna autorización (vigente o no)? Para no eliminarla (v0.35.1). */
	public static function hay_de_familia( int $familia_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE familia_id = %d LIMIT 1', self::table(), $familia_id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array[] Todas las de una familia, las más recientes primero (para el historial; con los datos descifrados si aún se conservan). */
	public static function de_familia( int $familia_id ): array {
		global $wpdb;
		return array_map( array( self::class, 'preparar' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE familia_id = %d AND grupo = %s ORDER BY id DESC', self::table(), $familia_id, Grupo::numero() ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	public static function revocar( int $id, int $user_id, string $motivo ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->update(
			self::table(),
			array(
				'estado'       => self::REVOCADO,
				'revocado_por' => $user_id,
				'revocado_en'  => current_time( 'mysql', true ),
				'motivo'       => mb_substr( sanitize_text_field( $motivo ), 0, 120 ),
			),
			array(
				'id'     => $id,
				'grupo'  => Grupo::numero(),
				'estado' => self::ACTIVO,
			)
		);
		if ( $ok ) {
			Logger::log( 'sepa_mandato_revocado', 'mandato', (string) $id );
		}
		return $ok;
	}

	/** La primera remesa enviada con estos mandatos los deja «usados»: desde entonces son RCUR. @param int[] $ids */
	public static function marcar_usados( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( wp_parse_id_list( $ids ) ) );
		if ( $ids ) {
			$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET usado_en = %s WHERE usado_en IS NULL AND id IN ($in)", array_merge( array( self::table(), current_time( 'mysql', true ) ), $ids ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** FRST la primera vez que se usa una autorización y RCUR las siguientes. */
	public static function secuencia( array $m ): string {
		return null === $m['usado_en'] ? 'FRST' : 'RCUR';
	}

	/** Vacía los datos de los mandatos revocados hace más de 14 meses (tarea diaria). @return int */
	public static function purgar(): int {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::MESES_CONSERVACION . ' months' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET datos_enc = NULL WHERE estado = %s AND datos_enc IS NOT NULL AND revocado_en < %s', self::table(), self::REVOCADO, $limite ) ); // phpcs:ignore WordPress.DB
	}

	// ---------------------------------------------------------------------

	/** Fila con los datos descifrados en $m['datos'] (vacíos si ya se borraron). */
	private static function preparar( array $fila ): array {
		$json  = null !== $fila['datos_enc'] ? Crypto::decrypt_contexto( (string) $fila['datos_enc'], 'sepa|mandato|' . (int) $fila['id'] ) : null;
		$datos = null !== $json ? json_decode( $json, true ) : null;
		$datos = is_array( $datos ) ? $datos : array();
		$fila['datos'] = $datos + array(
			'titular'   => '',
			'iban'      => '',
			'entidad'   => '',
			'calle'     => '',
			'cp'        => '',
			'poblacion' => '',
			'provincia' => '',
			'pais'      => '',
			'lugar'     => '',
		);
		foreach ( array( 'id', 'familia_id', 'firmado_por', 'revocado_por' ) as $k ) {
			$fila[ $k ] = (int) $fila[ $k ];
		}
		return $fila;
	}
}
