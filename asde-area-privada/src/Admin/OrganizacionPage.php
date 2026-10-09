<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
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

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_guardar' ) );
		add_action( 'admin_post_' . self::ACCION_MIGRAR, array( $this, 'handle_migrar' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
	}

	/** Renombra las cuentas existentes al usuario de acceso elegido (v0.14, doc 27). */
	public function handle_migrar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
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
			__( 'Organización federada', 'asde-area-privada' ),
			__( 'Organización federada', 'asde-area-privada' ),
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
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$post         = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$organizacion = sanitize_key( (string) ( $post['organizacion'] ?? '' ) );
		$codigo       = trim( (string) ( $post['codigo'] ?? '' ) );
		$anterior     = Organizacion::codigo();
		$cambia       = '' !== $anterior && $codigo !== $anterior;

		if ( $cambia && Grupos::todos() && empty( $post['confirmar_codigo'] ) ) {
			$this->aviso( 'error', 'No se ha guardado: para cambiar el código de la organización marca la casilla de confirmación (los números de socio del CRM con el código anterior dejarán de reconocerse como del grupo).' );
		} else {
			$identificador = isset( $post['identificador'] ) ? sanitize_key( (string) $post['identificador'] ) : null;
			$cambia_ident  = null !== $identificador && $identificador !== Organizacion::identificador();
			$r             = Organizacion::guardar( $organizacion, $codigo, (array) ( $post['federacion'] ?? array() ), $identificador );
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
						'conceptos'       => count( Organizacion::federacion()['conceptos'] ),
					)
				);
				$this->aviso(
						'success',
						sprintf( 'Guardado: %s, código %s.', Organizacion::nombre(), Organizacion::codigo() )
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
			<h1><?php esc_html_e( 'Organización federada', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Lo que depende de la organización federada de Scouts de España que usa el sistema: su nombre (sale en los textos del área privada), su código en el nº de socio local y el coste federativo con el que empieza cada ronda en la Tesorería de los grupos.', 'asde-area-privada' ); ?></p>

			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $aviso[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! Organizacion::configurada() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Falta elegir la organización y su código: sin ellos no se pueden reconocer ni generar números de socio.', 'asde-area-privada' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>

				<h2><?php esc_html_e( 'Organización', 'asde-area-privada' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-org"><?php esc_html_e( 'Organización federada', 'asde-area-privada' ); ?></label></th>
						<td>
							<select id="asde-org" name="organizacion" required>
								<option value=""><?php esc_html_e( '— Elige tu organización —', 'asde-area-privada' ); ?></option>
								<?php foreach ( Organizacion::FEDERADAS as $clave => list( $nombre, $territorio ) ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $datos['organizacion'], $clave ); ?>><?php echo esc_html( $nombre . ' · ' . $territorio ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Las 17 organizaciones federadas de Scouts de España (scout.es). Su nombre sale en los textos: en la pantalla de acceso («Área privada de los grupos de …») y en el coste federativo.', 'asde-area-privada' ); ?>
								<?php if ( Organizacion::web() ) : ?>
									<br><?php esc_html_e( 'Web:', 'asde-area-privada' ); ?> <a href="<?php echo esc_url( Organizacion::web() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Organizacion::web() ); ?></a>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-codigo"><?php esc_html_e( 'Código en el nº de socio local', 'asde-area-privada' ); ?></label></th>
						<td>
							<input type="text" id="asde-codigo" name="codigo" value="<?php echo esc_attr( $datos['codigo'] ); ?>" class="small-text" inputmode="numeric" pattern="[1-9][0-9]{0,2}" maxlength="3" required>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: ejemplo de números con el código y un grupo */
										__( 'Primera parte del nº de socio/a local: código + nº de grupo + 4 cifras. En Scouts de Balears es 7. %s', 'asde-area-privada' ),
										'' !== $datos['codigo'] ? sprintf( 'Con este código, en el grupo %1$s: %2$s, %3$s…', $grupo, NumeroSocio::formatear( $grupo, 1 ), NumeroSocio::formatear( $grupo, 2 ) ) : ''
									)
								);
								?>
							</p>
							<?php if ( '' !== $datos['codigo'] && Grupos::todos() ) : ?>
								<p class="asde-organizacion__cuidado">
									<label><input type="checkbox" name="confirmar_codigo" value="1"> <?php esc_html_e( 'Si cambio el código, entiendo que los números de socio del CRM con el código anterior dejarán de reconocerse como del grupo (habrá que corregirlos en el CRM y, mientras, esas personas no se pueden dar de alta). Los usuarios ya creados siguen entrando con su usuario de siempre o con su email.', 'asde-area-privada' ); ?></label>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Manual de uso', 'asde-area-privada' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-manual"><?php esc_html_e( 'Otro manual publicado (opcional)', 'asde-area-privada' ); ?></label></th>
						<td>
							<?php /* v0.27: el manual viene dentro del plugin; el campo solo guarda otra dirección, si se quiere. */ ?>
							<input type="url" id="asde-manual" name="manual" value="<?php echo esc_attr( Organizacion::manual_guardado() ); ?>" class="regular-text code" placeholder="<?php esc_attr_e( 'Vacío: el manual que trae el plugin', 'asde-area-privada' ); ?>">
							<p class="description"><?php esc_html_e( 'En cada página del área privada, «Ayuda» enseña el apartado del manual del rol de quien entra, con sus capturas, y al final enlaza a su manual completo. El manual viene dentro del plugin y es siempre de su misma versión: no hay que subirlo aparte. Déjalo vacío salvo que quieras usar un manual publicado en otro sitio: entonces, escribe aquí su carpeta (o su portada, …/manual/index.html).', 'asde-area-privada' ); ?></p>
							<?php if ( '' === Organizacion::manual_incluido() ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Esta copia del plugin no trae el manual (carpeta manual/): la ayuda sale sin capturas ni enlaces mientras no se ponga aquí la dirección de uno publicado.', 'asde-area-privada' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Usuario de acceso', 'asde-area-privada' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'El usuario de las personas es su…', 'asde-area-privada' ); ?></th>
						<td>
							<?php $ident = $datos['identificador']; ?>
							<fieldset>
								<label><input type="radio" name="identificador" value="<?php echo esc_attr( Organizacion::IDENTIFICADOR_ASDE ); ?>" <?php checked( Organizacion::configurada() ? $ident : Organizacion::IDENTIFICADOR_ASDE, Organizacion::IDENTIFICADOR_ASDE ); ?>> <strong><?php esc_html_e( 'nº de socio/a de Scouts de España', 'asde-area-privada' ); ?></strong> <?php esc_html_e( '(recomendado: siempre existe y es único; p. ej. 118241 y, para las familias, 118241T)', 'asde-area-privada' ); ?></label><br>
								<label><input type="radio" name="identificador" value="<?php echo esc_attr( Organizacion::IDENTIFICADOR_LOCAL ); ?>" <?php checked( Organizacion::configurada() ? $ident : Organizacion::IDENTIFICADOR_ASDE, Organizacion::IDENTIFICADOR_LOCAL ); ?>> <strong><?php esc_html_e( 'nº de socio/a local/territorial', 'asde-area-privada' ); ?></strong> <?php esc_html_e( '(el de siempre; p. ej. 76840397; hay que mantenerlo correcto en el CRM)', 'asde-area-privada' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Al entrar valen los dos números. El nº territorial se sigue vigilando (formatos antiguos, duplicados, inválidos), se puede corregir desde Personas y cada persona puede verlo en todas las pantallas con «Mostrar nº territorial»; si tiene un problema, el aviso sale siempre. Cambiar esto solo afecta a las cuentas que se creen a partir de ahora: para pasar las que ya existen, usa «Renombrar las cuentas existentes» debajo.', 'asde-area-privada' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Coste federativo por defecto', 'asde-area-privada' ); ?></h2>
				<p><?php esc_html_e( 'Lo que cada grupo paga a la organización por sus asociados activos. Es con lo que empieza cada ronda en la Tesorería de los grupos (Tesorería → Coste federativo), que lo pueden ajustar. Cambiarlo aquí no toca las rondas que un grupo ya guardó.', 'asde-area-privada' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asde-o-cuota"><?php esc_html_e( 'Cuota asociativa por asociado (€)', 'asde-area-privada' ); ?></label></th>
						<td><input type="number" id="asde-o-cuota" name="federacion[cuota_asociativa]" step="0.01" min="0" value="<?php echo esc_attr( $fed['cuota_asociativa'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-o-medio"><?php esc_html_e( 'Seguro promedio por asociado (€)', 'asde-area-privada' ); ?></label></th>
						<td><input type="number" id="asde-o-medio" name="federacion[seguro_promedio]" step="0.01" min="0" value="<?php echo esc_attr( $fed['seguro_promedio'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asde-o-corte"><?php esc_html_e( 'Edad de corte del seguro (años)', 'asde-area-privada' ); ?></label></th>
						<td><input type="number" id="asde-o-corte" name="federacion[edad_corte]" min="1" max="30" value="<?php echo esc_attr( $fed['edad_corte'] ); ?>" class="small-text"><p class="description"><?php esc_html_e( 'Separa las dos primas de los educandos (en Scouts de Balears, 14).', 'asde-area-privada' ); ?></p></td>
					</tr>
					<?php foreach ( $categorias as $cat => $label ) : ?>
						<tr>
							<th scope="row"><label for="asde-o-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( sprintf( 'Prima: %s (€)', mb_strtolower( $label ) ) ); ?></label></th>
							<td><input type="number" id="asde-o-<?php echo esc_attr( $cat ); ?>" name="federacion[primas][<?php echo esc_attr( $cat ); ?>]" step="0.01" min="0" value="<?php echo esc_attr( $fed['primas'][ $cat ] ); ?>" class="small-text"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Otros conceptos', 'asde-area-privada' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Cualquier otro importe que cobre la organización: por asociado, por educando, por adulto o una vez por grupo.', 'asde-area-privada' ); ?></p>
							<?php
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla propia del plugin: cada valor se escapa dentro de ella.
							echo TemplateLoader::render(
								'comun/conceptos',
								array(
									'conceptos' => $fed['conceptos'],
									'campo'     => 'federacion[conceptos]',
									'editable'  => true,
								)
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Guardar la organización', 'asde-area-privada' ) ); ?>
			</form>

			<?php $plan = MigracionUsuario::plan(); ?>
			<h2><?php esc_html_e( 'Renombrar las cuentas existentes', 'asde-area-privada' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: tipo de número, 2: cuentas ya con él, 3: cuentas a cambiar, 4: cuentas que no se pueden */
						__( 'Usuario de acceso guardado: %1$s. Cuentas que ya lo usan: %2$d. Cuentas que cambiarían: %3$d. Cuentas que no se pueden cambiar: %4$d.', 'asde-area-privada' ),
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
					<summary><?php esc_html_e( 'Ver qué cambiaría', 'asde-area-privada' ); ?></summary>
					<table class="widefat striped" style="max-width:520px"><thead><tr><th><?php esc_html_e( 'Usuario actual', 'asde-area-privada' ); ?></th><th><?php esc_html_e( 'Usuario nuevo', 'asde-area-privada' ); ?></th></tr></thead><tbody>
						<?php foreach ( array_slice( $plan['cambios'], 0, 300 ) as $c ) : ?>
							<tr><td><?php echo esc_html( $c['actual'] ); ?></td><td><?php echo esc_html( $c['nuevo'] ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
				</details>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_MIGRAR ); ?>">
					<?php wp_nonce_field( self::ACCION_MIGRAR ); ?>
					<p class="asde-organizacion__cuidado"><label><input type="checkbox" name="confirmar_migracion" value="1"> <?php esc_html_e( 'Entiendo que se cerrarán las sesiones abiertas de esas cuentas (volverán a entrar con su usuario nuevo o con el de siempre) y que los enlaces pendientes de «he olvidado la contraseña» dejarán de valer.', 'asde-area-privada' ); ?></label></p>
					<?php submit_button( __( 'Renombrar las cuentas existentes', 'asde-area-privada' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
			<?php if ( $plan['imposibles'] ) : ?>
				<details>
					<summary><?php esc_html_e( 'Cuentas que no se pueden cambiar (siguen entrando como hasta ahora)', 'asde-area-privada' ); ?></summary>
					<ul>
						<?php foreach ( array_slice( $plan['imposibles'], 0, 300 ) as $i ) : ?>
							<li><strong><?php echo esc_html( $i['actual'] ); ?></strong>: <?php echo esc_html( $i['motivo'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<style>
				.asde-organizacion .asde-conceptos__tabla { border-collapse: collapse; background: #fff; border: 1px solid #c3c4c7; max-width: 760px; width: 100%; }
				.asde-organizacion .asde-conceptos__tabla th, .asde-organizacion .asde-conceptos__tabla td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #f0f0f1; }
				.asde-organizacion .asde-conceptos__tabla input[type="text"] { width: 100%; }
				.asde-organizacion .asde-conceptos__tabla input[type="number"] { width: 110px; }
				.asde-organizacion .asde-conceptos__acciones { margin-top: 8px; }
				.asde-organizacion .asde-conceptos__acciones .asde-help { color: #646970; margin-left: 8px; }
				.asde-organizacion__cuidado { margin-top: 8px; color: #8a2424; }
			</style>
		</div>
		<?php
	}
}
