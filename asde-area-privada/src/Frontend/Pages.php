<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Páginas del área privada: páginas normales de WordPress con un shortcode,
 * para que hereden cabecera, menú, migas y estilos del tema activo (están pensadas
 * para Enfold, pero valen con cualquiera: ver Tema).
 * Cuelgan de /nuestro-grupo-scout/ si existe.
 */
final class Pages {

	private const OPTION = 'asde_ap_pages';

	/** clave => [slug, título, contenido, clave del padre] */
	public const DEFINICIONES = array(
		'area'      => array( 'area-privada', 'Área privada', '[asde_area_privada]', null ),
		'alta'      => array( 'alta-usuarios', 'Alta de usuarios', '[asde_alta_usuarios]', 'area' ),
		'usuarios'  => array( 'usuarios', 'Usuarios del área privada', '[asde_usuarios]', 'area' ),
		'emails'    => array( 'emails', 'Registro de emails', '[asde_emails]', 'usuarios' ),
		'personas'  => array( 'personas', 'Personas del grupo', '[asde_personas]', 'area' ),
		'ficha'     => array( 'ficha', 'Ficha', '[asde_ficha]', 'area' ),
		'cambios'   => array( 'cambios-pendientes', 'Cambios pendientes', '[asde_cambios]', 'area' ),
		'informes'  => array( 'informes', 'Informes', '[asde_informes]', 'area' ),
		'tesoreria' => array( 'tesoreria', 'Tesorería', '[asde_tesoreria]', 'area' ),
		'familias'  => array( 'familias', 'Familias', '[asde_familias]', 'tesoreria' ),
		'cuotas'    => array( 'cuotas', 'Cuotas y plazos', '[asde_cuotas]', 'tesoreria' ),
		'federacion' => array( 'coste-federativo', 'Coste federativo', '[asde_coste_federativo]', 'tesoreria' ),
		'banco'     => array( 'banco', 'Banco', '[asde_banco]', 'tesoreria' ),
		// v0.12: domiciliaciones SEPA (ficheros de adeudos para el banco).
		'sepa'      => array( 'domiciliaciones', 'Domiciliaciones SEPA', '[asde_sepa]', 'tesoreria' ),
		// El shortcode de Wordfence va dentro del nuestro para que Wordfence cargue sus scripts.
		'seguridad' => array( 'seguridad', 'Seguridad de la cuenta', '[asde_seguridad][wordfence_2fa_management][/asde_seguridad]', 'area' ),
		'acceso'    => array( 'acceso', 'Acceso socios', '[asde_acceso]', 'area' ),
		'activar'   => array( 'activar-cuenta', 'Activar cuenta', '[asde_activar_cuenta]', 'area' ),
		'recuperar' => array( 'recuperar-contrasena', 'Recuperar contraseña', '[asde_recuperar]', 'area' ),
		'solicitar' => array( 'solicitar-acceso', 'Solicitar acceso', '[asde_solicitar]', 'area' ),
		'solicitudes' => array( 'solicitudes', 'Solicitudes de acceso', '[asde_solicitudes]', 'area' ),
		// v0.11: pedidos de uniforme y «Mis pagos».
		'uniforme'         => array( 'uniforme', 'Uniforme', '[asde_uniforme]', 'area' ),
		'uniformes'        => array( 'gestion-pedidos', 'Gestión de pedidos de uniforme', '[asde_uniformes]', 'uniforme' ),
		'uniformes_config' => array( 'configuracion', 'Configuración de uniformes', '[asde_uniformes_config]', 'uniforme' ),
		'pagos'            => array( 'mis-pagos', 'Mis pagos', '[asde_mis_pagos]', 'area' ),
		// v0.16: alta de scouters con el enlace del grupo (doc 31).
		'alta_scouters'    => array( 'alta-scouters', 'Alta de scouters', '[asde_alta_scouters]', 'area' ),
		// v0.19: eventos (doc 32).
		'eventos'          => array( 'eventos', 'Eventos', '[asde_eventos]', 'area' ),
		'eventos_config'   => array( 'configuracion', 'Configuración de eventos', '[asde_eventos_config]', 'eventos' ),
		'mis_eventos'      => array( 'mis-eventos', 'Mis eventos', '[asde_mis_eventos]', 'area' ), // v0.19.1: inscripciones.
	);

	/** Páginas accesibles sin iniciar sesión (no salen en listados ni menús: Visibilidad). */
	public const PUBLICAS = array( 'acceso', 'activar', 'recuperar', 'solicitar', 'alta_scouters' );

	/** Páginas a las que se puede entrar aunque el CRM no dé acceso (para ver el motivo o gestionar la seguridad). */
	public const SIN_AMBITO = array( 'area', 'seguridad' );

	public static function install(): void {
		$ids  = self::ids();
		$raiz = get_page_by_path( 'nuestro-grupo-scout' );

		foreach ( self::DEFINICIONES as $key => list( $slug, $titulo, $contenido, $padre ) ) {
			$id = (int) ( $ids[ $key ] ?? 0 );
			if ( $id && 'page' === get_post_type( $id ) ) {
				if ( 'trash' === get_post_status( $id ) ) {
					// En la papelera: se recupera (crear otra dejaría una copia con la dirección cambiada).
					wp_untrash_post( $id );
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'publish',
						)
					);
				}
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => $titulo,
					'post_name'      => $slug,
					'post_parent'    => null === $padre ? ( $raiz ? $raiz->ID : 0 ) : (int) ( $ids[ $padre ] ?? 0 ),
					'post_content'   => $contenido,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ $key ] = $id;
			}
		}

		update_option( self::OPTION, $ids, false );

		// Ancho completo y sin barra de título: ajustes que solo entiende Enfold (con otro tema
		// no se toca nada; al cambiar a Enfold se aplican solos). Ver Frontend\Tema.
		Tema::ajustar_paginas( array_values( $ids ) );
	}

	/**
	 * «Generar el entorno»: recupera las páginas que están en la papelera o sin
	 * publicar, vuelve a poner el shortcode si alguien lo quitó (sin él la página
	 * no hace nada; el resto del texto se conserva) y crea las que faltan.
	 *
	 * @return string[] Qué se ha hecho.
	 */
	public static function reparar(): array {
		$hecho = array();
		$ids   = self::ids();
		foreach ( self::DEFINICIONES as $key => list( , $titulo, $contenido ) ) {
			$id = (int) ( $ids[ $key ] ?? 0 );
			if ( ! $id || 'page' !== get_post_type( $id ) ) {
				unset( $ids[ $key ] ); // Borrada del todo: se crea de nuevo.
				continue;
			}
			$estado = get_post_status( $id );
			if ( 'trash' === $estado ) {
				wp_untrash_post( $id );
				$hecho[] = sprintf( asde_t( 'Página «%s»: recuperada de la papelera.' ), $titulo );
			}
			if ( 'publish' !== $estado ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'publish',
					)
				);
				if ( 'trash' !== $estado ) {
					$hecho[] = sprintf( asde_t( 'Página «%s»: publicada (estaba como «%s»).' ), $titulo, $estado );
				}
			}
			$tag    = self::shortcode( $contenido );
			$actual = (string) get_post_field( 'post_content', $id );
			if ( '' !== $tag && ! has_shortcode( $actual, $tag ) ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => $contenido . ( '' !== trim( $actual ) ? "\n\n" . $actual : '' ),
					)
				);
				$hecho[] = sprintf( asde_t( 'Página «%s»: le faltaba [%s], que es lo que la hace funcionar; se ha vuelto a poner.' ), $titulo, $tag );
			}
		}
		update_option( self::OPTION, $ids, false );

		$antes = $ids;
		self::install();
		foreach ( array_diff_key( self::ids(), $antes ) as $key => $id ) {
			$hecho[] = sprintf( 'Página «%s»: creada.', self::DEFINICIONES[ $key ][1] );
		}
		return $hecho;
	}

	/**
	 * Estado de las páginas para «Generar el entorno».
	 *
	 * @return array{total:int, bien:int, problemas:string[]}
	 */
	public static function revisar(): array {
		$ids       = self::ids();
		$problemas = array();
		foreach ( self::DEFINICIONES as $key => list( , $titulo, $contenido ) ) {
			$id = (int) ( $ids[ $key ] ?? 0 );
			if ( ! $id || 'page' !== get_post_type( $id ) ) {
				$problemas[] = sprintf( asde_t( '«%s» no existe' ), $titulo );
			} elseif ( 'publish' !== get_post_status( $id ) ) {
				$problemas[] = sprintf( asde_t( '«%s» está como «%s»' ), $titulo, get_post_status( $id ) );
			} elseif ( ! has_shortcode( (string) get_post_field( 'post_content', $id ), self::shortcode( $contenido ) ) ) {
				$problemas[] = sprintf( asde_t( '«%s» no tiene su shortcode' ), $titulo );
			}
		}
		return array(
			'total'     => count( self::DEFINICIONES ),
			'bien'      => count( self::DEFINICIONES ) - count( $problemas ),
			'problemas' => $problemas,
		);
	}

	/** Etiqueta del primer shortcode del contenido (p. ej. «asde_personas»). */
	private static function shortcode( string $contenido ): string {
		return preg_match( '/^\[([a-z0-9_]+)/', $contenido, $m ) ? $m[1] : '';
	}

	/** @return array<string, int> */
	public static function ids(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public static function id( string $key ): int {
		return self::ids()[ $key ] ?? 0;
	}

	public static function url( string $key, array $args = array() ): string {
		$id  = self::id( $key );
		$url = $id ? get_permalink( $id ) : home_url( '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** Clave de la página del área privada que se está viendo, o null. */
	public static function current_key(): ?string {
		if ( ! is_page() ) {
			return null;
		}
		$key = array_search( (int) get_queried_object_id(), self::ids(), true );
		return false === $key ? null : (string) $key;
	}
}
