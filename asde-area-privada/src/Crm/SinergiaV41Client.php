<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Audit\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * API REST legacy v4_1 de SinergiaCRM (la que documenta su wiki).
 *
 * Endpoint preferente: /custom/service/v4_1_SticCustom/rest.php
 * Alternativa:         /service/v4_1/rest.php
 *
 * Notas del protocolo:
 * - La contraseña viaja en MD5 (exigencia del protocolo): solo sobre HTTPS.
 * - Los argumentos son POSICIONALES: el orden de las claves de rest_data importa.
 * - `query` es un fragmento WHERE en SQL: construirlo SIEMPRE con Query::*.
 * - Los valores llegan con entidades HTML; aquí se decodifican.
 *
 * ESCRITURA: solo set_entry() sobre registros existentes (nunca crea ni borra),
 * y solo a través de Crm\Escritura, que comprueba el permiso del usuario del
 * CRM, que la ficha sea del grupo y deja constancia en la auditoría.
 */
final class SinergiaV41Client implements CrmClientInterface, EscritorCrm {

	private const ENDPOINTS = array(
		'/custom/service/v4_1_SticCustom/rest.php',
		'/service/v4_1/rest.php',
	);

	/** Módulos cuyos permisos de escritura se revisan en la prueba de conexión. */
	private const WATCHED_MODULES = array( 'Contacts', 'stic_Contacts_Relationships', 'stic_Personal_Environment', 'stic_Registrations' );

	private const SESSION_TTL = 15 * MINUTE_IN_SECONDS;

	private const ERROR_INVALID_SESSION = 11;

	/** La sesión se renueva (se guarda de nuevo) si lleva más de esto sin hacerlo, mientras se sigue usando. */
	private const SESSION_RENOVAR = 5 * MINUTE_IN_SECONDS;

	private ?string $session  = null;
	private ?string $endpoint = null;
	private int $session_t    = 0;

	public function __construct(
		private string $base_url,
		private string $username,
		private string $password
	) {}

	// ---------------------------------------------------------------------
	// Prueba de conexión
	// ---------------------------------------------------------------------

	public function ping(): ConnectionResult {
		$last_error = '';

		foreach ( self::ENDPOINTS as $path ) {
			$endpoint = $this->base_url . $path;
			$login    = $this->raw_login( $endpoint );

			if ( is_wp_error( $login ) ) {
				$last_error = $login->get_error_message();
				if ( 'asde_crm_not_api' === $login->get_error_code() ) {
					continue; // Probar el siguiente endpoint.
				}
				return ConnectionResult::fail( $last_error, array( 'Endpoint: ' . $endpoint ) );
			}

			if ( empty( $login['id'] ) ) {
				return ConnectionResult::fail(
					/* translators: %s: reason the CRM gives for rejecting the sign-in */
					sprintf( /* translators: %s: text */ __( 'The CRM rejected the sign-in: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $this->error_text( $login ) ),
					array( 'Endpoint: ' . $endpoint )
				);
			}

			$session  = (string) $login['id'];
			$details  = array(
				__( 'API: REST v4_1', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'Endpoint: ' . $endpoint,
				'Usuario CRM: ' . (string) ( $login['name_value_list']['user_name']['value'] ?? $this->username ),
			);
			$warnings = $this->write_permission_warnings( $endpoint, $session );

			if ( ! empty( $login['name_value_list']['user_is_admin']['value'] ) ) {
				$warnings[] = __( 'The API user is a CRM ADMINISTRATOR. Use a dedicated user with a read-only role.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}

			$this->post( $endpoint, 'logout', array( 'session' => $session ) );
			$this->forget_session();

			return new ConnectionResult( true, __( 'Connection to SinergiaCRM successful.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $details, $warnings );
		}

		return ConnectionResult::fail(
			__( 'The SinergiaCRM API was not found at that URL. Check that it is the CRM\'s base address (e.g. https://entidad.sinergiacrm.org).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			array( $last_error )
		);
	}

	// ---------------------------------------------------------------------
	// Lectura
	// ---------------------------------------------------------------------

	/**
	 * Listado de registros de un módulo (get_entry_list).
	 *
	 * @param string                  $query  Fragmento WHERE construido con Query::*.
	 * @param string[]                $fields Campos a devolver (lista blanca).
	 * @param array<string, string[]> $links  link => campos del registro relacionado.
	 * @return array{entries: list<array>, total: int, next_offset: int}
	 */
	public function list_entries( string $module, string $query = '', array $fields = array( 'id' ), array $links = array(), int $offset = 0, int $max = 100, string $order_by = '' ): array {
		$data = $this->call(
			'get_entry_list',
			array(
				'module_name'               => $module,
				'query'                     => $query,
				'order_by'                  => $order_by,
				'offset'                    => $offset,
				'select_fields'             => array_values( $fields ),
				'link_name_to_fields_array' => $this->links_arg( $links ),
				'max_results'               => $max,
				'deleted'                   => 0,
				'favorites'                 => false,
			)
		);

		return array(
			'entries'     => $this->parse_entries( $data ),
			'total'       => (int) ( $data['total_count'] ?? 0 ),
			'next_offset' => (int) ( $data['next_offset'] ?? 0 ),
		);
	}

	/**
	 * Todos los registros que cumplen la consulta, paginando internamente.
	 *
	 * Si se llega a `$hard_limit` y el CRM aún tiene más, se lanza CrmException en vez de
	 * devolver una lista cortada: quien filtra después por grupo (PersonaRepository::todas,
	 * Duplicados) perdería en silencio las fichas que caen tras el corte (doc 59, RC-CRM-1).
	 *
	 * @return list<array>
	 * @throws CrmException Si hay más registros que el límite.
	 */
	public function list_all( string $module, string $query = '', array $fields = array( 'id' ), array $links = array(), string $order_by = '', int $hard_limit = 5000 ): array {
		$all    = array();
		$offset = 0;
		$hard_limit = max( 1, $hard_limit );
		do {
			$page = $this->list_entries( $module, $query, $fields, $links, $offset, min( 200, $hard_limit ), $order_by );
			$all  = array_merge( $all, $page['entries'] );
			if ( ! $page['entries'] || $page['next_offset'] <= $offset ) {
				break;
			}
			$offset = $page['next_offset'];
		} while ( count( $all ) < $page['total'] && count( $all ) < $hard_limit );

		if ( count( $all ) >= $hard_limit && count( $all ) < $page['total'] ) {
			// El total puede contar más de lo visible: se mira si de verdad queda algo después.
			$mas = $this->list_entries( $module, $query, array( 'id' ), array(), $offset, 1, $order_by );
			if ( $mas['entries'] ) {
				Logger::log(
					'crm_limite_lista',
					$module,
					'',
					array(
						'limite' => $hard_limit,
						'total'  => (int) $page['total'],
					)
				);
				/* translators: %d: maximum number of records read at once */
				throw new CrmException( esc_html( sprintf( /* translators: %d: number */ __( 'The CRM has more than %d records visible to this connection and they cannot all be read at once: the result would be incomplete. Tell whoever administers the private area.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hard_limit ) ) );
			}
		}

		return $all;
	}

	/**
	 * ⚠️ En el CRM de ASDE get_entries_count NO aplica el filtro de grupos de
	 * seguridad: cuenta registros de TODO el CRM federal. No usar para nada que
	 * dependa del ámbito del grupo (usar list_entries). Solo para diagnóstico.
	 */
	public function count( string $module, string $query = '' ): int {
		$data = $this->call(
			'get_entries_count',
			array(
				'module_name' => $module,
				'query'       => $query,
				'deleted'     => 0,
			)
		);
		return (int) ( $data['result_count'] ?? 0 );
	}

	/**
	 * Un registro por id (get_entry). null si no existe o no es visible.
	 * ⚠️ No garantiza el filtro por grupo de seguridad: para datos de personas
	 * usar list_entries con "tabla.id = '...'" (ver PersonaRepository::obtener).
	 */
	public function get_entry( string $module, string $id, array $fields, array $links = array() ): ?array {
		Query::id( $id ); // valida
		$data = $this->call(
			'get_entry',
			array(
				'module_name'               => $module,
				'id'                        => $id,
				'select_fields'             => array_values( $fields ),
				'link_name_to_fields_array' => $this->links_arg( $links ),
				'track_view'                => false,
			)
		);
		$entries = $this->parse_entries( $data );
		$entry   = $entries[0] ?? null;

		// get_entry devuelve una entrada vacía o con aviso si el registro no existe/está borrado.
		if ( ! $entry || '' === $entry['id'] || ! empty( $entry['fields']['warning'] ) ) {
			return null;
		}
		return $entry;
	}

	/**
	 * Registros relacionados a través de un link (get_relationships).
	 *
	 * @return list<array>
	 */
	public function related( string $module, string $id, string $link, string $query = '', array $fields = array( 'id' ), array $links = array(), int $offset = 0, int $limit = 200 ): array {
		Query::id( $id );
		$data = $this->call(
			'get_relationships',
			array(
				'module_name'                              => $module,
				'module_id'                                => $id,
				'link_field_name'                          => $link,
				'related_module_query'                     => $query,
				'related_fields'                           => array_values( $fields ),
				'related_module_link_name_to_fields_array' => $this->links_arg( $links ),
				'deleted'                                  => 0,
				'order_by'                                 => '',
				'offset'                                   => $offset,
				'limit'                                    => $limit,
			)
		);
		return $this->parse_entries( $data );
	}

	/** Metadatos de campos de un módulo (sin datos de registros). */
	public function module_fields( string $module, array $fields = array() ): array {
		return $this->call(
			'get_module_fields',
			array(
				'module_name' => $module,
				'fields'      => array_values( $fields ),
			)
		);
	}

	/** @return array<string, array<string, bool>> módulo => acción (list, view, edit, delete…) => permitido */
	public function acls(): array {
		$data = $this->call( 'get_available_modules', array( 'filter' => 'all' ) );
		$out  = array();
		foreach ( (array) ( $data['modules'] ?? array() ) as $m ) {
			$key = (string) ( $m['module_key'] ?? '' );
			foreach ( (array) ( $m['acls'] ?? array() ) as $acl ) {
				$out[ $key ][ (string) ( $acl['action'] ?? '' ) ] = ! empty( $acl['access'] );
			}
		}
		return $out;
	}

	/**
	 * Guarda campos de un registro EXISTENTE (set_entry con su id). No crea
	 * registros: sin id válido no se llama al CRM.
	 */
	public function set_entry( string $module, string $id, array $values ): string {
		Query::id( $id ); // valida
		$nvl = array(
			array(
				'name'  => 'id',
				'value' => $id,
			),
		);
		foreach ( $values as $name => $value ) {
			if ( 'id' !== $name ) {
				$nvl[] = array(
					'name'  => (string) $name,
					'value' => (string) $value,
				);
			}
		}
		$data = $this->call(
			'set_entry',
			array(
				'module_name'     => $module,
				'name_value_list' => $nvl,
			)
		);
		if ( ( $data['id'] ?? '' ) !== $id ) {
			throw new CrmException( esc_html( __( 'The CRM has not confirmed the change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
		}
		return $id;
	}

	/**
	 * Crea un registro NUEVO (set_entry sin id) y devuelve su id (v0.22, doc 34). Solo
	 * se usa desde Crm\Escritura::crear (permisos, módulos permitidos y auditoría).
	 */
	public function create_entry( string $module, array $values ): string {
		$nvl = array();
		foreach ( $values as $name => $value ) {
			if ( 'id' !== $name ) {
				$nvl[] = array(
					'name'  => (string) $name,
					'value' => (string) $value,
				);
			}
		}
		$data = $this->call(
			'set_entry',
			array(
				'module_name'     => $module,
				'name_value_list' => $nvl,
			)
		);
		$id = (string) ( $data['id'] ?? '' );
		if ( ! Query::is_id( $id ) ) {
			throw new CrmException( esc_html( __( 'The CRM has not confirmed the creation of the record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
		}
		return $id;
	}

	/**
	 * Enlaza un registro con otros (set_relationship, v0.22, doc 34): p. ej. una persona
	 * con el grupo de seguridad de su grupo o con una membresía. Solo desde Crm\Escritura.
	 *
	 * @param string[] $related_ids
	 */
	public function set_relationship( string $module, string $id, string $link, array $related_ids ): bool {
		Query::id( $id ); // valida
		foreach ( $related_ids as $r ) {
			Query::id( (string) $r );
		}
		$data = $this->call(
			'set_relationship',
			array(
				'module_name'     => $module,
				'module_id'       => $id,
				'link_field_name' => $link,
				'related_ids'     => array_values( array_map( 'strval', $related_ids ) ),
				'name_value_list' => array(),
				'delete'          => 0,
			)
		);
		return (int) ( $data['created'] ?? 0 ) > 0 && 0 === (int) ( $data['failed'] ?? 0 );
	}

	// ---------------------------------------------------------------------
	// Sesión y transporte
	// ---------------------------------------------------------------------

	/**
	 * Llamada autenticada. Reintenta una vez si la sesión ha caducado.
	 *
	 * @param array $args Argumentos SIN la sesión, en el orden del método remoto.
	 */
	private function call( string $method, array $args, bool $retry = true ): array {
		$session = $this->session();
		$data    = $this->post( (string) $this->endpoint, $method, array( 'session' => $session ) + $args );

		if ( is_wp_error( $data ) ) {
			throw new CrmException( esc_html( $data->get_error_message() ) );
		}

		if ( isset( $data['number'], $data['name'] ) && ! isset( $data['entry_list'] ) ) {
			if ( self::ERROR_INVALID_SESSION === (int) $data['number'] && $retry ) {
				$this->forget_session();
				return $this->call( $method, $args, false );
			}
			throw new CrmException( esc_html( sprintf( 'CRM (%s): %s', $method, $this->error_text( $data ) ) ) );
		}

		$this->renovar_sesion();
		return $data;
	}

	private function session(): string {
		if ( null !== $this->session ) {
			return $this->session;
		}

		$cached = get_transient( $this->session_key() );
		if ( is_array( $cached ) && ! empty( $cached['s'] ) && ! empty( $cached['e'] ) ) {
			$session = Crypto::decrypt( (string) $cached['s'] );
			if ( null !== $session ) {
				$this->session   = $session;
				$this->endpoint  = (string) $cached['e'];
				$this->session_t = (int) ( $cached['t'] ?? 0 );
				return $session;
			}
		}

		foreach ( self::ENDPOINTS as $path ) {
			$endpoint = $this->base_url . $path;
			$login    = $this->raw_login( $endpoint );
			if ( is_wp_error( $login ) ) {
				if ( 'asde_crm_not_api' === $login->get_error_code() ) {
					continue;
				}
				throw new CrmException( esc_html( $login->get_error_message() ) );
			}
			if ( empty( $login['id'] ) ) {
				/* translators: %s: reason the CRM gives for rejecting the sign-in */
				throw new CrmException( esc_html( sprintf( /* translators: %s: text */ __( 'The CRM rejected the sign-in: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $this->error_text( $login ) ) ) );
			}
			$this->session  = (string) $login['id'];
			$this->endpoint = $endpoint;
			$this->guardar_sesion();
			return $this->session;
		}

		throw new CrmException( esc_html( __( 'The SinergiaCRM API was not found at the configured URL.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
	}

	/** La sesión da acceso al CRM: se cachea cifrada y por poco tiempo. */
	private function guardar_sesion(): void {
		$this->session_t = time();
		set_transient( $this->session_key(), array( 's' => Crypto::encrypt( (string) $this->session ), 'e' => $this->endpoint, 't' => $this->session_t ), self::SESSION_TTL );
	}

	/**
	 * Tras una llamada bien hecha, la sesión sigue viva en el CRM: se alarga su tiempo
	 * aquí también. Antes caducaba a los 15 minutos de iniciarla aunque se estuviera
	 * usando, y cada quince minutos alguien esperaba además un inicio de sesión (~1 s).
	 */
	private function renovar_sesion(): void {
		if ( null !== $this->session && time() - $this->session_t > self::SESSION_RENOVAR ) {
			$this->guardar_sesion();
		}
	}

	private function forget_session(): void {
		$this->session  = null;
		$this->endpoint = null;
		delete_transient( $this->session_key() );
	}

	private function session_key(): string {
		return 'asde_ap_crm_sess_' . md5( $this->base_url . '|' . $this->username );
	}

	/** @return array|\WP_Error */
	private function raw_login( string $endpoint ) {
		return $this->post(
			$endpoint,
			'login',
			array(
				'user_auth'        => array(
					'user_name' => $this->username,
					'password'  => md5( $this->password ),
				),
				'application_name' => 'WordPress ASDE Área privada',
				'name_value_list'  => array(),
			)
		);
	}

	/** Revisa (sin leer registros) si el usuario puede escribir en los módulos clave. */
	private function write_permission_warnings( string $endpoint, string $session ): array {
		$modules = $this->post( $endpoint, 'get_available_modules', array( 'session' => $session, 'filter' => 'all' ) );
		if ( is_wp_error( $modules ) || empty( $modules['modules'] ) || ! is_array( $modules['modules'] ) ) {
			return array();
		}

		$writable = array();
		foreach ( $modules['modules'] as $module ) {
			$key = (string) ( $module['module_key'] ?? '' );
			if ( ! in_array( $key, self::WATCHED_MODULES, true ) || empty( $module['acls'] ) || ! is_array( $module['acls'] ) ) {
				continue;
			}
			foreach ( $module['acls'] as $acl ) {
				if ( in_array( $acl['action'] ?? '', array( 'edit', 'delete', 'import' ), true ) && ! empty( $acl['access'] ) ) {
					$writable[] = $key;
					break;
				}
			}
		}

		if ( ! $writable ) {
			return array();
		}
		return array(
			sprintf(
				/* translators: %s: list of modules */
				__( 'The API user has write permissions on: %s. With them, records can be edited from the private area (depending on each user\'s role).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				implode( ', ', array_unique( $writable ) )
			),
		);
	}

	/** @return array|\WP_Error */
	private function post( string $endpoint, string $method, array $args ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 25,
				'httpversion' => '1.1',
				'body'        => array(
					'method'        => $method,
					'input_type'    => 'JSON',
					'response_type' => 'JSON',
					'rest_data'     => wp_json_encode( $args ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: HTTP connection error message */
			return new \WP_Error( 'asde_crm_http', sprintf( /* translators: %s: text */ __( 'Cannot connect to the CRM: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// No se registra el cuerpo de la respuesta: podría contener datos personales.
		if ( 200 !== $code || ! is_array( $data ) ) {
			/* translators: 1: CRM API address, 2: HTTP response code */
			return new \WP_Error( 'asde_crm_not_api', sprintf( /* translators: 1: text, 2: number */ __( '%1$s answered HTTP %2$d without a valid response from the API.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $endpoint, $code ) );
		}
		return $data;
	}

	// ---------------------------------------------------------------------
	// Parseo de respuestas
	// ---------------------------------------------------------------------

	/** @param array<string, string[]> $links */
	private function links_arg( array $links ): array {
		$out = array();
		foreach ( $links as $name => $fields ) {
			$out[] = array(
				'name'  => $name,
				'value' => array_values( $fields ),
			);
		}
		return $out;
	}

	/**
	 * Normaliza entry_list + relationship_list a:
	 *   [ ['id' => ..., 'fields' => [campo => valor], 'links' => [link => [ [campo => valor], ... ]]], ... ]
	 *
	 * @return list<array>
	 */
	private function parse_entries( array $data ): array {
		$entries = array();
		foreach ( (array) ( $data['entry_list'] ?? array() ) as $i => $entry ) {
			$fields = $this->parse_nvl( (array) ( $entry['name_value_list'] ?? array() ) );
			$links  = array();

			foreach ( (array) ( $data['relationship_list'][ $i ]['link_list'] ?? array() ) as $link ) {
				$records = array();
				foreach ( (array) ( $link['records'] ?? array() ) as $record ) {
					$records[] = $this->parse_nvl( (array) ( $record['link_value'] ?? $record ) );
				}
				$links[ (string) ( $link['name'] ?? '' ) ] = $records;
			}

			$entries[] = array(
				'id'     => (string) ( $entry['id'] ?? ( $fields['id'] ?? '' ) ),
				'fields' => $fields,
				'links'  => $links,
			);
		}
		return $entries;
	}

	/** name_value_list ({campo: {name, value}} o [{name, value}]) → [campo => valor decodificado]. */
	private function parse_nvl( array $nvl ): array {
		$out = array();
		foreach ( $nvl as $key => $item ) {
			if ( is_array( $item ) && array_key_exists( 'value', $item ) ) {
				$name         = (string) ( $item['name'] ?? $key );
				$out[ $name ] = is_string( $item['value'] ) ? html_entity_decode( $item['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $item['value'];
			}
		}
		return $out;
	}

	private function error_text( array $data ): string {
		return (string) ( $data['description'] ?? $data['name'] ?? __( 'unexpected response', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}
}
