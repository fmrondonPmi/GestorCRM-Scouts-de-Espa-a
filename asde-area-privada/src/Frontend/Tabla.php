<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Ordenación por columnas y paginación de los listados del área privada.
 *
 * Todo va en la URL (orden, dir, pag, por_pagina), así que funciona sin
 * JavaScript, con el botón "atrás" y al compartir el enlace. El tamaño de página
 * (de 5 en 5 hasta 50; 10 si no se ha elegido, v0.31) se recuerda para cada usuario.
 */
final class Tabla {

	public const POR_PAGINA = array( 5, 10, 15, 20, 25, 30, 35, 40, 45, 50 );

	/** Tamaño de página si la persona no ha elegido otro (petición del usuario, v0.31). */
	public const POR_PAGINA_DEFECTO = 10;

	private const META_POR_PAGINA = 'asde_por_pagina';

	private string $orden;
	private string $dir;
	private int $pagina;
	private int $por_pagina;
	private int $total = 0;

	/**
	 * @param array<string, callable> $columnas Columnas ordenables: clave => fn( $fila ) que da el valor a comparar.
	 */
	public function __construct( private array $columnas, string $orden_defecto, string $dir_defecto = 'asc' ) {
		$orden       = isset( $_GET['orden'] ) ? sanitize_key( wp_unslash( $_GET['orden'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$dir         = isset( $_GET['dir'] ) ? sanitize_key( wp_unslash( $_GET['dir'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$this->orden = isset( $columnas[ $orden ] ) ? $orden : $orden_defecto;
		$this->dir   = in_array( $dir, array( 'asc', 'desc' ), true ) ? $dir : $dir_defecto;

		$pedido = isset( $_GET['por_pagina'] ) ? absint( $_GET['por_pagina'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::guardar_por_pagina( $pedido );
		$this->por_pagina = self::por_pagina_guardada();
		$this->pagina     = max( 1, isset( $_GET['pag'] ) ? absint( $_GET['pag'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** Tamaño de página que ha elegido la persona (el mismo en todos los listados). */
	public static function por_pagina_guardada(): int {
		$user_id = get_current_user_id();
		$pedido  = $user_id ? (int) get_user_meta( $user_id, self::META_POR_PAGINA, true ) : 0;
		return in_array( $pedido, self::POR_PAGINA, true ) ? $pedido : self::POR_PAGINA_DEFECTO;
	}

	/** Recuerda el tamaño elegido; cualquier otro valor se ignora. */
	public static function guardar_por_pagina( int $n ): void {
		$user_id = get_current_user_id();
		if ( $user_id && in_array( $n, self::POR_PAGINA, true ) ) {
			update_user_meta( $user_id, self::META_POR_PAGINA, $n );
		}
	}

	/** Los listados que paginan en el navegador (Usuarios) avisan aquí del tamaño elegido. */
	public static function register(): void {
		add_action( 'wp_ajax_asde_ap_por_pagina', array( self::class, 'ajax_por_pagina' ) );
	}

	public static function ajax_por_pagina(): void {
		check_ajax_referer( 'asde_ap_por_pagina', 'nonce' );
		self::guardar_por_pagina( isset( $_POST['por_pagina'] ) ? absint( $_POST['por_pagina'] ) : 0 );
		wp_send_json_success( self::por_pagina_guardada() );
	}

	public function orden(): string {
		return $this->orden;
	}

	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Ordena (orden estable: a igualdad se mantiene el orden previo) y devuelve la
	 * página pedida.
	 */
	public function aplicar( array $filas ): array {
		$valor = $this->columnas[ $this->orden ];
		$norm  = static function ( $v ) {
			return is_int( $v ) || is_float( $v ) ? $v : mb_strtolower( remove_accents( trim( (string) $v ) ) );
		};
		usort(
			$filas,
			function ( $a, $b ) use ( $valor, $norm ) {
				$va = $norm( $valor( $a ) );
				$vb = $norm( $valor( $b ) );
				// Los vacíos, siempre al final.
				if ( '' === $va || '' === $vb ) {
					return ( '' === $va ) <=> ( '' === $vb );
				}
				$c = ( is_string( $va ) || is_string( $vb ) ) ? strnatcmp( (string) $va, (string) $vb ) : $va <=> $vb;
				return 'desc' === $this->dir ? -$c : $c;
			}
		);
		$this->total  = count( $filas );
		$this->pagina = min( $this->pagina, $this->paginas() );
		return array_slice( $filas, ( $this->pagina - 1 ) * $this->por_pagina, $this->por_pagina );
	}

	public function paginas(): int {
		return max( 1, (int) ceil( $this->total / $this->por_pagina ) );
	}

	/** Texto del encabezado de una columna, como enlace para ordenar (con la flecha del orden actual). */
	public function cabecera( string $columna, string $etiqueta ): string {
		if ( ! isset( $this->columnas[ $columna ] ) ) {
			return esc_html( $etiqueta );
		}
		$activa = $columna === $this->orden;
		$dir    = $activa && 'asc' === $this->dir ? 'desc' : 'asc';
		$url    = add_query_arg(
			array(
				'orden' => $columna,
				'dir'   => $dir,
				'pag'   => false,
			)
		);
		$flecha = $activa ? ( 'asc' === $this->dir ? '▲' : '▼' ) : '↕';
		return sprintf(
			'<a href="%s" class="asde-orden%s" title="%s">%s <span class="asde-orden__flecha" aria-hidden="true">%s</span></a>',
			esc_url( $url ),
			$activa ? ' is-active' : '',
			esc_attr( sprintf( asde_t( 'Ordenar por %s (%s)' ), mb_strtolower( $etiqueta ), 'asc' === $dir ? asde_t( 'ascendente' ) : asde_t( 'descendente' ) ) ),
			esc_html( $etiqueta ),
			$flecha
		);
	}

	/** Atributo aria-sort para el <th>. */
	public function aria_sort( string $columna ): string {
		if ( $columna !== $this->orden ) {
			return '';
		}
		return ' aria-sort="' . ( 'asc' === $this->dir ? 'ascending' : 'descending' ) . '"';
	}

	/** Barra de paginación: "Mostrando 26–50 de 107", botones y tamaño de página. */
	public function paginacion(): string {
		if ( ! $this->total ) {
			return '';
		}
		$url = static fn( int $p ) => add_query_arg( 'pag', $p > 1 ? $p : false );

		// Páginas a mostrar: la primera, la última y dos a cada lado de la actual.
		$paginas = array();
		for ( $p = 1; $p <= $this->paginas(); $p++ ) {
			if ( 1 === $p || $p === $this->paginas() || abs( $p - $this->pagina ) <= 2 ) {
				$paginas[] = $p;
			} elseif ( end( $paginas ) !== '…' ) {
				$paginas[] = '…';
			}
		}

		// El formulario del tamaño conserva el resto de parámetros (filtros, orden…).
		$ocultos = array();
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( is_scalar( $v ) && ! in_array( $k, array( 'pag', 'por_pagina' ), true ) ) {
				$ocultos[ sanitize_key( $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}

		return TemplateLoader::render(
			'comun/paginacion',
			array(
				'pagina'     => $this->pagina,
				'paginas'    => $this->paginas(),
				'lista'      => $paginas,
				'total'      => $this->total,
				'desde'      => ( $this->pagina - 1 ) * $this->por_pagina + 1,
				'hasta'      => min( $this->total, $this->pagina * $this->por_pagina ),
				'por_pagina' => $this->por_pagina,
				'opciones'   => self::POR_PAGINA,
				'url'        => $url,
				'ocultos'    => $ocultos,
			)
		);
	}
}
