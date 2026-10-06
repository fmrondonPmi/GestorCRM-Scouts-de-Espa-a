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
	<h2 class="asde-card__title"><?php asde_e( 'Movimientos revisados' ); ?></h2>
	<p><?php asde_e( 'Cierra esta ventana para volver al listado: ya sale actualizado.' ); ?></p>
	<p class="asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
</section>
