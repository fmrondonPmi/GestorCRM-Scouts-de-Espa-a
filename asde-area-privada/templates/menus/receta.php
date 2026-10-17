<?php
/**
 * Una receta (v0.41, doc 49, B1): formulario si es del grupo, ficha si es de la biblioteca; alérgenos y escalado.
 *
 * @var array      $r
 * @var array      $borrador     Lo escrito si no se pudo guardar
 * @var bool       $propia       Es del grupo (se puede editar) o nueva
 * @var array[]    $lineas
 * @var array[]    $escaladas    Las líneas con la cantidad para «comensales»
 * @var array      $alergenos    Recetario::alergenos()
 * @var int        $comensales
 * @var array[]    $ingredientes Los que se pueden elegir
 * @var int        $extra        Filas vacías que se añaden
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ing
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Escalado;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;

defined( 'ABSPATH' ) || exit;

$nombre_ing = static fn( array $i ): string => IngredientesTable::COMUN === $i['grupo'] ? asde_t( $i['nombre'] ) : $i['nombre'];
$titulo     = $r['id'] ? ( $propia ? $r['nombre'] : asde_t( $r['nombre'] ) ) : asde_t( 'Receta nueva' );
$val        = static fn( string $k ) => $borrador[ $k ] ?? $r[ $k ] ?? '';
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $titulo ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( 'Volver al recetario' ); ?></a></span>
	</header>

	<?php if ( $r['id'] && ! $propia ) : ?>
		<div class="asde-notice asde-notice--warning"><?php asde_e( 'Es una receta de ejemplo de la biblioteca: no se puede cambiar. Cópiala a tu grupo para editarla.' ); ?></div>
		<form method="post">
			<?php wp_nonce_field( 'asde_menus' ); ?>
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<p><button type="submit" class="button" name="asde_action" value="receta_copiar"><?php asde_e( 'Copiar a mi grupo' ); ?></button></p>
		</form>
	<?php endif; ?>

	<?php if ( $r['id'] || $lineas ) : ?>
		<div class="asde-resumen-alergenos">
			<p>
				<strong><?php asde_e( 'Contiene:' ); ?></strong>
				<?php echo $alergenos['contiene'] ? esc_html( Alergenos::texto( $alergenos['contiene'] ) ) : '<span class="asde-muted">' . esc_html( asde_t( 'ningún alérgeno anotado' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<?php if ( $alergenos['trazas'] ) : ?>
				<p><strong><?php asde_e( 'Puede contener trazas de:' ); ?></strong> <?php echo esc_html( Alergenos::texto( $alergenos['trazas'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $alergenos['animal'] ) : ?>
				<p class="asde-muted"><?php asde_e( 'Lleva ingredientes de origen animal (no es apta para dietas vegetarianas ni veganas).' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $alergenos['por_alergeno'] as $a => $ings ) : ?>
				<p class="asde-muted"><?php echo esc_html( sprintf( asde_t( '%1$s: por %2$s' ), Alergenos::nombre( $a ), implode( ', ', array_unique( $ings ) ) ) ); ?></p>
			<?php endforeach; ?>
			<?php if ( $alergenos['sin_revisar'] ) : ?>
				<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( asde_t( 'Nadie ha revisado los alérgenos de: %s. Anótalos en «Ingredientes» antes de fiarte de esta lista.' ), implode( ', ', $alergenos['sin_revisar'] ) ) ); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $propia ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_menus' ); ?>
			<input type="hidden" name="asde_action" value="receta_guardar">
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<p>
				<label for="asde-rec-nombre"><?php asde_e( 'Nombre' ); ?></label>
				<input type="text" id="asde-rec-nombre" name="nombre" maxlength="160" required value="<?php echo esc_attr( (string) $val( 'nombre' ) ); ?>">
			</p>
			<p>
				<label for="asde-rec-tipo"><?php asde_e( 'Tipo de plato' ); ?></label>
				<select id="asde-rec-tipo" name="tipo">
					<?php foreach ( RecetasTable::TIPOS as $k => $n ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $val( 'tipo' ), $k ); ?>><?php echo esc_html( asde_t( $n ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="asde-rec-porc"><?php asde_e( 'Porciones base (para cuántas personas son las cantidades)' ); ?></label>
				<input type="number" id="asde-rec-porc" name="porciones" min="1" max="500" required value="<?php echo (int) $val( 'porciones' ); ?>">
			</p>
			<h3><?php asde_e( 'Ingredientes' ); ?></h3>
			<p class="asde-help"><?php asde_e( 'Si falta un ingrediente, créalo antes en «Ingredientes» con sus alérgenos.' ); ?> <a href="<?php echo esc_url( $url_ing ); ?>"><?php asde_e( 'Ir a ingredientes' ); ?></a></p>
			<table class="asde-table">
				<thead><tr><th scope="col"><?php asde_e( 'Ingrediente' ); ?></th><th scope="col"><?php asde_e( 'Cantidad' ); ?></th><th scope="col"><?php asde_e( 'Unidad' ); ?></th></tr></thead>
				<tbody>
					<?php
					$filas = $lineas;
					for ( $n = 0; $n < $extra; $n++ ) {
						$filas[] = array( 'ingrediente_id' => 0, 'cantidad' => '', 'unidad' => 'g' );
					}
					foreach ( $filas as $n => $l ) :
						?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Ingrediente' ) ); ?>">
								<select name="ing[]" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Ingrediente %d' ), $n + 1 ) ); ?>">
									<option value="0">—</option>
									<?php foreach ( $ingredientes as $i ) : ?>
										<option value="<?php echo (int) $i['id']; ?>" <?php selected( (int) $l['ingrediente_id'], $i['id'] ); ?>><?php echo esc_html( $nombre_ing( $i ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Cantidad' ) ); ?>"><input type="text" inputmode="decimal" name="cant[]" size="8" value="<?php echo '' === $l['cantidad'] ? '' : esc_attr( rtrim( rtrim( number_format( (float) $l['cantidad'], 3, ',', '' ), '0' ), ',' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Cantidad %d' ), $n + 1 ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Unidad' ) ); ?>">
								<select name="uni[]" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Unidad %d' ), $n + 1 ) ); ?>">
									<?php foreach ( Escalado::UNIDADES as $k => $u ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $l['unidad'], $k ); ?>><?php echo esc_html( asde_t( $u[0] ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-help"><?php asde_e( 'Para añadir más ingredientes, guarda y vuelve a abrir la receta: siempre salen filas vacías.' ); ?></p>
			<p>
				<label for="asde-rec-ela"><?php asde_e( 'Elaboración' ); ?></label>
				<textarea id="asde-rec-ela" name="elaboracion" rows="4" maxlength="4000"><?php echo esc_textarea( (string) $val( 'elaboracion' ) ); ?></textarea>
			</p>
			<p>
				<label for="asde-rec-obs"><?php asde_e( 'Observaciones' ); ?></label>
				<textarea id="asde-rec-obs" name="observaciones" rows="2" maxlength="1000"><?php echo esc_textarea( (string) $val( 'observaciones' ) ); ?></textarea>
			</p>
			<p><button type="submit" class="button"><?php asde_e( 'Guardar la receta' ); ?></button></p>
		</form>
	<?php elseif ( $lineas ) : ?>
		<h3><?php echo esc_html( sprintf( asde_t( 'Ingredientes (para %d porciones)' ), (int) $r['porciones'] ) ); ?></h3>
		<ul>
			<?php foreach ( $lineas as $l ) : ?>
				<li><?php echo esc_html( ( $l['ingrediente'] ? $nombre_ing( $l['ingrediente'] ) : '—' ) . ': ' . Escalado::texto( $l['cantidad'], $l['unidad'] ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $r['observaciones'] ) : ?>
			<p class="asde-muted"><?php echo esc_html( asde_t( $r['observaciones'] ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $r['id'] && $escaladas ) : ?>
	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php asde_e( 'Cantidades según los comensales' ); ?></h3>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<label for="asde-rec-com"><?php asde_e( 'Comensales' ); ?></label>
			<input type="number" id="asde-rec-com" name="comensales" min="1" max="5000" value="<?php echo (int) $comensales; ?>">
			<button type="submit" class="button"><?php asde_e( 'Calcular' ); ?></button>
		</form>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Ingrediente' ); ?></th><th scope="col"><?php echo esc_html( sprintf( asde_t( 'Para %d porciones' ), (int) $r['porciones'] ) ); ?></th><th scope="col"><?php echo esc_html( sprintf( asde_t( 'Para %d comensales' ), (int) $comensales ) ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $escaladas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Ingrediente' ) ); ?>"><?php echo esc_html( $l['ingrediente'] ? $nombre_ing( $l['ingrediente'] ) : '—' ); ?></td>
						<td data-label="<?php echo esc_attr( sprintf( asde_a( 'Para %d porciones' ), (int) $r['porciones'] ) ); ?>"><?php echo esc_html( Escalado::texto( $l['cantidad'], $l['unidad'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( sprintf( asde_a( 'Para %d comensales' ), (int) $comensales ) ); ?>"><strong><?php echo esc_html( $l['escalada'] ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>
