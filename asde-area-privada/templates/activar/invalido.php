<?php
/** @var string $url_recuperar */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2>Enlace no válido</h2>
	<p>Este enlace no es válido o ha caducado.</p>
	<ul>
		<li>Si ya tienes contraseña, <a href="<?php echo esc_url( wp_login_url() ); ?>">inicia sesión</a>.</li>
		<li>Si la has olvidado, <a href="<?php echo esc_url( $url_recuperar ); ?>">pide un enlace nuevo</a>.</li>
		<li>Si era una invitación, pide a la Secretaría de tu grupo que te la reenvíe.</li>
	</ul>
</section>
