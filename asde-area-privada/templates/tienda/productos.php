<?php
/**
 * Productos de la tienda (doc 48, fase 2): la lista de la administración.
 *
 * @var array<int, array>  $productos
 * @var array<int, array>  $variantes   producto => variantes
 * @var string[]           $categorias
 * @var string             $categoria   Filtro
 * @var string             $q           Filtro
 * @var bool               $archivados  Se ven también los archivados
 * @var string             $url
 * @var string             $url_nuevo
 * @var string             $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Inventario de la tienda' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_nuevo ); ?>"><?php asde_e( 'Añadir producto' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_csv ); ?>"><?php asde_e( 'Cargar desde CSV' ); ?></a>
		</span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Lo que está aquí es lo que la tienda puede vender. Cada producto tiene una o varias variantes (tallas, colores…) con sus existencias. Los «solo socios» solo los ven quienes han iniciado sesión.' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<label for="asde-tp-cat"><?php asde_e( 'Categoría' ); ?></label>
		<select id="asde-tp-cat" name="categoria">
			<option value=""><?php asde_e( 'Todas' ); ?></option>
			<?php foreach ( $categorias as $c ) : ?>
				<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $categoria, $c ); ?>><?php echo esc_html( $c ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-tp-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-tp-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre o referencia' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivados" value="1" <?php checked( $archivados ); ?>><span><?php asde_e( 'Ver también los archivados' ); ?></span></label>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<?php if ( ! $productos ) : ?>
		<p><?php asde_e( 'No hay productos con ese filtro. Empieza con «Añadir producto» o, si ya los tienes en una hoja de cálculo, con «Cargar desde CSV».' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--tienda-productos">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Producto' ); ?></th>
					<th scope="col"><?php asde_e( 'Categoría' ); ?></th>
					<th scope="col"><?php asde_e( 'Visible para' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Precio' ); ?></th>
					<th scope="col"><?php asde_e( 'Variantes' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Existencias' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $productos as $p ) : ?>
					<?php
					$vars  = $variantes[ $p['id'] ] ?? array();
					$total = VariantesTable::total( $vars );
					?>
					<tr<?php echo $p['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
						<td data-label="<?php echo esc_attr( asde_a( 'Producto' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'producto', $p['id'], $url ) ); ?>"><?php echo esc_html( $p['nombre'] ); ?></a>
							<?php if ( ! $p['activo'] ) : ?>
								<span class="asde-badge asde-badge--uniforme-cancelado"><?php asde_e( 'Archivado' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Categoría' ) ); ?>"><?php echo '' !== $p['categoria'] ? esc_html( $p['categoria'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Visible para' ) ); ?>"><?php echo ProductosTable::VIS_SOCIOS === $p['visibilidad'] ? esc_html( asde_t( 'Solo socios' ) ) : esc_html( asde_t( 'Todos' ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Precio' ) ); ?>" class="asde-num">
							<?php if ( null === $p['precio'] ) : ?>
								<span class="asde-muted"><?php asde_e( 'Por confirmar' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( Calculadora::euros( $p['precio'] ) ); ?>
								<?php if ( null !== $p['precio_socio'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'socios: %s' ), Calculadora::euros( $p['precio_socio'] ) ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Variantes' ) ); ?>">
							<?php
							$etiquetas = array();
							foreach ( $vars as $v ) {
								if ( 1 === $v['activo'] ) {
									$etiquetas[] = VariantesTable::etiqueta( $v );
								}
							}
							echo $etiquetas ? esc_html( wp_trim_words( implode( ', ', $etiquetas ), 12, '…' ) ) : '<span class="asde-muted">—</span>';
							?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Existencias' ) ); ?>" class="asde-num">
							<?php echo null === $total ? esc_html( asde_t( 'Sin límite' ) ) : (int) $total; ?>
							<?php if ( null !== $total && 0 === $total && $p['activo'] ) : ?>
								<span class="asde-badge asde-badge--inv-pocas"><?php asde_e( 'Agotado' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
