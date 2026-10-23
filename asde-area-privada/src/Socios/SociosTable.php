<?php
namespace AsdeBalears\AreaPrivada\Socios;

use AsdeBalears\AreaPrivada\Domain\NumeroSocio;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Tabla de equivalencias {prefix}asde_socios: lo ÚNICO de cada asociado que se
 * guarda en WordPress. Los datos personales se quedan en el CRM.
 *
 *   num_socio_asde   Nº de socio/a Scouts de España (CRM: numerosocio_c). Incremental, lo asigna el CRM.
 *   num_socio_local  Nº de socio/a local/territorial (CRM: numsocioantiguo_enficha_c), normalizado.
 *   origen           'crm' (leído del CRM) | 'generado' (asignado aquí; pendiente de introducir en el CRM).
 */
final class SociosTable {

	public const CRM      = 'crm';
	public const GENERADO = 'generado';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_socios';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				num_socio_asde varchar(20) NOT NULL,
				num_socio_local varchar(20) NOT NULL,
				origen varchar(10) NOT NULL DEFAULT 'crm',
				creado datetime NOT NULL,
				actualizado datetime NOT NULL,
				PRIMARY KEY  (num_socio_asde),
				UNIQUE KEY num_socio_local (num_socio_local)
			) $charset;"
		);
	}

	public static function local_de( string $asde ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT num_socio_local FROM %i WHERE num_socio_asde = %s', self::table(), $asde ) ); // phpcs:ignore WordPress.DB
		return null === $v ? null : (string) $v;
	}

	public static function asde_de( string $local ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT num_socio_asde FROM %i WHERE num_socio_local = %s', self::table(), $local ) ); // phpcs:ignore WordPress.DB
		return null === $v ? null : (string) $v;
	}

	/** @return array<string, array{local:string, origen:string}> asde => fila */
	public static function todas(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT num_socio_asde, num_socio_local, origen FROM %i', self::table() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r['num_socio_asde'] ] = array(
				'local'  => $r['num_socio_local'],
				'origen' => $r['origen'],
			);
		}
		return $out;
	}

	/** @return array<string, string> asde => local, solo los generados aquí pendientes de pasar al CRM. */
	public static function generados_pendientes(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT num_socio_asde, num_socio_local FROM %i WHERE origen = %s ORDER BY num_socio_local', self::table(), self::GENERADO ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r['num_socio_asde'] ] = $r['num_socio_local'];
		}
		return $out;
	}

	/**
	 * Vuelca lo leído del CRM. El CRM manda: si el CRM ya tiene el número de un
	 * socio, se guarda ese (y un "generado" pasa a "crm" al aparecer en el CRM).
	 *
	 * @param array<string, string> $pares asde => local normalizado (sin duplicados).
	 * @return int Filas insertadas o cambiadas.
	 */
	public static function sincronizar( array $pares ): int {
		global $wpdb;
		$actuales = self::todas();
		$ahora    = current_time( 'mysql', true );
		$cambios  = 0;

		foreach ( $pares as $asde => $local ) {
			$asde = (string) $asde;
			$fila = $actuales[ $asde ] ?? null;
			if ( $fila && $fila['local'] === $local && self::CRM === $fila['origen'] ) {
				continue;
			}
			// Si otro socio tenía reservado ese número aquí, el CRM prevalece: se libera.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE num_socio_local = %s AND num_socio_asde <> %s', self::table(), $local, $asde ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'INSERT INTO %i (num_socio_asde, num_socio_local, origen, creado, actualizado) VALUES (%s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE num_socio_local = VALUES(num_socio_local), origen = VALUES(origen), actualizado = VALUES(actualizado)',
					self::table(),
					$asde,
					$local,
					self::CRM,
					$ahora,
					$ahora
				)
			);
			++$cambios;
		}
		return $cambios;
	}

	/** El número ya está guardado en el CRM: deja de estar pendiente. */
	public static function fijar_crm( string $asde, string $local ): void {
		self::sincronizar( array( $asde => $local ) );
	}

	/** Quita la reserva de un socio (p. ej. antes de darle un número nuevo por estar duplicado). */
	public static function liberar( string $asde ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'num_socio_asde' => $asde ) );
	}

	/**
	 * Asigna el siguiente nº local libre a un socio que no tiene (reserva en esta tabla).
	 *
	 * @param string[] $numeros_crm Números locales (normalizados) que ya existen en el CRM.
	 * @return string|\WP_Error El número asignado.
	 */
	public static function asignar_siguiente( string $asde, string $grupo, array $numeros_crm ) {
		global $wpdb;
		$existente = self::local_de( $asde );
		if ( $existente ) {
			return $existente;
		}
		if ( ! NumeroSocio::configurado( $grupo ) ) {
			return new \WP_Error( 'asde_num_socio', asde_t( 'Falta la provincia del grupo (Datos del grupo): sin ella no se pueden generar números de socio.' ) );
		}

		$wpdb->query( "SELECT GET_LOCK('asde_num_socio', 10)" ); // Evita que dos personas reciban el mismo número.
		try {
			for ( $intento = 0; $intento < 5; $intento++ ) {
				$numero = self::siguiente( $grupo, $numeros_crm );
				$ahora  = current_time( 'mysql', true );
				$ok     = $wpdb->insert(
					self::table(),
					array(
						'num_socio_asde'  => $asde,
						'num_socio_local' => $numero,
						'origen'          => self::GENERADO,
						'creado'          => $ahora,
						'actualizado'     => $ahora,
					)
				);
				if ( $ok ) {
					return $numero;
				}
				$numeros_crm[] = $numero; // Ocupado: probar el siguiente.
			}
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('asde_num_socio')" );
		}
		return new \WP_Error( 'asde_num_socio', asde_t( 'No se ha podido asignar un número de socio. Inténtalo de nuevo.' ) );
	}

	/** Siguiente número libre: máximo entre CRM y esta tabla, + 1. */
	public static function siguiente( string $grupo, array $numeros_crm ): string {
		global $wpdb;
		// El contador es de todo el grupo: cuentan los números con la provincia completa (076840390) y los de antes de la v0.56 (76840390).
		$formas  = array_unique( array( NumeroSocio::prefijo( $grupo ), NumeroSocio::prefijo_antiguo( $grupo ) ) );
		$locales = array();
		foreach ( $formas as $prefijo ) {
			$locales = array_merge( $locales, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT num_socio_local FROM %i WHERE num_socio_local LIKE %s', self::table(), $wpdb->esc_like( $prefijo ) . '%' ) ) ); // phpcs:ignore WordPress.DB
		}
		$max = 0;
		foreach ( array_merge( $numeros_crm, $locales ) as $n ) {
			foreach ( $formas as $prefijo ) {
				if ( str_starts_with( (string) $n, $prefijo ) && strlen( $prefijo ) + 4 === strlen( (string) $n ) ) {
					$max = max( $max, NumeroSocio::sufijo( (string) $n ) );
				}
			}
		}
		return NumeroSocio::formatear( $grupo, $max + 1 );
	}
}
