<?php
namespace AsdeBalears\AreaPrivada\Informes;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Crm\Query;
use AsdeBalears\AreaPrivada\Crm\SinergiaV41Client;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Socios\SociosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta un informe contra el CRM, siempre dentro del ámbito del usuario:
 * - solo socios con membresía activa;
 * - scouters: solo sus secciones; Secretaría/Coordinación/administradores: todo el grupo;
 * - solo las columnas que su rol puede ver.
 * Los resultados no se guardan en ningún sitio.
 */
final class Generador {

	/** Campos que se leen siempre (orden y nº de socio). */
	private const BASE = array( 'id', 'numerosocio_c', 'numsocioantiguo_enficha_c', 'first_name', 'last_name', 'birthdate', 'stic_relationship_type_c' );

	public function __construct( private SinergiaV41Client $crm ) {}

	/**
	 * @return array{columnas: array<string,string>, filas: list<array<string,string>>, secciones: string[], avisos: string[], salud: bool}
	 * @throws \InvalidArgumentException Si el informe no se puede ejecutar para este usuario.
	 */
	public function ejecutar( Informe $informe, Ambito $ambito ): array {
		$permitidas = Catalogo::permitidas( $ambito );
		$cols       = array_values( array_intersect( $informe->columnas, array_keys( $permitidas ) ) );
		$avisos     = array();
		if ( count( $cols ) < count( $informe->columnas ) ) {
			$avisos[] = asde_t( 'Algunas columnas no se muestran porque tu rol no tiene acceso a esos datos.' );
		}
		if ( ! $cols ) {
			throw new \InvalidArgumentException( esc_html( asde_t( 'Elige al menos una columna.' ) ) );
		}

		// Ámbito de secciones.
		$secciones = $informe->filtros['secciones'];
		if ( ! $ambito->vision_global() ) {
			$mias      = $ambito->secciones_scouter();
			$secciones = $secciones ? array_values( array_intersect( $secciones, $mias ) ) : $mias;
			if ( ! $secciones ) {
				throw new \InvalidArgumentException( esc_html( asde_t( 'No tienes secciones asignadas (o no coinciden con las del informe). Pide a Secretaría que revise tu alta.' ) ) );
			}
		}

		$where = array( PersonaRepository::WHERE_ACTIVAS );
		if ( $secciones ) {
			$or = array();
			foreach ( $secciones as $s ) {
				$or[] = 'contacts_cstm.stic_relationship_type_c LIKE ' . Query::contains( '^' . Secciones::all()[ $s ]['crm'] . '^' );
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$hoy = date_create( 'today', wp_timezone() );
		if ( null !== $informe->filtros['edad_min'] ) {
			$where[] = 'contacts.birthdate <= ' . Query::str( ( clone $hoy )->modify( '-' . $informe->filtros['edad_min'] . ' years' )->format( 'Y-m-d' ) );
		}
		if ( null !== $informe->filtros['edad_max'] ) {
			$where[] = 'contacts.birthdate > ' . Query::str( ( clone $hoy )->modify( '-' . ( $informe->filtros['edad_max'] + 1 ) . ' years' )->format( 'Y-m-d' ) );
		}

		$filtro_salud = $informe->filtros['salud'];
		if ( $filtro_salud && ! $ambito->user->has_cap( 'asde_view_health' ) ) {
			$filtro_salud = false;
			$avisos[]     = asde_t( 'El filtro de datos de salud no se aplica porque tu rol no tiene acceso a esos datos.' );
		}

		$campos = self::BASE;
		foreach ( $cols as $c ) {
			$campos = array_merge( $campos, $permitidas[ $c ][2] );
		}
		if ( $filtro_salud ) {
			$campos = array_merge( $campos, array_keys( Catalogo::CAMPOS_SALUD_RELEVANTE ) );
		}

		$entries = PersonaRepository::filtrar_grupo( $this->crm->list_all( 'Contacts', implode( ' AND ', $where ), array_values( array_unique( $campos ) ), PersonaRepository::links_grupo() ) );
		$tabla   = SociosTable::todas();
		$grupo   = Grupo::actual()['numero'] ?? '';

		$filas = array();
		foreach ( $entries as $e ) {
			$f = $e['fields'];
			if ( $filtro_salud && ! self::tiene_salud( $f ) ) {
				continue;
			}
			$celdas = array();
			foreach ( $cols as $c ) {
				$celdas[ $c ] = $this->valor( $permitidas[ $c ], $f, $tabla, $grupo );
			}
			$filas[] = array(
				'orden'  => $this->clave_orden( $informe->filtros['orden'], $f ),
				'celdas' => $celdas,
			);
		}
		usort( $filas, static fn( $a, $b ) => strcmp( $a['orden'], $b['orden'] ) );

		$columnas = array();
		foreach ( $cols as $c ) {
			$columnas[ $c ] = $permitidas[ $c ][0];
		}

		return array(
			'columnas'  => $columnas,
			'filas'     => array_column( $filas, 'celdas' ),
			'secciones' => $secciones,
			'avisos'    => $avisos,
			'salud'     => Catalogo::incluye_salud( $cols ),
		);
	}

	private function valor( array $def, array $f, array $tabla, string $grupo ): string {
		list( , , $campos, $tipo ) = $def;
		$v = trim( (string) ( $f[ $campos[0] ] ?? '' ) );
		switch ( $tipo ) {
			case 'fecha':
				return $v ? wp_date( 'd/m/Y', strtotime( $v . ' 12:00' ) ) : '';
			case 'edad':
				$d = $v ? date_create( $v, wp_timezone() ) : false;
				return $d ? (string) date_diff( $d, date_create( 'today', wp_timezone() ) )->y : '';
			case 'bool':
				return '1' === $v ? 'Sí' : ( '0' === $v ? 'No' : '' );
			case 'sino':
				return array(
					'si' => 'Sí',
					'no' => 'No',
				)[ strtolower( $v ) ] ?? $v;
			case 'enum':
				return asde_t( Catalogo::ENUMS[ $campos[0] ][ $v ] ?? $v );
			case 'seccion':
				$nombres = array();
				foreach ( explode( ',', $v ) as $raw ) {
					$slug = Secciones::from_crm( trim( $raw, '^ ' ) );
					if ( $slug ) {
						$nombres[] = Secciones::nombre( $slug );
					}
				}
				return implode( ', ', $nombres );
			case 'num_local':
				$asde = trim( (string) ( $f['numerosocio_c'] ?? '' ) );
				return $tabla[ $asde ]['local'] ?? ( NumeroSocio::normalizar( $v, $grupo ) ?? $v );
			case 'nombre_tutor':
				return trim( $v . ' ' . trim( (string) ( $f[ $campos[1] ] ?? '' ) ) );
			default:
				return $v;
		}
	}

	private function clave_orden( string $orden, array $f ): string {
		$nombre = strtolower( remove_accents( trim( ( $f['last_name'] ?? '' ) . ' ' . ( $f['first_name'] ?? '' ) ) ) );
		switch ( $orden ) {
			case 'nombre':
				return strtolower( remove_accents( trim( ( $f['first_name'] ?? '' ) . ' ' . ( $f['last_name'] ?? '' ) ) ) );
			case 'edad':
				// Más jóvenes primero: fecha de nacimiento descendente.
				return ( $f['birthdate'] ?? '' ) ? ( 99999999 - (int) str_replace( '-', '', $f['birthdate'] ) ) . $nombre : 'zzzz' . $nombre;
			case 'seccion':
				$orden_secc = array_flip( array_keys( Secciones::all() ) );
				$slug       = Secciones::from_crm( trim( explode( ',', (string) ( $f['stic_relationship_type_c'] ?? '' ) )[0], '^ ' ) );
				return str_pad( (string) ( $orden_secc[ $slug ] ?? 99 ), 2, '0', STR_PAD_LEFT ) . $nombre;
			default:
				return $nombre;
		}
	}

	private static function tiene_salud( array $f ): bool {
		foreach ( Catalogo::CAMPOS_SALUD_RELEVANTE as $campo => $tipo ) {
			$v = trim( (string) ( $f[ $campo ] ?? '' ) );
			if ( ( 'texto' === $tipo && '' !== $v ) || ( 'bool' === $tipo && '1' === $v ) || ( 'sino' === $tipo && 'si' === strtolower( $v ) ) || ( 'si' === $tipo && 'Si' === $v ) ) {
				return true;
			}
		}
		return false;
	}

	/** Descarga CSV (separador ";" y BOM para que Excel lo abra bien en español). */
	public static function csv( string $nombre, array $resultado ): void {
		$archivo = sanitize_file_name( remove_accents( $nombre ?: 'informe' ) ) . '-' . wp_date( 'Ymd' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $archivo . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output (descarga), no es el sistema de ficheros
		fputcsv( $out, array_values( $resultado['columnas'] ), ';' );
		foreach ( $resultado['filas'] as $fila ) {
			fputcsv( $out, array_map( array( self::class, 'celda_segura' ), array_values( $fila ) ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output (descarga)
	}

	/**
	 * Evita que Excel interprete un valor como fórmula (inyección CSV). Los números
	 * (con signo y con coma o punto decimal, p. ej. un ajuste de -12,50) se dejan como
	 * están: son datos, no fórmulas, y con la comilla dejarían de poder sumarse.
	 */
	public static function celda_segura( $v ): string {
		$v = (string) $v;
		if ( '' === $v || preg_match( '/^-?\d+(?:[.,]\d+)?$/', $v ) ) {
			return $v;
		}
		return in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $v : $v;
	}
}
