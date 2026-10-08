<?php
/**
 * Añadir o cambiar un material del inventario (doc 32).
 *
 * El «+» junto al tipo abre los tipos en la ventana emergente; al cerrarla,
 * inventario.js actualiza el desplegable sin recargar (no se pierde lo escrito).
 *
 * @var array|null        $m         El material (null: uno nuevo)
 * @var array<int, array> $tipos
 * @var array             $borrador  Lo escrito la vez anterior, si no se pudo guardar
 * @var string            $url
 * @var string            $url_lista
 * @var string            $url_tipos
 * @var callable          $usuario   id => nombre
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;

defined( 'ABSPATH' ) || exit;

$euros = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
$valor = static function ( string $campo, $actual ) use ( $borrador ): string {
	return array_key_exists( $campo, $borrador ) ? (string) $borrador[ $campo ] : (string) $actual;
};
$id     = $m ? (int) $m['id'] : 0;
$fecha  = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$tipo   = (int) $valor( 'tipo', $m ? $m['tipo'] : 0 );
$activo = ! $m || null === $m['baja_en'];
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( '← Inventario' ); ?></a></p>

<section class="asde-card">
	<h2 class="asde-card__title"><?php echo $m ? esc_html( $m['descripcion'] ) : esc_html( asde_t( 'Añadir material' ) ); ?></h2>
	<?php if ( $m && ! $activo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php echo esc_html( sprintf( asde_t( 'Dado de baja el %1$s por %2$s.' ), $fecha( $m['baja_en'] ), $usuario( $m['baja_por'] ) ?: '—' ) ); ?>
			<?php echo '' !== $m['baja_motivo'] ? esc_html( sprintf( asde_t( 'Motivo: %s' ), $m['baja_motivo'] ) ) : ''; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--inventario">
		<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
		<input type="hidden" name="asde_action" value="material_guardar">
		<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
		<p>
			<label for="asde-inv-desc"><?php asde_e( 'Descripción' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
			<input type="text" id="asde-inv-desc" name="descripcion" maxlength="255" required value="<?php echo esc_attr( $valor( 'descripcion', $m['descripcion'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: Tienda canadiense 4 plazas' ) ); ?>">
		</p>
		<p>
			<label for="asde-inv-tipo-m"><?php asde_e( 'Tipo' ); ?></label>
			<span class="asde-inv-tipo">
				<select id="asde-inv-tipo-m" name="tipo" data-asde-inv-tipos>
					<option value="0"><?php asde_e( 'Sin tipo' ); ?></option>
					<?php foreach ( $tipos as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tipo, $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<a class="button asde-button-secundario asde-inv-mas" href="<?php echo esc_url( $url_tipos ); ?>" data-asde-modal data-asde-inv-mas title="<?php echo esc_attr( asde_a( 'Crear o cambiar los tipos de material' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Crear o cambiar los tipos de material' ) ); ?>">+</a>
			</span>
		</p>
		<div class="asde-form__columnas">
			<p>
				<label for="asde-inv-cant"><?php asde_e( 'Cantidad' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="number" id="asde-inv-cant" name="cantidad" min="0" max="<?php echo (int) MaterialesTable::MAX_CANTIDAD; ?>" step="1" required inputmode="numeric" value="<?php echo esc_attr( $valor( 'cantidad', $m ? $m['cantidad'] : 1 ) ); ?>">
			</p>
			<p>
				<label for="asde-inv-precio"><?php asde_e( 'Precio por unidad (€)' ); ?></label>
				<input type="text" id="asde-inv-precio" name="precio" inputmode="decimal" size="10" value="<?php echo esc_attr( $valor( 'precio', $euros( $m['precio'] ?? null ) ) ); ?>" placeholder="0,00">
			</p>
			<p>
				<label for="asde-inv-anio"><?php asde_e( 'Año de compra (opcional)' ); ?></label>
				<input type="number" id="asde-inv-anio" name="anio" min="<?php echo (int) MaterialesTable::ANIO_MINIMO; ?>" max="<?php echo (int) wp_date( 'Y' ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( $valor( 'anio', $m['anio'] ?? '' ) ); ?>">
			</p>
			<p>
				<label for="asde-inv-serie"><?php asde_e( 'Nº de serie' ); ?></label>
				<input type="text" id="asde-inv-serie" name="num_serie" maxlength="120" value="<?php echo esc_attr( $valor( 'num_serie', $m['num_serie'] ?? '' ) ); ?>">
			</p>
		</div>
		<p>
			<label for="asde-inv-notas"><?php asde_e( 'Notas' ); ?></label>
			<textarea id="asde-inv-notas" name="notas" rows="2" maxlength="500" placeholder="<?php echo esc_attr( asde_a( 'Dónde se guarda, estado, a quién se ha prestado…' ) ); ?>"><?php echo esc_textarea( $valor( 'notas', $m['notas'] ?? '' ) ); ?></textarea>
		</p>
		<p class="asde-form__acciones">
			<button type="submit" class="button button-primary"><?php echo $m ? esc_html( asde_t( 'Guardar' ) ) : esc_html( asde_t( 'Añadir al inventario' ) ); ?></button>
			<?php if ( ! $m ) : ?>
				<button type="submit" class="button" name="otro" value="1"><?php asde_e( 'Añadir y crear otro' ); ?></button>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( $m ) : ?>
		<p class="asde-muted">
			<?php echo esc_html( sprintf( asde_t( 'Creado el %1$s por %2$s.' ), $fecha( $m['creado'] ), $usuario( $m['creado_por'] ) ?: '—' ) ); ?>
			<?php echo $m['modificado'] ? esc_html( sprintf( asde_t( 'Último cambio el %1$s por %2$s.' ), $fecha( $m['modificado'] ), $usuario( $m['modificado_por'] ) ?: '—' ) ) : ''; ?>
		</p>
	<?php endif; ?>
</section>

<?php if ( $m ) : ?>
	<section class="asde-card">
		<?php if ( $activo ) : ?>
			<h3 class="asde-card__title"><?php asde_e( 'Dar de baja' ); ?></h3>
			<p class="asde-muted"><?php asde_e( 'Para el material que se ha roto, perdido o regalado. No se borra: deja de salir en el inventario y se puede recuperar.' ); ?></p>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
				<input type="hidden" name="asde_action" value="material_baja">
				<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
				<label for="asde-inv-motivo" class="screen-reader-text"><?php asde_e( 'Motivo' ); ?></label>
				<input type="text" id="asde-inv-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo (opcional)' ) ); ?>">
				<button type="submit" class="button"><?php asde_e( 'Dar de baja' ); ?></button>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>">
				<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
				<input type="hidden" name="asde_action" value="material_recuperar">
				<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
				<button type="submit" class="button"><?php asde_e( 'Recuperar en el inventario' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
