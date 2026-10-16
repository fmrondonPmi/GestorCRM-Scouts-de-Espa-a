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
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta un informe contra el CRM, siempre dentro del ámbito del usuario:
 * - solo socios con membresía activa;
 * - scouters: solo sus secciones; Secretaría/Coordinación/administradores: todo el grupo;
 * - solo las columnas que su rol puede ver.
 * Los resultados no se guardan en ningún sitio.
 *
 * Desde la v0.25, la cuota y los pagos de la familia de cada socio (de Tesorería, no
 * del CRM) para quien entra en Tesorería: así un scouter sabe quién de su sección ha
 * pagado y quién no.
 */
final class Generador {

	/** Campos que se leen siempre (orden y nº de socio). */
	private const BASE = array( 'id', 'numerosocio_c', 'numsocioantiguo_enficha_c', 'first_name', 'last_name', 'birthdate', 'stic_relationship_type_c' );

	public function __construct( private SinergiaV41Client $crm ) {}

	/**
	 * @return array{columnas: array<string,string>, filas: list<array<string,string>>, secciones: string[], avisos: string[], salud: bool, ronda: ?string, pago: string}
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
		if ( ! $ambito->vision_global() && ! Catalogo::solo_tesoreria( $ambito ) ) {
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

		// Cuota y pagos (v0.25): de la ronda elegida o de la actual.
		$filtro_pago = $informe->filtros['pago'];
		if ( '' !== $filtro_pago && ! Catalogo::ve_pagos( $ambito ) ) {
			$filtro_pago = '';
			$avisos[]    = asde_t( 'El filtro de pagos no se aplica porque tu rol no tiene acceso a Tesorería.' );
		}
		$ronda = ( '' !== $filtro_pago || Catalogo::incluye_pagos( $cols ) ) ? ( $informe->filtros['ronda'] ?: Config::ronda_actual() ) : null;

		$campos = self::BASE;
		foreach ( $cols as $c ) {
			$campos = array_merge( $campos, $permitidas[ $c ][2] );
		}
		if ( $filtro_salud ) {
			$campos = array_merge( $campos, array_keys( Catalogo::CAMPOS_SALUD_RELEVANTE ) );
		}

		$entries = PersonaRepository::filtrar_grupo( $this->crm->list_all( 'Contacts', implode( ' AND ', $where ), array_values( array_unique( $campos ) ), PersonaRepository::links_lectura() ) );
		// v0.35.1: las secciones, de las membresías vigentes (una baja del CRM aún en «Secciones actuales» no sale).
		$entries = array_values(
			array_filter(
				PersonaRepository::con_secciones_vigentes( $entries ),
				static function ( array $e ) use ( $secciones ): bool {
					if ( ! PersonaRepository::es_activa( $e ) ) {
						return false;
					}
					foreach ( $secciones as $s ) {
						if ( str_contains( strtolower( (string) $e['fields']['stic_relationship_type_c'] ), strtolower( '^' . Secciones::all()[ $s ]['crm'] . '^' ) ) ) {
							return true;
						}
					}
					return ! $secciones;
				}
			)
		);
		$tabla   = SociosTable::todas();
		$grupo   = Grupo::actual()['numero'] ?? '';
		$pagos   = null !== $ronda ? $this->pagos( $entries, $ronda ) : array();

		$filas = array();
		foreach ( $entries as $e ) {
			$f = $e['fields'];
			if ( $filtro_salud && ! self::tiene_salud( $f ) ) {
				continue;
			}
			$pago = $pagos[ trim( (string) ( $f['numerosocio_c'] ?? '' ) ) ] ?? null;
			if ( '' !== $filtro_pago && ! self::cumple_pago( $filtro_pago, $pago ) ) {
				continue;
			}
			$celdas = array();
			foreach ( $cols as $c ) {
				$celdas[ $c ] = 'pago' === $permitidas[ $c ][3] ? self::valor_pago( $c, $pago ) : $this->valor( $permitidas[ $c ], $f, $tabla, $grupo );
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
			'ronda'     => $ronda,
			'pago'      => $filtro_pago,
		);
	}

	/**
	 * La familia de Tesorería de cada socio del informe, con su cuota de la ronda y sus
	 * pagos. La cuota se calcula con todos los miembros de la familia, también los de
	 * otras secciones (como en el control de pagos).
	 *
	 * @param array[] $entries Fichas del CRM del informe.
	 * @return array<string, array{familia:array, calc:array, pagos:array[]}> nº Scouts de España => su familia
	 */
	private function pagos( array $entries, string $ronda ): array {
		$mapa = FamiliasTable::mapa_socios();
		$ids  = array();
		foreach ( $entries as $e ) {
			$num = trim( (string) ( $e['fields']['numerosocio_c'] ?? '' ) );
			if ( isset( $mapa[ $num ] ) ) {
				$ids[ $mapa[ $num ] ] = true;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$familias = array_intersect_key( FamiliasTable::todas(), $ids );
		$miembros = array();
		foreach ( $familias as $fam ) {
			$miembros = array_merge( $miembros, $fam['miembros'] );
		}
		$personas = ( new PersonaRepository( $this->crm ) )->obtener_varias( $miembros );
		$cfg      = Config::de( $ronda );
		$totales  = MovimientosTable::totales( $ronda );
		$hechos   = MovimientosTable::pagos_de_ronda( $ronda );
		$por_fam  = array();
		foreach ( $familias as $id => $fam ) {
			$por_fam[ $id ] = array(
				'familia' => $fam,
				'calc'    => Calculadora::familia( $fam, $personas, $cfg, $totales[ $id ] ?? array() ),
				'pagos'   => $hechos[ $id ] ?? array(),
			);
		}
		$out = array();
		foreach ( $mapa as $num => $id ) {
			if ( isset( $por_fam[ $id ] ) ) {
				$out[ (string) $num ] = $por_fam[ $id ];
			}
		}
		return $out;
	}

	/** ¿Cumple el filtro de pagos? $pago es null si el socio no está en ninguna familia. */
	private static function cumple_pago( string $filtro, ?array $pago ): bool {
		if ( 'sin_familia' === $filtro ) {
			return null === $pago;
		}
		if ( null === $pago ) {
			return false;
		}
		$estado = $pago['calc']['estado'];
		switch ( $filtro ) {
			case 'pagado':
				return Calculadora::PAGADO === $estado;
			case 'debe':
				return in_array( $estado, array( Calculadora::PENDIENTE, Calculadora::PARCIAL, Calculadora::ATRASADO ), true );
			case 'vencido':
				return Calculadora::ATRASADO === $estado;
			default:
				return true;
		}
	}

	/** Valor de una columna de la cuota y los pagos (los importes son los de la familia). */
	private static function valor_pago( string $col, ?array $pago ): string {
		if ( null === $pago ) {
			return 'pago_estado' === $col ? asde_t( 'Sin familia en Tesorería' ) : '';
		}
		$c = $pago['calc'];
		switch ( $col ) {
			case 'pago_familia':
				return (string) $pago['familia']['numero'];
			case 'pago_estado':
				return asde_t( Calculadora::ETIQUETAS[ $c['estado'] ] ?? $c['estado'] );
			case 'pago_a_pagar':
				return Calculadora::euros( (float) $c['a_pagar'] );
			case 'pago_pagado':
				return Calculadora::euros( (float) $c['pagado'] );
			case 'pago_pendiente':
				return Calculadora::euros( max( 0.0, (float) $c['pendiente'] ) );
			case 'pago_vencido':
				// Lo que ya tendría que estar pagado (plazos vencidos) y no lo está.
				$exigible = 0.0;
				foreach ( $c['plazos'] as $pl ) {
					if ( $pl['vencido'] ) {
						$exigible = (float) $pl['acumulado'];
					}
				}
				return Calculadora::euros( max( 0.0, round( $exigible - (float) $c['pagado'], 2 ) ) );
			case 'pago_hechos':
				return implode( '; ', array_map( static fn( $m ) => wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ' ' . Calculadora::euros( (float) $m['importe'] ), $pago['pagos'] ) );
			default:
				return '';
		}
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

	/**
	 * Lo que dice el informe en una línea (v0.29, la página y la versión para imprimir):
	 * «12 socios con membresía activa · Lobatos · desde 8 años · 11/10/2026 10:30».
	 */
	public static function resumen( Informe $informe, array $res ): string {
		$f      = $informe->filtros;
		$n      = count( $res['filas'] );
		$partes = array(
			sprintf( asde_n( '%d socio', '%d socios', $n ), $n ) . ' ' . asde_t( 'con membresía activa' ),
			$res['secciones'] ? implode( ', ', array_map( array( Secciones::class, 'nombre' ), $res['secciones'] ) ) : asde_t( 'todo el grupo' ),
		);
		if ( null !== $f['edad_min'] ) {
			$partes[] = sprintf( asde_t( 'desde %d años' ), $f['edad_min'] );
		}
		if ( null !== $f['edad_max'] ) {
			$partes[] = sprintf( asde_t( 'hasta %d años' ), $f['edad_max'] );
		}
		if ( $f['salud'] ) {
			$partes[] = asde_t( 'solo con datos de salud relevantes' );
		}
		$pago = (string) ( $res['pago'] ?? '' );
		if ( '' !== $pago && isset( Catalogo::FILTROS_PAGO[ $pago ] ) ) {
			$partes[] = mb_strtolower( asde_t( Catalogo::FILTROS_PAGO[ $pago ] ) );
		}
		$partes[] = wp_date( 'd/m/Y H:i' );
		return implode( ' · ', $partes );
	}

	/**
	 * Una fila del informe como ficha (v0.29, «Una ficha por persona»): el título (nombre y
	 * apellidos o, si no están, la primera columna) y el resto de datos, etiqueta => valor.
	 *
	 * @param array<string, string> $fila     columna => valor
	 * @param array<string, string> $columnas columna => etiqueta
	 * @return array{titulo: string, datos: array<string, string>}
	 */
	public static function ficha( array $fila, array $columnas ): array {
		$titulo = trim( ( $fila['nombre'] ?? '' ) . ' ' . ( $fila['apellidos'] ?? '' ) );
		$fuera  = array( 'nombre', 'apellidos' );
		if ( '' === $titulo ) {
			$primera = (string) array_key_first( $columnas );
			$titulo  = (string) ( $fila[ $primera ] ?? '' );
			$fuera   = array( $primera );
		}
		$datos = array();
		foreach ( $columnas as $col => $etiqueta ) {
			if ( ! in_array( $col, $fuera, true ) ) {
				$datos[ $etiqueta ] = (string) ( $fila[ $col ] ?? '' );
			}
		}
		return array(
			'titulo' => $titulo,
			'datos'  => $datos,
		);
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
