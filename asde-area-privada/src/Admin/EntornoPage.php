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
			__( 'Instalación y entorno', 'asde-area-privada' ),
			__( 'Instalación y entorno', 'asde-area-privada' ),
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
			esc_html__( 'Área privada de los Grupos Scouts:', 'asde-area-privada' ),
			esc_html__( 'falta preparar el entorno y dar de alta el grupo.', 'asde-area-privada' ),
			esc_url( self::url() ),
			esc_html__( 'Instalación y entorno', 'asde-area-privada' )
		);
	}

	public function handle_generar(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
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
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( self::ACCION_EMAIL );
		$user  = wp_get_current_user();
		$error = '';
		$fallo = static function ( \WP_Error $e ) use ( &$error ) {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $fallo );
		$ok = wp_mail(
			$user->user_email,
			'Prueba de email del área privada',
			"Este es un email de prueba enviado desde «Instalación y entorno» del área privada de " . get_bloginfo( 'name' ) . ".\n\nSi te ha llegado a la bandeja de entrada (y no a spam), las invitaciones y la recuperación de contraseña también llegarán."
		);
		remove_action( 'wp_mail_failed', $fallo );
		set_transient(
			self::INFORME . get_current_user_id(),
			array(
				'hecho'   => $ok ? array( sprintf( 'Email de prueba enviado a %s. Mira si llega a la bandeja de entrada (y no a spam).', $user->user_email ) ) : array(),
				'avisos'  => array(),
				'errores' => $ok ? array() : array( 'No se ha podido enviar el email de prueba' . ( '' !== $error ? ': ' . $error : '.' ) ),
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
			<h1><?php esc_html_e( 'Instalación y entorno del área privada', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Deja este WordPress listo para el área privada de los Grupos Scouts: tablas, roles, páginas, el acceso en el menú, tareas programadas y, si lo marcas, los ajustes del sitio que hagan falta. Se puede pulsar las veces que quieras: solo crea o repara lo que falta, nunca borra nada ni toca el contenido de la web.', 'asde-area-privada' ); ?></p>

			<?php if ( is_array( $informe ) ) : ?>
				<?php if ( $informe['hecho'] ) : ?>
					<div class="notice notice-success"><p><strong><?php esc_html_e( 'Hecho:', 'asde-area-privada' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['hecho'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<?php if ( $informe['errores'] ) : ?>
					<div class="notice notice-error"><p><strong><?php esc_html_e( 'No se ha podido:', 'asde-area-privada' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['errores'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
				<?php if ( $informe['avisos'] ) : ?>
					<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Queda en tus manos:', 'asde-area-privada' ); ?></strong></p><ul class="asde-entorno__lista"><?php foreach ( $informe['avisos'] as $linea ) : ?><li><?php echo esc_html( $linea ); ?></li><?php endforeach; ?></ul></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-entorno__generar">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_GENERAR ); ?>">
				<?php wp_nonce_field( self::ACCION_GENERAR ); ?>
				<?php if ( $opciones ) : ?>
					<p><strong><?php esc_html_e( 'Además, ajustes de este WordPress que conviene cambiar (solo se tocan los marcados):', 'asde-area-privada' ); ?></strong></p>
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
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Generar / reparar el entorno', 'asde-area-privada' ); ?></button>
				</p>
			</form>

			<h2>
				<?php esc_html_e( 'Estado', 'asde-area-privada' ); ?>
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

			<h2><?php esc_html_e( 'Siguientes pasos', 'asde-area-privada' ); ?></h2>
			<ol class="asde-entorno__pasos">
				<?php if ( ! \AsdeBalears\AreaPrivada\Domain\Organizacion::configurada() ) : ?>
					<li><?php echo wp_kses_post( sprintf( __( '<a href="%s">Elige tu organización federada</a> y su código en el nº de socio local (y revisa el coste federativo por defecto).', 'asde-area-privada' ), esc_url( OrganizacionPage::url() ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( ! defined( 'ASDE_AP_ENCRYPTION_KEY' ) ) : ?>
					<li>
						<?php
						echo esc_html(
							Grupos::todos()
								? __( '(Recomendado) Añade a wp-config.php una clave propia para cifrar las contraseñas del CRM. Como ya hay grupos, después tendrás que volver a escribir sus contraseñas. Por ejemplo, esta clave, generada ahora al azar (no se guarda en ningún sitio):', 'asde-area-privada' )
								: __( '(Recomendado, ANTES de dar de alta los grupos) Añade a wp-config.php una clave propia para cifrar las contraseñas del CRM. Por ejemplo, esta clave, generada ahora al azar (no se guarda en ningún sitio):', 'asde-area-privada' )
						);
						?>
						<pre class="asde-entorno__clave">define( 'ASDE_AP_ENCRYPTION_KEY', '<?php echo esc_html( rtrim( strtr( base64_encode( random_bytes( 36 ) ), '+/', '-_' ), '=' ) ); ?>' );</pre>
					</li>
				<?php endif; ?>
				<li><?php echo wp_kses_post( sprintf( __( '<a href="%s">Da de alta tu Grupo Scout</a> con su nº, su nombre y el usuario y la contraseña del CRM, y pulsa «Probar conexión».', 'asde-area-privada' ), esc_url( add_query_arg( 'nuevo', '1', admin_url( 'admin.php?page=asde-area-privada' ) ) ) ) ); ?></li>
				<li><?php echo wp_kses_post( sprintf( __( 'Pasa el <a href="%s">diagnóstico de tareas en segundo plano</a> (en LiteSpeed/Hostinger suele hacer falta «noabort»).', 'asde-area-privada' ), esc_url( class_exists( '\Asde_Diagnostico_Segundo_Plano', false ) ? \Asde_Diagnostico_Segundo_Plano::url() : admin_url( 'tools.php' ) ) ) ); ?></li>
				<li>
					<?php esc_html_e( 'Comprueba que los emails llegan:', 'asde-area-privada' ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asde-entorno__email">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_EMAIL ); ?>">
						<?php wp_nonce_field( self::ACCION_EMAIL ); ?>
						<button type="submit" class="button"><?php echo esc_html( sprintf( __( 'Enviarme un email de prueba (%s)', 'asde-area-privada' ), wp_get_current_user()->user_email ) ); ?></button>
					</form>
				</li>
				<li><?php esc_html_e( 'Desde el área privada, da acceso a Secretaría y Coordinación (Personas → Dar acceso); a partir de ahí, ellos dan acceso al resto y aprueban las solicitudes de acceso.', 'asde-area-privada' ); ?></li>
			</ol>
			<style>
				.asde-entorno__tabla { max-width: 1100px; }
				.asde-entorno__tabla th { width: 230px; }
				.asde-entorno__icono { width: 28px; font-size: 16px; }
				.asde-entorno__enlaces a { display: inline-block; margin-right: 10px; white-space: nowrap; }
				.asde-entorno__fila--falta th { color: #b32d2e; }
				.asde-entorno__resumen { margin-left: 10px; font-size: 13px; font-weight: 400; color: #646970; }
				.asde-entorno__opcion { margin: 8px 0 8px 4px; }
				.asde-entorno__opcion select { margin-left: 8px; }
				.asde-entorno__lista { list-style: disc; margin-left: 20px; }
				.asde-entorno__email { display: inline-block; margin-left: 6px; }
				.asde-entorno__pasos li { margin-bottom: 8px; }
				.asde-entorno__clave { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 8px 12px; max-width: 900px; overflow: auto; user-select: all; }
			</style>
		</div>
		<?php
	}
}
