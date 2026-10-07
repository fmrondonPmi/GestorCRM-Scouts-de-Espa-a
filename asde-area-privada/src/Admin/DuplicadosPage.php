<?php
namespace AsdeBalears\AreaPrivada\Admin;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin → Grupos Scouts → Documentos repetidos (v0.24, doc 34, §3.7): fichas de un
 * grupo que comparten el mismo documento, por si ya había duplicados de antes de las
 * altas desde la web. Solo lectura: se buscan al pulsar el botón (no al abrir la página)
 * y no se guarda nada; el documento se enseña enmascarado.
 */
final class DuplicadosPage {

	public const SLUG        = 'asde-area-privada-duplicados';
	private const PADRE      = 'asde-area-privada';
	private const CAPABILITY = 'manage_options';
	private const NONCE      = 'asde_ap_duplicados';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 13 );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			self::PADRE,
			__( 'Documentos repetidos', 'asde-area-privada' ),
			__( 'Documentos repetidos', 'asde-area-privada' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** El documento, sin enseñarlo entero: ••••5678Z. */
	public static function enmascarar( string $doc ): string {
		return str_repeat( '•', max( 0, strlen( $doc ) - 4 ) ) . substr( $doc, -4 );
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$grupos  = array_values( array_filter( Grupos::todos(), static fn( array $g ) => '' !== (string) $g['crm_id'] ) );
		$pedido  = isset( $_GET['grupo'] ) ? sanitize_text_field( wp_unslash( $_GET['grupo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el nonce se comprueba abajo antes de consultar el CRM
		$valido  = '' !== $pedido && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::NONCE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- es la comprobación
		$grupo   = $valido ? Grupos::por_numero( $pedido ) : null;
		$error   = '';
		$lista   = null;
		if ( $grupo && '' !== (string) $grupo['crm_id'] ) {
			try {
				$lista = Grupo::con(
					$grupo,
					static function () {
						$dup = Duplicados::make();
						if ( ! $dup ) {
							throw new CrmException( esc_html( __( 'Sin conexión con el CRM para este grupo.', 'asde-area-privada' ) ) );
						}
						return $dup->repetidos();
					}
				);
			} catch ( CrmException $e ) {
				$error = $e->getMessage();
			}
		}
		?>
		<div class="wrap asde-duplicados">
			<h1><?php esc_html_e( 'Documentos repetidos en el CRM', 'asde-area-privada' ); ?></h1>
			<p><?php esc_html_e( 'Busca en las fichas de un grupo las que tienen el mismo DNI, NIE u otro documento (escrito de cualquier forma: con guion, en minúsculas…). Suelen ser la misma persona dada de alta dos veces antes de las altas desde la web; hay que unificarlas en el CRM. Solo lee: no cambia nada.', 'asde-area-privada' ); ?></p>
			<?php if ( ! $grupos ) : ?>
				<p><?php esc_html_e( 'No hay ningún grupo con la conexión al CRM comprobada.', 'asde-area-privada' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
					<label for="asde-dup-grupo"><?php esc_html_e( 'Grupo', 'asde-area-privada' ); ?></label>
					<select id="asde-dup-grupo" name="grupo">
						<?php foreach ( $grupos as $g ) : ?>
							<option value="<?php echo esc_attr( $g['numero'] ); ?>" <?php selected( $pedido, (string) $g['numero'] ); ?>><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Buscar documentos repetidos', 'asde-area-privada' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php elseif ( null !== $lista ) : ?>
				<h2><?php echo esc_html( Grupos::etiqueta( $grupo ) ); ?></h2>
				<?php if ( ! $lista ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'No hay fichas con el mismo documento en este grupo.', 'asde-area-privada' ); ?></p></div>
				<?php else : ?>
					<p>
						<?php
						/* translators: %d: documentos repetidos */
						echo esc_html( sprintf( _n( '%d documento está en más de una ficha:', '%d documentos están en más de una ficha:', count( $lista ), 'asde-area-privada' ), count( $lista ) ) );
						?>
					</p>
					<table class="widefat striped">
						<thead><tr><th scope="col"><?php esc_html_e( 'Documento', 'asde-area-privada' ); ?></th><th scope="col"><?php esc_html_e( 'Fichas', 'asde-area-privada' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $lista as $r ) : ?>
								<tr>
									<td><code><?php echo esc_html( self::enmascarar( $r['documento'] ) ); ?></code></td>
									<td>
										<ul style="margin:0;">
											<?php foreach ( $r['fichas'] as $f ) : ?>
												<li>
													<?php echo esc_html( $f['nombre'] ); ?> ·
													<?php /* translators: %s: nº de socio */ echo esc_html( sprintf( __( 'nº Scouts de España %s', 'asde-area-privada' ), $f['socio'] ) ); ?> ·
													<?php echo $f['activa'] ? esc_html__( 'con membresía activa', 'asde-area-privada' ) : esc_html__( 'sin membresía activa', 'asde-area-privada' ); ?>
												</li>
											<?php endforeach; ?>
										</ul>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
