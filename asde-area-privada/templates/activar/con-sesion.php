<?php
/** @var string $url_area */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php asde_e( 'Ya has iniciado sesión' ); ?></h2>
	<p><?php asde_e( 'Para activar otra cuenta, cierra antes tu sesión.' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( $url_area ); ?>"><?php asde_e( 'Ir al área privada' ); ?></a></p>
</section>
