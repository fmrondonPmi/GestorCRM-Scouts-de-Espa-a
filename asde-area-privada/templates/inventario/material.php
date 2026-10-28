<?php
/**
 * Añadir o cambiar un material del inventario (doc 32).
 *
 * El «+» junto al tipo abre los tipos en la ventana emergente; al cerrarla,
 * inventario.js actualiza el desplegable sin recargar (no se pierde lo escrito).
 *
 * @var array|null        $m         El material (null: uno nuevo)
 * @var array<int, array> $tipos
 * @var int               $camisa    Id del tipo «Camisa uniforme» (v0.29)
 * @var array             $cfg       Configuración de uniformes (modelos y tallas)
 * @var array             $borrador  Lo escrito la vez anterior, si no se pudo guardar
 * @var string            $url
 * @var string            $url_lista
 * @var string            $url_tipos
 * @var callable          $usuario   id => nombre
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

$euros = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
$valor = static function ( string $campo, $actual ) use ( $borrador ): string {
	return array_key_exists( $campo, $borrador ) ? (string) $borrador[ $campo ] : (string) $actual;
};
$id     = $m ? (int) $m['id'] : 0;
$fecha  = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$tipo   = (int) $valor( 'tipo', $m ? $m['tipo'] : 0 );
$activo = ! $m || null === $m['baja_en'];
$modelo = Config::modelo_de( $valor( 'modelo', $m['modelo'] ?? '' ), $cfg );
$talla  = $valor( 'talla', $m['talla'] ?? '' );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( '← Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card" data-asde-plegable="inventario-material">
	<h2 class="asde-card__title"><?php echo $m ? esc_html( $m['descripcion'] ) : esc_html( __( 'Add equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h2>
	<?php if ( $m && ! $activo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Written off on %1$s by %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $m['baja_en'] ), $usuario( $m['baja_por'] ) ?: '—' ) ); ?>
			<?php echo '' !== $m['baja_motivo'] ? esc_html( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['baja_motivo'] ) ) : ''; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--inventario">
		<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
		<input type="hidden" name="asde_action" value="material_guardar">
		<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
		<p>
			<label for="asde-inv-desc"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true" data-asde-inv-no-camisa>*</span></label>
			<input type="text" id="asde-inv-desc" name="descripcion" maxlength="255" required value="<?php echo esc_attr( $valor( 'descripcion', $m['descripcion'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'For example: 4-person Canadian tent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		</p>
		<p>
			<label for="asde-inv-tipo-m"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<span class="asde-inv-tipo">
				<select id="asde-inv-tipo-m" name="tipo" data-asde-inv-tipos>
					<option value="0"><?php esc_html_e( 'No type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					<?php foreach ( $tipos as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tipo, $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<a class="button asde-button-secundario asde-inv-mas" href="<?php echo esc_url( $url_tipos ); ?>" data-asde-modal data-asde-inv-mas title="<?php echo esc_attr( esc_attr__( 'Create or change the equipment types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Create or change the equipment types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">+</a>
			</span>
		</p>
		<?php /* v0.29: camisas del uniforme. Sin JavaScript se ven siempre; inventario.js las enseña solo con ese tipo. */ ?>
		<div class="asde-inv-camisa" data-asde-inv-camisa="<?php echo (int) $camisa; ?>">
			<div class="asde-form__columnas">
				<p>
					<label for="asde-inv-modelo"><?php esc_html_e( 'Model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
					<select id="asde-inv-modelo" name="modelo">
						<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( array_keys( $cfg['modelos'] ) as $id_modelo ) : ?>
							<option value="<?php echo esc_attr( (string) $id_modelo ); ?>" <?php selected( $modelo, (string) $id_modelo ); ?>><?php echo esc_html( Config::nombre_modelo( $cfg, (string) $id_modelo ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="asde-inv-talla"><?php esc_html_e( 'Size', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
					<select id="asde-inv-talla" name="talla">
						<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( $cfg['modelos'] as $id_modelo => $mod ) : ?>
							<optgroup label="<?php echo esc_attr( Config::nombre_modelo( $cfg, (string) $id_modelo ) ); ?>" data-modelo="<?php echo esc_attr( (string) $id_modelo ); ?>">
								<?php foreach ( $mod['tallas'] as $t ) : ?>
									<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $modelo === (string) $id_modelo && $talla === $t ); ?>><?php echo esc_html( $t ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
			<p class="asde-help"><?php esc_html_e( 'Only for the type "Uniform shirt": uniform management assigns these shirts to orders and deducts them from here. If you don\'t write the description, it is filled in automatically.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</div>
		<div class="asde-form__columnas">
			<p>
				<label for="asde-inv-cant"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="number" id="asde-inv-cant" name="cantidad" min="0" max="<?php echo (int) MaterialesTable::MAX_CANTIDAD; ?>" step="1" required inputmode="numeric" value="<?php echo esc_attr( $valor( 'cantidad', $m ? $m['cantidad'] : 1 ) ); ?>">
			</p>
			<p>
				<label for="asde-inv-precio"><?php esc_html_e( 'Price per unit (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inv-precio" name="precio" inputmode="decimal" size="10" value="<?php echo esc_attr( $valor( 'precio', $euros( $m['precio'] ?? null ) ) ); ?>" placeholder="0,00">
			</p>
			<p>
				<label for="asde-inv-anio"><?php esc_html_e( 'Year of purchase (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="number" id="asde-inv-anio" name="anio" min="<?php echo (int) MaterialesTable::ANIO_MINIMO; ?>" max="<?php echo (int) wp_date( 'Y' ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( $valor( 'anio', $m['anio'] ?? '' ) ); ?>">
			</p>
			<p>
				<label for="asde-inv-serie"><?php esc_html_e( 'Serial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inv-serie" name="num_serie" maxlength="120" value="<?php echo esc_attr( $valor( 'num_serie', $m['num_serie'] ?? '' ) ); ?>">
			</p>
		</div>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label class="asde-choice"><input type="checkbox" name="en_tienda" value="1" <?php checked( 1 === (int) ( $valor( 'en_tienda', $m['en_tienda'] ?? 0 ) ) ); ?>><span><?php esc_html_e( 'Sold in the shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<label for="asde-inv-vtienda"><?php esc_html_e( 'Name in the shop (size, colour…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-inv-vtienda" name="variante_tienda" maxlength="80" value="<?php echo esc_attr( $valor( 'variante_tienda', $m['variante_tienda'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Single', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></label>
		</p>
		<p class="asde-help"><?php esc_html_e( 'Rows with the same description form one shop product, each as a size or variant. The shop\'s stock is the quantity of this row; the price and who it is sold to are set in "Shop inventory".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p>
			<label for="asde-inv-notas"><?php esc_html_e( 'Notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-inv-notas" name="notas" rows="2" maxlength="500" placeholder="<?php echo esc_attr( esc_attr__( 'Where it is kept, status, who it has been lent to…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $valor( 'notas', $m['notas'] ?? '' ) ); ?></textarea>
		</p>
		<p class="asde-form__acciones">
			<button type="submit" class="button button-primary"><?php echo $m ? esc_html( __( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Add to the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button>
			<?php if ( ! $m ) : ?>
				<button type="submit" class="button" name="otro" value="1"><?php esc_html_e( 'Add and create another', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( $m ) : ?>
		<p class="asde-muted">
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Created on %1$s by %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $m['creado'] ), $usuario( $m['creado_por'] ) ?: '—' ) ); ?>
			<?php echo $m['modificado'] ? esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Last change on %1$s by %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $m['modificado'] ), $usuario( $m['modificado_por'] ) ?: '—' ) ) : ''; ?>
		</p>
	<?php endif; ?>
</section>

<?php if ( $m ) : ?>
	<section class="asde-card">
		<?php if ( $activo ) : ?>
			<h3 class="asde-card__title"><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p class="asde-muted"><?php esc_html_e( 'For equipment that has been broken, lost or given away. It is not deleted: it stops appearing in the inventory and can be recovered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
				<input type="hidden" name="asde_action" value="material_baja">
				<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
				<label for="asde-inv-motivo" class="screen-reader-text"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inv-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>">
				<?php wp_nonce_field( 'asde_inventario_material_' . $id ); ?>
				<input type="hidden" name="asde_action" value="material_recuperar">
				<input type="hidden" name="material" value="<?php echo (int) $id; ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Recover in the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
