<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\ConnectionResult;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Grupos Scouts dados de alta en la web (a mano, por un administrador).
 *
 * De cada grupo se guarda: nº (3 cifras, clave de todo lo del grupo), nombre y
 * el usuario y la contraseña (cifrada) de SU acceso al CRM. La URL del CRM es
 * común (Crm\Settings). Opción `asde_ap_grupos`, sin autoload.
 *
 * Al probar la conexión se busca en el CRM el grupo de seguridad del grupo
 * (SecurityGroups con tipoasociacion_c = GS y el mismo nº). Sin él, el grupo no
 * lee datos del CRM, y con él cada consulta se filtra por ese grupo de
 * seguridad: nunca hay visibilidad entre grupos, aunque unas credenciales
 * vieran varios.
 */
final class Grupos {

	private const OPTION = 'asde_ap_grupos';

	public const OK         = 'ok';
	public const ERROR      = 'error';
	public const SIN_PROBAR = 'sin_probar';

	private static ?array $cache = null;

	/** @return array<string, array{id:string, numero:string, nombre:string, usuario:string, crm_id:string, filtrar:bool, estado:string, mensaje:string, detalles:string[], avisos:string[], probado:int}> nº => grupo (sin la contraseña) */
	public static function todos(): array {
		if ( null === self::$cache ) {
			self::$cache = array();
			foreach ( self::raw() as $numero => $g ) {
				unset( $g['clave_enc'] );
				self::$cache[ (string) $numero ] = $g;
			}
		}
		return self::$cache;
	}

	/** Por nº de grupo (o por el id del grupo de seguridad del CRM, que usaba la v0.5). */
	public static function obtener( string $id ): ?array {
		$todos = self::todos();
		if ( isset( $todos[ $id ] ) ) {
			return $todos[ $id ];
		}
		foreach ( $todos as $g ) {
			if ( '' !== $g['crm_id'] && $g['crm_id'] === $id ) {
				return $g;
			}
		}
		return null;
	}

	public static function por_numero( string $numero ): ?array {
		return self::todos()[ $numero ] ?? null;
	}

	/**
	 * Grupo "padre" de un usuario: el guardado al darle de alta o, si no, el que
	 * indica su nº de socio local (desde la v0.56, provincia con su 0 + nº de grupo
	 * + 4 dígitos, o la forma de antes, sin el 0; con T en las familias).
	 */
	public static function de_usuario( \WP_User $user ): ?array {
		$id = (string) get_user_meta( $user->ID, Usuarios::META_GRUPO, true );
		if ( '' !== $id && ( $g = self::obtener( $id ) ) ) {
			return $g;
		}
		$grupo = NumeroSocio::grupo_de_login( $user->user_login, array_map( 'strval', array_keys( self::todos() ) ) );
		return null !== $grupo ? self::por_numero( $grupo ) : null;
	}

	/** Nombre para mostrar, con el nº de grupo si el nombre no lo lleva. */
	public static function etiqueta( array $grupo ): string {
		return str_contains( $grupo['nombre'], $grupo['numero'] ) ? $grupo['nombre'] : sprintf( '%s (%s)', $grupo['nombre'], $grupo['numero'] );
	}

	/** Vacía la caché de la petición. */
	public static function olvidar(): void {
		self::$cache = null;
		ClientFactory::olvidar();
		NumeroSocio::olvidar();
	}

	/** @return array{usuario:string, clave:?string}|null */
	public static function credenciales( string $numero ): ?array {
		$g = self::raw()[ $numero ] ?? null;
		if ( ! $g ) {
			return null;
		}
		return array(
			'usuario' => (string) $g['usuario'],
			'clave'   => '' === $g['clave_enc'] ? null : Crypto::decrypt( $g['clave_enc'] ),
		);
	}

	public static function tiene_clave( string $numero ): bool {
		return '' !== ( self::raw()[ $numero ]['clave_enc'] ?? '' );
	}

	/** "684", "58" → "684", "058". Null si no es un nº de grupo válido. */
	public static function normalizar_numero( string $valor ): ?string {
		$v = preg_replace( '/\D/', '', $valor );
		return ( '' !== $v && strlen( $v ) <= 3 && (int) $v > 0 ) ? str_pad( $v, 3, '0', STR_PAD_LEFT ) : null;
	}

	// ---------------------------------------------------------------------
	// Administración (Ajustes → Grupos Scouts)
	// ---------------------------------------------------------------------

	/**
	 * Alta o edición. La contraseña vacía (null) conserva la guardada. Si cambian
	 * las credenciales, el grupo hay que volver a verificarlo.
	 *
	 * @return array|\WP_Error El grupo guardado.
	 */
	public static function guardar( string $numero, string $nombre, string $usuario, ?string $clave, bool $es_alta ) {
		$num = self::normalizar_numero( $numero );
		if ( null === $num ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'El nº de grupo debe tener hasta 3 cifras (p. ej. 684).' ) );
		}
		$nombre  = trim( sanitize_text_field( $nombre ) );
		$usuario = trim( sanitize_text_field( $usuario ) );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'Escribe el nombre del grupo.' ) );
		}
		$raw = self::raw();
		if ( $es_alta && isset( $raw[ $num ] ) ) {
			return new \WP_Error( 'asde_grupo', sprintf( asde_t( 'El grupo %s ya está dado de alta.' ), $num ) );
		}
		if ( ! $es_alta && ! isset( $raw[ $num ] ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'Ese grupo no existe.' ) );
		}
		$g = $raw[ $num ] ?? self::vacio( $num );

		$cambia = $g['usuario'] !== $usuario || ( null !== $clave && '' !== $clave );
		$g['nombre']  = $nombre;
		$g['usuario'] = $usuario;
		if ( null !== $clave && '' !== $clave ) {
			$g['clave_enc'] = Crypto::encrypt( $clave );
		}
		if ( $cambia ) {
			$g['crm_id']  = '';
			$g['filtrar'] = false;
			$g['estado']  = self::SIN_PROBAR;
			$g['mensaje'] = asde_t( 'Credenciales nuevas: falta probar la conexión.' );
		}
		$raw[ $num ] = $g;
		self::guardar_raw( $raw );
		return self::por_numero( $num );
	}

	/**
	 * Email de contacto del grupo: el que se enseña en «Solicitar acceso» cuando
	 * los datos no se pueden comprobar (p. ej. grupo@example.invalid).
	 */
	public static function fijar_email_contacto( string $numero, string $email ): bool {
		$raw = self::raw();
		if ( ! isset( $raw[ $numero ] ) ) {
			return false;
		}
		$email = strtolower( trim( $email ) );
		$raw[ $numero ]['email_contacto'] = is_email( $email ) ? $email : '';
		self::guardar_raw( $raw );
		return true;
	}

	public static function email_contacto( ?array $grupo ): string {
		$email = (string) ( $grupo['email_contacto'] ?? '' );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Provincia del grupo (dos cifras, p. ej. «07»): los dos primeros dígitos de su nº de socio/a local.
	 * Si el grupo aún no la tiene guardada, sale del código de organización de antes de la v0.56
	 * (en Scouts de Balears, 7 → 07) para que nada cambie. '' si no se sabe.
	 */
	public static function provincia( string $numero ): string {
		$g = self::por_numero( $numero );
		$p = Provincias::normalizar( (string) ( $g['provincia'] ?? '' ) );
		return $p ?? ( Provincias::normalizar( Organizacion::codigo_antiguo() ) ?? '' );
	}

	/**
	 * Guarda los datos del grupo (v0.56, «Datos del grupo»): nombre, denominación fiscal, CIF,
	 * dirección, código postal, población, provincia y email de contacto. No toca las credenciales
	 * del CRM ni el nº de grupo.
	 *
	 * @param array<string, string> $d
	 * @return array|\WP_Error El grupo guardado.
	 */
	public static function guardar_datos( string $numero, array $d ) {
		$raw = self::raw();
		if ( ! isset( $raw[ $numero ] ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'Ese grupo no existe.' ) );
		}
		$texto  = static fn( $v, int $max ) => mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max );
		$nombre = $texto( $d['nombre'] ?? '', 120 );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'Escribe el nombre del grupo.' ) );
		}
		$cif = strtoupper( (string) preg_replace( '/[\s.-]/', '', (string) ( $d['cif'] ?? '' ) ) );
		if ( '' !== $cif && ! preg_match( '/^[A-Z0-9]{9}$/', $cif ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'El CIF tiene que tener 9 caracteres (letras y números), p. ej. G07123456.' ) );
		}
		$cp = trim( (string) ( $d['cp'] ?? '' ) );
		if ( '' !== $cp && ! preg_match( '/^\d{5}$/', $cp ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'El código postal tiene que tener 5 cifras.' ) );
		}
		$provincia = trim( (string) ( $d['provincia'] ?? '' ) );
		$provincia = '' === $provincia ? '' : ( Provincias::normalizar( $provincia ) ?? '' );
		if ( '' === $provincia && '' !== trim( (string) ( $d['provincia'] ?? '' ) ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'Elige la provincia de la lista.' ) );
		}
		$email = strtolower( trim( (string) ( $d['email_contacto'] ?? '' ) ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'El email de contacto no es válido.' ) );
		}
		$g                   = $raw[ $numero ];
		$g['nombre']         = $nombre;
		$g['denominacion']   = $texto( $d['denominacion'] ?? '', 120 );
		$g['cif']            = $cif;
		$g['sede']           = $texto( $d['sede'] ?? '', 160 );
		$g['cp']             = $cp;
		$g['poblacion']      = $texto( $d['poblacion'] ?? '', 80 );
		$g['provincia']      = $provincia;
		$g['email_contacto'] = $email;
		$raw[ $numero ]      = $g;
		self::guardar_raw( $raw );
		return self::por_numero( $numero );
	}

	/** Dirección completa del grupo en una línea (calle, código postal y población, provincia), lo que haya. */
	/**
	 * Dirección en una línea con los datos guardados en el grupo (sin respaldos: el membrete enseña solo lo que el grupo
	 * escribió). DatosGrupo::direccion() la usa con los respaldos.
	 */
	public static function direccion( ?array $grupo ): string {
		if ( ! $grupo ) {
			return '';
		}
		$cp_pob = trim( (string) ( $grupo['cp'] ?? '' ) . ' ' . (string) ( $grupo['poblacion'] ?? '' ) );
		$prov   = Provincias::nombre( Provincias::normalizar( (string) ( $grupo['provincia'] ?? '' ) ) ?? '' );
		return implode( ', ', array_filter( array( (string) ( $grupo['sede'] ?? '' ), $cp_pob, $prov ) ) );
	}

	/**
	 * Crea el grupo de una solicitud aprobada (v0.50, doc 50 fase 6) con la credencial **ya cifrada** (no pasa en claro por
	 * ningún formulario) y la comprueba otra vez. Si en este momento ya no funciona, el grupo no se crea.
	 *
	 * @param array $s Fila de `GrupoSolicitudes` (con los secretos cifrados).
	 * @return array|\WP_Error El grupo creado.
	 */
	public static function alta_desde_solicitud( array $s, string $usuario ) {
		$num = self::normalizar_numero( (string) $s['numero'] );
		if ( null === $num ) {
			return new \WP_Error( 'asde_grupo', asde_t( 'El nº de grupo debe tener hasta 3 cifras (p. ej. 684).' ) );
		}
		$raw = self::raw();
		if ( isset( $raw[ $num ] ) ) {
			return new \WP_Error( 'asde_grupo', sprintf( asde_t( 'El grupo %s ya está dado de alta.' ), $num ) );
		}
		$g                   = self::vacio( $num );
		$g['nombre']         = mb_substr( trim( sanitize_text_field( (string) $s['nombre'] ) ), 0, 120 );
		$g['usuario']        = $usuario;
		$g['clave_enc']      = (string) $s['clave_enc'];
		$g['sede']           = sanitize_text_field( (string) $s['sede'] );
		$g['cif']            = sanitize_text_field( (string) $s['cif'] );
		$g['denominacion']   = sanitize_text_field( (string) $s['denominacion'] );
		$g['email_contacto'] = is_email( (string) $s['email'] ) ? strtolower( (string) $s['email'] ) : '';
		$raw[ $num ]         = $g;
		self::guardar_raw( $raw );
		$res = self::verificar( $num );
		if ( ! $res->ok ) {
			self::borrar( $num );
			return new \WP_Error( 'asde_grupo', $res->message );
		}
		return self::por_numero( $num );
	}

	/** Línea de membrete del grupo para documentos: denominación fiscal (o nombre), CIF y sede, lo que haya. */
	public static function membrete( ?array $grupo ): string {
		if ( ! $grupo ) {
			return '';
		}
		$partes = array_filter(
			array(
				'' !== (string) ( $grupo['denominacion'] ?? '' ) ? (string) $grupo['denominacion'] : '',
				'' !== (string) ( $grupo['cif'] ?? '' ) ? 'CIF ' . $grupo['cif'] : '',
				self::direccion( $grupo ),
			)
		);
		return implode( ' · ', $partes );
	}

	/** Baja del grupo: se borra su configuración; sus usuarios y datos se conservan (y vuelven si se da de alta otra vez). */
	public static function borrar( string $numero ): bool {
		$raw = self::raw();
		if ( ! isset( $raw[ $numero ] ) ) {
			return false;
		}
		unset( $raw[ $numero ] );
		self::guardar_raw( $raw );
		return true;
	}

	/**
	 * Prueba las credenciales del grupo y busca su grupo de seguridad en el CRM.
	 * Guarda el resultado en el grupo (estado, mensaje, avisos).
	 */
	public static function verificar( string $numero ): ConnectionResult {
		$raw = self::raw();
		if ( ! isset( $raw[ $numero ] ) ) {
			return ConnectionResult::fail( asde_t( 'Ese grupo no existe.' ) );
		}
		$g      = $raw[ $numero ];
		$client = ClientFactory::para_grupo( $numero );
		if ( ! $client ) {
			$res = ConnectionResult::fail( '' === Settings::url() ? asde_t( 'Falta la URL del CRM.' ) : asde_t( 'Faltan el usuario o la contraseña del CRM de este grupo (o no se puede descifrar la contraseña guardada).' ) );
		} else {
			$res = $client->ping();
			if ( $res->ok ) {
				try {
					$filas = $client->list_entries( 'SecurityGroups', '', array( 'id', 'name', 'numgrupo_c', 'tipoasociacion_c' ), array(), 0, 200 )['entries'];
					$gs    = array_values( array_filter( $filas, static fn( $f ) => 'GS' === ( $f['fields']['tipoasociacion_c'] ?? '' ) ) );
					$suyo  = null;
					foreach ( $gs as $f ) {
						if ( (int) preg_replace( '/\D/', '', (string) ( $f['fields']['numgrupo_c'] ?? '' ) ) === (int) $numero ) {
							$suyo = $f;
						}
					}
					if ( $suyo ) {
						$g['crm_id']    = (string) $suyo['id'];
						$g['filtrar']   = count( $gs ) > 1;
						$res->message   = sprintf( asde_t( 'Conexión correcta. En el CRM, estas credenciales ven el grupo «%s».' ), (string) $suyo['fields']['name'] );
						$res->details[] = sprintf( asde_t( 'Grupos Scouts visibles con estas credenciales: %d' ), count( $gs ) );
						if ( count( $gs ) > 1 ) {
							$res->warnings[] = sprintf( asde_t( 'Estas credenciales ven %d Grupos Scouts. La web filtra siempre por el grupo %s, pero lo ideal es un usuario del CRM que vea solo su grupo.' ), count( $gs ), $numero );
						}
					} else {
						$g['crm_id']  = '';
						$g['filtrar'] = false;
						$visibles     = array_map( static fn( $f ) => (string) $f['fields']['name'], $gs );
						$res          = ConnectionResult::fail(
							sprintf( asde_t( 'Las credenciales funcionan, pero no ven el Grupo Scout %s en el CRM. Revisa el nº de grupo o pide a ASDE permisos sobre ese grupo.' ), $numero ),
							array_merge( $res->details, array( asde_t( 'Grupos Scouts visibles: ' ) . ( $visibles ? implode( ', ', $visibles ) : 'ninguno' ) ) )
						);
					}
				} catch ( CrmException $e ) {
					$res = ConnectionResult::fail( asde_t( 'Conexión correcta, pero no se han podido leer los grupos del CRM: ' ) . $e->getMessage(), $res->details );
				}
			}
		}

		\AsdeBalears\AreaPrivada\Crm\Permisos::olvidar( $numero ); // Se vuelven a leer con la próxima consulta.
		$g['estado']   = $res->ok ? self::OK : self::ERROR;
		$g['mensaje']  = $res->message;
		$g['detalles'] = array_values( array_map( 'strval', $res->details ) );
		$g['avisos']   = array_values( array_map( 'strval', $res->warnings ) );
		$g['probado']  = time();
		$raw[ $numero ] = $g;
		self::guardar_raw( $raw );
		return $res;
	}

	/** Tarea diaria. */
	public static function verificar_todos(): void {
		foreach ( array_keys( self::raw() ) as $numero ) {
			self::verificar( (string) $numero );
		}
	}

	/**
	 * v0.5 → v0.6: los grupos detectados en el CRM (con clave = id del grupo de
	 * seguridad) pasan a tener clave = nº, y el usuario y la contraseña globales
	 * pasan al grupo. Los metas de usuario con el id antiguo se cambian por el nº.
	 */
	public static function migrar(): void {
		$raw = get_option( self::OPTION );
		$crm = get_option( Settings::OPTION );
		$crm = is_array( $crm ) ? $crm : array();

		if ( is_array( $raw ) && $raw && ! isset( reset( $raw )['usuario'] ) ) {
			$nuevo = array();
			$mapa  = array();
			foreach ( $raw as $uuid => $g ) {
				$num = self::normalizar_numero( (string) ( $g['numero'] ?? '' ) );
				if ( null === $num ) {
					continue;
				}
				$nuevo[ $num ]           = self::vacio( $num );
				$nuevo[ $num ]['nombre']    = (string) ( $g['nombre'] ?? $num );
				$nuevo[ $num ]['usuario']   = (string) ( $crm['username'] ?? '' );
				$nuevo[ $num ]['clave_enc'] = (string) ( $crm['password_enc'] ?? '' );
				$nuevo[ $num ]['crm_id']    = (string) $uuid;
				$nuevo[ $num ]['filtrar']   = count( $raw ) > 1;
				$nuevo[ $num ]['estado']    = '' !== $nuevo[ $num ]['clave_enc'] ? self::OK : self::SIN_PROBAR;
				$nuevo[ $num ]['mensaje']   = 'Configuración pasada desde la versión anterior (usuario del CRM común).';
				$mapa[ (string) $uuid ]     = $num;
			}
			self::guardar_raw( $nuevo );
			foreach ( array( Usuarios::META_GRUPO, Grupo::META_CONTEXTO ) as $meta ) {
				foreach ( $mapa as $uuid => $num ) {
					$ids = get_users(
						array(
							'meta_key'   => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
							'meta_value' => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery
							'fields'     => 'ID',
						)
					);
					foreach ( $ids as $uid ) {
						update_user_meta( (int) $uid, $meta, $num );
					}
				}
			}
		}

		// En la opción del CRM queda solo la URL (común).
		if ( array_diff( array_keys( $crm ), array( 'url' ) ) ) {
			update_option( Settings::OPTION, array( 'url' => Settings::normalize_url( (string) ( $crm['url'] ?? '' ) ) ), false );
		}
		delete_transient( 'asde_ap_grupos_reintento' );
		self::olvidar();
	}

	// ---------------------------------------------------------------------

	/** @return array<string, array> Con la contraseña cifrada. */
	private static function raw(): array {
		$g = get_option( self::OPTION );
		if ( ! is_array( $g ) || ( $g && ! isset( reset( $g )['usuario'] ) ) ) {
			return array(); // Vacío o formato antiguo (se convierte en Installer → migrar()).
		}
		$out = array();
		foreach ( $g as $numero => $datos ) {
			$numero         = (string) $numero;
			$out[ $numero ] = array_merge( self::vacio( $numero ), (array) $datos, array( 'id' => $numero, 'numero' => $numero ) );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function guardar_raw( array $raw ): void {
		ksort( $raw, SORT_STRING );
		update_option( self::OPTION, $raw, false );
		self::olvidar();
	}

	private static function vacio( string $numero ): array {
		return array(
			'id'        => $numero,
			'numero'    => $numero,
			'nombre'    => $numero,
			'usuario'   => '',
			'clave_enc' => '',
			'crm_id'    => '',
			'filtrar'   => false,
			'estado'    => self::SIN_PROBAR,
			'mensaje'   => '',
			'detalles'  => array(),
			'avisos'    => array(),
			'probado'   => 0,
			'email_contacto' => '',
			// v0.50 (doc 50, fase 6): datos que da el grupo al darse de alta por invitación (vacíos = como siempre).
			'sede'         => '',
			'cif'          => '',
			'denominacion' => '',
			// v0.56 (doc 58): «Datos del grupo». La provincia (dos cifras) es el principio de su nº de socio/a local.
			'cp'           => '',
			'poblacion'    => '',
			'provincia'    => '',
		);
	}
}
