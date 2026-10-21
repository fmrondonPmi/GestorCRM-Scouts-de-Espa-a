<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Domain\GrupoInvitaciones;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Organizacion;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Invitar a un grupo (v0.50, doc 50 fase 5; solo en la web de la oficina).
 *
 * Genera el enlace de un solo uso para que un grupo dé de alta su acceso: se enseña **una sola vez** (en la base
 * solo queda su hash) y se puede copiar o enviar por email. Lista las invitaciones con su estado y deja anular las
 * pendientes.
 */
final class GruposInvitacionesPage {

	public const SLUG        = 'asde-area-privada-invitaciones';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	private const ACCION     = 'asde_ap_grupo_invitar';
	private const ANULAR     = 'asde_ap_grupo_invitacion_anular';
	private const AVISO      = 'asde_ap_ginv_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_post_' . self::ACCION, array( $this, 'handle_invitar' ) );
		add_action( 'admin_post_' . self::ANULAR, array( $this, 'handle_anular' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		if ( ! Organizacion::es_oficina() ) {
			return;
		}
		add_submenu_page( self::PADRE, __( 'Invitar a un grupo', 'asde-area-privada' ), __( 'Invitar a un grupo', 'asde-area-privada' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	public function handle_invitar(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Organizacion::es_oficina() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$inv   = GrupoInvitaciones::crear( $email );
		GrupoInvitaciones::recordar_para_mostrar( $inv['url'] );
		if ( '' !== $email && is_email( $email ) ) {
			$error = GrupoInvitaciones::enviar_por_email( $email, $inv['url'] );
			$this->aviso( '' === $error ? 'success' : 'warning', '' === $error ? 'Invitación creada y enviada por email.' : 'Invitación creada, pero el email no se ha podido enviar (' . $error . '). Copia el enlace y envíalo tú.' );
		} else {
			$this->aviso( 'success', 'Invitación creada. Copia el enlace: no se volverá a mostrar.' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public function handle_anular(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Organizacion::es_oficina() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ANULAR );
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- comprobado arriba
		$hecho = GrupoInvitaciones::anular( $id );
		$this->aviso( $hecho ? 'success' : 'error', $hecho ? 'Invitación anulada.' : 'Esa invitación ya no se puede anular.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$aviso  = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );
		$enlace = GrupoInvitaciones::tomar_para_mostrar();
		$lista  = GrupoInvitaciones::lista();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invitar a un grupo', 'asde-area-privada' ); ?></h1>
			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $aviso[0] ); ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $enlace ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'Enlace de la invitación (solo se muestra ahora):', 'asde-area-privada' ); ?></strong></p>
					<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $enlace ); ?>" onfocus="this.select()" aria-label="<?php esc_attr_e( 'Enlace de la invitación', 'asde-area-privada' ); ?>"></p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Cada enlace sirve una sola vez y vale 14 días. El grupo rellena sus datos, comprueba el acceso al CRM y los envía; después los revisas en «Altas de grupos».', 'asde-area-privada' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION ); ?>">
				<?php wp_nonce_field( self::ACCION ); ?>
				<p>
					<label for="asde-ginv-email"><?php esc_html_e( 'Enviar por email (opcional)', 'asde-area-privada' ); ?></label>
					<input type="email" id="asde-ginv-email" name="email" class="regular-text" autocomplete="off">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Crear la invitación', 'asde-area-privada' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Invitaciones', 'asde-area-privada' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Creada', 'asde-area-privada' ); ?></th><th><?php esc_html_e( 'Caduca', 'asde-area-privada' ); ?></th><th><?php esc_html_e( 'Estado', 'asde-area-privada' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $lista as $f ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $f['creada'], 'd/m/Y H:i' ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $f['expira'], 'd/m/Y' ) ); ?></td>
						<td><?php echo esc_html( GrupoInvitaciones::ESTADOS[ $f['estado_real'] ] ?? $f['estado_real'] ); ?></td>
						<td>
							<?php if ( GrupoInvitaciones::PENDIENTE === $f['estado_real'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ANULAR ); ?>">
									<input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
									<?php wp_nonce_field( self::ANULAR ); ?>
									<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Anular', 'asde-area-privada' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $lista ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Todavía no hay invitaciones.', 'asde-area-privada' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: altas por revisar */ _n( 'Hay %d alta de grupo por revisar.', 'Hay %d altas de grupo por revisar.', GrupoSolicitudes::pendientes(), 'asde-area-privada' ), GrupoSolicitudes::pendientes() ) ); ?></p>
		</div>
		<?php
	}
}
