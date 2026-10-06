<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Módulos (v0.20): qué módulos del área privada usa cada
 * grupo. Una fila por grupo y una casilla por módulo. Ver Domain\Modulos.
 */
final class ModulosPage {

	public const SLUG        = 'asde-area-privada-modulos';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	public const ACCION      = 'asde_ap_modulos';
	private const AVISO      = 'asde_ap_modulos_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_guardar' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Módulos', 'asde-area-privada' ),
			__( 'Módulos', 'asde-area-privada' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_guardar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$pedidos = isset( $_POST['activos'] ) && is_array( $_POST['activos'] ) ? wp_unslash( $_POST['activos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea abajo: solo números de grupo y claves de módulo conocidos
		$apagados = array();
		foreach ( Grupos::todos() as $g ) {
			$activos = array_values( array_intersect( array_keys( Modulos::LISTA ), array_map( 'sanitize_key', (array) ( $pedidos[ $g['numero'] ] ?? array() ) ) ) );
			$off     = Modulos::guardar( (string) $g['numero'], $activos );
			if ( $off ) {
				$apagados[ $g['numero'] ] = $off;
			}
		}
		Logger::log( 'modulos_guardados', 'sitio', '', array( 'apagados' => $apagados ) );
		set_transient( self::AVISO . get_current_user_id(), __( 'Módulos guardados.', 'asde-area-privada' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$aviso = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );
		$grupos = Grupos::todos();
		?>
		<div class="wrap asde-modulos">
			<h1><?php esc_html_e( 'Módulos del área privada', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Elige qué partes del área privada usa cada grupo. Lo que se desactiva deja de salir en el menú, en el inicio y en sus pantallas para todas las cuentas de ese grupo, también para los administradores mientras trabajan en él. No se borra nada: al activarlo de nuevo, todo está como estaba.', 'asde-area-privada' ); ?></p>
			<?php if ( is_string( $aviso ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $grupos ) : ?>
				<p><?php esc_html_e( 'Todavía no hay ningún grupo dado de alta.', 'asde-area-privada' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
					<?php wp_nonce_field( self::ACCION ); ?>
					<table class="widefat striped asde-modulos__tabla">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Grupo', 'asde-area-privada' ); ?></th>
								<?php foreach ( Modulos::LISTA as $m => $def ) : ?>
									<th scope="col" title="<?php echo esc_attr( asde_t( $def[1] ) ); ?>">
										<?php echo esc_html( asde_t( $def[0] ) ); ?>
										<?php if ( '' !== $def[3] ) : ?>
											<br><span class="description"><?php /* translators: %s: módulo del que depende */ echo esc_html( sprintf( __( '(dentro de %s)', 'asde-area-privada' ), asde_t( Modulos::LISTA[ $def[3] ][0] ) ) ); ?></span>
										<?php endif; ?>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $grupos as $g ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></th>
									<?php foreach ( Modulos::LISTA as $m => $def ) : ?>
										<?php $id = 'asde-mod-' . $g['numero'] . '-' . $m; ?>
										<td>
											<label for="<?php echo esc_attr( $id ); ?>" class="screen-reader-text"><?php echo esc_html( Grupos::etiqueta( $g ) . ' · ' . asde_t( $def[0] ) ); ?></label>
											<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="activos[<?php echo esc_attr( $g['numero'] ); ?>][]" value="<?php echo esc_attr( $m ); ?>" <?php checked( $this->marcado( $m, (string) $g['numero'] ) ); ?>>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Banco y Domiciliaciones SEPA van dentro de Tesorería: si se desactiva Tesorería, tampoco se ven. «Mis pagos» se queda mientras el grupo use Tesorería o Uniformes.', 'asde-area-privada' ); ?></p>
					<?php submit_button( __( 'Guardar los módulos', 'asde-area-privada' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** La casilla refleja lo guardado del propio módulo (aunque su módulo padre esté apagado). */
	private function marcado( string $modulo, string $grupo ): bool {
		$apagados = Modulos::apagados();
		return empty( $apagados[ $grupo ] ) || ! array_key_exists( $modulo, $apagados[ $grupo ] );
	}
}
