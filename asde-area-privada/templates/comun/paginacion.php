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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$boton = static function ( bool $activo, int $p, string $texto, string $etiqueta ) use ( $url ) {
	return $activo
		? sprintf( '<li><a href="%s" aria-label="%s">%s</a></li>', esc_url( $url( $p ) ), esc_attr( $etiqueta ), esc_html( $texto ) )
		: sprintf( '<li><span class="is-disabled" aria-hidden="true">%s</span></li>', esc_html( $texto ) );
};
?>
<nav class="asde-paginacion" aria-label="<?php echo esc_attr( esc_attr__( 'Pagination', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
	<p class="asde-paginacion__info"><?php esc_html_e( 'Showing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $desde; ?>–<?php echo (int) $hasta; ?> <?php esc_html_e( 'of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $total; ?></p>
	<?php if ( $paginas > 1 ) : ?>
		<ul class="asde-paginacion__botones">
			<?php
			echo wp_kses( $boton( $pagina > 1, 1, '«', esc_html( __( 'First page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() );
			echo wp_kses( $boton( $pagina > 1, $pagina - 1, '‹', esc_html( __( 'Previous page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() );
			foreach ( $lista as $p ) {
				if ( '…' === $p ) {
					echo '<li><span class="asde-paginacion__hueco" aria-hidden="true">…</span></li>';
				} elseif ( $p === $pagina ) {
					echo '<li><span class="is-current" aria-current="page">' . (int) $p . '</span></li>';
				} else {
					echo '<li><a href="' . esc_url( $url( (int) $p ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: text */ __( 'Page %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p ) ) . '">' . (int) $p . '</a></li>';
				}
			}
			echo wp_kses( $boton( $pagina < $paginas, $pagina + 1, '›', esc_html( __( 'Next page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() );
			echo wp_kses( $boton( $pagina < $paginas, $paginas, '»', esc_html( __( 'Last page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() );
			?>
		</ul>
	<?php endif; ?>
	<form method="get" class="asde-paginacion__tamano">
		<?php foreach ( $ocultos as $k => $v ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<?php endforeach; ?>
		<?php wp_nonce_field( 'asde_ap_por_pagina', '_asde_pp', false ); ?>
		<label for="asde-por-pagina"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-por-pagina" name="por_pagina" data-asde-autoenviar>
			<?php foreach ( $opciones as $o ) : ?>
				<option value="<?php echo (int) $o; ?>" <?php selected( $por_pagina, $o ); ?>><?php echo (int) $o; ?></option>
			<?php endforeach; ?>
		</select>
		<span><?php esc_html_e( 'per page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
	</form>
</nav>
