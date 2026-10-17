<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los eventos de cada grupo (opción asde_ap_eventos_<grupo>, doc 43):
 *
 * - **tipos**: catálogo de tipos de actividad. Un tipo es una plantilla de valores por
 *   defecto (si tiene coste, si se comunica a ASDE Baleares); en cada evento se cambia.
 * - **margen** del fondo de emergencia: activo y porcentaje (5 % por defecto, P-145).
 * - **titulaciones**: qué valores del campo «formación de tiempo libre» del CRM cuentan
 *   como título de director/a o de monitor/a. Vacío = se deduce del texto del valor.
 * - **autorización** (v0.35): el texto que firma la familia al inscribir (vacío = el del
 *   plugin) y su versión, que sube cada vez que el grupo lo cambia: cada firma guarda la
 *   versión que se firmó (como la autorización SEPA).
 * - **texto_pago**: cómo pagar un evento (cuenta, Bizum…), para las familias.
 * - **recordatorio_dias**: cuántos días antes se manda el recordatorio (0 = no se manda).
 * - **avisos** (v0.39): qué avisos se mandan (publicado, confirmada, plaza, recordatorio,
 *   cancelado); cada federación o grupo decide cuáles.
 * - **push**: si además de los emails se dispara el aviso para la app móvil.
 * - **dias_oferta**: días que tiene quien está en la lista de espera para confirmar una plaza.
 * - **recordatorio_extra** (v0.39): segundo recordatorio, estos días antes (0 = no; menor que
 *   recordatorio_dias).
 * - **edad_cuenta_propia**: edad desde la que una persona recibe los avisos en su propia cuenta
 *   y se puede inscribir sola (14 por defecto).
 * - **textos**: por aviso, un párrafo propio al principio y otro al final del email (vacío = ninguno).
 */
final class Config {

	private const OPTION = 'asde_ap_eventos_';

	public const MAX_TIPOS = 20;

	public const MAX_AUTORIZACION = 3000;

	/** Avisos que se pueden activar o desactivar (los mismos que Avisos::TIPOS). */
	public const AVISOS = array( 'publicado', 'confirmada', 'plaza', 'recordatorio', 'cancelado' );

	/** Días por defecto para confirmar una plaza ofrecida, y su máximo. */
	public const DIAS_OFERTA     = 3;
	public const MAX_DIAS_OFERTA = 30;

	/** Edad por defecto de la cuenta propia y su rango; máximo de cada texto propio de los avisos. */
	public const EDAD_CUENTA_PROPIA = 14;
	public const EDAD_MIN           = 10;
	public const EDAD_MAX           = 18;
	public const MAX_TEXTO_AVISO    = 600;

	/**
	 * Texto de la autorización del plugin (art. 21.3.g de la Ley 10/2022: autorización de
	 * participación de quien tiene la tutela). Va sin huecos: la pantalla pone encima a quién
	 * y a qué evento se autoriza.
	 */
	public const AUTORIZACION = 'Como madre, padre o tutor/a legal, autorizo su participación en esta actividad del grupo scout, organizada bajo la responsabilidad de su equipo de scouters, y declaro que he leído la información de la actividad. Autorizo también que, en caso de urgencia y si no se me puede localizar, el equipo tome las decisiones médicas, quirúrgicas o de traslado que indique el personal sanitario.';

	/**
	 * Los ocho tipos de actividad del plan (doc 43, apartado 1). Las reuniones de sección y
	 * las de adultos no son eventos (P-140).
	 *
	 * - coste: el evento nace con presupuesto (lo valida Tesorería antes de publicar);
	 * - comunicacion: 'auto' = se pide la comunicación a ASDE Baleares si hay 2 o más noches
	 *   y 10 o más menores (P-154 bis); 'no' = nunca (lo organiza otro, o son adultos);
	 * - crm: `type` de stic_Events para la copia al CRM (última fase).
	 */
	public const TIPOS = array(
		'salida'     => array(
			'nombre'       => 'Salida o excursión',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'acampada'   => array(
			'nombre'       => 'Acampada',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'campamento' => array(
			'nombre'       => 'Campamento',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'ruta'       => array(
			'nombre'       => 'Ruta o travesía',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'grupo'      => array(
			'nombre'       => 'Actividad de grupo',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'servicio'   => array(
			'nombre'       => 'Servicio o voluntariado',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'externo'    => array(
			'nombre'       => 'Evento externo',
			'coste'        => true,
			'comunicacion' => 'no',
			'crm'          => 'festivalof',
		),
		'formacion'  => array(
			'nombre'       => 'Formación',
			'coste'        => false,
			'comunicacion' => 'no',
			'crm'          => 'Curso',
		),
	);

	/** @return array{tipos:array, margen_activo:bool, margen_pct:float, titulaciones:array{director:string[], monitor:string[]}} */
	public static function defaults(): array {
		$tipos = array();
		foreach ( self::TIPOS as $id => $t ) {
			$tipos[ $id ] = $t + array( 'activo' => true );
		}
		return array(
			'tipos'         => $tipos,
			'margen_activo' => true,
			'margen_pct'    => 5.0,
			'titulaciones'  => array(
				'director' => array(),
				'monitor'  => array(),
			),
			'autorizacion_texto'   => '',
			'autorizacion_version' => 1,
			'texto_pago'           => '',
			'recordatorio_dias'    => 3,
			'avisos'               => array_fill_keys( self::AVISOS, true ),
			'push'                 => true,
			'dias_oferta'          => self::DIAS_OFERTA,
			'recordatorio_extra'   => 0,
			'edad_cuenta_propia'   => self::EDAD_CUENTA_PROPIA,
			'textos'               => array(),
			// v0.40 (doc 49): el calendario de tareas — las fechas de fin de semana se adelantan al viernes (P-185) y las plantillas del grupo.
			'calendario_viernes'   => true,
			'plantillas'           => array(),
		);
	}

	/** Configuración del grupo (lo guardado sobre los valores por defecto). */
	public static function de( string $grupo = '' ): array {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		$c     = (array) get_option( self::OPTION . $grupo, array() );
		$d     = self::defaults();
		$cfg   = array_merge( $d, array_intersect_key( $c, $d ) );

		$tipos = array();
		foreach ( (array) $cfg['tipos'] as $id => $t ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || '' === trim( (string) ( $t['nombre'] ?? '' ) ) ) {
				continue;
			}
			$tipos[ $id ] = array(
				'nombre'       => mb_substr( (string) $t['nombre'], 0, 60 ),
				'coste'        => ! empty( $t['coste'] ),
				'comunicacion' => 'no' === ( $t['comunicacion'] ?? '' ) ? 'no' : 'auto',
				'crm'          => mb_substr( (string) ( $t['crm'] ?? ( self::TIPOS[ $id ]['crm'] ?? 'jornada' ) ), 0, 30 ),
				'activo'       => ! isset( $t['activo'] ) || (bool) $t['activo'],
			);
		}
		$cfg['tipos']         = $tipos ?: $d['tipos'];
		$cfg['margen_activo'] = (bool) $cfg['margen_activo'];
		$cfg['margen_pct']    = min( 50.0, max( 0.0, round( (float) $cfg['margen_pct'], 2 ) ) );
		$tit                  = (array) $cfg['titulaciones'];
		$cfg['titulaciones']  = array(
			'director' => array_values( array_filter( array_map( 'strval', (array) ( $tit['director'] ?? array() ) ) ) ),
			'monitor'  => array_values( array_filter( array_map( 'strval', (array) ( $tit['monitor'] ?? array() ) ) ) ),
		);
		$cfg['autorizacion_texto']   = mb_substr( (string) $cfg['autorizacion_texto'], 0, self::MAX_AUTORIZACION );
		$cfg['autorizacion_version'] = max( 1, (int) $cfg['autorizacion_version'] );
		$cfg['texto_pago']           = mb_substr( (string) $cfg['texto_pago'], 0, 1000 );
		$cfg['recordatorio_dias']    = min( 30, max( 0, (int) $cfg['recordatorio_dias'] ) );
		$avisos                      = (array) $cfg['avisos'];
		$cfg['avisos']               = array();
		foreach ( self::AVISOS as $a ) {
			$cfg['avisos'][ $a ] = ! isset( $avisos[ $a ] ) || (bool) $avisos[ $a ];
		}
		$cfg['push']        = (bool) $cfg['push'];
		$cfg['dias_oferta'] = min( self::MAX_DIAS_OFERTA, max( 1, (int) $cfg['dias_oferta'] ) );

		$extra                      = max( 0, (int) $cfg['recordatorio_extra'] );
		$cfg['recordatorio_extra']  = $extra < $cfg['recordatorio_dias'] ? $extra : 0; // El segundo va después del primero.
		$cfg['edad_cuenta_propia']  = min( self::EDAD_MAX, max( self::EDAD_MIN, (int) $cfg['edad_cuenta_propia'] ) );
		$cfg['calendario_viernes']  = (bool) $cfg['calendario_viernes'];
		$cfg['plantillas']          = Plantillas::limpiar( $cfg['plantillas'] );
		$textos                     = (array) $cfg['textos'];
		$cfg['textos']              = array();
		foreach ( self::AVISOS as $a ) {
			foreach ( array( 'intro', 'pie' ) as $k ) {
				$cfg['textos'][ $a ][ $k ] = mb_substr( trim( (string) ( $textos[ $a ][ $k ] ?? '' ) ), 0, self::MAX_TEXTO_AVISO );
			}
		}
		return $cfg;
	}

	/** ¿Está activado este aviso en el grupo? (por defecto, sí). */
	public static function aviso_activo( string $tipo, string $grupo = '' ): bool {
		return ! empty( self::de( $grupo )['avisos'][ $tipo ] );
	}

	/** Párrafo propio del grupo para un aviso: intro | pie ('' si no lo hay). */
	public static function texto_aviso( string $tipo, string $cual, string $grupo = '' ): string {
		return (string) ( self::de( $grupo )['textos'][ $tipo ][ $cual ] ?? '' );
	}

	/** El texto de la autorización que se enseña y se firma: el del grupo o, si no tiene, el del plugin (traducido). */
	public static function autorizacion( array $cfg ): string {
		return '' !== trim( $cfg['autorizacion_texto'] ) ? $cfg['autorizacion_texto'] : asde_t( self::AUTORIZACION );
	}

	/** Versión que se guarda con cada firma: «v3» (o «v3-propio» si el grupo ha escrito el suyo). */
	public static function version_autorizacion( array $cfg ): string {
		return 'v' . $cfg['autorizacion_version'] . ( '' !== trim( $cfg['autorizacion_texto'] ) ? '-propio' : '' );
	}

	public static function guardar( array $cfg, string $grupo = '' ): void {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		// Si cambia el texto de la autorización, cambia su versión: las firmas anteriores siguen diciendo qué texto firmaron.
		$antes = self::de( $grupo );
		if ( array_key_exists( 'autorizacion_texto', $cfg ) && trim( (string) $cfg['autorizacion_texto'] ) !== trim( $antes['autorizacion_texto'] ) ) {
			$cfg['autorizacion_version'] = $antes['autorizacion_version'] + 1;
		}
		update_option( self::OPTION . $grupo, array_intersect_key( $cfg, self::defaults() ), false );
	}

	public static function borrar( string $grupo ): void {
		delete_option( self::OPTION . $grupo );
	}

	/** @return array<string, array> Tipos con los que se puede crear un evento. */
	public static function tipos_activos( array $cfg ): array {
		return array_filter( $cfg['tipos'], static fn( array $t ) => $t['activo'] );
	}

	/** Nombre de un tipo, también de uno que ya no está en la configuración. */
	public static function nombre_tipo( array $cfg, string $tipo ): string {
		return isset( $cfg['tipos'][ $tipo ] ) ? asde_t( $cfg['tipos'][ $tipo ]['nombre'] ) : $tipo;
	}

	/** Identificador para un tipo nuevo a partir de su nombre. */
	public static function id_para( string $nombre, array $existentes ): string {
		$base = substr( sanitize_key( remove_accents( str_replace( ' ', '-', strtolower( $nombre ) ) ) ), 0, 30 ) ?: 'tipo';
		$id   = $base;
		for ( $n = 2; isset( $existentes[ $id ] ); $n++ ) {
			$id = $base . '-' . $n;
		}
		return $id;
	}
}
