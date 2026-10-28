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
			__( 'Duplicate documents', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			__( 'Duplicate documents', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
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
							throw new CrmException( esc_html( __( 'No connection to the CRM for this group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
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
			<h1><?php esc_html_e( 'Duplicate documents in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h1>
			<p><?php esc_html_e( 'Search the records of a group for those with the same ID, foreigner ID or other document (written in any form: with a hyphen, in lower case…). They are usually the same person registered twice before registrations from the website; they must be merged in the CRM. Read-only: nothing is changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php if ( ! $grupos ) : ?>
				<p><?php esc_html_e( 'There is no group with a checked CRM connection.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>
					<label for="asde-dup-grupo"><?php esc_html_e( 'Group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-dup-grupo" name="grupo">
						<?php foreach ( $grupos as $g ) : ?>
							<option value="<?php echo esc_attr( $g['numero'] ); ?>" <?php selected( $pedido, (string) $g['numero'] ); ?>><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Search for duplicate documents', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php elseif ( null !== $lista ) : ?>
				<h2><?php echo esc_html( Grupos::etiqueta( $grupo ) ); ?></h2>
				<?php if ( ! $lista ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'There are no records with the same document in this group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
				<?php else : ?>
					<p>
						<?php
						/* translators: %d: duplicate documents */
						echo esc_html( sprintf( /* translators: %d: number */ _n( '%d documento está en más de una ficha:', '%d documentos están en más de una ficha:', count( $lista ), 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $lista ) ) );
						?>
					</p>
					<table class="widefat striped">
						<thead><tr><th scope="col"><?php esc_html_e( 'Document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Records', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $lista as $r ) : ?>
								<tr>
									<td><code><?php echo esc_html( self::enmascarar( $r['documento'] ) ); ?></code></td>
									<td>
										<ul style="margin:0;">
											<?php foreach ( $r['fichas'] as $f ) : ?>
												<li>
													<?php echo esc_html( $f['nombre'] ); ?> ·
													<?php /* translators: %s: member number */ echo esc_html( sprintf( __( 'Scouts de España no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['socio'] ) ); ?> ·
													<?php echo $f['activa'] ? esc_html__( 'with an active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_html__( 'no active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
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
