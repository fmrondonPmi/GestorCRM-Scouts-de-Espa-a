<?php
/**
 * CRM simulado para las pruebas automáticas (SOLO pruebas; no va en el paquete).
 *
 * Responde como la API REST v4_1 de SinergiaCRM con personas inventadas,
 * interceptando (pre_http_request) toda petición a la URL del CRM configurada:
 * mientras está activo, ninguna prueba llega al CRM real. También hace de
 * EscritorCrm (Crm\Escritura::usar_para_pruebas): lo «escrito» se guarda en
 * memoria y se ve en las lecturas siguientes.
 *
 * Entiende el pequeño subconjunto de SQL que usa el plugin (AND/OR, =, IN,
 * LIKE, IS [NOT] NULL y la subconsulta de emails).
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\EscritorCrm;
use AsdeBalears\AreaPrivada\Crm\Settings;

final class Asde_Prueba_Crm implements EscritorCrm {

	/** @var array<int, array<string, string>> Fichas (campos del CRM). */
	public array $personas = array();

	/** @var array<int, array{id:string, campos:array}> Lo escrito, en orden. */
	public array $escrituras = array();

	public int $llamadas = 0;

	/** Simula que el CRM no responde. */
	public bool $caido = false;

	/** @var string[] Campos que el CRM «no acepta» al escribir. */
	public array $rechazar = array();

	private string $grupo_sg;
	private string $base;
	/** @var callable|null */
	private $filtro = null;

	public function __construct( array $personas, string $grupo_sg ) {
		$this->personas = array_values( $personas );
		$this->grupo_sg = $grupo_sg;
		$this->base     = rtrim( Settings::url(), '/' );
	}

	/** Ficha inventada: $n da el id, el nº Scouts de España (990000 + n) y el nº local (768499 + n). */
	public static function persona( int $n, string $nombre, string $apellidos, string $nacimiento, string $secciones, array $extra = array() ): array {
		return array_merge(
			array(
				'id'                          => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n ),
				'numerosocio_c'               => (string) ( 990000 + $n ),
				'numsocioantiguo_enficha_c'   => sprintf( '768499%02d', $n ),
				'first_name'                  => $nombre,
				'last_name'                   => $apellidos,
				'birthdate'                   => $nacimiento,
				'stic_relationship_type_c'    => '' === $secciones ? '' : '^' . implode( '^,^', explode( ',', $secciones ) ) . '^',
				'stic_identification_number_c' => '',
				'email1'                      => '',
				'phone_mobile'                => '',
				'cargoeducativo_c'            => '',
				'tutor1_nombre_c'             => '',
				'tutor1_apellidos_c'          => '',
				'tutor1_email_c'              => '',
				'tutor2_nombre_c'             => '',
				'tutor2_apellidos_c'          => '',
				'tutor2_email_c'              => '',
			),
			$extra
		);
	}

	public function activar(): void {
		$this->filtro = function ( $pre, $args, $url ) {
			return $this->interceptar( $pre, (array) $args, (string) $url );
		};
		add_filter( 'pre_http_request', $this->filtro, 1, 3 );
		ClientFactory::olvidar();
	}

	public function desactivar(): void {
		if ( $this->filtro ) {
			remove_filter( 'pre_http_request', $this->filtro, 1 );
		}
		ClientFactory::olvidar();
	}

	/** Valor actual de un campo de una ficha (por nº Scouts de España). */
	public function campo( string $num_asde, string $campo ): string {
		foreach ( $this->personas as $p ) {
			if ( $p['numerosocio_c'] === $num_asde ) {
				return (string) ( $p[ $campo ] ?? '' );
			}
		}
		return '';
	}

	/** @return array|\WP_Error|false */
	private function interceptar( $pre, array $args, string $url ) {
		if ( '' === $this->base || ! str_starts_with( $url, $this->base ) ) {
			return $pre;
		}
		++$this->llamadas;
		if ( $this->caido ) {
			return new WP_Error( 'prueba', 'CRM caído (simulado)' );
		}
		$body  = (array) ( $args['body'] ?? array() );
		$datos = json_decode( (string) ( $body['rest_data'] ?? '{}' ), true ) ?: array();
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $this->responder( (string) ( $body['method'] ?? '' ), $datos ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function responder( string $metodo, array $d ): array {
		switch ( $metodo ) {
			case 'login':
				return array(
					'id'              => 'sesion-de-prueba',
					'name_value_list' => array(
						'user_name' => array(
							'name'  => 'user_name',
							'value' => 'usuario-de-prueba',
						),
					),
				);
			case 'logout':
				return array();
			case 'get_entry_list':
				return $this->lista( (string) ( $d['module_name'] ?? '' ), (string) ( $d['query'] ?? '' ), (array) ( $d['select_fields'] ?? array() ), (array) ( $d['link_name_to_fields_array'] ?? array() ), (int) ( $d['offset'] ?? 0 ), (int) ( $d['max_results'] ?? 100 ) );
			case 'set_entry':
				$valores = array();
				$id      = '';
				foreach ( (array) ( $d['name_value_list'] ?? array() ) as $nv ) {
					if ( 'id' === ( $nv['name'] ?? '' ) ) {
						$id = (string) $nv['value'];
					} else {
						$valores[ (string) $nv['name'] ] = (string) $nv['value'];
					}
				}
				return array( 'id' => $this->set_entry( 'Contacts', $id, $valores ) );
			default:
				return array(
					'number'      => 20,
					'name'        => 'Module Does Not Exist',
					'description' => 'Método no simulado: ' . $metodo,
				);
		}
	}

	// --- EscritorCrm --------------------------------------------------------

	public function set_entry( string $module, string $id, array $values ): string {
		foreach ( $this->personas as $i => $p ) {
			if ( $p['id'] === $id ) {
				// Los campos de $rechazar no se guardan (como un permiso por campo del CRM): se detecta al releer.
				$this->personas[ $i ] = array_merge( $p, array_map( 'strval', array_diff_key( $values, array_flip( $this->rechazar ) ) ) );
				$this->escrituras[]   = array(
					'id'     => $id,
					'campos' => $values,
				);
				return $id;
			}
		}
		return '';
	}

	public function get_entry( string $module, string $id, array $fields, array $links = array() ): ?array {
		foreach ( $this->personas as $p ) {
			if ( $p['id'] === $id ) {
				return array(
					'id'     => $id,
					'fields' => array_intersect_key( $p, array_flip( $fields ) ),
				);
			}
		}
		return null;
	}

	// --- Consultas ------------------------------------------------------------

	private function lista( string $modulo, string $query, array $campos, array $links, int $offset, int $max ): array {
		$filas = 'Contacts' === $modulo ? $this->personas : array();
		if ( '' !== trim( $query ) ) {
			$ast   = Asde_Prueba_Consulta::analizar( $query );
			$filas = array_values( array_filter( $filas, static fn( $r ) => Asde_Prueba_Consulta::cumple( $ast, $r ) ) );
		}
		$trozo   = array_slice( $filas, $offset, max( 1, $max ) );
		$con_sg  = (bool) array_filter( $links, static fn( $l ) => 'SecurityGroups' === ( $l['name'] ?? '' ) );
		$entries = array();
		$rels    = array();
		foreach ( $trozo as $r ) {
			$nvl = array();
			foreach ( array_unique( array_merge( array( 'id' ), $campos ) ) as $c ) {
				$nvl[ $c ] = array(
					'name'  => $c,
					'value' => (string) ( $r[ $c ] ?? '' ),
				);
			}
			$entries[] = array(
				'id'              => $r['id'],
				'module_name'     => $modulo,
				'name_value_list' => $nvl,
			);
			$rels[]    = array(
				'link_list' => $con_sg ? array(
					array(
						'name'    => 'SecurityGroups',
						'records' => array( array( 'link_value' => array( 'id' => array( 'name' => 'id', 'value' => $this->grupo_sg ) ) ) ),
					),
				) : array(),
			);
		}
		return array(
			'result_count'      => count( $entries ),
			'total_count'       => count( $filas ),
			'next_offset'       => $offset + count( $entries ),
			'entry_list'        => $entries,
			'relationship_list' => $rels,
		);
	}
}

/** Evaluador del subconjunto de SQL de las consultas del plugin. */
final class Asde_Prueba_Consulta {

	private array $t = array();
	private int $i   = 0;

	public static function analizar( string $q ): array {
		$p    = new self();
		$p->t = self::tokens( $q );
		return $p->expr();
	}

	private static function tokens( string $q ): array {
		$t   = array();
		$pos = 0;
		$len = strlen( $q );
		while ( $pos < $len ) {
			if ( ! preg_match( "/\G\s*(?:('(?:[^']|'')*')|(<=|>=|<>|!=|=|<|>)|([(),])|([A-Za-z_][A-Za-z0-9_.]*)|(-?\d+(?:\.\d+)?))/", $q, $m, 0, $pos ) || '' === $m[0] ) {
				break;
			}
			$pos += strlen( $m[0] );
			if ( '' !== ( $m[1] ?? '' ) ) {
				$t[] = array( 's', str_replace( "''", "'", substr( $m[1], 1, -1 ) ) );
			} elseif ( '' !== ( $m[2] ?? '' ) ) {
				$t[] = array( 'op', $m[2] );
			} elseif ( '' !== ( $m[3] ?? '' ) ) {
				$t[] = array( $m[3], $m[3] );
			} elseif ( '' !== ( $m[4] ?? '' ) ) {
				$t[] = array( 'id', $m[4] );
			} else {
				$t[] = array( 's', $m[5] );
			}
		}
		return $t;
	}

	private function ver( int $k = 0 ): array {
		return $this->t[ $this->i + $k ] ?? array( 'fin', '' );
	}

	private function palabra( string $w, int $k = 0 ): bool {
		$t = $this->ver( $k );
		return 'id' === $t[0] && 0 === strcasecmp( $t[1], $w );
	}

	private function expr(): array {
		$n = $this->term();
		while ( $this->palabra( 'OR' ) ) {
			++$this->i;
			$n = array( 'or', $n, $this->term() );
		}
		return $n;
	}

	private function term(): array {
		$n = $this->factor();
		while ( $this->palabra( 'AND' ) ) {
			++$this->i;
			$n = array( 'and', $n, $this->factor() );
		}
		return $n;
	}

	private function factor(): array {
		if ( $this->palabra( 'NOT' ) ) {
			++$this->i;
			return array( 'not', $this->factor() );
		}
		if ( '(' === $this->ver()[0] ) {
			++$this->i;
			$n = $this->expr();
			++$this->i; // ')'
			return $n;
		}
		$col = strtolower( (string) preg_replace( '/^(contacts_cstm|contacts|securitygroups)\./i', '', $this->ver()[1] ) );
		++$this->i;
		if ( $this->palabra( 'IS' ) ) {
			++$this->i;
			$no       = $this->palabra( 'NOT' );
			$this->i += $no ? 2 : 1; // [NOT] NULL
			return array( 'null', $col, ! $no );
		}
		$no = false;
		if ( $this->palabra( 'NOT' ) ) {
			$no = true;
			++$this->i;
		}
		if ( $this->palabra( 'IN' ) ) {
			$this->i += 2; // IN (
			if ( $this->palabra( 'SELECT' ) ) {
				// Subconsulta de emails: se toman los de «email_address_caps IN (…)».
				$profundidad = 1;
				$emails      = array();
				$captura     = false;
				while ( $profundidad > 0 && 'fin' !== $this->ver()[0] ) {
					$t = $this->ver();
					if ( 'id' === $t[0] && str_ends_with( strtolower( $t[1] ), 'email_address_caps' ) ) {
						$captura = true;
					} elseif ( $captura && 's' === $t[0] ) {
						$emails[] = strtolower( $t[1] );
					} elseif ( '(' === $t[0] ) {
						++$profundidad;
					} elseif ( ')' === $t[0] ) {
						--$profundidad;
						if ( $captura && 1 === $profundidad ) {
							$captura = false;
						}
					}
					++$this->i;
				}
				return array( 'emails', $emails, $no );
			}
			$vals = array();
			while ( ')' !== $this->ver()[0] && 'fin' !== $this->ver()[0] ) {
				if ( 's' === $this->ver()[0] ) {
					$vals[] = $this->ver()[1];
				}
				++$this->i;
			}
			++$this->i; // )
			return array( 'in', $col, $vals, $no );
		}
		if ( $this->palabra( 'LIKE' ) ) {
			++$this->i;
			$v = $this->ver()[1];
			++$this->i;
			return array( 'like', $col, $v, $no );
		}
		$op = $this->ver()[1];
		++$this->i;
		$v = $this->ver()[1];
		++$this->i;
		return array( 'cmp', $col, $op, $v );
	}

	private static function norm( string $v ): string {
		return strtolower( remove_accents( $v ) );
	}

	public static function cumple( array $n, array $r ): bool {
		switch ( $n[0] ) {
			case 'and':
				return self::cumple( $n[1], $r ) && self::cumple( $n[2], $r );
			case 'or':
				return self::cumple( $n[1], $r ) || self::cumple( $n[2], $r );
			case 'not':
				return ! self::cumple( $n[1], $r );
			case 'null':
				return $n[2] ? ! array_key_exists( $n[1], $r ) : array_key_exists( $n[1], $r );
			case 'in':
				$en = in_array( self::norm( (string) ( $r[ $n[1] ] ?? '' ) ), array_map( array( self::class, 'norm' ), $n[2] ), true );
				return $n[3] ? ! $en : $en;
			case 'emails':
				$en = '' !== (string) ( $r['email1'] ?? '' ) && in_array( strtolower( (string) $r['email1'] ), $n[1], true );
				return $n[2] ? ! $en : $en;
			case 'like':
				$patron = '/^' . str_replace( array( '%', '_' ), array( '.*', '.' ), preg_quote( self::norm( $n[2] ), '/' ) ) . '$/su';
				$en     = (bool) preg_match( $patron, self::norm( (string) ( $r[ $n[1] ] ?? '' ) ) );
				return $n[3] ? ! $en : $en;
			case 'cmp':
				$a = self::norm( (string) ( $r[ $n[1] ] ?? '' ) );
				$b = self::norm( (string) $n[3] );
				switch ( $n[2] ) {
					case '=':
						return $a === $b;
					case '!=':
					case '<>':
						return $a !== $b;
				}
		}
		return false;
	}
}
