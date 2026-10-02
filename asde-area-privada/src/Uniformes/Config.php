<?php
namespace AsdeBalears\AreaPrivada\Uniformes;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los pedidos de uniforme de cada grupo (opción
 * asde_ap_uniformes_<grupo>): precio de la camisa, modelos y tallas, insignias
 * (nombre, dónde va, precio e imagen), guías de tallas y de colocación, si los
 * pedidos están abiertos y el texto que explica cómo pagar.
 *
 * Las imágenes son adjuntos de la biblioteca de medios de WordPress (su id).
 * Un precio de insignia vacío (null) es «por confirmar»: se puede pedir, y
 * Tesorería cobra el importe real al validar el pago.
 */
final class Config {

	private const OPTION = 'asde_ap_uniformes_';

	/** Camisa scout oficial de ASDE: tallas de las tablas de medidas (2026-09-29). */
	public const MODELOS = array(
		'masculina' => array(
			'nombre' => 'Camisa masculina',
			'tallas' => array( '6', '8', '10', '12', '14', '16', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL' ),
		),
		'femenina'  => array(
			'nombre' => 'Camisa femenina',
			'tallas' => array( '12', '14', '16', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL' ),
		),
	);

	public const MAX_INSIGNIAS = 10;

	/** @return array{precio_camisa:float, modelos:array, tallas_especiales:string[], insignias:array, guia_insignias:int, abierto:bool, texto_pago:string} */
	public static function defaults(): array {
		$modelos = array();
		foreach ( self::MODELOS as $id => $m ) {
			$modelos[ $id ] = $m + array( 'guia' => 0 );
		}
		return array(
			'precio_camisa'     => 25.0,
			'modelos'           => $modelos,
			'tallas_especiales' => array( '3XL' ),
			'insignias'         => array(),
			'guia_insignias'    => 0,
			'abierto'           => true,
			'texto_pago'        => '',
		);
	}

	/** Configuración del grupo (lo guardado sobre los valores por defecto). */
	public static function de( string $grupo = '' ): array {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		$c     = (array) get_option( self::OPTION . $grupo, array() );
		$d     = self::defaults();
		$cfg   = array_merge( $d, array_intersect_key( $c, $d ) );

		$cfg['precio_camisa'] = max( 0.0, round( (float) $cfg['precio_camisa'], 2 ) );
		$modelos              = array();
		foreach ( (array) $cfg['modelos'] as $id => $m ) {
			$id     = sanitize_key( (string) $id );
			$tallas = array_values( array_filter( array_map( static fn( $t ) => mb_substr( trim( (string) $t ), 0, 10 ), (array) ( $m['tallas'] ?? array() ) ) ) );
			if ( '' !== $id && $tallas ) {
				$modelos[ $id ] = array(
					'nombre' => mb_substr( (string) ( $m['nombre'] ?? $id ), 0, 60 ),
					'tallas' => $tallas,
					'guia'   => (int) ( $m['guia'] ?? 0 ),
				);
			}
		}
		$cfg['modelos']           = $modelos ?: $d['modelos'];
		$cfg['tallas_especiales'] = array_values( array_filter( array_map( 'strval', (array) $cfg['tallas_especiales'] ) ) );
		$insignias                = array();
		foreach ( (array) $cfg['insignias'] as $id => $i ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || '' === trim( (string) ( $i['nombre'] ?? '' ) ) ) {
				continue;
			}
			$insignias[ $id ] = array(
				'nombre' => mb_substr( (string) $i['nombre'], 0, 80 ),
				'lugar'  => mb_substr( (string) ( $i['lugar'] ?? '' ), 0, 60 ),
				'precio' => isset( $i['precio'] ) && '' !== $i['precio'] && null !== $i['precio'] ? max( 0.0, round( (float) $i['precio'], 2 ) ) : null,
				'imagen' => (int) ( $i['imagen'] ?? 0 ),
				'activa' => ! isset( $i['activa'] ) || (bool) $i['activa'],
			);
		}
		$cfg['insignias']      = $insignias;
		$cfg['guia_insignias'] = (int) $cfg['guia_insignias'];
		$cfg['abierto']        = (bool) $cfg['abierto'];
		$cfg['texto_pago']     = mb_substr( (string) $cfg['texto_pago'], 0, 600 );
		return $cfg;
	}

	public static function guardar( array $cfg, string $grupo = '' ): void {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		update_option( self::OPTION . $grupo, array_intersect_key( $cfg, self::defaults() ), false );
	}

	public static function borrar( string $grupo ): void {
		delete_option( self::OPTION . $grupo );
	}

	/** @return array<string, array> Insignias que se pueden pedir. */
	public static function insignias_activas( array $cfg ): array {
		return array_filter( $cfg['insignias'], static fn( array $i ) => $i['activa'] );
	}

	public static function talla_valida( array $cfg, string $modelo, string $talla ): bool {
		return isset( $cfg['modelos'][ $modelo ] ) && in_array( $talla, $cfg['modelos'][ $modelo ]['tallas'], true );
	}

	public static function es_especial( array $cfg, string $talla ): bool {
		return in_array( $talla, $cfg['tallas_especiales'], true );
	}

	/** Identificador para una insignia nueva a partir de su nombre. */
	public static function id_para( string $nombre, array $existentes ): string {
		$base = substr( sanitize_key( remove_accents( str_replace( ' ', '-', strtolower( $nombre ) ) ) ), 0, 30 ) ?: 'insignia';
		$id   = $base;
		for ( $n = 2; isset( $existentes[ $id ] ); $n++ ) {
			$id = $base . '-' . $n;
		}
		return $id;
	}

	/**
	 * Tabla de medidas de la camisa de un modelo (v0.12.5): la imagen que el grupo haya
	 * puesto en la configuración o, si no, la de ASDE que va dentro del plugin
	 * (assets/img/medidas-camisa-<modelo>.png). '' si no hay ninguna.
	 */
	public static function guia_medidas( array $cfg, string $modelo ): string {
		$propia = self::imagen( (int) ( $cfg['modelos'][ $modelo ]['guia'] ?? 0 ), 'full' );
		if ( '' !== $propia ) {
			return $propia;
		}
		$fichero = 'assets/img/medidas-camisa-' . sanitize_key( $modelo ) . '.png';
		return is_readable( ASDE_AP_DIR . $fichero ) ? plugins_url( $fichero, ASDE_AP_FILE ) : '';
	}

	/** URL de una imagen de la configuración (adjunto), o ''. */
	public static function imagen( int $adjunto, string $tamano = 'medium' ): string {
		return $adjunto ? (string) wp_get_attachment_image_url( $adjunto, $tamano ) : '';
	}
}
