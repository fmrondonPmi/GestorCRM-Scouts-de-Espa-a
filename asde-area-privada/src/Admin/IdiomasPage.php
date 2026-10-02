<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Idiomas\Traducciones;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Idioma y etiquetas (v0.15, doc 28).
 *
 * - Idioma por defecto del área privada (castellano, catalán, euskera o gallego) y si cada
 *   persona puede elegir el suyo.
 * - Gestión de etiquetas: como ninguna traducción es perfecta, la administración puede
 *   cambiar cualquier texto del área en cualquier idioma (también el castellano). Lo que
 *   se cambia aquí manda sobre el catálogo que trae el plugin.
 */
final class IdiomasPage {

	public const SLUG        = 'asde-area-privada-idiomas';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	public const ACCION      = 'asde_ap_idiomas';
	public const POR_PAGINA  = 40;
	private const AVISO      = 'asde_ap_idiomas_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 13 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACCION . '_exportar', array( $this, 'handle_exportar' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Idioma y etiquetas', 'asde-area-privada' ),
			__( 'Idioma y etiquetas', 'asde-area-privada' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	// ---------------------------------------------------------------------
	// Acciones
	// ---------------------------------------------------------------------

	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$accion   = sanitize_key( (string) ( $post['accion'] ?? '' ) );
		$idioma   = Idioma::es_valido( $post['idioma'] ?? null ) ? $post['idioma'] : Idioma::DEFECTO;
		$volver   = array( 'idioma' => $idioma );

		switch ( $accion ) {
			case 'ajustes':
				$por_defecto = (string) ( $post['por_defecto'] ?? '' );
				if ( Idioma::guardar_ajustes( $por_defecto, ! empty( $post['elegir'] ) ) ) {
					Logger::log( 'idioma_ajustes', 'sitio', '', array( 'por_defecto' => $por_defecto, 'elegir' => ! empty( $post['elegir'] ) ) );
					$this->aviso( 'success', sprintf( 'Guardado: el área privada está en %s por defecto%s.', Idioma::nombre( $por_defecto ), ! empty( $post['elegir'] ) ? ' y cada persona puede elegir el suyo' : ', sin que cada persona pueda elegir' ) );
				} else {
					$this->aviso( 'error', 'No se ha guardado: ese idioma no existe.' );
				}
				break;

			case 'etiquetas':
				$cambios  = array();
				$rechazos = 0;
				foreach ( (array) ( $post['et'] ?? array() ) as $fila ) {
					$clave = (string) ( $fila['clave'] ?? '' );
					$texto = (string) ( $fila['texto'] ?? '' );
					if ( '' === $clave || ! isset( Traducciones::textos()[ $clave ] ) ) {
						continue;
					}
					if ( '' !== trim( $texto ) && ! Traducciones::mismos_marcadores( $clave, $texto ) ) {
						++$rechazos; // Le faltan o le sobran marcadores %s: reventaría el texto.
						continue;
					}
					$cambios[ $clave ] = $texto;
				}
				$total = Traducciones::guardar( $idioma, $cambios );
				Logger::log( 'etiquetas_guardadas', 'sitio', '', array( 'idioma' => $idioma, 'filas' => count( $cambios ) ) );
				$this->aviso(
					$rechazos ? 'error' : 'success',
					sprintf( 'Etiquetas guardadas (%1$s): ahora hay %2$d personalizadas.', Idioma::nombre( $idioma ), $total )
					. ( $rechazos ? sprintf( ' No se han guardado %d porque cambian los marcadores (%%s, %%d…) del texto original: tienen que estar todos y los mismos.', $rechazos ) : '' )
				);
				break;

			case 'restablecer':
				if ( empty( $post['confirmar'] ) ) {
					$this->aviso( 'error', 'No se ha quitado nada: marca la casilla de confirmación.' );
					break;
				}
				Traducciones::restablecer( $idioma );
				Logger::log( 'etiquetas_restablecidas', 'sitio', '', array( 'idioma' => $idioma ) );
				$this->aviso( 'success', sprintf( 'Quitadas todas las etiquetas personalizadas de %s: vuelve a las traducciones del plugin.', Idioma::nombre( $idioma ) ) );
				break;

			case 'importar':
				$fichero = $_FILES['fichero'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
				if ( ! is_array( $fichero ) || empty( $fichero['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $fichero['error'] ?? 1 ) || ! is_uploaded_file( (string) $fichero['tmp_name'] ) ) {
					$this->aviso( 'error', 'No se ha importado nada: elige el fichero .json que descargaste.' );
					break;
				}
				$contenido = (string) file_get_contents( (string) $fichero['tmp_name'] );
				$r         = strlen( $contenido ) > 2 * MB_IN_BYTES ? new \WP_Error( 'asde_etiquetas', 'El fichero es demasiado grande.' ) : Traducciones::importar( $contenido );
				if ( is_wp_error( $r ) ) {
					$this->aviso( 'error', $r->get_error_message() );
				} else {
					Logger::log( 'etiquetas_importadas', 'sitio', '', array( 'etiquetas' => $r ) );
					$this->aviso( 'success', sprintf( 'Importadas %d etiquetas personalizadas (sustituyen a las que había).', $r ) );
				}
				break;
		}
		wp_safe_redirect( self::url( $volver + array( 's' => sanitize_text_field( $post['s'] ?? '' ) ) ) );
		exit;
	}

	/** Descarga las etiquetas personalizadas en JSON. */
	public function handle_exportar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION . '_exportar' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="etiquetas-area-privada-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo Traducciones::exportar(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	// ---------------------------------------------------------------------
	// Pantalla
	// ---------------------------------------------------------------------

	/**
	 * Textos que se enseñan en la tabla de un idioma, con su filtro y su página.
	 *
	 * @return array{filas: array<int, array{clave:string, grupo:string, traduccion:string, propia:string}>, total:int, paginas:int}
	 */
	public static function listado( string $idioma, string $buscar, string $filtro, int $pagina ): array {
		$catalogo = Traducciones::catalogo( $idioma );
		$propias  = Traducciones::personalizadas()[ $idioma ] ?? array();
		$buscar   = mb_strtolower( trim( $buscar ) );
		$filas    = array();
		foreach ( Traducciones::textos() as $clave => $grupo ) {
			$clave      = (string) $clave;
			$traduccion = Idioma::DEFECTO === $idioma ? $clave : (string) ( $catalogo[ $clave ] ?? '' );
			$propia     = (string) ( $propias[ $clave ] ?? '' );
			if ( 'personalizadas' === $filtro && '' === $propia ) {
				continue;
			}
			if ( 'sin_traducir' === $filtro && ( Idioma::DEFECTO === $idioma || '' !== $traduccion ) ) {
				continue;
			}
			if ( '' !== $buscar && ! str_contains( mb_strtolower( $clave . ' ' . $traduccion . ' ' . $propia ), $buscar ) ) {
				continue;
			}
			$filas[] = array(
				'clave'      => $clave,
				'grupo'      => (string) $grupo,
				'traduccion' => $traduccion,
				'propia'     => $propia,
			);
		}
		$total   = count( $filas );
		$paginas = max( 1, (int) ceil( $total / self::POR_PAGINA ) );
		$pagina  = max( 1, min( $pagina, $paginas ) );
		return array(
			'filas'   => array_slice( $filas, ( $pagina - 1 ) * self::POR_PAGINA, self::POR_PAGINA ),
			'total'   => $total,
			'paginas' => $paginas,
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$aviso = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );

		$ajustes = Idioma::ajustes();
		// phpcs:disable WordPress.Security.NonceVerification
		$idioma = Idioma::es_valido( $_GET['idioma'] ?? null ) ? (string) $_GET['idioma'] : ( Idioma::DEFECTO === $ajustes['por_defecto'] ? 'ca' : $ajustes['por_defecto'] );
		$buscar = sanitize_text_field( wp_unslash( (string) ( $_GET['s'] ?? '' ) ) );
		$filtro = in_array( $_GET['ver'] ?? '', array( 'personalizadas', 'sin_traducir' ), true ) ? (string) $_GET['ver'] : 'todas';
		$pagina = max( 1, (int) ( $_GET['pagina'] ?? 1 ) );
		// phpcs:enable
		$l       = self::listado( $idioma, $buscar, $filtro, $pagina );
		$total   = count( Traducciones::textos() );
		$catalogo = Traducciones::catalogo( $idioma );
		$propias  = Traducciones::personalizadas();
		$base     = array( 'idioma' => $idioma, 's' => $buscar, 'ver' => $filtro );
		?>
		<div class="wrap asde-idiomas-admin">
			<h1><?php esc_html_e( 'Idioma y etiquetas', 'asde-area-privada' ); ?></h1>

			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $aviso[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Idioma del área privada', 'asde-area-privada' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<input type="hidden" name="accion" value="ajustes">
				<input type="hidden" name="idioma" value="<?php echo esc_attr( $idioma ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-idioma-defecto"><?php esc_html_e( 'Idioma por defecto', 'asde-area-privada' ); ?></label></th>
						<td>
							<select id="asde-idioma-defecto" name="por_defecto">
								<?php foreach ( Idioma::disponibles() as $codigo => $nombre ) : ?>
									<option value="<?php echo esc_attr( $codigo ); ?>" <?php selected( $ajustes['por_defecto'], $codigo ); ?>><?php echo esc_html( $nombre ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'El idioma en el que se ve el área privada (menús, pantallas, avisos y emails) para quien no ha elegido otro. Los idiomas oficiales de España con lengua propia: castellano, catalán, euskera y gallego.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Elegir idioma', 'asde-area-privada' ); ?></th>
						<td>
							<label><input type="checkbox" name="elegir" value="1" <?php checked( $ajustes['elegir'] ); ?>> <?php esc_html_e( 'Cada persona puede elegir su idioma (sale un selector en el menú y en las pantallas de acceso; se recuerda en su usuario)', 'asde-area-privada' ); ?></label>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar', 'asde-area-privada' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Etiquetas', 'asde-area-privada' ); ?></h2>
			<p><?php esc_html_e( 'Ninguna traducción es perfecta: aquí puedes cambiar cualquier texto del área privada, en cualquier idioma (también el castellano). Lo que escribas manda sobre la traducción que trae el plugin; si lo dejas vacío, vuelve a la del plugin. Los marcadores como %s o %d son huecos que rellena el sistema (un nombre, un número…): tienen que estar todos, aunque puedes cambiarlos de sitio. Se admiten <strong>, <em> y <code>.', 'asde-area-privada' ); ?></p>

			<h3 class="nav-tab-wrapper">
				<?php foreach ( Idioma::disponibles() as $codigo => $nombre ) : ?>
					<?php
					$n_propias = count( $propias[ $codigo ] ?? array() );
					$n_catalogo = Idioma::DEFECTO === $codigo ? $total : count( Traducciones::catalogo( $codigo ) );
					?>
					<a class="nav-tab<?php echo $codigo === $idioma ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'idioma' => $codigo ) ) ); ?>">
						<?php echo esc_html( $nombre ); ?>
						<span class="description">(<?php echo esc_html( sprintf( '%1$d/%2$d · %3$d propias', $n_catalogo, $total, $n_propias ) ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</h3>

			<form method="get" class="asde-etiquetas__filtro">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="idioma" value="<?php echo esc_attr( $idioma ); ?>">
				<p class="search-box" style="float:none;margin:12px 0;">
					<label class="screen-reader-text" for="asde-et-buscar"><?php esc_html_e( 'Buscar texto', 'asde-area-privada' ); ?></label>
					<input type="search" id="asde-et-buscar" name="s" value="<?php echo esc_attr( $buscar ); ?>" placeholder="<?php esc_attr_e( 'Buscar un texto…', 'asde-area-privada' ); ?>">
					<select name="ver">
						<option value="todas" <?php selected( $filtro, 'todas' ); ?>><?php esc_html_e( 'Todos los textos', 'asde-area-privada' ); ?></option>
						<option value="personalizadas" <?php selected( $filtro, 'personalizadas' ); ?>><?php esc_html_e( 'Solo los que he personalizado', 'asde-area-privada' ); ?></option>
						<?php if ( Idioma::DEFECTO !== $idioma ) : ?>
							<option value="sin_traducir" <?php selected( $filtro, 'sin_traducir' ); ?>><?php esc_html_e( 'Sin traducción del plugin', 'asde-area-privada' ); ?></option>
						<?php endif; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'asde-area-privada' ); ?></button>
					<span class="description"><?php echo esc_html( sprintf( '%d textos', $l['total'] ) ); ?></span>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<input type="hidden" name="accion" value="etiquetas">
				<input type="hidden" name="idioma" value="<?php echo esc_attr( $idioma ); ?>">
				<input type="hidden" name="s" value="<?php echo esc_attr( $buscar ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>
				<table class="widefat striped asde-etiquetas">
					<thead>
						<tr>
							<th style="width:30%"><?php esc_html_e( 'Texto original (castellano)', 'asde-area-privada' ); ?></th>
							<?php if ( Idioma::DEFECTO !== $idioma ) : ?>
								<th style="width:30%"><?php echo esc_html( sprintf( 'Traducción del plugin (%s)', Idioma::nombre( $idioma ) ) ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Tu texto', 'asde-area-privada' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $l['filas'] ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No hay textos con ese filtro.', 'asde-area-privada' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $l['filas'] as $i => $f ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $f['clave'] ); ?>
									<br><span class="description"><?php echo esc_html( $f['grupo'] ); ?></span>
									<input type="hidden" name="et[<?php echo (int) $i; ?>][clave]" value="<?php echo esc_attr( $f['clave'] ); ?>">
								</td>
								<?php if ( Idioma::DEFECTO !== $idioma ) : ?>
									<td><?php echo '' !== $f['traduccion'] ? esc_html( $f['traduccion'] ) : '<em>' . esc_html__( '(sin traducir: se ve en castellano)', 'asde-area-privada' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php endif; ?>
								<td>
									<textarea name="et[<?php echo (int) $i; ?>][texto]" rows="<?php echo (int) max( 2, min( 6, (int) ceil( strlen( $f['clave'] ) / 55 ) ) ); ?>" class="large-text" lang="<?php echo esc_attr( $idioma ); ?>" placeholder="<?php echo esc_attr( '' !== $f['traduccion'] ? $f['traduccion'] : $f['clave'] ); ?>"><?php echo esc_textarea( $f['propia'] ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $l['paginas'] > 1 ) : ?>
					<p class="tablenav-pages">
						<?php for ( $p = 1; $p <= $l['paginas']; $p++ ) : ?>
							<a class="button<?php echo $p === $pagina ? ' button-primary' : ''; ?>" href="<?php echo esc_url( self::url( $base + array( 'pagina' => $p ) ) ); ?>"><?php echo (int) $p; ?></a>
						<?php endfor; ?>
					</p>
				<?php endif; ?>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar las etiquetas de esta página', 'asde-area-privada' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Copia de seguridad', 'asde-area-privada' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACCION . '_exportar' ), self::ACCION . '_exportar' ) ); ?>"><?php esc_html_e( 'Descargar mis etiquetas (.json)', 'asde-area-privada' ); ?></a></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<input type="hidden" name="accion" value="importar">
				<input type="hidden" name="idioma" value="<?php echo esc_attr( $idioma ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>
				<p>
					<input type="file" name="fichero" accept=".json,application/json">
					<button type="submit" class="button"><?php esc_html_e( 'Importar (sustituye a las que hay)', 'asde-area-privada' ); ?></button>
				</p>
			</form>

			<?php if ( ! empty( $propias[ $idioma ] ) ) : ?>
				<h2><?php esc_html_e( 'Volver a las del plugin', 'asde-area-privada' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
					<input type="hidden" name="accion" value="restablecer">
					<input type="hidden" name="idioma" value="<?php echo esc_attr( $idioma ); ?>">
					<?php wp_nonce_field( self::ACCION ); ?>
					<p>
						<label><input type="checkbox" name="confirmar" value="1"> <?php echo esc_html( sprintf( 'Quitar las %1$d etiquetas que he personalizado en %2$s', count( $propias[ $idioma ] ), Idioma::nombre( $idioma ) ) ); ?></label>
						<button type="submit" class="button"><?php esc_html_e( 'Quitar', 'asde-area-privada' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
