<?php
/**
 * Cargar recetas desde un CSV (v0.41, doc 49, B1).
 *
 * @var array|null $resultado  recetas, ingredientes_nuevos, errores
 * @var string     $ejemplo    CSV de ejemplo, para copiar
 * @var string     $url
 * @var string     $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Cargar recetas desde un CSV' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( 'Volver al recetario' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Una fila por cada ingrediente de cada receta, con las columnas: Receta; Tipo; Porciones; Ingrediente; Cantidad; Unidad. Las unidades son g, kg, ml, l, ud, cda y pizca. Los ingredientes que no existan se crean sin alérgenos y sin revisar: anótalos después en «Ingredientes». No se pisa ninguna receta que ya exista.' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
		<?php wp_nonce_field( 'asde_menus' ); ?>
		<input type="hidden" name="asde_action" value="recetas_importar">
		<p>
			<label for="asde-rci-fichero"><?php asde_e( 'Fichero CSV' ); ?></label>
			<input type="file" id="asde-rci-fichero" name="fichero" accept=".csv,.txt">
		</p>
		<p><button type="submit" class="button"><?php asde_e( 'Cargar' ); ?></button></p>
	</form>
	<h3><?php asde_e( 'Ejemplo' ); ?></h3>
	<pre class="asde-pre"><?php echo esc_html( $ejemplo ); ?></pre>
</section>

<?php if ( $resultado ) : ?>
	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php asde_e( 'Resultado de la carga' ); ?></h3>
		<p><strong><?php echo esc_html( sprintf( asde_n( '%d receta cargada.', '%d recetas cargadas.', (int) $resultado['recetas'] ), (int) $resultado['recetas'] ) ); ?></strong>
			<?php echo esc_html( sprintf( asde_n( '%d ingrediente nuevo, por revisar.', '%d ingredientes nuevos, por revisar.', (int) $resultado['ingredientes_nuevos'] ), (int) $resultado['ingredientes_nuevos'] ) ); ?></p>
		<?php if ( $resultado['errores'] ) : ?>
			<h4><?php asde_e( 'No se han cargado' ); ?></h4>
			<ul>
				<?php foreach ( $resultado['errores'] as $e ) : ?>
					<li><?php echo esc_html( sprintf( asde_t( 'Fila %1$d: %2$s' ), (int) $e['fila'], $e['motivo'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
<?php endif; ?>
