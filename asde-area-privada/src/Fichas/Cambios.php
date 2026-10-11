<?php
namespace AsdeBalears\AreaPrivada\Fichas;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Cola de cambios propuestos ({prefix}asde_cambios, docs/07): los cambios que
 * pide una cuenta familiar en la ficha de sus menores los aprueba la Secretaría,
 * la Coordinación o un scouter de la sección; al aprobarlos se escriben en el CRM.
 *
 *   pendiente → aplicado (escrito en el CRM) | error (el CRM no lo aceptó)
 *   pendiente → rechazado (con motivo) | cancelado (quien lo propuso, o sustituido por otro más reciente)
 *
 * Los valores (anterior y nuevo) son datos personales: se guardan cifrados.
 */
final class Cambios {

	public const PENDIENTE = 'pendiente';
	public const APLICADO  = 'aplicado';
	public const RECHAZADO = 'rechazado';
	public const CANCELADO = 'cancelado';
	public const ERROR     = 'error';

	public const ESTADOS = array(
		self::PENDIENTE => 'Pendiente de aprobación',
		self::APLICADO  => 'Aprobado y guardado en el CRM',
		self::RECHAZADO => 'Rechazado',
		self::CANCELADO => 'Cancelado',
		self::ERROR     => 'Aprobado, pero el CRM no lo aceptó',
	);

	/**
	 * Cambios hechos directamente en una ficha (v0.29, «Resueltos»): los motivos con los que
	 * Escritura::guardar() deja constancia en la auditoría. No están las propuestas aprobadas
	 * (ya salen como propuestas) ni los nº de socio de la numeración (van aparte, en Personas).
	 */
	public const MOTIVOS_DIRECTOS = array(
		'ficha'            => 'Guardado en la ficha',
		'documento'        => 'Documento puesto desde la ficha',
		'solicitud_acceso' => 'Email de una solicitud de acceso',
	);

	/** Datos que no están en la ficha (CamposFicha) pero se escriben desde la web. */
	public const ETIQUETAS_EXTRA = array(
		'stic_identification_number_c' => 'Documento de identidad',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_cambios';
	}

	/** Nombre de un dato de la ficha para mostrarlo («Móvil», «Alergias»…). */
	public static function etiqueta( string $campo ): string {
		$def = CamposFicha::todos()[ $campo ][0] ?? self::ETIQUETAS_EXTRA[ $campo ] ?? '';
		return '' !== $def ? asde_t( $def ) : $campo;
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
				socio varchar(20) NOT NULL,
				campo varchar(64) NOT NULL,
				anterior_enc text NULL,
				nuevo_enc text NULL,
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				propuesto_por bigint(20) unsigned NOT NULL DEFAULT 0,
				propuesto_en datetime NOT NULL,
				revisado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revisado_en datetime NULL,
				motivo varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo_estado (grupo, estado),
				KEY socio (socio)
			) $charset;"
		);
	}

	/**
	 * Propone cambios en la ficha de una persona. Un cambio pendiente anterior del
	 * mismo campo queda sustituido por el nuevo.
	 *
	 * @param array<string, array{0:string, 1:string}> $cambios campo => [valor actual en el CRM, valor nuevo]
	 * @return int Nº de cambios propuestos.
	 */
	public static function proponer( Persona $p, array $cambios ): int {
		global $wpdb;
		$n = 0;
		foreach ( $cambios as $campo => list( $anterior, $nuevo ) ) {
			$wpdb->update(
				self::table(),
				array(
					'estado'      => self::CANCELADO,
					'motivo'      => 'Sustituido por un cambio más reciente.',
					'revisado_en' => current_time( 'mysql', true ),
				),
				array(
					'grupo'  => Grupo::numero(),
					'socio'  => $p->num_socio_asde,
					'campo'  => $campo,
					'estado' => self::PENDIENTE,
				)
			);
			$wpdb->insert(
				self::table(),
				array(
					'grupo'         => Grupo::numero(),
					'socio'         => $p->num_socio_asde,
					'campo'         => $campo,
					'anterior_enc'  => Crypto::encrypt( $anterior ),
					'nuevo_enc'     => Crypto::encrypt( $nuevo ),
					'estado'        => self::PENDIENTE,
					'propuesto_por' => get_current_user_id(),
					'propuesto_en'  => current_time( 'mysql', true ),
				)
			);
			$id = (int) $wpdb->insert_id;
			if ( $id ) {
				++$n;
				Logger::log( 'cambio_propuesto', 'socio', $p->num_socio_asde, array( 'campo' => $campo, 'cambio' => $id ) );
				do_action( 'asde_cambio_propuesto', $id, $p->num_socio_asde, $campo );
			}
		}
		return $n;
	}

	/** @return array[] Cambios del grupo (descifrados), más recientes primero. */
	public static function listado( string $estado = self::PENDIENTE, string $socio = '' ): array {
		global $wpdb;
		// Consulta fija: cada filtro opcional va como valor («'' = ''» lo deja sin efecto), sin montar SQL (v0.26.2, Plugin Check).
		$e     = isset( self::ESTADOS[ $estado ] ) ? $estado : '';
		$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM %i WHERE grupo = %s'
				. " AND ( %s = '' OR estado = %s )"
				. " AND ( %s = '' OR socio = %s )"
				. ' ORDER BY id DESC LIMIT 1000',
				self::table(),
				Grupo::numero(),
				$e,
				$e,
				$socio,
				$socio
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'descifrar' ), (array) $filas );
	}

	/**
	 * Cambios hechos directamente en las fichas en los últimos $dias (de la auditoría): solo
	 * qué datos, quién y cuándo (los valores no se guardan, regla 6). La auditoría no lleva el
	 * grupo: quien llama se queda con las personas de su grupo (las que encuentra en el CRM).
	 *
	 * @return array<int, array{id:int, creado:string, user_id:int, socio:string, campos:string[], motivo:string}>
	 */
	public static function directos( int $dias, string $socio = '' ): array {
		global $wpdb;
		$desde = gmdate( 'Y-m-d H:i:s', time() - max( 1, $dias ) * DAY_IN_SECONDS );
		$filas = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, creado, user_id, objeto_id, detalles FROM %i WHERE accion = 'crm_ficha_editada' AND objeto = 'socio' AND creado >= %s"
				. " AND ( %s = '' OR objeto_id = %s )"
				. ' ORDER BY id DESC LIMIT 500',
				Logger::table(),
				$desde,
				$socio,
				$socio
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $filas as $f ) {
			$d      = json_decode( (string) $f['detalles'], true );
			$motivo = is_array( $d ) ? (string) ( $d['motivo'] ?? '' ) : '';
			$campos = is_array( $d ) ? array_values( array_filter( array_map( 'strval', (array) ( $d['campos'] ?? array() ) ) ) ) : array();
			if ( ! isset( self::MOTIVOS_DIRECTOS[ $motivo ] ) || ! $campos ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $f['id'],
				'creado'  => (string) $f['creado'],
				'user_id' => (int) $f['user_id'],
				'socio'   => (string) $f['objeto_id'],
				'campos'  => $campos,
				'motivo'  => $motivo,
			);
		}
		return $out;
	}

	public static function contar_pendientes(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s AND estado = %s', self::table(), Grupo::numero(), self::PENDIENTE ) ); // phpcs:ignore WordPress.DB
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::descifrar( $r ) : null;
	}

	/**
	 * Aprueba un cambio y lo escribe en el CRM. Si el dato del CRM ya no es el
	 * que había al proponerlo, no se aplica salvo que se confirme ($forzar).
	 *
	 * @param string $actual Valor actual en el CRM (releído por quien aprueba).
	 * @return true|\WP_Error
	 */
	public static function aprobar( int $id, Persona $p, string $actual, bool $forzar = false ) {
		$c = self::obtener( $id );
		if ( ! $c || self::PENDIENTE !== $c['estado'] || $c['socio'] !== $p->num_socio_asde ) {
			return new \WP_Error( 'asde_cambio', asde_t( 'Ese cambio ya no está pendiente.' ) );
		}
		if ( ! $forzar && ! Escritura::iguales( $actual, $c['anterior'] ) ) {
			return new \WP_Error( 'asde_cambio_conflicto', asde_t( 'El dato del CRM ha cambiado desde que se propuso. Revísalo y confirma si quieres aplicarlo igualmente.' ) );
		}
		$r = Escritura::guardar( $p, array( $c['campo'] => $c['nuevo'] ), 'cambio_aprobado_' . $id );
		if ( is_wp_error( $r ) || ! in_array( $c['campo'], $r['guardados'], true ) ) {
			self::marcar( $id, self::ERROR, is_wp_error( $r ) ? $r->get_error_message() : asde_t( 'El CRM no ha aceptado el valor.' ) );
			return is_wp_error( $r ) ? $r : new \WP_Error( 'asde_cambio', asde_t( 'El CRM no ha aceptado el valor.' ) );
		}
		self::marcar( $id, self::APLICADO );
		do_action( 'asde_cambio_aprobado', $id, $p->num_socio_asde, $c['campo'] );
		return true;
	}

	/**
	 * Aprueba varios cambios de una persona a la vez (v0.29) y los escribe en el CRM en una
	 * sola escritura. Los que tienen el dato del CRM cambiado desde la propuesta se quedan
	 * pendientes, salvo los de $forzar (quien revisa ya vio el aviso y los marcó igualmente).
	 *
	 * @param int[]                 $ids
	 * @param array<string, string> $actuales campo => valor de ahora en el CRM (releído por quien aprueba).
	 * @param int[]                 $forzar
	 * @return array{aprobados:int[], conflictos:int[], errores:array<int, string>}
	 */
	public static function aprobar_varios( array $ids, Persona $p, array $actuales, array $forzar = array() ): array {
		$out     = array(
			'aprobados'  => array(),
			'conflictos' => array(),
			'errores'    => array(),
		);
		$valores = array();
		$de      = array(); // campo => id del cambio que lo trae
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$c = self::obtener( $id );
			if ( ! $c || self::PENDIENTE !== $c['estado'] || $c['socio'] !== $p->num_socio_asde ) {
				$out['errores'][ $id ] = asde_t( 'Ese cambio ya no está pendiente.' );
				continue;
			}
			if ( ! in_array( $id, $forzar, true ) && ! Escritura::iguales( (string) ( $actuales[ $c['campo'] ] ?? '' ), $c['anterior'] ) ) {
				$out['conflictos'][] = $id;
				continue;
			}
			// Del mismo dato no hay dos pendientes (proponer() sustituye el anterior); por si acaso, manda el más reciente.
			if ( isset( $de[ $c['campo'] ] ) && $de[ $c['campo'] ] > $id ) {
				continue;
			}
			$valores[ $c['campo'] ] = $c['nuevo'];
			$de[ $c['campo'] ]      = $id;
		}
		if ( ! $valores ) {
			return $out;
		}
		$r = Escritura::guardar( $p, $valores, 'cambios_aprobados' );
		foreach ( $de as $campo => $id ) {
			if ( is_wp_error( $r ) || ! in_array( $campo, $r['guardados'], true ) ) {
				$motivo = is_wp_error( $r ) ? $r->get_error_message() : asde_t( 'El CRM no ha aceptado el valor.' );
				self::marcar( $id, self::ERROR, $motivo );
				$out['errores'][ $id ] = $motivo;
				continue;
			}
			self::marcar( $id, self::APLICADO );
			do_action( 'asde_cambio_aprobado', $id, $p->num_socio_asde, $campo );
			$out['aprobados'][] = $id;
		}
		return $out;
	}

	public static function rechazar( int $id, string $motivo ) {
		$c = self::obtener( $id );
		if ( ! $c || self::PENDIENTE !== $c['estado'] ) {
			return new \WP_Error( 'asde_cambio', asde_t( 'Ese cambio ya no está pendiente.' ) );
		}
		if ( '' === trim( $motivo ) ) {
			return new \WP_Error( 'asde_cambio', asde_t( 'Indica el motivo del rechazo: se le mostrará a quien lo propuso.' ) );
		}
		self::marcar( $id, self::RECHAZADO, $motivo );
		do_action( 'asde_cambio_rechazado', $id, $c['socio'], $c['campo'] );
		return true;
	}

	/** Quien lo propuso puede retirarlo mientras esté pendiente. */
	public static function cancelar( int $id ) {
		$c = self::obtener( $id );
		if ( ! $c || self::PENDIENTE !== $c['estado'] || (int) $c['propuesto_por'] !== get_current_user_id() ) {
			return new \WP_Error( 'asde_cambio', asde_t( 'Ese cambio no se puede cancelar.' ) );
		}
		self::marcar( $id, self::CANCELADO, 'Retirado por quien lo propuso.' );
		return true;
	}

	private static function marcar( int $id, string $estado, string $motivo = '' ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'estado'       => $estado,
				'motivo'       => mb_substr( $motivo, 0, 255 ),
				'revisado_por' => get_current_user_id(),
				'revisado_en'  => current_time( 'mysql', true ),
			),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		Logger::log( 'cambio_' . $estado, 'cambio', (string) $id );
	}

	private static function descifrar( array $r ): array {
		$r['anterior'] = (string) Crypto::decrypt( (string) $r['anterior_enc'] );
		$r['nuevo']    = (string) Crypto::decrypt( (string) $r['nuevo_enc'] );
		unset( $r['anterior_enc'], $r['nuevo_enc'] );
		return $r;
	}
}
