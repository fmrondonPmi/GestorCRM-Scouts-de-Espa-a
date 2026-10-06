<?php
/**
 * Paginación de un listado.
 *
 * @var int                     $pagina
 * @var int                     $paginas
 * @var array<int|string>       $lista      Nº de página o '…'
 * @var int                     $total
 * @var int                     $desde
 * @var int                     $hasta
 * @var int                     $por_pagina
 * @var int[]                   $opciones
 * @var callable(int): string   $url
 * @var array<string, string>   $ocultos    Parámetros que conserva el cambio de tamaño
 */
defined( 'ABSPATH' ) || exit;

$boton = static function ( bool $activo, int $p, string $texto, string $etiqueta ) use ( $url ) {
	return $activo
		? sprintf( '<li><a href="%s" aria-label="%s">%s</a></li>', esc_url( $url( $p ) ), esc_attr( $etiqueta ), esc_html( $texto ) )
		: sprintf( '<li><span class="is-disabled" aria-hidden="true">%s</span></li>', esc_html( $texto ) );
};
?>
<nav class="asde-paginacion" aria-label="<?php echo esc_attr( asde_a( 'Paginación' ) ); ?>">
	<p class="asde-paginacion__info"><?php asde_e( 'Mostrando' ); ?> <?php echo (int) $desde; ?>–<?php echo (int) $hasta; ?> <?php asde_e( 'de' ); ?> <?php echo (int) $total; ?></p>
	<?php if ( $paginas > 1 ) : ?>
		<ul class="asde-paginacion__botones">
			<?php
			echo $boton( $pagina > 1, 1, '«', esc_html( asde_t( 'Primera página' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $boton( $pagina > 1, $pagina - 1, '‹', esc_html( asde_t( 'Página anterior' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $lista as $p ) {
				if ( '…' === $p ) {
					echo '<li><span class="asde-paginacion__hueco" aria-hidden="true">…</span></li>';
				} elseif ( $p === $pagina ) {
					echo '<li><span class="is-current" aria-current="page">' . (int) $p . '</span></li>';
				} else {
					echo '<li><a href="' . esc_url( $url( (int) $p ) ) . '" aria-label="' . esc_attr( sprintf( asde_t( 'Página %s' ), $p ) ) . '">' . (int) $p . '</a></li>';
				}
			}
			echo $boton( $pagina < $paginas, $pagina + 1, '›', esc_html( asde_t( 'Página siguiente' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $boton( $pagina < $paginas, $paginas, '»', esc_html( asde_t( 'Última página' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</ul>
	<?php endif; ?>
	<form method="get" class="asde-paginacion__tamano">
		<?php foreach ( $ocultos as $k => $v ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<?php endforeach; ?>
		<label for="asde-por-pagina"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-por-pagina" name="por_pagina" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
			<?php foreach ( $opciones as $o ) : ?>
				<option value="<?php echo (int) $o; ?>" <?php selected( $por_pagina, $o ); ?>><?php echo (int) $o; ?></option>
			<?php endforeach; ?>
		</select>
		<span><?php asde_e( 'por página' ); ?></span>
		<noscript><button type="submit" class="button"><?php asde_e( 'Aplicar' ); ?></button></noscript>
	</form>
</nav>
