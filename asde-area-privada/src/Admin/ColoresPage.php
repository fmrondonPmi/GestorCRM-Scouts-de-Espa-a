<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Colores;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Colores (v0.15, doc 28): la administración elige los colores
 * del área privada, con una vista previa en vivo hecha con el propio CSS del plugin, unos
 * modelos de partida y avisos de contraste. Ver Frontend\Colores.
 */
final class ColoresPage {

	public const SLUG        = 'asde-area-privada-colores';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	public const ACCION      = 'asde_ap_colores';
	public const AJAX        = 'asde_ap_colores_vista';
	private const AVISO      = 'asde_ap_colores_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_guardar' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax_vista' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Colores', 'asde-area-privada' ),
			__( 'Colores', 'asde-area-privada' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function scripts( string $pantalla ): void {
		if ( ! str_ends_with( $pantalla, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		// El CSS del área, para que la vista previa sea la de verdad.
		wp_enqueue_style( 'asde-area-privada', plugins_url( 'assets/css/area-privada.css', ASDE_AP_FILE ), array(), ASDE_AP_VERSION );
		// Los colores elegidos (v0.31: con wp_add_inline_style; colores.js cambia este <style> al vuelo).
		wp_add_inline_style( 'asde-area-privada', wp_strip_all_tags( Colores::css() ) );
		wp_enqueue_script( 'asde-colores', plugins_url( 'assets/js/colores.js', ASDE_AP_FILE ), array( 'wp-color-picker' ), ASDE_AP_VERSION, true );
		wp_localize_script(
			'asde-colores',
			'asdeColores',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'accion'  => self::AJAX,
				'nonce'   => wp_create_nonce( self::AJAX ),
				'modelos' => array_map( static fn( array $m ) => $m[1], Colores::modelos() ),
			)
		);
	}

	/** Vista previa: el CSS y los avisos para los colores que se están eligiendo (sin guardar nada). */
	public function ajax_vista(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'mensaje' => 'No tienes permiso.' ), 403 );
		}
		check_ajax_referer( self::AJAX, 'nonce' );
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$colores = Colores::limpiar( (array) ( $post['colores'] ?? array() ) );
		wp_send_json_success(
			array(
				'css'    => Colores::css( $colores ),
				'avisos' => Colores::avisos( $colores ),
			)
		);
	}

	public function handle_guardar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$accion = sanitize_key( (string) ( $post['accion'] ?? 'guardar' ) );

		if ( 'restablecer' === $accion ) {
			Colores::restablecer();
			Logger::log( 'colores_restablecidos', 'sitio', '' );
			$this->aviso( 'success', 'Colores restablecidos: el área vuelve a los colores de siempre.' );
		} elseif ( 'modelo' === $accion ) {
			$modelo = Colores::modelos()[ sanitize_key( (string) ( $post['modelo'] ?? '' ) ) ] ?? null;
			if ( ! $modelo ) {
				$this->aviso( 'error', 'No se ha aplicado: ese modelo no existe.' );
			} else {
				Colores::guardar( array( 'primary' => $modelo[1] ) );
				Logger::log( 'colores_modelo', 'sitio', '', array( 'modelo' => sanitize_key( (string) $post['modelo'] ) ) );
				$this->aviso( 'success', sprintf( 'Aplicado el modelo «%s»: el resto de tonos salen del color principal. Puedes ajustar los que quieras.', $modelo[0] ) );
			}
		} else {
			$g = Colores::guardar( (array) ( $post['colores'] ?? array() ) );
			Logger::log( 'colores_guardados', 'sitio', '', array( 'cambiados' => array_keys( $g ) ) );
			$avisos = Colores::avisos();
			$this->aviso( 'success', ( $g ? 'Colores guardados.' : 'Guardado: sin cambios, el área usa los colores de siempre.' ) . ( $avisos ? ' Ojo: ' . implode( ' ', $avisos ) : '' ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$aviso = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );
		$guardados = Colores::guardados();
		$def       = Colores::definiciones();
		?>
		<div class="wrap asde-colores">
			<h1><?php esc_html_e( 'Colores del área privada', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Elige los colores del área privada. Con el color principal basta: de él salen los fondos suaves, los bordes y el color del texto que va encima. Lo que dejes sin elegir se queda como estaba (por defecto, el color principal del tema Enfold o el morado de siempre).', 'asde-area-privada' ); ?></p>

			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $aviso[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Modelos de partida', 'asde-area-privada' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-colores__modelos">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<input type="hidden" name="accion" value="modelo">
				<?php wp_nonce_field( self::ACCION ); ?>
				<?php foreach ( Colores::modelos() as $clave => list( $nombre, $principal ) ) : ?>
					<button type="submit" name="modelo" value="<?php echo esc_attr( $clave ); ?>" class="button asde-colores__modelo">
						<span class="asde-colores__muestra" style="background:<?php echo esc_attr( $principal ); ?>"></span>
						<?php echo esc_html( $nombre ); ?>
					</button>
				<?php endforeach; ?>
			</form>

			<div class="asde-colores__cuerpo">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-colores__form" id="asde-colores-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
					<input type="hidden" name="accion" value="guardar">
					<?php wp_nonce_field( self::ACCION ); ?>
					<?php foreach ( Colores::grupos() as $grupo => $titulo ) : ?>
						<h2><?php echo esc_html( $titulo ); ?></h2>
						<table class="form-table" role="presentation">
							<?php foreach ( $def as $clave => list( $etiqueta, $por_defecto, $g, $ayuda ) ) : ?>
								<?php
								if ( $g !== $grupo ) {
									continue;
								}
								?>
								<tr>
									<th scope="row"><label for="asde-color-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></label></th>
									<td>
										<input type="text" id="asde-color-<?php echo esc_attr( $clave ); ?>" name="colores[<?php echo esc_attr( $clave ); ?>]" value="<?php echo esc_attr( $guardados[ $clave ] ?? '' ); ?>" class="asde-color" data-default-color="<?php echo esc_attr( $por_defecto ); ?>" placeholder="<?php echo esc_attr( $por_defecto ); ?>">
										<p class="description"><?php echo esc_html( $ayuda ); ?> <?php echo esc_html( sprintf( 'Por defecto: %s.', $por_defecto ) ); ?></p>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endforeach; ?>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar los colores', 'asde-area-privada' ); ?></button>
						<button type="submit" name="accion" value="restablecer" class="button" data-asde-confirmar="<?php esc_attr_e( '¿Volver a los colores de siempre?', 'asde-area-privada' ); ?>"><?php esc_html_e( 'Restablecer', 'asde-area-privada' ); ?></button>
					</p>
				</form>

				<div class="asde-colores__vista">
					<h2><?php esc_html_e( 'Vista previa', 'asde-area-privada' ); ?></h2>
					<div id="asde-colores-avisos" class="notice notice-warning inline" <?php echo Colores::avisos() ? '' : 'hidden'; ?>>
						<p><?php echo esc_html( implode( ' ', Colores::avisos() ) ); ?></p>
					</div>
					<div class="asde-ap asde-colores__area">
						<nav class="asde-nav" aria-label="Vista previa">
							<ul class="asde-nav__tabs">
								<li><a href="#" class="is-active" aria-current="page">Inicio</a></li>
								<li class="asde-nav__grupo" role="presentation"><span>Mi espacio</span></li>
								<li><a href="#">Mis datos</a></li>
								<li><a href="#">Mis pagos</a></li>
								<li class="asde-nav__grupo" role="presentation"><span>Gestión del grupo</span></li>
								<li><a href="#">Personas</a></li>
							</ul>
						</nav>
						<section class="asde-card">
							<h2>Hola, Aina</h2>
							<p>Este es un texto normal con <a href="#">un enlace</a> y <span class="asde-muted">un texto atenuado</span>.</p>
							<p>
								<span class="asde-badge asde-badge--rol">Scouter / Voluntario</span>
								<span class="asde-badge asde-badge--pago-pagado">Pagado</span>
								<span class="asde-badge asde-badge--pago-parcial">Parcial</span>
								<span class="asde-badge asde-badge--pago-pendiente">Pendiente</span>
								<span class="asde-badge asde-badge--pago-atrasado">Atrasado</span>
								<span class="asde-badge asde-badge--pago-exento">Exento</span>
							</p>
							<p><button type="button" class="button">Un botón</button></p>
						</section>
						<div class="asde-notice asde-notice--success" role="presentation"><strong>Correcto.</strong> Se ha guardado el pedido.</div>
						<div class="asde-notice asde-notice--warning" role="presentation"><strong>Aviso.</strong> Falta confirmar un pago.</div>
						<div class="asde-notice asde-notice--error" role="presentation"><strong>Error.</strong> No se ha podido guardar.</div>
						<div class="asde-notice asde-notice--info" role="presentation"><strong>Información.</strong> El grupo revisará tu solicitud.</div>
						<table class="asde-table">
							<thead><tr><th>Nombre</th><th>Sección</th><th>Estado</th></tr></thead>
							<tbody>
								<tr><td data-label="Nombre">Aina</td><td data-label="Sección">Lobatos</td><td data-label="Estado"><span class="asde-badge asde-badge--estado-activo">Activo</span></td></tr>
								<tr><td data-label="Nombre">Joan</td><td data-label="Sección">Escultas</td><td data-label="Estado"><span class="asde-badge asde-badge--estado-invitado">Invitado</span></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
