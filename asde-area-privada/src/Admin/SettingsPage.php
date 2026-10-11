<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Administración del plugin (solo administradores, manage_options):
 * - URL del CRM, común para todos los grupos;
 * - Grupos Scouts: alta, edición y baja manual (nombre, nº y usuario y
 *   contraseña del CRM de cada grupo) y prueba de la conexión.
 */
final class SettingsPage {

	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'asde-area-privada';
	private const GROUP          = 'asde_ap_crm_group';
	private const ACCION_GUARDAR = 'asde_ap_grupo_guardar';
	private const ACCION_PROBAR  = 'asde_ap_grupo_probar';
	private const ACCION_BORRAR  = 'asde_ap_grupo_borrar';
	private const AVISO          = 'asde_ap_admin_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::ACCION_GUARDAR, array( $this, 'handle_guardar' ) );
		add_action( 'admin_post_' . self::ACCION_PROBAR, array( $this, 'handle_probar' ) );
		add_action( 'admin_post_' . self::ACCION_BORRAR, array( $this, 'handle_borrar' ) );
		add_filter( 'option_page_capability_' . self::GROUP, static fn() => self::CAPABILITY );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Gestor CRM para Grupos Scouts', 'asde-area-privada' ),
			__( 'Grupos Scouts', 'asde-area-privada' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Grupos y conexión CRM', 'asde-area-privada' ),
			__( 'Grupos y conexión CRM', 'asde-area-privada' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => array(),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Acciones (admin-post.php)
	// ---------------------------------------------------------------------

	public function handle_guardar(): void {
		$this->comprobar( self::ACCION_GUARDAR );
		$es_alta = '1' === $this->post( 'alta' );
		$numero  = $this->post( 'numero' );
		$clave   = isset( $_POST['clave'] ) ? (string) wp_unslash( $_POST['clave'] ) : ''; // phpcs:ignore WordPress.Security
		$r       = Grupos::guardar( $numero, $this->post( 'nombre' ), $this->post( 'usuario' ), '' === $clave ? null : $clave, $es_alta );

		if ( is_wp_error( $r ) ) {
			$this->aviso( 'error', $r->get_error_message() );
			$this->volver( $es_alta ? array( 'nuevo' => '1' ) : array( 'editar' => $numero ) );
		}
		Grupos::fijar_email_contacto( $r['numero'], $this->post( 'email_contacto' ) );
		Logger::log( $es_alta ? 'grupo_alta' : 'grupo_editado', 'grupo', $r['numero'], array( 'nombre' => $r['nombre'] ) );

		$texto = sprintf( $es_alta ? 'Grupo %s dado de alta.' : 'Grupo %s guardado.', Grupos::etiqueta( $r ) );
		if ( Grupos::SIN_PROBAR === $r['estado'] && '' !== $r['usuario'] && Grupos::tiene_clave( $r['numero'] ) ) {
			$res   = Grupos::verificar( $r['numero'] );
			$texto .= ' ' . $res->message;
			$this->aviso( $res->ok ? ( $res->warnings ? 'warning' : 'success' ) : 'error', $texto );
		} else {
			$this->aviso( 'success', $texto );
		}
		$this->volver();
	}

	public function handle_probar(): void {
		$this->comprobar( self::ACCION_PROBAR );
		$numero = $this->post( 'numero' );
		$res    = Grupos::verificar( $numero );
		$this->aviso( $res->ok ? ( $res->warnings ? 'warning' : 'success' ) : 'error', sprintf( 'Grupo %s: %s', $numero, $res->message ) );
		$this->volver();
	}

	public function handle_borrar(): void {
		$this->comprobar( self::ACCION_BORRAR );
		$numero = $this->post( 'numero' );
		$g      = Grupos::por_numero( $numero );
		if ( $g && Grupos::borrar( $numero ) ) {
			Logger::log( 'grupo_baja', 'grupo', $numero, array( 'nombre' => $g['nombre'] ) );
			$this->aviso( 'success', sprintf( 'Grupo %s dado de baja. Sus usuarios ya no pueden entrar; sus datos (usuarios, familias, pagos, informes) se conservan y vuelven si se da de alta otra vez con el mismo nº.', Grupos::etiqueta( $g ) ) );
		} else {
			$this->aviso( 'error', 'Ese grupo no existe.' );
		}
		$this->volver();
	}

	// ---------------------------------------------------------------------
	// Pantalla
	// ---------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$grupos = Grupos::todos();
		$editar = isset( $_GET['editar'] ) ? Grupos::por_numero( sanitize_text_field( wp_unslash( $_GET['editar'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$nuevo  = ! $editar && ( isset( $_GET['nuevo'] ) || ! $grupos ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap asde-ap-settings">
			<h1><?php esc_html_e( 'ASDE Scouts de España · Gestor CRM para Grupos Scouts', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Cada Grupo Scout entra en el CRM con su propio usuario. La web lee los datos del CRM y, si ese usuario tiene permiso de edición, también guarda en el CRM los cambios de las fichas y de los números de socio (según el rol de cada persona en el área privada). Nunca crea ni borra fichas.', 'asde-area-privada' ); ?></p>

			<p><a class="button" href="<?php echo esc_url( EntornoPage::url() ); ?>"><?php esc_html_e( 'Instalación y entorno', 'asde-area-privada' ); ?></a> <span class="description"><?php esc_html_e( 'Prepara un WordPress nuevo (páginas, menú, roles, ajustes) y comprueba que todo está en orden.', 'asde-area-privada' ); ?></span></p>

			<?php settings_errors(); ?>
			<?php $this->render_aviso(); ?>

			<h2><?php esc_html_e( 'Conexión con SinergiaCRM', 'asde-area-privada' ); ?></h2>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-url"><?php esc_html_e( 'URL del CRM (común a todos los grupos)', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="url" id="asde-url" name="<?php echo esc_attr( Settings::OPTION ); ?>[url]" value="<?php echo esc_attr( Settings::url() ); ?>" class="regular-text" placeholder="https://entidad.sinergiacrm.org" <?php echo Settings::url_from_constant() ? 'readonly' : ''; ?>>
							<p class="description"><?php esc_html_e( 'Dirección base del CRM, sin rutas. Si pegas la URL completa de rest.php se recorta automáticamente.', 'asde-area-privada' ); ?></p>
							<?php if ( Settings::url_from_constant() ) : ?>
								<p class="description"><?php /* translators: %s: nombre de la constante definida en wp-config.php */ echo wp_kses_post( sprintf( __( 'Definida en wp-config.php (%s); no se puede cambiar aquí.', 'asde-area-privada' ), '<code>' . Settings::CONSTANT_URL . '</code>' ) ); ?></p>
							<?php endif; ?>
							<?php if ( str_starts_with( Settings::url(), 'http://' ) ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Atención: la URL no usa HTTPS. Las credenciales viajarían sin cifrar.', 'asde-area-privada' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar URL', 'asde-area-privada' ), 'primary', 'submit', true, Settings::url_from_constant() ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>

			<hr>
			<h2 class="wp-heading-inline"><?php esc_html_e( 'Grupos Scouts', 'asde-area-privada' ); ?></h2>
			<?php if ( ! $nuevo && ! $editar ) : ?>
				<a href="<?php echo esc_url( $this->url( array( 'nuevo' => '1' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Añadir grupo', 'asde-area-privada' ); ?></a>
			<?php endif; ?>

			<?php if ( $grupos ) : ?>
				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Nº', 'asde-area-privada' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Nombre', 'asde-area-privada' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Usuario del CRM', 'asde-area-privada' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Conexión', 'asde-area-privada' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Acciones', 'asde-area-privada' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $grupos as $g ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $g['numero'] ); ?></strong></td>
								<td><?php echo esc_html( $g['nombre'] ); ?></td>
								<td>
									<?php echo '' !== $g['usuario'] ? esc_html( $g['usuario'] ) : '<em>' . esc_html__( 'sin usuario', 'asde-area-privada' ) . '</em>'; ?>
									<br><span class="description"><?php echo Grupos::tiene_clave( $g['numero'] ) ? esc_html__( 'Contraseña guardada (cifrada)', 'asde-area-privada' ) : esc_html__( 'Sin contraseña', 'asde-area-privada' ); ?></span>
								</td>
								<td><?php $this->render_estado( $g ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $this->url( array( 'editar' => $g['numero'] ) ) ); ?>"><?php esc_html_e( 'Editar', 'asde-area-privada' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_PROBAR ); ?>">
										<input type="hidden" name="numero" value="<?php echo esc_attr( $g['numero'] ); ?>">
										<?php wp_nonce_field( self::ACCION_PROBAR ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Probar conexión', 'asde-area-privada' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" data-asde-confirmar="<?php /* translators: %s: nombre del grupo */ echo esc_attr( sprintf( __( '¿Dar de baja el grupo %s? Sus usuarios dejarán de poder entrar. Sus datos se conservan.', 'asde-area-privada' ), Grupos::etiqueta( $g ) ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_BORRAR ); ?>">
										<input type="hidden" name="numero" value="<?php echo esc_attr( $g['numero'] ); ?>">
										<?php wp_nonce_field( self::ACCION_BORRAR ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Dar de baja', 'asde-area-privada' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Todavía no hay ningún grupo. Da de alta el primero: sin grupos nadie puede entrar al área privada.', 'asde-area-privada' ); ?></p>
			<?php endif; ?>

			<?php if ( $nuevo || $editar ) : ?>
				<?php $this->render_formulario( $editar ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tareas en segundo plano', 'asde-area-privada' ); ?></h2>
			<p><?php esc_html_e( 'Las correcciones masivas de números de socio trabajan en segundo plano, con un monitor en el área privada. Comprueba que este hosting lo permite (en LiteSpeed puede hacer falta «noabort» en el .htaccess):', 'asde-area-privada' ); ?>
				<a class="button" href="<?php echo esc_url( \Asde_Diagnostico_Segundo_Plano::url() ); ?>"><?php esc_html_e( 'Diagnóstico de tareas en segundo plano', 'asde-area-privada' ); ?></a>
			</p>

			<hr>
			<details>
				<summary><?php esc_html_e( 'Seguridad y configuración avanzada', 'asde-area-privada' ); ?></summary>
				<ul style="list-style:disc;margin-left:20px;">
					<li><?php esc_html_e( 'Las contraseñas del CRM se guardan cifradas (libsodium) y nunca se vuelven a mostrar.', 'asde-area-privada' ); ?></li>
					<li><?php esc_html_e( 'Usa en cada grupo un usuario del CRM dedicado a la web, que vea solo su grupo. Con permiso de edición en Contactos, el área privada permite editar las fichas; con un rol de solo lectura, todo se muestra en solo lectura.', 'asde-area-privada' ); ?></li>
					<li><?php esc_html_e( 'Al probar la conexión se busca el grupo en el CRM. Sin él, el grupo no lee datos; con él, todas las consultas se filtran por ese grupo.', 'asde-area-privada' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'En producción se recomienda fijar en wp-config.php:', 'asde-area-privada' ); ?></p>
				<pre style="background:#f6f7f7;padding:12px;">define( 'ASDE_CRM_URL', 'https://entidad.sinergiacrm.org' );
define( 'ASDE_AP_ENCRYPTION_KEY', '...' ); // clave propia para cifrar las contraseñas guardadas</pre>
				<p class="description"><?php esc_html_e( 'Si cambia la clave de cifrado (o las salts de WordPress, si no hay clave propia), hay que volver a escribir las contraseñas de los grupos.', 'asde-area-privada' ); ?></p>
			</details>
		</div>
		<?php
	}

	private function render_formulario( ?array $g ): void {
		$alta = null === $g;
		?>
		<div class="card" style="max-width:720px;margin-top:20px;">
			<h2><?php /* translators: %s: número del grupo */ echo $alta ? esc_html__( 'Añadir grupo', 'asde-area-privada' ) : esc_html( sprintf( __( 'Editar el grupo %s', 'asde-area-privada' ), $g['numero'] ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_GUARDAR ); ?>">
				<input type="hidden" name="alta" value="<?php echo $alta ? '1' : '0'; ?>">
				<?php wp_nonce_field( self::ACCION_GUARDAR ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-g-numero"><?php esc_html_e( 'Nº de grupo', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="text" id="asde-g-numero" name="numero" value="<?php echo esc_attr( $g['numero'] ?? '' ); ?>" class="small-text" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" required <?php echo $alta ? '' : 'readonly'; ?>>
							<p class="description"><?php echo $alta ? esc_html__( 'Hasta 3 cifras (p. ej. 684). Es la clave de todo lo del grupo y no se puede cambiar después.', 'asde-area-privada' ) : esc_html__( 'El nº no se puede cambiar: para usar otro, da de baja el grupo y añádelo de nuevo.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-nombre"><?php esc_html_e( 'Nombre', 'asde-area-privada' ); ?></label></th>
						<td><input type="text" id="asde-g-nombre" name="nombre" value="<?php echo esc_attr( $g['nombre'] ?? '' ); ?>" class="regular-text" placeholder="Grupo Scout Myotragus" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-email"><?php esc_html_e( 'Email de contacto del grupo', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="email" id="asde-g-email" name="email_contacto" value="<?php echo esc_attr( $g['email_contacto'] ?? '' ); ?>" class="regular-text" placeholder="grupo@example.invalid">
							<p class="description"><?php esc_html_e( 'Se enseña en «Solicitar acceso» cuando los datos que escriben no se pueden comprobar, para que pidan el acceso por email.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-usuario"><?php esc_html_e( 'Usuario del CRM', 'asde-area-privada' ); ?></label></th>
						<td><input type="text" id="asde-g-usuario" name="usuario" value="<?php echo esc_attr( $g['usuario'] ?? '' ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-clave"><?php esc_html_e( 'Contraseña del CRM', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="password" id="asde-g-clave" name="clave" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! $alta && Grupos::tiene_clave( $g['numero'] ) ? esc_attr__( '•••••••• (guardada; déjalo vacío para no cambiarla)', 'asde-area-privada' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Al guardar con usuario y contraseña se prueba la conexión automáticamente.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $alta ? __( 'Dar de alta el grupo', 'asde-area-privada' ) : __( 'Guardar cambios', 'asde-area-privada' ), 'primary', 'submit', false ); ?>
				<a href="<?php echo esc_url( $this->url() ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'asde-area-privada' ); ?></a>
			</form>
		</div>
		<?php
	}

	private function render_estado( array $g ): void {
		$icono = array(
			Grupos::OK         => '<span style="color:#00a32a;">●</span> ' . esc_html__( 'Conectado', 'asde-area-privada' ),
			Grupos::ERROR      => '<span style="color:#d63638;">●</span> ' . esc_html__( 'Error', 'asde-area-privada' ),
			Grupos::SIN_PROBAR => '<span style="color:#dba617;">●</span> ' . esc_html__( 'Sin probar', 'asde-area-privada' ),
		);
		echo wp_kses_post( $icono[ $g['estado'] ] ?? $icono[ Grupos::SIN_PROBAR ] );
		if ( '' === $g['crm_id'] ) {
			echo '<br><strong style="color:#d63638;">' . esc_html__( 'No lee datos del CRM hasta que la prueba encuentre el grupo.', 'asde-area-privada' ) . '</strong>';
		}
		if ( '' !== $g['mensaje'] ) {
			echo '<br><span class="description">' . esc_html( $g['mensaje'] ) . '</span>';
		}
		foreach ( $g['avisos'] as $aviso ) {
			echo '<br><span class="description">⚠️ ' . esc_html( $aviso ) . '</span>';
		}
		if ( $g['probado'] ) {
			/* translators: %s: fecha y hora de la última prueba de conexión */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Probado el %s', 'asde-area-privada' ), wp_date( 'd/m/Y H:i', (int) $g['probado'] ) ) ) . '</span>';
		}
	}

	// ---------------------------------------------------------------------

	private function comprobar( string $accion ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( $accion );
	}

	private function post( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security
	}

	private function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/** @return never */
	private function volver( array $args = array() ): void {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	private function render_aviso(): void {
		$a = get_transient( self::AVISO . get_current_user_id() );
		if ( ! is_array( $a ) ) {
			return;
		}
		delete_transient( self::AVISO . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $a[0] ), esc_html( $a[1] ) );
	}
}
