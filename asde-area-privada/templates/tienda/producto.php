<?php
/**
 * Un producto de la tienda: crear o editar, con sus variantes y existencias (doc 48, fase 2).
 *
 * @var array|null         $p           null = producto nuevo
 * @var array<int, array>  $variantes
 * @var array              $borrador    Lo escrito en un guardado que falló
 * @var string[]           $categorias
 * @var int                $nuevas      Variantes nuevas que caben en un guardado
 * @var string             $url
 * @var string             $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;

defined( 'ABSPATH' ) || exit;

$id     = $p ? (int) $p['id'] : 0;
$euros  = static fn( $v ): string => null === $v || '' === $v ? '' : number_format( (float) $v, 2, ',', '' );
// Lo que se enseña en cada campo: lo que se escribió y no se pudo guardar, o lo guardado.
$val    = static function ( string $campo, $guardado ) use ( $borrador ) {
	return array_key_exists( $campo, $borrador ) ? wp_unslash( $borrador[ $campo ] ) : $guardado;
};
$marca  = static function ( string $campo, int $guardado ) use ( $borrador ): bool {
	return $borrador ? '1' === (string) ( $borrador[ $campo ] ?? '' ) : 1 === $guardado;
};
$imagen = $p ? Config::imagen( $p['imagen'], 'thumbnail' ) : '';
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( '← Productos' ); ?></a></p>

<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_tienda_producto_' . $id ); ?>
	<input type="hidden" name="asde_action" value="producto_guardar">
	<input type="hidden" name="producto" value="<?php echo (int) $id; ?>">

	<section class="asde-card" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php echo $p ? esc_html( $p['nombre'] ) : esc_html( asde_t( 'Nuevo producto' ) ); ?>
			<?php if ( $p && ! $p['activo'] ) : ?>
				<span class="asde-badge asde-badge--uniforme-cancelado"><?php asde_e( 'Archivado' ); ?></span>
			<?php endif; ?>
		</h2>
		<p>
			<label for="asde-tp-nombre"><?php asde_e( 'Nombre' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
			<input type="text" id="asde-tp-nombre" name="nombre" value="<?php echo esc_attr( $val( 'nombre', $p['nombre'] ?? '' ) ); ?>" maxlength="120" size="40" required>
		</p>
		<p>
			<label for="asde-tp-desc"><?php asde_e( 'Descripción' ); ?></label>
			<textarea id="asde-tp-desc" name="descripcion" rows="3" maxlength="2000"><?php echo esc_textarea( $val( 'descripcion', $p['descripcion'] ?? '' ) ); ?></textarea>
		</p>
		<p>
			<label for="asde-tp-cat"><?php asde_e( 'Categoría' ); ?></label>
			<input type="text" id="asde-tp-cat" name="categoria" value="<?php echo esc_attr( $val( 'categoria', $p['categoria'] ?? '' ) ); ?>" maxlength="60" list="asde-tp-cats">
			<datalist id="asde-tp-cats">
				<?php foreach ( $categorias as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>">
				<?php endforeach; ?>
			</datalist>
		</p>
		<p>
			<label for="asde-tp-vis"><?php asde_e( 'Quién lo ve y lo puede comprar' ); ?></label>
			<select id="asde-tp-vis" name="visibilidad">
				<?php foreach ( ProductosTable::VISIBILIDADES as $clave => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $val( 'visibilidad', $p['visibilidad'] ?? ProductosTable::VIS_TODOS ), $clave ); ?>><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="asde-muted"><?php asde_e( 'Un producto «solo socios» no sale a quien no ha iniciado sesión, ni en la lista ni con su dirección.' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-precio"><?php asde_e( 'Precio (€)' ); ?></label>
			<input type="text" id="asde-tp-precio" name="precio" value="<?php echo esc_attr( $val( 'precio', $euros( $p['precio'] ?? null ) ) ); ?>" inputmode="decimal" size="8">
			<span class="asde-muted"><?php asde_e( 'Vacío = «precio por confirmar»: se puede pedir y Tesorería cobra el importe real.' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-psocio"><?php asde_e( 'Precio de socio (€, opcional)' ); ?></label>
			<input type="text" id="asde-tp-psocio" name="precio_socio" value="<?php echo esc_attr( $val( 'precio_socio', $euros( $p['precio_socio'] ?? null ) ) ); ?>" inputmode="decimal" size="8">
			<span class="asde-muted"><?php asde_e( 'Si lo pones, los socios con sesión pagan este precio en vez del general.' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-limite"><?php asde_e( 'Máximo por persona (0 = sin límite)' ); ?></label>
			<input type="number" id="asde-tp-limite" name="limite_persona" value="<?php echo (int) $val( 'limite_persona', $p['limite_persona'] ?? 0 ); ?>" min="0" max="<?php echo (int) ProductosTable::MAX_LIMITE; ?>" class="small-text">
		</p>
		<label class="asde-choice"><input type="checkbox" name="requiere_proveedor" value="1" <?php checked( $marca( 'requiere_proveedor', (int) ( $p['requiere_proveedor'] ?? 0 ) ) ); ?>><span><?php asde_e( 'Se encarga al proveedor (pasa por «incluido en el pedido del grupo» antes de entregarse)' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="sale_inventario" value="1" <?php checked( $marca( 'sale_inventario', (int) ( $p['sale_inventario'] ?? 0 ) ) ); ?>><span><?php asde_e( 'Se puede servir del inventario de material del grupo (solo las camisas del uniforme)' ); ?></span></label>
		<p>
			<?php asde_e( 'Imagen:' ); ?><br>
			<?php if ( '' !== $imagen ) : ?>
				<img class="asde-config-img" src="<?php echo esc_url( $imagen ); ?>" alt="<?php echo esc_attr( $p['nombre'] ); ?>">
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="imagen_quitar" value="1"><span><?php asde_e( 'Quitar' ); ?></span></label>
			<?php endif; ?>
			<input type="file" name="imagen" accept="image/png,image/jpeg,image/webp,image/gif">
		</p>
	</section>

	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php asde_e( 'Variantes y existencias' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Una variante es lo que se compra: una talla, un color… Si el producto no tiene variantes, deja una sola («Única»). Las existencias no se escriben: se suman o restan en «Cambiar existencias» (+5, -2) y queda anotado. «Sin límite» no cuenta unidades (lo que se encarga al proveedor).' ); ?></p>
		<?php if ( $variantes ) : ?>
			<table class="asde-table asde-table--tienda-variantes">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Variante' ); ?></th>
						<th scope="col"><?php asde_e( 'Referencia' ); ?></th>
						<th scope="col"><?php asde_e( 'Precio propio (€)' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'Existencias' ); ?></th>
						<th scope="col"><?php asde_e( 'Cambiar existencias' ); ?></th>
						<th scope="col"><?php asde_e( 'Sin límite' ); ?></th>
						<th scope="col"><?php asde_e( 'En venta' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $variantes as $v ) : ?>
						<?php $vid = (int) $v['id']; ?>
						<tr<?php echo $v['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
							<td data-label="<?php echo esc_attr( asde_a( 'Variante' ) ); ?>"><input type="text" name="v_etiqueta[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $v['etiqueta'] ); ?>" maxlength="80" size="18" aria-label="<?php echo esc_attr( asde_a( 'Variante' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Referencia' ) ); ?>"><input type="text" name="v_sku[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $v['sku'] ); ?>" maxlength="60" size="10" aria-label="<?php echo esc_attr( asde_a( 'Referencia' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Precio propio (€)' ) ); ?>"><input type="text" name="v_precio[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $euros( $v['precio'] ) ); ?>" inputmode="decimal" size="7" aria-label="<?php echo esc_attr( asde_a( 'Precio propio (€)' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Existencias' ) ); ?>" class="asde-num"><?php echo $v['ilimitado'] ? esc_html( asde_t( 'Sin límite' ) ) : (int) $v['existencias']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Cambiar existencias' ) ); ?>"><input type="text" name="v_ajuste[<?php echo (int) $vid; ?>]" value="" inputmode="numeric" size="6" placeholder="+5" aria-label="<?php echo esc_attr( asde_a( 'Cambiar existencias' ) ); ?>" <?php disabled( (bool) $v['ilimitado'] ); ?>></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Sin límite' ) ); ?>"><input type="checkbox" name="v_ilimitado[<?php echo (int) $vid; ?>]" value="1" <?php checked( (bool) $v['ilimitado'] ); ?> aria-label="<?php echo esc_attr( asde_a( 'Sin límite' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( asde_a( 'En venta' ) ); ?>"><input type="checkbox" name="v_activa[<?php echo (int) $vid; ?>]" value="1" <?php checked( (bool) $v['activo'] ); ?> aria-label="<?php echo esc_attr( asde_a( 'En venta' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-tp-motivo"><?php asde_e( 'Motivo del cambio de existencias (opcional)' ); ?></label>
				<input type="text" id="asde-tp-motivo" name="motivo_ajuste" value="" maxlength="200" size="40" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: llegada del pedido, recuento…' ) ); ?>">
			</p>
		<?php endif; ?>

		<h4><?php echo $variantes ? esc_html( asde_t( 'Añadir variantes' ) ) : esc_html( asde_t( 'Variantes' ) ); ?></h4>
		<?php for ( $i = 1; $i <= $nuevas; $i++ ) : ?>
			<p class="asde-form__fila">
				<input type="text" name="nueva_etiqueta_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_etiqueta_' . $i, '' ) ); ?>" maxlength="80" size="16" placeholder="<?php echo esc_attr( asde_a( 'Variante (talla, color…)' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Variante' ) ); ?>">
				<input type="text" name="nueva_sku_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_sku_' . $i, '' ) ); ?>" maxlength="60" size="10" placeholder="<?php echo esc_attr( asde_a( 'Referencia' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Referencia' ) ); ?>">
				<input type="text" name="nueva_precio_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_precio_' . $i, '' ) ); ?>" inputmode="decimal" size="7" placeholder="<?php echo esc_attr( asde_a( 'Precio propio' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Precio propio (€)' ) ); ?>">
				<input type="text" name="nueva_existencias_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_existencias_' . $i, '' ) ); ?>" inputmode="numeric" size="7" placeholder="<?php echo esc_attr( asde_a( 'Existencias' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Existencias' ) ); ?>">
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="nueva_ilimitado_<?php echo (int) $i; ?>" value="1" <?php checked( '1' === (string) $val( 'nueva_ilimitado_' . $i, '' ) ); ?>><span><?php asde_e( 'Sin límite' ); ?></span></label>
			</p>
		<?php endfor; ?>
	</section>

	<p class="asde-form__acciones">
		<button type="submit" class="button button-primary"><?php asde_e( 'Guardar' ); ?></button>
		<?php if ( ! $p ) : ?>
			<button type="submit" name="otro" value="1" class="button asde-button-secundario"><?php asde_e( 'Guardar y añadir otro' ); ?></button>
		<?php endif; ?>
		<a class="button asde-button-secundario" href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( 'Volver a la lista' ); ?></a>
	</p>
</form>

<?php if ( $p ) : ?>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>"<?php echo $p['activo'] ? ' data-asde-confirmar="' . esc_attr( asde_js( '¿Archivar este producto? Dejará de venderse (se puede recuperar).' ) ) . '"' : ''; ?>>
		<?php wp_nonce_field( 'asde_tienda_producto_' . $id ); ?>
		<input type="hidden" name="producto" value="<?php echo (int) $id; ?>">
		<input type="hidden" name="asde_action" value="<?php echo $p['activo'] ? 'producto_archivar' : 'producto_recuperar'; ?>">
		<p class="asde-form__acciones">
			<button type="submit" class="button asde-button-secundario"><?php echo $p['activo'] ? esc_html( asde_t( 'Archivar el producto' ) ) : esc_html( asde_t( 'Recuperar el producto' ) ); ?></button>
		</p>
	</form>
<?php endif; ?>
