<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Install\Entorno;
use AsdeBalears\AreaPrivada\Pwa\Ajustes;
use AsdeBalears\AreaPrivada\Pwa\Comprobacion;
use AsdeBalears\AreaPrivada\Pwa\Iconos;
use AsdeBalears\AreaPrivada\Pwa\Manifiesto;
use AsdeBalears\AreaPrivada\Pwa\Pwa;
use AsdeBalears\AreaPrivada\Pwa\ServiceWorker;
use AsdeBalears\AreaPrivada\Pwa\Suscripciones;
use AsdeBalears\AreaPrivada\Pwa\Vapid;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → App móvil (docs/pwa/, sección 5): encender o apagar la app,
 * elegir el nombre que lleva bajo el icono, ver con qué icono y color sale y comprobar que el
 * servidor la sirve bien (sin cachés por medio).
 */
final class PwaPage {

	public const SLUG        = 'asde-area-privada-pwa';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	private const ACCION     = 'asde_ap_pwa';
	private const COMPROBAR  = 'asde_ap_pwa_comprobar';
	private const AVISO      = 'asde_ap_pwa_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_guardar' ) );
		add_action( 'admin_post_' . self::COMPROBAR, array( $this, 'handle_comprobar' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'App móvil', 'asde-area-privada' ),
			__( 'App móvil', 'asde-area-privada' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	public function handle_guardar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$antes  = Ajustes::datos();
		$activa = ! empty( $post['activa'] );
		$nombre = Ajustes::limpiar_nombre( (string) ( $post['nombre_corto'] ?? '' ) );
		$push   = ! empty( $post['push'] );
		Ajustes::guardar( $activa, $nombre, $push );
		$claves = true;
		if ( $push ) {
			$claves = Vapid::asegurar(); // Las claves del servidor se crean la primera vez que se encienden los avisos.
		}
		Logger::log(
			'pwa_guardada',
			'sitio',
			'',
			array(
				'activa'            => $activa,
				'activa_antes'      => $antes['activa'],
				'nombre_propio'     => '' !== $nombre,
				'nombre_propio_antes' => '' !== $antes['nombre_corto'],
				'push'              => $push,
				'push_antes'        => $antes['push'],
			)
		);
		delete_transient( Comprobacion::RESULTADO ); // Lo comprobado ya no vale.
		if ( $push && ! $claves ) {
			$this->aviso( 'error', 'Guardado, pero el servidor no ha podido crear las claves de los avisos push (falta OpenSSL con curvas elípticas). Los avisos no se ofrecerán hasta que se puedan crear: consulta con el hosting.' );
		} else {
			$this->aviso(
				'success',
				( $activa
					? 'Guardado: la app móvil está activa.'
					: 'Guardado: la app móvil está apagada. Quien ya la tenía instalada la verá retirarse sola en su próxima visita.' )
				. ( $push && $activa ? ' Los avisos push están encendidos.' : '' )
			);
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public function handle_comprobar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::COMPROBAR );
		Comprobacion::ejecutar();
		wp_safe_redirect( self::url() . '#asde-pwa-servidor' );
		exit;
	}

	/** @return array<string, string> estado => etiqueta */
	private static function etiquetas(): array {
		return array(
			Entorno::OK    => '✅ Bien',
			Entorno::AVISO => '⚠️ Revisar',
			Entorno::FALTA => '❌ Falla',
			Entorno::INFO  => 'ℹ️ Info',
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$aviso = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );
		$d        = Ajustes::datos();
		$ids      = Suscripciones::usuarios_con_suscripcion();
		$suscritos = array(
			'cuentas'      => count( $ids ),
			'dispositivos' => array_sum( array_map( array( Suscripciones::class, 'cuantos' ), $ids ) ),
		);
		$colores  = Manifiesto::colores();
		$ultimo   = Comprobacion::ultimo();
		$etiqueta = self::etiquetas();
		?>
		<div class="wrap asde-pwa">
			<h1><?php esc_html_e( 'App móvil', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'El área privada se puede instalar en el móvil (Android e iPhone) como una app: con su icono, a pantalla completa y con una pantalla «Sin conexión». No se publica en las tiendas y el móvil no guarda ningún dato de las personas. Cómo publicarla en producción: docs/pwa/MANUAL-PUBLICACION.md.', 'asde-area-privada' ); ?></p>

			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $aviso[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Ajustes', 'asde-area-privada' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'App móvil', 'asde-area-privada' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="activa" value="1" <?php checked( $d['activa'] ); ?>>
								<?php esc_html_e( 'Activa (se ofrece instalarla y funciona sin conexión la pantalla «Sin conexión»)', 'asde-area-privada' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Si la apagas, desaparecen el manifiesto y los avisos, y las apps ya instaladas se retiran solas en su próxima visita (puede tardar hasta 24 horas). Las personas conservan el icono de su pantalla de inicio hasta que lo borren.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Avisos push', 'asde-area-privada' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="push" value="1" <?php checked( $d['push'] ); ?>>
								<?php esc_html_e( 'Ofrecer a las personas activar avisos en el móvil (notificaciones push)', 'asde-area-privada' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Aparece una tarjeta «Avisos en este móvil» en el inicio del área. El aviso es siempre genérico («Tienes una novedad en el área privada»): no lleva nombres ni datos. Cada persona decide en su móvil y por categoría. En iPhone solo funciona con la app instalada (iOS 16.4 o posterior). Se suma al email, no lo sustituye. De momento solo se pueden activar y desactivar los avisos en cada móvil: el envío llega en la siguiente versión.', 'asde-area-privada' ); ?>
							</p>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: móviles suscritos, 2: cuentas */
										__( 'Ahora: %1$d móviles suscritos en %2$d cuentas. Claves del servidor: %3$s.', 'asde-area-privada' ),
										$suscritos['dispositivos'],
										$suscritos['cuentas'],
										Vapid::existe() ? __( 'creadas', 'asde-area-privada' ) : __( 'sin crear (se crean al guardar con los avisos encendidos)', 'asde-area-privada' )
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-pwa-nombre"><?php esc_html_e( 'Nombre bajo el icono', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="text" id="asde-pwa-nombre" name="nombre_corto" class="regular-text" maxlength="<?php echo (int) Ajustes::MAX_NOMBRE; ?>" value="<?php echo esc_attr( $d['nombre_corto'] ); ?>" placeholder="<?php esc_attr_e( 'Área privada', 'asde-area-privada' ); ?>">
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: máximo de caracteres */
										__( 'Déjalo vacío para usar «Área privada» (en el idioma de cada persona). Máximo %d caracteres: más largo, el móvil lo corta. Solo se aplica a las apps que se instalen a partir de ahora.', 'asde-area-privada' ),
										Ajustes::MAX_NOMBRE
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar', 'asde-area-privada' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Cómo sale la app', 'asde-area-privada' ); ?></h2>
			<table class="widefat striped" style="max-width: 880px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Nombre', 'asde-area-privada' ); ?></th>
						<td><?php echo esc_html( Manifiesto::nombre() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Icono', 'asde-area-privada' ); ?></th>
						<td>
							<?php if ( Iconos::del_sitio() ) : ?>
								<img src="<?php echo esc_url( Iconos::apple() ); ?>" alt="" width="48" height="48" style="vertical-align: middle; border-radius: 10px">
								<?php esc_html_e( 'El «Icono del sitio» de WordPress.', 'asde-area-privada' ); ?>
							<?php else : ?>
								<img src="<?php echo esc_url( Iconos::apple() ); ?>" alt="" width="48" height="48" style="vertical-align: middle; border-radius: 10px">
								<?php esc_html_e( 'El icono genérico (una tienda de campaña morada).', 'asde-area-privada' ); ?>
								<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=title_tagline' ) ); ?>"><?php esc_html_e( 'Poner el icono del grupo (imagen cuadrada de 512 × 512 px)', 'asde-area-privada' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Color', 'asde-area-privada' ); ?></th>
						<td>
							<span style="display:inline-block;width:16px;height:16px;border-radius:50%;vertical-align:middle;border:1px solid rgba(0,0,0,.25);background:<?php echo esc_attr( $colores['primary'] ); ?>"></span>
							<code><?php echo esc_html( $colores['primary'] ); ?></code>
							<?php esc_html_e( '(el color principal del área privada: barra del móvil y pantalla «Sin conexión»)', 'asde-area-privada' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . ColoresPage::SLUG ) ); ?>"><?php esc_html_e( 'Cambiar los colores', 'asde-area-privada' ); ?></a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Alcance', 'asde-area-privada' ); ?></th>
						<td><code><?php echo esc_html( Manifiesto::alcance() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Direcciones de la app', 'asde-area-privada' ); ?></th>
						<td>
							<?php foreach ( Comprobacion::rutas() as $ruta => list( , $nombre ) ) : ?>
								<?php echo esc_html( $nombre ); ?>: <a href="<?php echo esc_url( home_url( '/' . $ruta ) ); ?>" target="_blank" rel="noopener"><code>/<?php echo esc_html( $ruta ); ?></code></a><br>
							<?php endforeach; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2 id="asde-pwa-servidor"><?php esc_html_e( 'Comprobar el servidor', 'asde-area-privada' ); ?></h2>
			<p><?php esc_html_e( 'El sitio se pide a sí mismo las tres direcciones de la app y comprueba que responden bien y que ninguna caché (LiteSpeed Cache, un CDN…) las guarda ni las cambia. Si alguna sale cacheada, los móviles no se enterarían de las actualizaciones. No cambia nada del sitio.', 'asde-area-privada' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::COMPROBAR ); ?>">
				<?php wp_nonce_field( self::COMPROBAR ); ?>
				<?php submit_button( __( 'Comprobar el servidor', 'asde-area-privada' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $ultimo ) : ?>
				<p><em><?php echo esc_html( sprintf( 'Última comprobación: %s.', wp_date( 'j/n/Y H:i', (int) $ultimo['hora'] ) ) ); ?></em></p>
				<?php if ( ! $ultimo['activa'] ) : ?>
					<p><?php esc_html_e( 'La app estaba apagada: no hay nada que comprobar.', 'asde-area-privada' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width: 880px">
						<tbody>
							<?php foreach ( $ultimo['filas'] as $ruta => $fila ) : ?>
								<tr>
									<td style="width: 110px"><?php echo esc_html( $etiqueta[ $fila[0] ] ?? $fila[0] ); ?></td>
									<td><strong><?php echo esc_html( $fila[1] ); ?></strong> <code>/<?php echo esc_html( $ruta ); ?></code><br><?php echo esc_html( $fila[2] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
