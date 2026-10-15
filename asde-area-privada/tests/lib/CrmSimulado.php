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

	/**
	 * Fichas de OTROS grupos u organizaciones (doc 34): get_entry_list no las devuelve
	 * (el CRM solo da las del grupo), pero get_entries_count las cuenta, como en el CRM
	 * de ASDE, que cuenta en todo el CRM federal (doc 04, §7.6).
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $otros_grupos = array();

	/** Simula que get_entries_count deja de ser federal y solo cuenta lo del grupo (el canario del doc 34). */
	public bool $recuento_por_grupo = false;

	// --- Altas (v0.22, doc 34, fase 2) -----------------------------------------

	/**
	 * Membresías (stic_Contacts_Relationships): cada una con `id`, `persona` (id de la
	 * ficha, o '' si aún no está enlazada) y sus campos.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $membresias = array();

	/** Fichas creadas que el CRM no puso en el grupo de seguridad del grupo (no las ve hasta enlazarlas). */
	public array $sin_grupo = array();

	/** Como `securitysuite_inherit_creator` de SuiteCRM: la ficha nueva entra en el grupo de quien la crea (P-73). */
	public bool $heredar_grupo = true;

	/** El enlace con la persona puesto en el set_entry de la membresía la enlaza ya (si no, hace falta set_relationship). */
	public bool $enlazar_en_set_entry = true;

	/** El CRM pone el nº de Scouts de España a las personas nuevas (P-72). */
	public bool $numerar = true;

	public int $siguiente_numero = 995001;

	/** @var array<string, int> «metodo:modulo» (o «metodo») => cuántas veces falla a propósito. */
	public array $fallos = array();

	/** @var array<string, array<string, bool>> ACL por módulo (get_available_modules). */
	public array $acls = array(
		'Contacts'                    => array( 'list' => true, 'view' => true, 'edit' => true ),
		'stic_Contacts_Relationships' => array( 'list' => true, 'view' => true, 'edit' => true ),
	);

	/** Nº del grupo scout (solo para listar su grupo de seguridad: servidor simulado). */
	public string $numgrupo = '999';

	private int $creados = 0;

	/** Responde también a get_module_fields con tipos básicos (por defecto no: las pruebas no lo necesitan). */
	public bool $metadatos = false;

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
			case 'get_available_modules':
				$modulos = array();
				foreach ( $this->acls as $m => $acciones ) {
					$acls = array();
					foreach ( $acciones as $accion => $si ) {
						$acls[] = array(
							'action' => $accion,
							'access' => $si,
						);
					}
					$modulos[] = array(
						'module_key' => $m,
						'acls'       => $acls,
					);
				}
				return array( 'modules' => $modulos );
			case 'get_entry':
				$r = $this->buscar_ficha( (string) ( $d['id'] ?? '' ) );
				$entradas = $r ? $this->lista_de( array( $r ), 'Contacts', (array) ( $d['select_fields'] ?? array() ), array() ) : array();
				return array(
					'entry_list'        => $entradas['entry_list'] ?? array(),
					'relationship_list' => array(),
				);
			case 'set_relationship':
				if ( $this->falla( 'set_relationship' ) ) {
					return array( 'number' => 40, 'name' => 'Fallo simulado', 'description' => 'set_relationship' );
				}
				return array( 'created' => $this->relacionar( (string) ( $d['module_id'] ?? '' ), (string) ( $d['link_field_name'] ?? '' ), (array) ( $d['related_ids'] ?? array() ) ) ? 1 : 0, 'failed' => 0, 'deleted' => 0 );
			case 'get_relationships':
				$filas = array();
				if ( 'stic_contacts_relationships_contacts' === ( $d['link_field_name'] ?? '' ) ) {
					$filas = array_values( array_filter( $this->membresias, static fn( $m ) => $m['persona'] === (string) ( $d['module_id'] ?? '' ) ) );
				}
				return $this->lista_de( $filas, 'stic_Contacts_Relationships', (array) ( $d['related_fields'] ?? array() ), array() );
			case 'get_entries_count':
				$filas = 'Contacts' === ( $d['module_name'] ?? '' ) ? array_merge( $this->personas, $this->sin_grupo, $this->recuento_por_grupo ? array() : $this->otros_grupos ) : array();
				$query = (string) ( $d['query'] ?? '' );
				if ( '' !== trim( $query ) ) {
					$ast   = Asde_Prueba_Consulta::analizar( $query );
					$filas = array_filter( $filas, static fn( $r ) => Asde_Prueba_Consulta::cumple( $ast, $r ) );
				}
				return array( 'result_count' => (string) count( $filas ) );
			case 'get_entry_list':
				return $this->lista( (string) ( $d['module_name'] ?? '' ), (string) ( $d['query'] ?? '' ), (array) ( $d['select_fields'] ?? array() ), (array) ( $d['link_name_to_fields_array'] ?? array() ), (int) ( $d['offset'] ?? 0 ), (int) ( $d['max_results'] ?? 100 ) );
			case 'set_entry':
				$modulo  = (string) ( $d['module_name'] ?? 'Contacts' );
				$valores = array();
				$id      = '';
				foreach ( (array) ( $d['name_value_list'] ?? array() ) as $nv ) {
					if ( 'id' === ( $nv['name'] ?? '' ) ) {
						$id = (string) $nv['value'];
					} else {
						$valores[ (string) $nv['name'] ] = (string) $nv['value'];
					}
				}
				if ( $this->falla( 'set_entry:' . $modulo ) ) {
					return array( 'number' => 40, 'name' => 'Fallo simulado', 'description' => 'set_entry ' . $modulo );
				}
				if ( '' === $id ) {
					return array( 'id' => 'Contacts' === $modulo ? $this->crear_persona( $valores ) : $this->crear_membresia( $valores ) );
				}
				return array( 'id' => 'Contacts' === $modulo ? $this->set_entry( 'Contacts', $id, $valores ) : $this->cambiar_membresia( $id, $valores ) );
			case 'get_module_fields':
				if ( ! $this->metadatos ) {
					return array( 'number' => 20, 'name' => 'Module Does Not Exist', 'description' => 'Método no simulado: ' . $metodo );
				}
				$campos = array();
				foreach ( (array) ( $d['fields'] ?? array() ) as $c ) {
					$c = (string) $c;
					$tipo = str_starts_with( $c, 'email' ) || str_ends_with( $c, '_email_c' ) ? 'email' : ( str_starts_with( $c, 'phone' ) || str_contains( $c, 'movil' ) || str_contains( $c, 'telefono' ) ? 'phone' : ( str_contains( $c, 'fecha' ) || 'birthdate' === $c ? 'date' : 'varchar' ) );
					$campos[ $c ] = array( 'name' => $c, 'type' => $tipo, 'label' => $c, 'len' => 100 );
				}
				return array( 'module_fields' => $campos );
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

	// --- Altas: lo que hace el CRM al guardar ---------------------------------

	/** Una persona nueva: id nuevo, nº de Scouts de España (si numera) y en el grupo (si hereda). */
	private function crear_persona( array $valores ): string {
		$id     = sprintf( 'de000000-0000-4000-8000-%012d', 800000 + ++$this->creados );
		$ficha  = array_merge(
			self::persona( 0, '', '', '', '' ),
			array(
				'id'                        => $id,
				'numerosocio_c'             => $this->numerar ? (string) $this->siguiente_numero++ : '',
				'numsocioantiguo_enficha_c' => '',
				'stic_relationship_type_c'  => '^^',
			),
			array_diff_key( $valores, array_flip( array( 'id', 'numerosocio_c', 'stic_relationship_type_c' ) ) )
		);
		$this->escrituras[] = array( 'modulo' => 'Contacts', 'id' => $id, 'campos' => $valores, 'nueva' => true );
		if ( $this->heredar_grupo ) {
			$this->personas[] = $ficha;
		} else {
			$this->sin_grupo[] = $ficha;
		}
		return $id;
	}

	/** Membresía nueva: `active` según las fechas y «Secciones actuales» recalculada (como los hooks de SinergiaCRM, P-74). */
	private function crear_membresia( array $valores ): string {
		$id      = sprintf( 'de000001-0000-4000-8000-%012d', 800000 + ++$this->creados );
		$persona = $this->enlazar_en_set_entry ? (string) ( $valores['stic_contacts_relationships_contactscontacts_ida'] ?? '' ) : '';
		$this->membresias[] = array_merge(
			array(
				'id'         => $id,
				'persona'    => $persona,
				'end_date'   => '',
				'end_reason' => '',
				'numsocio_c' => '',
			),
			array_diff_key( $valores, array_flip( array( 'id', 'stic_contacts_relationships_contactscontacts_ida' ) ) )
		);
		$this->escrituras[] = array( 'modulo' => 'stic_Contacts_Relationships', 'id' => $id, 'campos' => $valores, 'nueva' => true );
		$this->recalcular( $id );
		return $id;
	}

	private function cambiar_membresia( string $id, array $valores ): string {
		foreach ( $this->membresias as $i => $m ) {
			if ( $m['id'] === $id ) {
				$this->membresias[ $i ] = array_merge( $m, array_diff_key( $valores, array_flip( array( 'id' ) ) ) );
				$this->escrituras[]     = array( 'modulo' => 'stic_Contacts_Relationships', 'id' => $id, 'campos' => $valores );
				$this->recalcular( $id );
				return $id;
			}
		}
		return '';
	}

	/** Para sembrar las pruebas: una membresía de una ficha (activa si no se da fecha de fin). */
	public function alta_membresia( string $persona, string $tipo, string $inicio, string $fin = '' ): string {
		$id = $this->crear_membresia(
			array(
				'relationship_type'                                => $tipo,
				'start_date'                                       => $inicio,
				'end_date'                                         => $fin,
				'estado_c'                                         => '' === $fin ? 'activo' : 'baja',
				'stic_contacts_relationships_contactscontacts_ida' => $persona,
			)
		);
		$this->membresias[ count( $this->membresias ) - 1 ]['persona'] = $persona;
		$this->recalcular( $id );
		array_pop( $this->escrituras ); // Es la semilla, no algo escrito por la web.
		return $id;
	}

	/** `active` de la membresía y «Secciones actuales» de su persona. */
	private function recalcular( string $membresia ): void {
		$hoy     = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ); // La fecha del sitio, como el plugin.
		$persona = '';
		foreach ( $this->membresias as $i => $m ) {
			if ( $m['id'] === $membresia ) {
				// Como SinergiaCRM (P-74): solo por las fechas; `estado_c` no cuenta (v0.34: una membresía en «baja» sin fecha sigue activa).
				$activa                           = (string) ( $m['start_date'] ?? '' ) <= $hoy && ( '' === (string) ( $m['end_date'] ?? '' ) || (string) $m['end_date'] > $hoy );
				$this->membresias[ $i ]['active'] = $activa ? '1' : '0';
				$persona                          = $m['persona'];
			}
		}
		if ( '' === $persona ) {
			return;
		}
		$secciones = array();
		foreach ( $this->membresias as $m ) {
			if ( $m['persona'] === $persona && '1' === $m['active'] ) {
				$secciones[] = '^' . $m['relationship_type'] . '^';
			}
		}
		foreach ( array( 'personas', 'sin_grupo' ) as $lista ) {
			foreach ( $this->{$lista} as $i => $p ) {
				if ( $p['id'] === $persona ) {
					$this->{$lista}[ $i ]['stic_relationship_type_c'] = $secciones ? implode( ',', array_unique( $secciones ) ) : '^^';
				}
			}
		}
	}

	/** set_relationship: la persona al grupo de seguridad del grupo, o una membresía a su persona. */
	private function relacionar( string $persona, string $enlace, array $ids ): bool {
		if ( 'SecurityGroups' === $enlace ) {
			if ( array( $this->grupo_sg ) !== array_values( array_map( 'strval', $ids ) ) ) {
				return false;
			}
			foreach ( $this->sin_grupo as $i => $p ) {
				if ( $p['id'] === $persona ) {
					$this->personas[] = $p;
					unset( $this->sin_grupo[ $i ] );
					$this->sin_grupo = array_values( $this->sin_grupo );
					break;
				}
			}
			$this->escrituras[] = array( 'modulo' => 'SecurityGroups', 'id' => $persona, 'campos' => array( 'enlace' => $enlace ) );
			return true;
		}
		if ( 'stic_contacts_relationships_contacts' === $enlace ) {
			foreach ( $this->membresias as $i => $m ) {
				if ( in_array( $m['id'], array_map( 'strval', $ids ), true ) ) {
					$this->membresias[ $i ]['persona'] = $persona;
					$this->recalcular( $m['id'] );
				}
			}
			$this->escrituras[] = array( 'modulo' => 'stic_Contacts_Relationships', 'id' => $persona, 'campos' => array( 'enlace' => $enlace ) );
			return true;
		}
		return false;
	}

	private function falla( string $clave ): bool {
		foreach ( array( $clave, strtok( $clave, ':' ) ) as $k ) {
			if ( ( $this->fallos[ $k ] ?? 0 ) > 0 ) {
				--$this->fallos[ $k ];
				return true;
			}
		}
		return false;
	}

	private function buscar_ficha( string $id ): ?array {
		foreach ( array_merge( $this->personas, $this->sin_grupo ) as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	// --- Servidor de la API simulado (tests/servidor-crm) ----------------------

	/** Contesta una petición de la API v4_1 (método y rest_data ya decodificado). */
	public function responder_http( string $metodo, array $datos ): array {
		++$this->llamadas;
		return $this->responder( $metodo, $datos );
	}

	/** Estado para guardarlo entre peticiones. */
	public function exportar(): array {
		return array(
			'personas'         => $this->personas,
			'otros_grupos'     => $this->otros_grupos,
			'sin_grupo'        => $this->sin_grupo,
			'membresias'       => $this->membresias,
			'siguiente_numero' => $this->siguiente_numero,
			'creados'          => $this->creados,
			'heredar_grupo'    => $this->heredar_grupo,
			'numgrupo'         => $this->numgrupo,
		);
	}

	public function importar( array $estado ): void {
		foreach ( array( 'personas', 'otros_grupos', 'sin_grupo', 'membresias' ) as $k ) {
			$this->{$k} = array_values( (array) ( $estado[ $k ] ?? array() ) );
		}
		$this->siguiente_numero = (int) ( $estado['siguiente_numero'] ?? $this->siguiente_numero );
		$this->creados          = (int) ( $estado['creados'] ?? 0 );
		$this->heredar_grupo    = (bool) ( $estado['heredar_grupo'] ?? true );
		$this->numgrupo         = (string) ( $estado['numgrupo'] ?? $this->numgrupo );
	}

	// --- Consultas ------------------------------------------------------------

	private function lista( string $modulo, string $query, array $campos, array $links, int $offset, int $max ): array {
		if ( 'SecurityGroups' === $modulo ) {
			$grupo = array(
				'id'               => $this->grupo_sg,
				'name'             => 'Grupo Scout ' . $this->numgrupo,
				'numgrupo_c'       => $this->numgrupo,
				'tipoasociacion_c' => 'GS',
			);
			return $this->lista_de( array( $grupo ), $modulo, $campos, array() );
		}
		$filas = 'Contacts' === $modulo ? $this->personas : array();
		if ( '' !== trim( $query ) ) {
			$ast   = Asde_Prueba_Consulta::analizar( $query );
			$filas = array_values( array_filter( $filas, static fn( $r ) => Asde_Prueba_Consulta::cumple( $ast, $r ) ) );
		}
		$trozo   = array_slice( $filas, $offset, max( 1, $max ) );
		$con_sg  = (bool) array_filter( $links, static fn( $l ) => 'SecurityGroups' === ( $l['name'] ?? '' ) );
		// v0.35.1: las membresías de cada ficha (PersonaRepository::links_lectura()).
		$con_m   = array_values( array_filter( $links, static fn( $l ) => 'stic_contacts_relationships_contacts' === ( $l['name'] ?? '' ) ) );
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
			$link_list = $con_sg ? array(
				array(
					'name'    => 'SecurityGroups',
					'records' => array( array( 'link_value' => array( 'id' => array( 'name' => 'id', 'value' => $this->grupo_sg ) ) ) ),
				),
			) : array();
			if ( $con_m ) {
				$registros = array();
				foreach ( $this->membresias as $m ) {
					if ( (string) ( $m['persona'] ?? '' ) !== (string) $r['id'] ) {
						continue;
					}
					$lv = array();
					foreach ( (array) ( $con_m[0]['value'] ?? array() ) as $c ) {
						$lv[ $c ] = array(
							'name'  => $c,
							'value' => (string) ( $m[ $c ] ?? '' ),
						);
					}
					$registros[] = array( 'link_value' => $lv );
				}
				$link_list[] = array(
					'name'    => 'stic_contacts_relationships_contacts',
					'records' => $registros,
				);
			}
			$rels[] = array( 'link_list' => $link_list );
		}
		return array(
			'result_count'      => count( $entries ),
			'total_count'       => count( $filas ),
			'next_offset'       => $offset + count( $entries ),
			'entry_list'        => $entries,
			'relationship_list' => $rels,
		);
	}

	/** Respuesta de lista con estas filas tal cual (sin filtrar ni paginar). */
	private function lista_de( array $filas, string $modulo, array $campos, array $links ): array {
		$entries = array();
		foreach ( $filas as $r ) {
			$nvl = array();
			foreach ( array_unique( array_merge( array( 'id' ), $campos ) ) as $c ) {
				$nvl[ $c ] = array(
					'name'  => $c,
					'value' => (string) ( $r[ $c ] ?? '' ),
				);
			}
			$entries[] = array(
				'id'              => (string) $r['id'],
				'module_name'     => $modulo,
				'name_value_list' => $nvl,
			);
		}
		return array(
			'result_count'      => count( $entries ),
			'total_count'       => count( $entries ),
			'next_offset'       => count( $entries ),
			'entry_list'        => $entries,
			'relationship_list' => array_fill( 0, count( $entries ), array( 'link_list' => array() ) ),
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
