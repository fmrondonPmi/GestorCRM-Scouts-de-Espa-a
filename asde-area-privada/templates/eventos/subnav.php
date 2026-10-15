<?php
/**
 * Pestañas de las páginas de eventos (Agenda · Configuración) o de un evento (Resumen · Equipo · Presupuesto).
 *
 * @var array<string, string> $items  clave de página (o URL completa) => etiqueta
 * @var string                $activo
 */
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;
?>
<div class="asde-subnav">
	<ul class="asde-subnav__tabs">
		<?php foreach ( $items as $key => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( str_starts_with( (string) $key, 'http' ) ? (string) $key : Pages::url( (string) $key ) ); ?>"<?php echo $key === $activo ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
