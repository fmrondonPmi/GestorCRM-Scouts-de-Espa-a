<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Oficina;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Usuarios\MigracionUsuario;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Organización federada: todo lo que antes era
 * propio de Scouts de Balears (nombre en los textos, código del nº de socio
 * local y coste federativo por defecto, con sus otros conceptos).
 */
final class OrganizacionPage {

	public const SLUG        = 'asde-area-privada-organizacion';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	private const ACCION     = 'asde_ap_organizacion';
	private const ACCION_MIGRAR = 'asde_ap_migrar_usuarios';
	private const AVISO      = 'asde_ap_organizacion_aviso_';
	private const ACCION_OFICINA = 'asde_ap_oficina_credencial';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_guardar' ) );
		add_action( 'admin_post_' . self::ACCION_MIGRAR, array( $this, 'handle_migrar' ) );
		add_action( 'admin_post_' . self::ACCION_OFICINA, array( $this, 'handle_oficina' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}

	/** Credencial del CRM de la oficina federal (v0.50, doc 50 fase 2): guardar o probar. La contraseña no se vuelve a mostrar. */
	public function handle_oficina(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION_OFICINA );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! Organizacion::es_oficina() ) {
			$this->aviso( 'error', 'La credencial de la oficina solo se guarda en una web de tipo «La oficina federal».' );
		} elseif ( isset( $post['probar'] ) ) {
			$r = Oficina::probar();
			Logger::log( 'oficina_credencial_probada', 'sitio', '', array( 'ok' => $r->ok ) );
			$this->aviso( $r->ok ? 'success' : 'error', $r->message . ( $r->warnings ? ' ' . implode( ' ', $r->warnings ) : '' ) );
		} elseif ( isset( $post['olvidar'] ) ) {
			Oficina::olvidar_credencial();
			Logger::log( 'oficina_credencial_borrada', 'sitio', '' );
			$this->aviso( 'success', 'Credencial de la oficina borrada.' );
		} elseif ( Oficina::guardar( sanitize_text_field( (string) ( $post['usuario'] ?? '' ) ), (string) ( $post['clave'] ?? '' ) ) ) {
			Logger::log( 'oficina_credencial_guardada', 'sitio', '', array( 'usuario' => true ) ); // Solo que se guardó: ni usuario ni contraseña.
			$this->aviso( 'success', 'Credencial de la oficina guardada. Pulsa «Probar la conexión».' );
		} else {
			$this->aviso( 'error', 'No se ha guardado: escribe el usuario y, la primera vez, la contraseña.' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Renombra las cuentas existentes al usuario de acceso elegido (v0.14, doc 27). */
	public function handle_migrar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION_MIGRAR );
		if ( empty( $_POST['confirmar_migracion'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->aviso( 'error', 'No se ha cambiado ningún usuario: marca la casilla de confirmación (se cerrarán las sesiones abiertas de esas cuentas).' );
		} else {
			$r = MigracionUsuario::aplicar();
			$this->aviso(
				$r['fallidos'] ? 'error' : 'success',
				sprintf( 'Usuarios renombrados: %1$d%2$s. Sus sesiones se han cerrado; entran con su nuevo usuario (o con el de siempre).', $r['hechos'], $r['fallidos'] ? sprintf( ' (%d no se han podido cambiar)', $r['fallidos'] ) : '' )
			);
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Federated organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Federated organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function scripts( string $pantalla ): void {
		if ( str_ends_with( $pantalla, self::SLUG ) ) {
			wp_enqueue_script( 'asde-conceptos', plugins_url( 'assets/js/conceptos.js', ASDE_AP_FILE ), array(), ASDE_AP_VERSION, true );
		}
	}

	public function handle_guardar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$post         = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$organizacion = sanitize_key( (string) ( $post['organizacion'] ?? '' ) );
		$cambia       = false; // v0.56: el nº de socio/a ya no lleva un código de la organización: sale de la provincia de cada grupo (Datos del grupo).

		{
			$identificador = isset( $post['identificador'] ) ? sanitize_key( (string) $post['identificador'] ) : null;
			$tipo_antes    = Organizacion::tipo_instalacion();
			if ( isset( $post['tipo_instalacion'] ) ) {
				Organizacion::guardar_tipo( sanitize_key( (string) $post['tipo_instalacion'] ) ); // v0.44: Grupo u Oficina (doc 50).
			}
			$cambia_ident  = null !== $identificador && $identificador !== Organizacion::identificador();
			$r             = Organizacion::guardar( $organizacion, '', (array) ( $post['federacion'] ?? array() ), $identificador );
			if ( ! is_wp_error( $r ) && isset( $post['manual'] ) ) {
				$r = Organizacion::guardar_manual( (string) $post['manual'] ); // v0.26: el manual publicado (ayuda de cada página).
			}
			if ( is_wp_error( $r ) ) {
				$this->aviso( 'error', $r->get_error_message() );
			} else {
				if ( $cambia ) {
					// El análisis de los números guardado de cada grupo usaba el código anterior.
					foreach ( array_keys( Grupos::todos() ) as $grupo ) {
						delete_transient( 'asde_ap_numeracion_' . $grupo );
					}
				}
				Logger::log(
					'organizacion_guardada',
					'sitio',
					'',
					array(
						'organizacion'    => $organizacion,
						'codigo_cambiado' => $cambia,
							'identificador'   => Organizacion::identificador(),
							'tipo'            => Organizacion::tipo_instalacion(),
							'tipo_cambiado'   => $tipo_antes !== Organizacion::tipo_instalacion(),
						'conceptos'       => count( Organizacion::federacion()['conceptos'] ),
					)
				);
				$this->aviso(
						'success',
						sprintf( 'Guardado: %s.', Organizacion::nombre() )
						. ( $cambia_ident ? ' El usuario de acceso de las cuentas NUEVAS es ahora el ' . Identidad::etiqueta() . '; para pasar las que ya existen, usa «Renombrar las cuentas existentes» (más abajo).' : '' )
					);
			}
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
		$datos      = Organizacion::datos();
		$fed        = $datos['federacion'];
		$categorias = Organizacion::categorias_seguro( (int) $fed['edad_corte'] );
		$grupo      = (string) ( array_key_first( Grupos::todos() ) ?? '101' ); // Solo para el ejemplo.
		?>
		<div class="wrap asde-organizacion">
			<h1><?php esc_html_e( 'Federated organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h1>
			<p><?php esc_html_e( 'What depends on the Scouts de España federated organisation that uses the system: its name (it appears in the private area\'s texts) and the federation cost each round starts with in the groups\' Treasury. The format of the local member number is set by each group with its province in "Group details" (in the private area).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $aviso[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! Organizacion::configurada() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The federated organisation still has to be chosen.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>

				<h2><?php esc_html_e( 'Organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-org"><?php esc_html_e( 'Federated organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<select id="asde-org" name="organizacion" required>
								<option value=""><?php esc_html_e( '— Choose your organisation —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php foreach ( Organizacion::FEDERADAS as $clave => list( $nombre, $territorio ) ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $datos['organizacion'], $clave ); ?>><?php echo esc_html( $nombre . ' · ' . $territorio ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'The 17 federated organisations of Scouts de España (scout.es). Its name appears in the texts: on the sign-in screen ("Private area of the groups of …") and in the federation cost.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
								<?php if ( Organizacion::web() ) : ?>
									<br><?php esc_html_e( 'Website:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( Organizacion::web() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Organizacion::web() ); ?></a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Local member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'There is no organisation code any more: the local member no. is province (2 digits, with its leading 0) + group no. + 4 digits, and each group chooses its province in "Group details" (private area).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'User manual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-manual"><?php esc_html_e( 'Manual with screenshots (extra help)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td>
							<input type="url" id="asde-manual" name="manual" value="<?php echo esc_attr( Organizacion::manual() ); ?>" class="regular-text code" placeholder="https://…/manual/">
							<p class="description"><?php esc_html_e( 'The help for each page and the full manual for each role come inside the plugin, text only, and open in the private area window. Here goes the folder (or the front page, …/manual/index.html) where you publish the manual with screenshots, the "manual-de-uso" zip, which is uploaded by hand: it appears at the end of the help as "Manual with screenshots", in another tab. Empty: no such link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Installation type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'This website is that of…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<td>
							<?php $tipo = Organizacion::tipo_instalacion(); ?>
							<fieldset>
								<label><input type="radio" name="tipo_instalacion" value="<?php echo esc_attr( Organizacion::TIPO_GRUPO ); ?>" <?php checked( $tipo, Organizacion::TIPO_GRUPO ); ?> <?php disabled( null !== Organizacion::tipo_fijado_en_codigo() ); ?>> <?php esc_html_e( 'A group (or several groups)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><br>
								<label><input type="radio" name="tipo_instalacion" value="<?php echo esc_attr( Organizacion::TIPO_OFICINA ); ?>" <?php checked( $tipo, Organizacion::TIPO_OFICINA ); ?> <?php disabled( null !== Organizacion::tipo_fijado_en_codigo() ); ?>> <?php esc_html_e( 'The federal office', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Changing it does not delete any data. On an office website the invitations and group registrations appear, and the office can view the groups read-only.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
							<?php if ( null !== Organizacion::tipo_fijado_en_codigo() ) : ?>
								<p class="description"><?php esc_html_e( 'It is set by the ASDE_AP_MODO constant in wp-config.php: it cannot be changed from here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sign-in username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'People\'s username is their…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<td>
							<?php $ident = $datos['identificador']; ?>
							<fieldset>
								<label><input type="radio" name="identificador" value="<?php echo esc_attr( Organizacion::IDENTIFICADOR_ASDE ); ?>" <?php checked( Organizacion::configurada() ? $ident : Organizacion::IDENTIFICADOR_ASDE, Organizacion::IDENTIFICADOR_ASDE ); ?>> <strong><?php esc_html_e( 'Scouts de España member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( '(recommended: it always exists and is unique; e.g. 118241 and, for families, 118241T)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><br>
								<label><input type="radio" name="identificador" value="<?php echo esc_attr( Organizacion::IDENTIFICADOR_LOCAL ); ?>" <?php checked( Organizacion::configurada() ? $ident : Organizacion::IDENTIFICADOR_ASDE, Organizacion::IDENTIFICADOR_LOCAL ); ?>> <strong><?php esc_html_e( 'local/territorial member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( '(the usual one; e.g. 76840397; it must be kept correct in the CRM)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Both numbers work when signing in. The territorial no. is still monitored (old formats, duplicates, invalid ones), can be corrected from People and each person can see it on all screens with "Show territorial no."; if it has a problem, the notice always appears. Changing this only affects accounts created from now on: to convert the existing ones, use "Rename existing accounts" below.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Default federation cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<p><?php esc_html_e( 'What each group pays the organisation for its active members. It is what each round starts with in the groups\' Treasury (Treasury → Federation cost), which they can adjust. Changing it here does not touch rounds a group has already saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-o-cuota"><?php esc_html_e( 'Association fee per member (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td><input type="number" id="asde-o-cuota" name="federacion[cuota_asociativa]" step="0.01" min="0" value="<?php echo esc_attr( $fed['cuota_asociativa'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-o-medio"><?php esc_html_e( 'Average insurance per member (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td><input type="number" id="asde-o-medio" name="federacion[seguro_promedio]" step="0.01" min="0" value="<?php echo esc_attr( $fed['seguro_promedio'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-o-corte"><?php esc_html_e( 'Insurance cut-off age (years)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
						<td><input type="number" id="asde-o-corte" name="federacion[edad_corte]" min="1" max="30" value="<?php echo esc_attr( $fed['edad_corte'] ); ?>" class="small-text"><p class="description"><?php esc_html_e( 'It separates the two premiums for youth members (at Scouts de Balears, 14).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></td>
					</tr>
					<?php foreach ( $categorias as $cat => $label ) : ?>
						<tr>
							<th scope="row"><label for="asde-o-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( sprintf( 'Prima: %s (€)', mb_strtolower( $label ) ) ); ?></label></th>
							<td><input type="number" id="asde-o-<?php echo esc_attr( $cat ); ?>" name="federacion[primas][<?php echo esc_attr( $cat ); ?>]" step="0.01" min="0" value="<?php echo esc_attr( $fed['primas'][ $cat ] ); ?>" class="small-text"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Any other amount the organisation charges: per member, per youth member, per adult or once per group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
							<?php
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla propia del plugin: cada valor se escapa dentro de ella.
							echo wp_kses(
								TemplateLoader::render(
								'comun/conceptos',
								array(
									'conceptos' => $fed['conceptos'],
									'campo'     => 'federacion[conceptos]',
									'editable'  => true,
								)
								),
								asde_html_permitido()
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save the organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
			</form>

			<?php if ( Organizacion::es_oficina() ) : ?>
				<?php $of = Oficina::datos(); ?>
				<h2><?php esc_html_e( 'Office CRM credential', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
				<p class="description"><?php esc_html_e( 'With this credential the office staff view the groups (one at a time) read-only. Ask the office for a CRM user that can only read. The password is stored encrypted and is not shown again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_OFICINA ); ?>">
					<?php wp_nonce_field( self::ACCION_OFICINA ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="asde-of-usuario"><?php esc_html_e( 'CRM username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
							<td><input type="text" id="asde-of-usuario" name="usuario" value="<?php echo esc_attr( $of['usuario'] ); ?>" class="regular-text" autocomplete="off"></td>
						</tr>
						<tr>
							<th scope="row"><label for="asde-of-clave"><?php esc_html_e( 'Password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></th>
							<td>
								<input type="password" id="asde-of-clave" name="clave" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $of['tiene_clave'] ? '••••••••' : '' ); ?>">
								<p class="description"><?php echo esc_html( $of['tiene_clave'] ? __( 'Saved. Leave it empty to keep it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'None has been saved yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
							</td>
						</tr>
						<?php if ( $of['probado'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last test', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
								<td><?php echo esc_html( wp_date( 'd/m/Y H:i', $of['probado'] ) . ' · ' . $of['mensaje'] ); ?></td>
							</tr>
						<?php endif; ?>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the credential', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
						<button type="submit" name="probar" value="1" class="button" <?php disabled( ! $of['tiene_clave'] ); ?>><?php esc_html_e( 'Test the connection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
						<button type="submit" name="olvidar" value="1" class="button" <?php disabled( ! $of['tiene_clave'] ); ?>><?php esc_html_e( 'Delete the credential', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
				</form>
			<?php endif; ?>

			<?php $plan = MigracionUsuario::plan(); ?>
			<h2><?php esc_html_e( 'Rename existing accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: type of number, 2: accounts already using it, 3: accounts to change, 4: accounts that cannot be changed */
						__( 'Sign-in username saved: %1$s. Accounts already using it: %2$d. Accounts that would change: %3$d. Accounts that cannot be changed: %4$d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
						Identidad::etiqueta(),
						$plan['iguales'],
						count( $plan['cambios'] ),
						count( $plan['imposibles'] )
					)
				);
				?>
			</p>
			<?php if ( $plan['cambios'] ) : ?>
				<details>
					<summary><?php esc_html_e( 'See what would change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<table class="widefat striped" style="max-width:520px"><thead><tr><th><?php esc_html_e( 'Current username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th><?php esc_html_e( 'New username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead><tbody>
						<?php foreach ( array_slice( $plan['cambios'], 0, 300 ) as $c ) : ?>
							<tr><td><?php echo esc_html( $c['actual'] ); ?></td><td><?php echo esc_html( $c['nuevo'] ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
				</details>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_MIGRAR ); ?>">
					<?php wp_nonce_field( self::ACCION_MIGRAR ); ?>
					<p class="asde-organizacion__cuidado"><label><input type="checkbox" name="confirmar_migracion" value="1"> <?php esc_html_e( 'I understand that the open sessions of those accounts will be closed (they will sign in again with their new username or the usual one) and that pending "I forgot my password" links will stop working.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label></p>
					<?php submit_button( __( 'Rename existing accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
			<?php if ( $plan['imposibles'] ) : ?>
				<details>
					<summary><?php esc_html_e( 'Accounts that cannot be changed (they keep signing in as before)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<ul>
						<?php foreach ( array_slice( $plan['imposibles'], 0, 300 ) as $i ) : ?>
							<li><strong><?php echo esc_html( $i['actual'] ); ?></strong>: <?php echo esc_html( $i['motivo'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}
}
