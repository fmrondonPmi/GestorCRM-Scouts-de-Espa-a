<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Domain\GrupoAltas;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Organizacion;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Altas de grupos (v0.50, doc 50 fase 6; solo en la web de la oficina).
 *
 * Lista las altas que han enviado los grupos con su invitación y deja aprobarlas o rechazarlas. Del detalle **nunca**
 * se muestran el usuario ni la contraseña del CRM, ni el DNI ni el nombre de la primera persona: solo que la
 * credencial está guardada y verificada.
 */
final class GruposAltasPage {

	public const SLUG        = 'asde-area-privada-altas';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	private const APROBAR    = 'asde_ap_grupo_alta_aprobar';
	private const RECHAZAR   = 'asde_ap_grupo_alta_rechazar';
	private const REINTENTAR = 'asde_ap_grupo_alta_reintentar';
	private const AVISO      = 'asde_ap_galta_aviso_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 13 );
		add_action( 'admin_post_' . self::APROBAR, array( $this, 'handle_aprobar' ) );
		add_action( 'admin_post_' . self::RECHAZAR, array( $this, 'handle_rechazar' ) );
		add_action( 'admin_post_' . self::REINTENTAR, array( $this, 'handle_reintentar' ) );
	}

	public static function url( int $id = 0 ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( $id ? '&alta=' . $id : '' ) );
	}

	public function add_menu(): void {
		if ( ! Organizacion::es_oficina() ) {
			return;
		}
		$n = GrupoSolicitudes::pendientes();
		add_submenu_page(
			self::PADRE,
			__( 'Altas de grupos', 'asde-area-privada' ),
			__( 'Altas de grupos', 'asde-area-privada' ) . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function aviso( string $tipo, string $texto ): void {
		set_transient( self::AVISO . get_current_user_id(), array( $tipo, $texto ), 5 * MINUTE_IN_SECONDS );
	}

	private function id_enviado(): int {
		return absint( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- se llama tras check_admin_referer()
	}

	public function handle_aprobar(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Organizacion::es_oficina() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::APROBAR );
		$id = $this->id_enviado();
		$r  = GrupoAltas::aprobar( $id );
		if ( is_wp_error( $r ) ) {
			$this->aviso( 'error', 'No se ha aprobado: ' . $r->get_error_message() );
		} else {
			$this->aviso( 'success', sprintf( 'Grupo %s dado de alta y comprobado. Sus módulos se activan desde Grupos Scouts → Módulos.', $r['numero'] ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Vuelve a buscar la ficha de la primera persona con acceso y a mandarle la invitación. */
	public function handle_reintentar(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Organizacion::es_oficina() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::REINTENTAR );
		$id     = $this->id_enviado();
		$estado = GrupoAltas::primer_usuario( $id );
		$this->aviso( GrupoSolicitudes::USUARIO_INVITADO === $estado ? 'success' : 'warning', GrupoSolicitudes::USUARIO_INVITADO === $estado ? 'Invitación enviada a la primera persona con acceso.' : 'Todavía no se ha podido: la ficha con ese DNI no existe o no está activa en el CRM del grupo.' );
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	public function handle_rechazar(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Organizacion::es_oficina() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::RECHAZAR );
		$hecho = GrupoAltas::rechazar( $this->id_enviado() );
		$this->aviso( $hecho ? 'success' : 'error', $hecho ? 'Alta rechazada: se ha borrado la credencial guardada y se ha avisado al grupo.' : 'Esa solicitud ya no está pendiente.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$aviso = get_transient( self::AVISO . get_current_user_id() );
		delete_transient( self::AVISO . get_current_user_id() );
		$id    = absint( wp_unslash( $_GET['alta'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura
		$s     = $id ? GrupoSolicitudes::obtener( $id ) : null;
		$s     = $s ? GrupoSolicitudes::sin_secretos( $s ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Altas de grupos', 'asde-area-privada' ); ?></h1>
			<?php if ( is_array( $aviso ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $aviso[0] ); ?>"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $s ) : ?>
				<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'Volver a la lista', 'asde-area-privada' ); ?></a></p>
				<h2><?php echo esc_html( sprintf( '%s (%s)', $s['nombre'], $s['numero'] ) ); ?></h2>
				<table class="widefat striped" style="max-width:720px"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Estado', 'asde-area-privada' ); ?></th><td><?php echo esc_html( GrupoSolicitudes::ESTADOS[ $s['estado'] ] ?? $s['estado'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Sede', 'asde-area-privada' ); ?></th><td><?php echo esc_html( $s['sede'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Email del grupo', 'asde-area-privada' ); ?></th><td><?php echo esc_html( $s['email'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'CIF', 'asde-area-privada' ); ?></th><td><?php echo esc_html( $s['cif'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Denominación fiscal', 'asde-area-privada' ); ?></th><td><?php echo esc_html( $s['denominacion'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Rol de la primera persona', 'asde-area-privada' ); ?></th><td><?php echo esc_html( GrupoSolicitudes::ROLES[ $s['rol'] ] ?? $s['rol'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Acceso al CRM', 'asde-area-privada' ); ?></th><td><?php echo esc_html( $s['verificada'] ? sprintf( /* translators: %s: fecha */ __( 'Credencial guardada y comprobada el %s', 'asde-area-privada' ), get_date_from_gmt( $s['verificada'], 'd/m/Y H:i' ) ) : __( 'Sin comprobar', 'asde-area-privada' ) ); ?></td></tr>
				</tbody></table>
				<?php if ( GrupoSolicitudes::APROBADA === $s['estado'] ) : ?>
					<?php if ( GrupoSolicitudes::USUARIO_INVITADO === $s['usuario_estado'] ) : ?>
						<p><strong><?php esc_html_e( 'Primera persona con acceso:', 'asde-area-privada' ); ?></strong> <?php esc_html_e( 'invitación enviada al email del grupo.', 'asde-area-privada' ); ?></p>
					<?php else : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Falta el primer usuario: la ficha con ese DNI no existe o no está activa en el CRM del grupo. Corrígelo en el CRM y vuelve a intentarlo; mientras, nadie del grupo puede entrar.', 'asde-area-privada' ); ?></p></div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::REINTENTAR ); ?>">
							<input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
							<?php wp_nonce_field( self::REINTENTAR ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Buscar la ficha y enviar la invitación', 'asde-area-privada' ); ?></button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( GrupoSolicitudes::PENDIENTE === $s['estado'] ) : ?>
					<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::APROBAR ); ?>">
						<input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
						<?php wp_nonce_field( self::APROBAR ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Aprobar y dar de alta el grupo', 'asde-area-privada' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" data-asde-confirmar="<?php echo esc_attr__( '¿Rechazar esta alta? Se borra la credencial guardada y se avisa al grupo (sin el motivo).', 'asde-area-privada' ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RECHAZAR ); ?>">
						<input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
						<?php wp_nonce_field( self::RECHAZAR ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Rechazar', 'asde-area-privada' ); ?></button>
					</form>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<?php $lista = GrupoSolicitudes::lista(); ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Recibida', 'asde-area-privada' ); ?></th><th><?php esc_html_e( 'Grupo', 'asde-area-privada' ); ?></th><th><?php esc_html_e( 'Estado', 'asde-area-privada' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $lista as $f ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $f['creada'], 'd/m/Y H:i' ) ); ?></td>
							<td><?php echo esc_html( sprintf( '%s (%s)', $f['nombre'], $f['numero'] ) ); ?></td>
							<td><?php echo esc_html( GrupoSolicitudes::ESTADOS[ $f['estado'] ] ?? $f['estado'] ); ?></td>
							<td><a href="<?php echo esc_url( self::url( (int) $f['id'] ) ); ?>"><?php esc_html_e( 'Ver', 'asde-area-privada' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $lista ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Todavía no ha llegado ningún alta.', 'asde-area-privada' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
