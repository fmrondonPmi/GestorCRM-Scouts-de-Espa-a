<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura de personas (Contacts) del CRM de ASDE.
 *
 * Las fichas se identifican por el Nº de socio/a Scouts de España
 * (numerosocio_c): único, lo asigna el CRM y es la clave de la tabla de
 * equivalencias de WordPress.
 *
 * Cada grupo usa sus credenciales del CRM, que en los LISTADOS solo ven las
 * fichas de sus grupos de seguridad. OJO: no todos los métodos de la API aplican
 * ese filtro (get_entries_count cuenta todo el CRM federal), por eso aquí toda
 * lectura —incluida la de una ficha concreta— pasa por get_entry_list, y además
 * se filtra por el grupo de seguridad del grupo en el que se trabaja.
 *
 * Desde la v0.12.3 todas(), obtener() y obtener_varias() se sirven de una
 * instantánea cifrada y de pocos minutos de las fichas del grupo (Instantanea):
 * cada consulta al CRM tarda de 0,5 a 1,5 s y las páginas hacían hasta 6. Sin salud
 * (solo CAMPOS). Las búsquedas (nombre, email, documento, fecha) y valores() siguen
 * yendo al CRM en cada consulta.
 */
final class PersonaRepository {

	/** Lista blanca de campos. Sin datos de salud. */
	public const CAMPOS = array(
		'id',
		'numerosocio_c',
		'numsocioantiguo_enficha_c',
		'first_name',
		'last_name',
		'birthdate',
		'email1',
		'phone_mobile',
		'stic_relationship_type_c',
		'cargoeducativo_c',
		'tutor1_nombre_c',
		'tutor1_apellidos_c',
		'tutor1_email_c',
		'tutor2_nombre_c',
		'tutor2_apellidos_c',
		'tutor2_email_c',
	);

	/** Condición SQL: alguna membresía activa ("Secciones actuales" no vacío). */
	public const WHERE_ACTIVAS = "contacts_cstm.stic_relationship_type_c IS NOT NULL AND contacts_cstm.stic_relationship_type_c NOT IN ('', '^^')";

	public function __construct( private SinergiaV41Client $crm ) {}

	public static function make(): ?self {
		$reader = ClientFactory::reader();
		return $reader ? new self( $reader ) : null;
	}

	public function crm(): SinergiaV41Client {
		return $this->crm;
	}

	public static function es_num_asde( string $numero ): bool {
		return (bool) preg_match( '/^\d{1,12}$/', $numero );
	}

	/**
	 * Busca por nombre y/o apellidos, email (propio o de tutor), DNI o nº de socio
	 * (Scouts de España o local).
	 *
	 * @param bool $solo_activas Solo fichas con membresía activa (lo normal en todo el área privada).
	 * @return Persona[]
	 */
	public function buscar( string $texto, int $limite = 30, bool $solo_activas = true ): array {
		$texto = trim( (string) preg_replace( '/\s+/u', ' ', $texto ) );
		if ( mb_strlen( $texto ) < 3 ) {
			return array();
		}

		if ( is_email( $texto ) ) {
			$where = $this->where_emails( array( strtolower( $texto ) ) );
		} else {
			$por_nombre = array();
			foreach ( array_slice( array_filter( explode( ' ', $texto ), static fn( $t ) => mb_strlen( $t ) >= 2 ), 0, 4 ) as $termino ) {
				$like         = Query::contains( $termino );
				$por_nombre[] = "(contacts.first_name LIKE $like OR contacts.last_name LIKE $like)";
			}
			$compacto = str_replace( array( ' ', '-', '.' ), '', $texto );
			$exactos  = array(
				'contacts_cstm.stic_identification_number_c = ' . Query::str( $compacto ),
				'contacts_cstm.numerosocio_c = ' . Query::str( $compacto ),
			);
			if ( ctype_digit( $compacto ) ) {
				// Nº local en cualquiera de sus formatos (76840123, 076840123…).
				$exactos[] = 'contacts_cstm.numsocioantiguo_enficha_c LIKE ' . Query::contains( ltrim( $compacto, '0' ) );
			}
			$where = '(' . ( $por_nombre ? '(' . implode( ' AND ', $por_nombre ) . ') OR ' : '' ) . implode( ' OR ', $exactos ) . ')';
		}

		if ( $solo_activas ) {
			$where .= ' AND ' . self::WHERE_ACTIVAS;
		}

		// Si las credenciales ven varios grupos se filtra después: se piden más para no quedarse cortos.
		$pedir    = ! empty( Grupo::actual()['filtrar'] ) ? $limite * 4 : $limite;
		$personas = $this->personas( $this->crm->list_entries( 'Contacts', $where, self::CAMPOS, self::links_grupo(), 0, $pedir )['entries'] );
		return array_slice( $personas, 0, $limite );
	}

	/** Ficha por Nº de socio Scouts de España (solo si es del grupo). */
	public function obtener( string $num_asde ): ?Persona {
		if ( ! self::es_num_asde( $num_asde ) ) {
			return null;
		}
		$entradas = $this->entradas_grupo();
		if ( null !== $entradas ) {
			$hay = Instantanea::por_numeros( Grupo::numero(), array( $num_asde ) );
			if ( $hay ) {
				return 1 === count( $hay ) ? Persona::from_crm( $hay[0] ) : null;
			}
			// Una ficha que no está (recién creada en el CRM): se pregunta al CRM.
		}
		$personas = $this->personas( $this->crm->list_entries( 'Contacts', 'contacts_cstm.numerosocio_c = ' . Query::str( $num_asde ), self::CAMPOS, self::links_grupo(), 0, 2 )['entries'] );
		return 1 === count( $personas ) ? $personas[0] : null;
	}

	/**
	 * Valores de campos concretos de una ficha del grupo (p. ej. los de la ficha
	 * editable, incluidos los de salud si se pueden ver). Nada se guarda.
	 *
	 * @param string[] $campos
	 * @return array<string, string> campo => valor
	 */
	public function valores( Persona $p, array $campos ): array {
		$campos  = array_values( array_unique( array_filter( $campos, static fn( $c ) => (bool) preg_match( '/^[a-z0-9_]+$/', (string) $c ) ) ) );
		$entries = self::filtrar_grupo( $this->crm->list_entries( 'Contacts', 'contacts.id = ' . Query::id( $p->id ), array_merge( array( 'id' ), $campos ), self::links_grupo(), 0, 2 )['entries'] );
		return 1 === count( $entries ) ? array_map( 'strval', $entries[0]['fields'] ) : array();
	}

	/**
	 * Aislamiento entre grupos: con cada ficha se pide su grupo de seguridad del
	 * CRM y se descarta todo lo que no sea del grupo en el que se trabaja. Así no
	 * hay visibilidad entre grupos aunque unas credenciales vieran varios.
	 */
	public static function links_grupo(): array {
		return array( 'SecurityGroups' => array( 'id' ) );
	}

	/** @param array[] $entries Entradas de get_entry_list (con links_grupo()). */
	public static function filtrar_grupo( array $entries ): array {
		$crm_id = (string) ( Grupo::actual()['crm_id'] ?? '' );
		if ( '' === $crm_id ) {
			return array(); // Grupo sin verificar: no se muestra nada.
		}
		return array_values(
			array_filter(
				$entries,
				static fn( $e ) => in_array( $crm_id, array_map( 'strval', array_column( $e['links']['SecurityGroups'] ?? array(), 'id' ) ), true )
			)
		);
	}

	/**
	 * Varias fichas por Nº Scouts de España (solo las del grupo).
	 *
	 * @param string[] $nums
	 * @return array<string, Persona> num_asde => Persona, en el orden pedido
	 */
	public function obtener_varias( array $nums ): array {
		$nums = array_values( array_unique( array_filter( array_map( 'strval', $nums ), array( self::class, 'es_num_asde' ) ) ) );
		if ( ! $nums ) {
			return array();
		}
		$por_num  = array();
		$entradas = $this->entradas_grupo();
		if ( null !== $entradas ) {
			foreach ( $this->ordenar( array_map( array( Persona::class, 'from_crm' ), Instantanea::por_numeros( Grupo::numero(), $nums ) ) ) as $p ) {
				$por_num[ $p->num_socio_asde ] = $p;
			}
			// Las que no están en la instantánea (recién creadas en el CRM) se piden al CRM.
			$nums_vivas = array_values( array_diff( $nums, array_keys( $por_num ) ) );
		} else {
			$nums_vivas = $nums;
		}
		foreach ( array_chunk( $nums_vivas, 50 ) as $lote ) {
			$in = implode( ',', array_map( array( Query::class, 'str' ), $lote ) );
			foreach ( $this->personas( $this->crm->list_entries( 'Contacts', "contacts_cstm.numerosocio_c IN ($in)", self::CAMPOS, self::links_grupo(), 0, 60 )['entries'] ) as $p ) {
				$por_num[ $p->num_socio_asde ] = $p;
			}
		}
		$out = array();
		foreach ( $nums as $n ) {
			if ( isset( $por_num[ $n ] ) ) {
				$out[ $n ] = $por_num[ $n ];
			}
		}
		return $out;
	}

	/**
	 * Campos concretos de varias fichas del grupo, leídos **en vivo** y en una sola
	 * consulta (por cada 50). Es para datos que no están en la instantánea y que solo se
	 * necesitan en una pantalla concreta (p. ej. la formación y el certificado de delitos
	 * sexuales del equipo de un evento, v0.19). Nada se guarda.
	 *
	 * @param string[] $nums   Nº Scouts de España.
	 * @param string[] $campos Campos del módulo Contacts.
	 * @return array<string, array<string, string>> num_asde => campo => valor
	 */
	public function campos_varias( array $nums, array $campos ): array {
		$nums   = array_values( array_unique( array_filter( array_map( 'strval', $nums ), array( self::class, 'es_num_asde' ) ) ) );
		$campos = array_values( array_unique( array_filter( $campos, static fn( $c ) => (bool) preg_match( '/^[a-z0-9_]+$/', (string) $c ) ) ) );
		$out    = array();
		foreach ( array_chunk( $nums, 50 ) as $lote ) {
			$in      = implode( ',', array_map( array( Query::class, 'str' ), $lote ) );
			$entries = self::filtrar_grupo( $this->crm->list_entries( 'Contacts', "contacts_cstm.numerosocio_c IN ($in)", array_merge( array( 'id', 'numerosocio_c' ), $campos ), self::links_grupo(), 0, 60 )['entries'] );
			foreach ( $entries as $e ) {
				$num = (string) ( $e['fields']['numerosocio_c'] ?? '' );
				if ( in_array( $num, $lote, true ) ) {
					$out[ $num ] = array_map( 'strval', array_intersect_key( $e['fields'], array_flip( $campos ) ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Todas las fichas del grupo.
	 *
	 * @return Persona[]
	 */
	public function todas( bool $solo_activas = true ): array {
		$entradas = $this->entradas_grupo();
		if ( null !== $entradas ) {
			return $this->ordenar(
				array_map(
					array( Persona::class, 'from_crm' ),
					$solo_activas ? array_values( array_filter( $entradas, array( self::class, 'es_activa' ) ) ) : $entradas
				)
			);
		}
		return $this->personas( $this->crm->list_all( 'Contacts', $solo_activas ? self::WHERE_ACTIVAS : '', self::CAMPOS, self::links_grupo() ) );
	}

	/**
	 * Fichas en las que aparece alguno de estos emails (propio o de tutor):
	 * sirve para detectar hermanos/as y emails familiares compartidos.
	 *
	 * @param string[] $emails
	 * @return Persona[]
	 */
	public function por_emails( array $emails, int $limite = 30, bool $solo_activas = true ): array {
		$emails = $this->limpiar_emails( $emails );
		if ( ! $emails ) {
			return array();
		}
		$where = $this->where_emails( $emails ) . ( $solo_activas ? ' AND ' . self::WHERE_ACTIVAS : '' );
		return $this->personas( $this->crm->list_entries( 'Contacts', $where, self::CAMPOS, self::links_grupo(), 0, $limite )['entries'] );
	}

	/** Número de fichas del grupo (activas o no) en las que aparece este email (máx. 50). */
	public function contar_email( string $email ): int {
		return count( $this->por_emails( array( $email ), 50, false ) );
	}

	/** Documento sin espacios, puntos ni guiones y en mayúsculas (12345678-z → 12345678Z). */
	public static function normalizar_documento( string $documento ): string {
		return strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', $documento ) );
	}

	/**
	 * Fichas del grupo con membresía activa y este nº de documento (DNI, NIE…),
	 * escrito con o sin guiones, espacios o minúsculas. Solo para verificar
	 * solicitudes de acceso: el documento se compara en memoria y no se devuelve.
	 *
	 * @return Persona[]
	 */
	public function por_documento( string $documento ): array {
		$doc = self::normalizar_documento( $documento );
		if ( strlen( $doc ) < 5 || strlen( $doc ) > 20 ) {
			return array();
		}
		$digitos = (string) preg_replace( '/\D/', '', $doc );
		$where   = ( strlen( $digitos ) >= 5 ? 'contacts_cstm.stic_identification_number_c LIKE ' . Query::contains( $digitos ) : 'contacts_cstm.stic_identification_number_c = ' . Query::str( $doc ) )
			. ' AND ' . self::WHERE_ACTIVAS;
		$entradas = $this->crm->list_entries( 'Contacts', $where, array_merge( self::CAMPOS, array( 'stic_identification_number_c' ) ), self::links_grupo(), 0, 20 )['entries'];
		$iguales  = array_filter( $entradas, static fn( array $e ) => self::normalizar_documento( (string) ( $e['fields']['stic_identification_number_c'] ?? '' ) ) === $doc );
		return $this->personas( array_values( $iguales ) );
	}

	/**
	 * Fichas del grupo con membresía activa nacidas en esta fecha (AAAA-MM-DD).
	 * Para las solicitudes de acceso de menores sin documento.
	 *
	 * @return Persona[]
	 */
	public function por_nacimiento( string $fecha ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			return array();
		}
		return $this->personas( $this->crm->list_entries( 'Contacts', 'contacts.birthdate = ' . Query::str( $fecha ) . ' AND ' . self::WHERE_ACTIVAS, self::CAMPOS, self::links_grupo(), 0, 60 )['entries'] );
	}

	/**
	 * Entradas del CRM → Persona[], ordenadas por apellidos y nombre.
	 * (El CRM de ASDE ignora el parámetro order_by de get_entry_list.)
	 *
	 * @return Persona[]
	 */
	private function personas( array $entries ): array {
		return $this->ordenar( array_map( array( Persona::class, 'from_crm' ), self::filtrar_grupo( $entries ) ) );
	}

	/** @param Persona[] $personas @return Persona[] */
	private function ordenar( array $personas ): array {
		$clave = static fn( Persona $p ) => strtolower( remove_accents( $p->apellidos . ' ' . $p->nombre ) );
		usort( $personas, static fn( Persona $a, Persona $b ) => strcmp( $clave( $a ), $clave( $b ) ) );
		return $personas;
	}

	// ---------------------------------------------------------------------
	// Instantánea (v0.12.3)
	// ---------------------------------------------------------------------

	/** Membresía activa: «Secciones actuales» no vacío (lo mismo que WHERE_ACTIVAS, en memoria). */
	private static function es_activa( array $entrada ): bool {
		$v = trim( (string) ( $entrada['fields']['stic_relationship_type_c'] ?? '' ) );
		return '' !== $v && '^^' !== $v;
	}

	/**
	 * Todas las fichas del grupo (activas o no), de la instantánea: fresca, o antigua
	 * mientras se refresca en segundo plano, o leída ahora del CRM si no hay. null si
	 * está desactivada o el grupo no está verificado (entonces se lee del CRM).
	 *
	 * @return list<array{id:string, fields:array<string,string>}>|null
	 */
	private function entradas_grupo(): ?array {
		$grupo = Grupo::numero();
		if ( '' === $grupo || '' === (string) ( Grupo::actual()['crm_id'] ?? '' ) || 0 === Instantanea::segundos() ) {
			return null;
		}
		$snap   = Instantanea::leer( $grupo );
		$estado = Instantanea::estado( $snap );
		if ( 'fresca' === $estado ) {
			return $snap['entries'];
		}
		if ( 'gracia' === $estado ) {
			Instantanea::pedir_refresco( $grupo );
			return $snap['entries'];
		}
		// Caducada: una sola petición lee el CRM; las que llegan a la vez esperan su resultado.
		if ( Instantanea::tomar_cerrojo( $grupo ) ) {
			try {
				return $this->refrescar_instantanea();
			} finally {
				Instantanea::soltar_cerrojo( $grupo );
			}
		}
		$nueva = Instantanea::esperar_fresca( $grupo );
		return $nueva ? $nueva['entries'] : $this->refrescar_instantanea();
	}

	/**
	 * Vuelve a leer del CRM todas las fichas del grupo y las guarda (cifradas).
	 *
	 * @return list<array{id:string, fields:array<string,string>}>
	 */
	public function refrescar_instantanea(): array {
		$entradas = array();
		foreach ( self::filtrar_grupo( $this->crm->list_all( 'Contacts', '', self::CAMPOS, self::links_grupo() ) ) as $e ) {
			$entradas[] = array(
				'id'     => (string) $e['id'],
				'fields' => array_map( 'strval', $e['fields'] ),
			);
		}
		if ( Instantanea::segundos() > 0 ) {
			Instantanea::guardar( Grupo::numero(), $entradas );
		}
		return $entradas;
	}

	/** @return string[] */
	private function limpiar_emails( array $emails ): array {
		return array_values( array_unique( array_filter( array_map( static fn( $e ) => strtolower( trim( (string) $e ) ), $emails ), 'is_email' ) ) );
	}

	/**
	 * Email principal/secundario (tablas de email de SuiteCRM, únicas en las que el
	 * CRM permite subconsultas) o emails de tutor 1 y 2 (campos propios de ASDE).
	 *
	 * @param string[] $emails Ya validados y en minúsculas.
	 */
	private function where_emails( array $emails ): string {
		$mayus = implode( ',', array_map( static fn( $e ) => Query::str( strtoupper( $e ) ), $emails ) );
		$minus = implode( ',', array_map( array( Query::class, 'str' ), $emails ) );
		return '(contacts.id IN (SELECT eabr.bean_id FROM email_addr_bean_rel eabr INNER JOIN email_addresses ea ON ea.id = eabr.email_address_id'
			. " WHERE eabr.deleted = 0 AND ea.deleted = 0 AND eabr.bean_module = 'Contacts' AND ea.email_address_caps IN ($mayus))"
			. " OR contacts_cstm.tutor1_email_c IN ($minus) OR contacts_cstm.tutor2_email_c IN ($minus))";
	}
}
