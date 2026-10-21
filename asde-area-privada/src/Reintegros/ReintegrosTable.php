<?php
namespace AsdeBalears\AreaPrivada\Reintegros;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas.

/**
 * Reintegros de gastos de los scouters ({prefix}asde_reintegros, v0.47, doc 53): quien ha pagado algo de su
 * bolsillo lo pide con el justificante; Tesorería lo aprueba y Tesorería o Coordinación lo marcan pagado.
 *
 * - El IBAN (si cobra por transferencia) va **cifrado** y se borra al terminar (pagado, rechazado o cancelado).
 * - El justificante va cifrado en un fichero (ver Eventos\Justificantes).
 * - **Nada se borra**: un reintegro equivocado se cancela o se rechaza.
 */
final class ReintegrosTable {

	public const SOLICITADO = 'solicitado';
	public const APROBADO   = 'aprobado';
	public const RECHAZADO  = 'rechazado';
	public const PAGADO     = 'pagado';
	public const CANCELADO  = 'cancelado';

	public const ESTADOS = array(
		self::SOLICITADO => 'Por aprobar',
		self::APROBADO   => 'Aprobado, por pagar',
		self::PAGADO     => 'Pagado',
		self::RECHAZADO  => 'Rechazado',
		self::CANCELADO  => 'Cancelado',
	);

	public const EFECTIVO      = 'efectivo';
	public const TRANSFERENCIA = 'transferencia';

	/** Cómo prefiere cobrar quien lo pide. */
	public const FORMAS = array(
		self::EFECTIVO      => 'En efectivo',
		self::TRANSFERENCIA => 'Por transferencia',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_reintegros';
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
				solicitante bigint(20) unsigned NOT NULL,
				concepto varchar(120) NOT NULL DEFAULT '',
				fecha date NOT NULL,
				importe decimal(10,2) NOT NULL,
				evento_id bigint(20) unsigned NOT NULL DEFAULT 0,
				forma varchar(14) NOT NULL DEFAULT 'efectivo',
				iban_enc text NULL,
				justificante varchar(64) NOT NULL DEFAULT '',
				justificante_tipo varchar(40) NOT NULL DEFAULT '',
				justificante_nombre varchar(120) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'solicitado',
				motivo varchar(255) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				revisado_en datetime NULL,
				revisado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				pago_fecha date NULL,
				pago_ref varchar(120) NOT NULL DEFAULT '',
				pagado_en datetime NULL,
				pagado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				gasto_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY solicitante (solicitante)
			) $charset;"
		);
	}

	/** Nº del reintegro (para el asunto de los emails): R + grupo + id (R6840007). */
	public static function numero( array $r ): string {
		return 'R' . $r['grupo'] . str_pad( (string) $r['id'], 4, '0', STR_PAD_LEFT );
	}

	/** @param array<string, mixed> $d Columnas ya validadas por Servicio. @return int Id. */
	public static function crear( array $d ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array_merge(
				$d,
				array(
					'grupo'       => Grupo::numero(),
					'solicitante' => get_current_user_id(),
					'importe'     => round( (float) $d['importe'], 2 ),
					'estado'      => self::SOLICITADO,
					'creado'      => current_time( 'mysql', true ),
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Reintegro del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A );
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @param array<string, mixed> $d */
	public static function actualizar( int $id, array $d ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			$d,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/**
	 * Cambia de estado solo si seguía en el estado de antes (una sentencia: dos personas a la vez no lo hacen dos veces).
	 *
	 * @param array<string, mixed> $d Lo que se guarda además del estado.
	 */
	public static function cambiar( int $id, string $de, string $a, array $d = array() ): bool {
		global $wpdb;
		$d['estado'] = $a;
		return 1 === (int) $wpdb->update(
			self::table(),
			$d,
			array(
				'id'     => $id,
				'grupo'  => Grupo::numero(),
				'estado' => $de,
			)
		);
	}

	/** @return array[] Todos los del grupo, los más recientes primero. */
	public static function todos(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY creado DESC, id DESC', self::table(), Grupo::numero() ), ARRAY_A );
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** @return array[] Los de una persona, los más recientes primero. */
	public static function de_usuario( int $user_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND solicitante = %d ORDER BY creado DESC, id DESC', self::table(), Grupo::numero(), $user_id ), ARRAY_A );
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Cuántos hay en este estado en el grupo (para el menú y el inicio). */
	public static function contar( string $estado ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s AND estado = %s', self::table(), Grupo::numero(), $estado ) );
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'solicitante', 'evento_id', 'revisado_por', 'pagado_por', 'gasto_id' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['importe']  = round( (float) $f['importe'], 2 );
		$f['iban_enc'] = (string) ( $f['iban_enc'] ?? '' );
		return $f;
	}
}
