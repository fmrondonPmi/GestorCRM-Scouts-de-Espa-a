<?php
/** @var string $mensaje */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-no-plegable>
	<h2><?php asde_e( 'Acceso restringido' ); ?></h2>
	<p><?php echo esc_html( $mensaje ); ?></p>
</section>
