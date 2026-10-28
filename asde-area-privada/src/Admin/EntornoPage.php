<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Install\Entorno;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Instalación y entorno.
 *
 * Un botón que deja un WordPress nuevo listo para el área privada (Entorno),
 * con la lista de lo que está bien, lo que falta y los siguientes pasos.
 */
final class EntornoPage {

	public const SLUG            = 'asde-area-privada-entorno';
	private const PADRE          = 'asde-area-privada';
	private const CAPABILITY     = 'manage_options';
	private const ACCION_GENERAR = 'asde_ap_entorno';
	private const ACCION_EMAIL   = 'asde_ap_entorno_email';
	private const INFORME        = 'asde_ap_entorno_informe_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_' . self::ACCION_GENERAR, array( $this, 'handle_generar' ) );
		add_action( 'admin_post_' . self::ACCION_EMAIL, array( $this, 'handle_email' ) );
		add_action( 'admin_notices', array( $this, 'aviso_instalacion' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Installation and environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Installation and environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** En un WordPress recién instalado (sin grupos), avisa en Escritorio y Plugins de dónde se prepara todo. */
	public function aviso_instalacion(): void {
		$pantalla = get_current_screen();
		if ( ! current_user_can( self::CAPABILITY ) || ! $pantalla || ! in_array( $pantalla->id, array( 'dashboard', 'plugins' ), true ) || Grupos::todos() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Scout Groups private area:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			esc_html__( 'the environment still has to be prepared and the group registered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			esc_url( self::url() ),
			esc_html__( 'Installation and environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )
		);
	}

	public function handle_generar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION_GENERAR );
		$marcadas = array();
		foreach ( array_keys( Entorno::opciones() ) as $clave ) {
			$marcadas[ $clave ] = ! empty( $_POST[ 'opcion_' . $clave ] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		set_transient( self::INFORME . get_current_user_id(), Entorno::generar( $marcadas ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Email de prueba a la propia cuenta de quien lo pide. */
	public function handle_email(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( self::ACCION_EMAIL );
		$user  = wp_get_current_user();
		$error = \AsdeBalears\AreaPrivada\Usuarios\RegistroEmails::enviar(
			$user->user_email,
			'Prueba de email del área privada',
			"Este es un email de prueba enviado desde «Instalación y entorno» del área privada de " . get_bloginfo( 'name' ) . ".\n\nSi te ha llegado a la bandeja de entrada (y no a spam), las invitaciones y la recuperación de contraseña también llegarán.",
			array() // Texto plano.
		);
		$ok    = '' === $error;
		set_transient(
			self::INFORME . get_current_user_id(),
			array(
				'hecho'   => $ok ? array( sprintf( 'Email de prueba enviado a %s. Mira si llega a la bandeja de entrada (y no a spam).', $user->user_email ) ) : array(),
				'avisos'  => array(),
				'errores' => $ok ? array() : array( 'No se ha podido enviar el email de prueba: ' . $error ),
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::url() );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$informe = get_transient( self::INFORME . get_current_user_id() );
		delete_transient( self::INFORME . get_current_user_id() );
		$estado   = Entorno::comprobar();
		$opciones = Entorno::opciones();
		$iconos   = array(
			Entorno::OK    => '✅',
			Entorno::AVISO => '⚠️',
			Entorno::FALTA => '❌',
			Entorno::INFO  => 'ℹ️',
		);
		$cuenta   = array_count_values( array_column( $estado, 0 ) );
		?>
		<div class="wrap asde-entorno">
			<h1><?php esc_html_e( 'Private area installation and environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h1>
			<p><?php esc_html_e( 'Gets this WordPress ready for the Scout Groups private area: tables, roles, pages, the menu access, scheduled tasks and, if you tick it, the site settings that are needed. You can press it as many times as you like: it only creates or repairs what is missing, never deletes anything and never touches the website\'s content.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

			<?php if ( is_array( $informe ) ) : ?>
				<?php if ( $informe['hecho'] ) : ?>
					<div class="notice notice-success"><p><strong><?php esc_html_e( 'Done:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['hecho'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<?php if ( $informe['errores'] ) : ?>
					<div class="notice notice-error"><p><strong><?php esc_html_e( 'Could not do:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['errores'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<?php if ( $informe['avisos'] ) : ?>
					<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Left in your hands:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['avisos'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-entorno__generar">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_GENERAR ); ?>">
				<?php wp_nonce_field( self::ACCION_GENERAR ); ?>
				<?php if ( $opciones ) : ?>
					<p><strong><?php esc_html_e( 'In addition, settings of this WordPress that should be changed (only the ticked ones are touched):', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p>
					<?php foreach ( $opciones as $clave => list( $etiqueta, $explicacion ) ) : ?>
						<p class="asde-entorno__opcion">
							<label>
								<input type="checkbox" name="opcion_<?php echo esc_attr( $clave ); ?>" value="1" checked>
								<?php echo esc_html( $etiqueta ); ?>
							</label>
							<br><span class="description"><?php echo esc_html( $explicacion ); ?></span>
						</p>
					<?php endforeach; ?>
				<?php endif; ?>
				<p>
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Generate / repair the environment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
			</form>

			<h2>
				<?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<span class="asde-entorno__resumen"><?php echo esc_html( sprintf( '%d bien · %d por hacer · %d avisos', $cuenta[ Entorno::OK ] ?? 0, $cuenta[ Entorno::FALTA ] ?? 0, ( $cuenta[ Entorno::AVISO ] ?? 0 ) + ( $cuenta[ Entorno::INFO ] ?? 0 ) ) ); ?></span>
			</h2>
			<table class="widefat striped asde-entorno__tabla">
				<tbody>
					<?php foreach ( $estado as $clave => list( $e, $pieza, $detalle, $enlaces ) ) : ?>
						<tr class="asde-entorno__fila asde-entorno__fila--<?php echo esc_attr( $e ); ?>" data-pieza="<?php echo esc_attr( $clave ); ?>">
							<td class="asde-entorno__icono" aria-label="<?php echo esc_attr( $e ); ?>"><?php echo esc_html( $iconos[ $e ] ?? '' ); ?></td>
							<th scope="row"><?php echo esc_html( $pieza ); ?></th>
							<td><?php echo esc_html( $detalle ); ?></td>
							<td class="asde-entorno__enlaces">
								<?php foreach ( $enlaces as $texto => $url ) : ?>
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $texto ); ?></a>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Next steps', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<ol class="asde-entorno__pasos">
				<?php if ( ! \AsdeBalears\AreaPrivada\Domain\Organizacion::configurada() ) : ?>
					<li><?php /* translators: %s: address of the Federated organisation screen */ echo wp_kses_post( sprintf( __( '<a href="%s">Choose your federated organisation</a> and its code in the local member number (and check the default federation cost).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_url( OrganizacionPage::url() ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( ! defined( 'ASDE_AP_ENCRYPTION_KEY' ) ) : ?>
					<li>
						<?php
						echo esc_html(
							Grupos::todos()
								? __( '(Recommended) Add your own key to wp-config.php to encrypt the CRM passwords. As there are already groups, you will then have to type their passwords again. For example, this key, generated at random just now (it is not stored anywhere):', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )
								: __( '(Recommended, BEFORE registering the groups) Add your own key to wp-config.php to encrypt the CRM passwords. For example, this key, generated at random just now (it is not stored anywhere):', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )
						);
						?>
						<pre class="asde-entorno__clave">define( 'ASDE_AP_ENCRYPTION_KEY', '<?php echo esc_html( rtrim( strtr( base64_encode( random_bytes( 36 ) ), '+/', '-_' ), '=' ) ); ?>' );</pre>
					</li>
				<?php endif; ?>
				<li><?php /* translators: %s: address of the Groups and CRM connection screen */ echo wp_kses_post( sprintf( __( '<a href="%s">Register your Scout Group</a> with its number, its name and the CRM username and password, and press "Test connection".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_url( add_query_arg( 'nuevo', '1', admin_url( 'admin.php?page=asde-area-privada' ) ) ) ) ); ?></li>
				<li><?php /* translators: %s: address of the background tasks diagnostic */ echo wp_kses_post( sprintf( __( 'Run the <a href="%s">background tasks diagnostic</a> (on LiteSpeed/Hostinger "noabort" is usually needed).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_url( class_exists( '\Asde_Diagnostico_Segundo_Plano', false ) ? \Asde_Diagnostico_Segundo_Plano::url() : admin_url( 'tools.php' ) ) ) ); ?></li>
				<li>
					<?php esc_html_e( 'Check that emails arrive:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-entorno__email">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_EMAIL ); ?>">
						<?php wp_nonce_field( self::ACCION_EMAIL ); ?>
						<button type="submit" class="button"><?php /* translators: %s: email of the account that requests it */ echo esc_html( sprintf( __( 'Send me a test email (%s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_get_current_user()->user_email ) ); ?></button>
					</form>
				</li>
				<li><?php esc_html_e( 'From the private area, give access to the Secretary\'s office and Coordination (People → Give access); from there, they give access to the rest and approve the access requests.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
