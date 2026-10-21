<?php
/**
 * «Alta de grupo» con un enlace que no vale (v0.50, doc 50 fase 5): siempre el mismo mensaje, sea cual sea el motivo.
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php asde_e( 'Alta de grupo' ); ?></h2>
	<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este enlace no es válido o ya no está en uso. Pide una invitación nueva a la oficina.' ); ?></div>
</section>
