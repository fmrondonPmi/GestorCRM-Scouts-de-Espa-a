<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Módulos (v0.20): qué módulos del área privada usa cada
 * grupo. Una fila por grupo y una casilla por módulo. Ver Domain\Modulos.
 *
 * v0.23: la última columna activa las altas en el CRM del grupo (doc 34, Crm\Altas):
 * nacen apagadas y se encienden grupo a grupo.
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
			__( 'Modules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Modules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_guardar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$pedidos = isset( $_POST['activos'] ) && is_array( $_POST['activos'] ) ? wp_unslash( $_POST['activos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea abajo: solo números de grupo y claves de módulo conocidos
		$altas    = isset( $_POST['altas'] ) && is_array( $_POST['altas'] ) ? array_map( 'sanitize_text_field', array_map( 'strval', array_keys( map_deep( wp_unslash( $_POST['altas'] ), 'sanitize_text_field' ) ) ) ) : array();
		$apagados = array();
		$con      = array();
		foreach ( Grupos::todos() as $g ) {
			$si = in_array( (string) $g['numero'], $altas, true );
			if ( $si !== Altas::activo( (string) $g['numero'] ) ) {
				Altas::activar( (string) $g['numero'], $si );
			}
			if ( $si ) {
				$con[] = (string) $g['numero'];
			}
			$activos = array_values( array_intersect( array_keys( Modulos::lista() ), array_map( 'sanitize_key', (array) ( $pedidos[ $g['numero'] ] ?? array() ) ) ) );
			$off     = Modulos::guardar( (string) $g['numero'], $activos );
			if ( $off ) {
				$apagados[ $g['numero'] ] = $off;
			}
		}
		Logger::log(
			'modulos_guardados',
			'sitio',
			'',
			array(
				'apagados'  => $apagados,
				'altas_crm' => $con,
			)
		);
		set_transient( self::AVISO . get_current_user_id(), __( 'Modules saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 5 * MINUTE_IN_SECONDS );
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
			<h1><?php esc_html_e( 'Private area modules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h1>
			<p><?php esc_html_e( 'Choose which parts of the private area each group uses. What is turned off stops appearing in the menu, on the home page and in its screens for all accounts of that group, including administrators while they work on it. Nothing is deleted: when you turn it on again, everything is as it was.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php if ( is_string( $aviso ) ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $aviso ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $grupos ) : ?>
				<p><?php esc_html_e( 'No group has been registered yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
					<?php wp_nonce_field( self::ACCION ); ?>
					<table class="widefat striped asde-modulos__tabla">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
								<?php foreach ( Modulos::lista() as $m => $def ) : ?>
									<th scope="col" title="<?php echo esc_attr( $def[1] ); ?>">
										<?php echo esc_html( $def[0] ); ?>
										<?php if ( '' !== $def[3] ) : ?>
											<br><span class="description"><?php /* translators: %s: module it depends on */ echo esc_html( sprintf( __( '(inside %s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Modulos::lista()[ $def[3] ][0] ) ); ?></span>
										<?php endif; ?>
									</th>
								<?php endforeach; ?>
								<th scope="col" title="<?php esc_attr_e( 'The Secretary\'s office and Coordination register people and memberships, withdrawals and section changes from the website, writing to the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>">
									<?php esc_html_e( 'Registrations in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<br><span class="description"><?php esc_html_e( '(writes to the CRM)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $grupos as $g ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></th>
									<?php foreach ( Modulos::lista() as $m => $def ) : ?>
										<?php $id = 'asde-mod-' . $g['numero'] . '-' . $m; ?>
										<td>
											<label for="<?php echo esc_attr( $id ); ?>" class="screen-reader-text"><?php echo esc_html( Grupos::etiqueta( $g ) . ' · ' . $def[0] ); ?></label>
											<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="activos[<?php echo esc_attr( $g['numero'] ); ?>][]" value="<?php echo esc_attr( $m ); ?>" <?php checked( $this->marcado( $m, (string) $g['numero'] ) ); ?>>
										</td>
									<?php endforeach; ?>
									<?php $id = 'asde-altas-' . $g['numero']; ?>
									<td>
										<label for="<?php echo esc_attr( $id ); ?>" class="screen-reader-text"><?php echo esc_html( Grupos::etiqueta( $g ) . ' · ' . __( 'Registrations in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></label>
										<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="altas[<?php echo esc_attr( $g['numero'] ); ?>]" value="1" <?php checked( Altas::activo( (string) $g['numero'] ) ); ?>>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Bank and SEPA direct debits are part of Treasury: if Treasury is turned off, they are not seen either. "My payments" stays while the group uses Treasury, Uniforms or Events.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p class="description"><?php esc_html_e( '"Registrations in the CRM" starts turned off. With it, the Secretary\'s office and Coordination register new people with their membership, give new memberships to those who return, withdrawals and section changes, and set the document for those who do not have one: everything is written to the Scouts de España CRM. It needs the group\'s CRM user to be able to create people and memberships; if it cannot, the screens do not appear.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php submit_button( __( 'Save the modules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** La casilla refleja lo guardado del propio módulo (aunque su módulo padre esté apagado). */
	private function marcado( string $modulo, string $grupo ): bool {
		return Modulos::encendido( $modulo, $grupo ); // v0.32: algunos nacen apagados.
	}
}
