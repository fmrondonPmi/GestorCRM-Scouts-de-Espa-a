<?php
/** @var string $url_recuperar */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php asde_e( 'Enlace no válido' ); ?></h2>
	<p><?php asde_e( 'Este enlace no es válido o ha caducado.' ); ?></p>
	<ul>
		<li><?php asde_e( 'Si ya tienes contraseña,' ); ?> <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php asde_e( 'inicia sesión' ); ?></a>.</li>
		<li><?php asde_e( 'Si la has olvidado,' ); ?> <a href="<?php echo esc_url( $url_recuperar ); ?>"><?php asde_e( 'pide un enlace nuevo' ); ?></a>.</li>
		<li><?php asde_e( 'Si era una invitación, pide a la Secretaría de tu grupo que te la reenvíe.' ); ?></li>
	</ul>
</section>
