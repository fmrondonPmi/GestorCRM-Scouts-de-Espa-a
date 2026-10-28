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
			__( 'CRM Manager for Scout Groups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Scout Groups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Groups and CRM connection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Groups and CRM connection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
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
			<h1><?php esc_html_e( 'ASDE Scouts de España · CRM Manager for Scout Groups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h1>
			<p><?php esc_html_e( 'Each Scout Group signs in to the CRM with its own user. The website reads the data from the CRM and, if that user has edit permission, also saves in the CRM the changes to records and member numbers (according to each person\'s role in the private area). It never creates or deletes records.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

			<p><a class="button" href="<?php echo esc_url( EntornoPage::url() ); ?>"><?php esc_html_e( 'Installation and environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a> <span class="description"><?php esc_html_e( 'Prepares a new WordPress (pages, menu, roles, settings) and checks that everything is in order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>

			<?php settings_errors(); ?>
			<?php $this->render_aviso(); ?>

			<h2><?php esc_html_e( 'Connection to SinergiaCRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-url"><?php esc_html_e( 'CRM URL (common to all groups)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<input type="url" id="asde-url" name="<?php echo esc_attr( Settings::OPTION ); ?>[url]" value="<?php echo esc_attr( Settings::url() ); ?>" class="regular-text" placeholder="https://entidad.sinergiacrm.org" <?php echo Settings::url_from_constant() ? 'readonly' : ''; ?>>
							<p class="description"><?php esc_html_e( 'Base address of the CRM, without paths. If you paste the full rest.php URL it is trimmed automatically.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
							<?php if ( Settings::url_from_constant() ) : ?>
								<p class="description"><?php /* translators: %s: name of the constant defined in wp-config.php */ echo wp_kses_post( sprintf( __( 'Defined in wp-config.php (%s); it cannot be changed here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<code>' . Settings::CONSTANT_URL . '</code>' ) ); ?></p>
							<?php endif; ?>
							<?php if ( str_starts_with( Settings::url(), 'http://' ) ) : ?>
								<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Warning: the URL does not use HTTPS. The credentials would travel unencrypted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save URL', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'primary', 'submit', true, Settings::url_from_constant() ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>

			<hr>
			<h2 class="wp-heading-inline"><?php esc_html_e( 'Scout Groups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<?php if ( ! $nuevo && ! $editar ) : ?>
				<a href="<?php echo esc_url( $this->url( array( 'nuevo' => '1' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php endif; ?>

			<?php if ( $grupos ) : ?>
				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'No.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'CRM username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $grupos as $g ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $g['numero'] ); ?></strong></td>
								<td><?php echo esc_html( $g['nombre'] ); ?></td>
								<td>
									<?php echo '' !== $g['usuario'] ? esc_html( $g['usuario'] ) : '<em>' . esc_html__( 'no user', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . '</em>'; ?>
									<br><span class="description"><?php echo Grupos::tiene_clave( $g['numero'] ) ? esc_html__( 'Password saved (encrypted)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_html__( 'No password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								</td>
								<td><?php $this->render_estado( $g ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $this->url( array( 'editar' => $g['numero'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_PROBAR ); ?>">
										<input type="hidden" name="numero" value="<?php echo esc_attr( $g['numero'] ); ?>">
										<?php wp_nonce_field( self::ACCION_PROBAR ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Test connection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" data-asde-confirmar="<?php /* translators: %s: name of the group */ echo esc_attr( sprintf( __( 'Withdraw group %s? Its users will no longer be able to sign in. Its data is kept.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Grupos::etiqueta( $g ) ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_BORRAR ); ?>">
										<input type="hidden" name="numero" value="<?php echo esc_attr( $g['numero'] ); ?>">
										<?php wp_nonce_field( self::ACCION_BORRAR ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'There is no group yet. Register the first one: without groups nobody can sign in to the private area.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>

			<?php if ( $nuevo || $editar ) : ?>
				<?php $this->render_formulario( $editar ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Background tasks', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<p><?php esc_html_e( 'Mass corrections of member numbers work in the background, with a monitor in the private area. Check that this hosting allows it (on LiteSpeed "noabort" may be needed in .htaccess):', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<a class="button" href="<?php echo esc_url( \Asde_Diagnostico_Segundo_Plano::url() ); ?>"><?php esc_html_e( 'Background tasks diagnostic', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>

			<hr>
			<details>
				<summary><?php esc_html_e( 'Security and advanced settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
				<ul style="list-style:disc;margin-left:20px;">
					<li><?php esc_html_e( 'CRM passwords are stored encrypted (libsodium) and are never shown again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
					<li><?php esc_html_e( 'Use in each group a CRM user dedicated to the website, which sees only its own group. With edit permission on Contacts, the private area allows editing records; with a read-only role, everything is shown read-only.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
					<li><?php esc_html_e( 'When the connection is tested, the group is looked up in the CRM. Without it, the group reads no data; with it, all queries are filtered by that group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'In production it is recommended to set in wp-config.php:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<pre style="background:#f6f7f7;padding:12px;">define( 'ASDE_CRM_URL', 'https://entidad.sinergiacrm.org' );
define( 'ASDE_AP_ENCRYPTION_KEY', '...' ); // clave propia para cifrar las contraseñas guardadas</pre>
				<p class="description"><?php esc_html_e( 'If the encryption key changes (or the WordPress salts, if there is no key of your own), the groups\' passwords must be typed again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			</details>
		</div>
		<?php
	}

	private function render_formulario( ?array $g ): void {
		$alta = null === $g;
		?>
		<div class="card" style="max-width:720px;margin-top:20px;">
			<h2><?php /* translators: %s: group number */ echo $alta ? esc_html__( 'Add group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_html( sprintf( /* translators: %s: text */ __( 'Edit group %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $g['numero'] ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_GUARDAR ); ?>">
				<input type="hidden" name="alta" value="<?php echo $alta ? '1' : '0'; ?>">
				<?php wp_nonce_field( self::ACCION_GUARDAR ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-g-numero"><?php esc_html_e( 'Group no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<input type="text" id="asde-g-numero" name="numero" value="<?php echo esc_attr( $g['numero'] ?? '' ); ?>" class="small-text" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" required <?php echo $alta ? '' : 'readonly'; ?>>
							<p class="description"><?php echo $alta ? esc_html__( 'Up to 3 digits (e.g. 684). It is the key to everything about the group and cannot be changed afterwards.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_html__( 'The number cannot be changed: to use another, withdraw the group and add it again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td><input type="text" id="asde-g-nombre" name="nombre" value="<?php echo esc_attr( $g['nombre'] ?? '' ); ?>" class="regular-text" placeholder="Grupo Scout Myotragus" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-email"><?php esc_html_e( 'Group contact email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<input type="email" id="asde-g-email" name="email_contacto" value="<?php echo esc_attr( $g['email_contacto'] ?? '' ); ?>" class="regular-text" placeholder="grupo@example.invalid">
							<p class="description"><?php esc_html_e( 'It is shown in "Request access" when the details entered cannot be checked, so that they request access by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-usuario"><?php esc_html_e( 'CRM username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td><input type="text" id="asde-g-usuario" name="usuario" value="<?php echo esc_attr( $g['usuario'] ?? '' ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-g-clave"><?php esc_html_e( 'CRM password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<input type="password" id="asde-g-clave" name="clave" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! $alta && Grupos::tiene_clave( $g['numero'] ) ? esc_attr__( '•••••••• (saved; leave it empty to keep it)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'When saving with a username and password the connection is tested automatically.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $alta ? __( 'Register the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Save changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'primary', 'submit', false ); ?>
				<a href="<?php echo esc_url( $this->url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</form>
		</div>
		<?php
	}

	private function render_estado( array $g ): void {
		$icono = array(
			Grupos::OK         => '<span style="color:#00a32a;">●</span> ' . esc_html__( 'Connected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			Grupos::ERROR      => '<span style="color:#d63638;">●</span> ' . esc_html__( 'Error', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			Grupos::SIN_PROBAR => '<span style="color:#dba617;">●</span> ' . esc_html__( 'Not tested', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
		echo wp_kses_post( $icono[ $g['estado'] ] ?? $icono[ Grupos::SIN_PROBAR ] );
		if ( '' === $g['crm_id'] ) {
			echo '<br><strong style="color:#d63638;">' . esc_html__( 'It reads no CRM data until the test finds the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . '</strong>';
		}
		if ( '' !== $g['mensaje'] ) {
			echo '<br><span class="description">' . esc_html( $g['mensaje'] ) . '</span>';
		}
		foreach ( $g['avisos'] as $aviso ) {
			echo '<br><span class="description">⚠️ ' . esc_html( $aviso ) . '</span>';
		}
		if ( $g['probado'] ) {
			/* translators: %s: date and time of the last connection test */
			echo '<br><span class="description">' . esc_html( sprintf( /* translators: %s: text */ __( 'Tested on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y H:i', (int) $g['probado'] ) ) ) . '</span>';
		}
	}

	// ---------------------------------------------------------------------

	private function comprobar( string $accion ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
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
