<?php
/**
 * Después de marcar movimientos como revisados desde la ventana emergente: los
 * avisos (los pinta el envoltorio) y cerrar. Al cerrarla, el listado se actualiza.
 *
 * @var string $url Listado del banco
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'Reviewed transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'Close this window to go back to the list: it now shows the update.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<p class="asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>
