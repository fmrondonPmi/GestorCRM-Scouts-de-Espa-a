<?php
/**
 * Tienda · cómo se elige la cantidad de un producto (v0.45, doc 52, T2).
 *
 * - Un producto con una sola variante (sin tallas): solo la cantidad.
 * - Un producto con varias (tallas, modelos…): filas «cantidad + desplegable de talla». Se puede pedir
 *   la misma prenda en varias tallas (hasta 4 filas); con JavaScript solo se ve la primera y un botón
 *   «Añadir otra talla» enseña las demás; sin JavaScript se ven todas.
 *
 * Lo que llega: `cant[id de variante]` (una variante) o `lin[id de producto][cant][]` y
 * `lin[id de producto][var][]` (varias). El servidor lo junta todo en `cant[variante]`.
 *
 * @var array                  $prod      Producto
 * @var array[]                $variantes Variantes activas del producto
 * @var bool                   $es_socio  Para el precio de socio
 * @var array<int, int>        $previas   Lo que se había escrito (id de variante => cantidad), si el pedido no se pudo hacer
 * @var bool                   $existencias Si se enseñan «quedan n» (en la compra con sesión)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;

defined( 'ABSPATH' ) || exit;

$previas     = $previas ?? array();
$existencias = $existencias ?? false;
$max         = (int) ProductosTable::MAX_LIMITE;

/** «M · 12,00 € (quedan 3)» / «L · 12,00 € (agotado)». */
$texto = static function ( array $v ) use ( $prod, $es_socio, $existencias ): string {
	$precio = Servicio::precio_variante( $prod, $v, $es_socio );
	$t      = 'Única' === $v['etiqueta'] ? '' : VariantesTable::etiqueta( $v ) . ' · ';
	$t     .= null === $precio ? asde_t( 'Precio por confirmar' ) : Calculadora::euros( $precio );
	if ( ! VariantesTable::hay( $v ) ) {
		$t .= ' (' . asde_t( 'Agotado' ) . ')';
	} elseif ( $existencias && 0 === $v['ilimitado'] && $v['existencias'] <= 5 ) {
		$t .= ' (' . sprintf( asde_n( 'Queda %d', 'Quedan %d', $v['existencias'] ), $v['existencias'] ) . ')';
	}
	return $t;
};
?>
<?php if ( 1 === count( $variantes ) ) : ?>
	<?php $v = $variantes[0]; ?>
	<ul class="asde-producto__variantes">
		<li>
			<label for="asde-var-<?php echo (int) $v['id']; ?>"><?php echo esc_html( $texto( $v ) ); ?></label>
			<input type="number" id="asde-var-<?php echo (int) $v['id']; ?>" name="cant[<?php echo (int) $v['id']; ?>]" value="<?php echo (int) ( $previas[ $v['id'] ] ?? 0 ); ?>" min="0" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric" <?php disabled( ! VariantesTable::hay( $v ) ); ?> aria-label="<?php echo esc_attr( sprintf( asde_t( 'Cantidad: %s' ), $prod['nombre'] ) ); ?>">
		</li>
	</ul>
<?php else : ?>
	<?php
	$filas = array_slice( array_map( null, array_keys( $previas ), array_values( $previas ) ), 0, 4 );
	$n     = min( 4, count( $variantes ) );
	?>
	<div class="asde-producto__lineas" data-asde-tienda-lineas>
		<?php for ( $k = 0; $k < $n; $k++ ) : ?>
			<?php
			$sel  = (int) ( $filas[ $k ][0] ?? 0 );
			$cant = (int) ( $filas[ $k ][1] ?? 0 );
			$id_c = 'asde-lc-' . (int) $prod['id'] . '-' . $k;
			$id_t = 'asde-lt-' . (int) $prod['id'] . '-' . $k;
			?>
			<p class="asde-producto__linea"<?php echo $k > 0 && 0 === $cant ? ' data-asde-linea-extra' : ''; ?>>
				<label for="<?php echo esc_attr( $id_c ); ?>"><?php asde_e( 'Cantidad' ); ?></label>
				<input type="number" id="<?php echo esc_attr( $id_c ); ?>" name="lin[<?php echo (int) $prod['id']; ?>][cant][]" value="<?php echo (int) $cant; ?>" min="0" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric">
				<label for="<?php echo esc_attr( $id_t ); ?>"><?php asde_e( 'Talla' ); ?></label>
				<select id="<?php echo esc_attr( $id_t ); ?>" name="lin[<?php echo (int) $prod['id']; ?>][var][]">
					<option value=""><?php asde_e( 'Elige la talla…' ); ?></option>
					<?php foreach ( $variantes as $v ) : ?>
						<option value="<?php echo (int) $v['id']; ?>" <?php selected( $sel, (int) $v['id'] ); ?> <?php disabled( ! VariantesTable::hay( $v ) ); ?>><?php echo esc_html( $texto( $v ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endfor; ?>
		<?php if ( $n > 1 ) : ?>
			<p><button type="button" class="asde-btn-link" data-asde-linea-mas hidden><?php asde_e( 'Añadir otra talla' ); ?></button></p>
		<?php endif; ?>
	</div>
<?php endif; ?>
