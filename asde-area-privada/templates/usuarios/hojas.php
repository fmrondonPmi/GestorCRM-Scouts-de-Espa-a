<?php
/**
 * Sección «Usuarios» (v0.25): sus páginas son hojas de un mismo libro, como en una
 * hoja de cálculo. La barra de hojas va encima de la hoja que se ve.
 *
 * Sin JavaScript cada hoja es un enlace a su página. Con JavaScript
 * (assets/js/hojas.js) se cambia de hoja sin recargar: la hoja que se abre por
 * primera vez se pide al servidor y las ya abiertas se conservan como se dejaron
 * (filtros, búsqueda, página).
 *
 * @var array<string, array{nombre:string, url:string}> $hojas     Página => hoja
 * @var string                                          $activa    La hoja que se ve
 * @var string                                          $contenido HTML de la hoja (ya escapado por su vista)
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="asde-libro" data-asde-libro>
	<nav class="asde-hojas" aria-label="<?php echo esc_attr( asde_a( 'Hojas de Usuarios' ) ); ?>" data-asde-hojas>
		<ul class="asde-hojas__lista">
			<?php foreach ( $hojas as $clave => $h ) : ?>
				<li class="asde-hojas__item">
					<a class="asde-hojas__pestana<?php echo $clave === $activa ? ' is-active' : ''; ?>" href="<?php echo esc_url( $h['url'] ); ?>" data-asde-hoja-ir="<?php echo esc_attr( $clave ); ?>"<?php echo $clave === $activa ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $h['nombre'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<div class="asde-hoja" data-asde-hoja="<?php echo esc_attr( $activa ); ?>">
		<?php echo $contenido; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de la vista de la hoja, ya escapado. ?>
	</div>
</div>
