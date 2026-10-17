<?php
/**
 * Ingredientes del grupo y de la biblioteca común (v0.41, doc 49, B1).
 *
 * @var array[]          $lista
 * @var array<int, int>  $usos        ingrediente => nº de recetas que lo usan
 * @var array|null       $edicion     El que se está editando (solo los del grupo)
 * @var bool             $archivados
 * @var string           $url
 * @var string           $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Escalado;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;

defined( 'ABSPATH' ) || exit;

$e = $edicion ?: array( 'id' => 0, 'nombre' => '', 'unidad' => 'g', 'alergenos' => array(), 'trazas' => array(), 'animal' => false, 'coste' => null, 'revisado' => false );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Ingredientes' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( 'Volver al recetario' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Cada ingrediente lleva sus alérgenos; las recetas los heredan. «Puede contener» son las trazas que avisa la etiqueta. Marca «revisado» cuando hayas comprobado la etiqueta: las recetas avisan de los que nadie ha revisado. Los de la biblioteca de ejemplo no se pueden cambiar.' ); ?></p>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
		<?php wp_nonce_field( 'asde_menus' ); ?>
		<input type="hidden" name="asde_action" value="ingrediente_guardar">
		<input type="hidden" name="ingrediente" value="<?php echo (int) $e['id']; ?>">
		<h3><?php echo esc_html( $e['id'] ? asde_t( 'Editar el ingrediente' ) : asde_t( 'Ingrediente nuevo' ) ); ?></h3>
		<p>
			<label for="asde-ing-nombre"><?php asde_e( 'Nombre' ); ?></label>
			<input type="text" id="asde-ing-nombre" name="nombre" maxlength="120" required value="<?php echo esc_attr( $e['nombre'] ); ?>">
		</p>
		<p>
			<label for="asde-ing-uni"><?php asde_e( 'Unidad habitual' ); ?></label>
			<select id="asde-ing-uni" name="unidad">
				<?php foreach ( Escalado::UNIDADES as $k => $u ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e['unidad'], $k ); ?>><?php echo esc_html( asde_t( $u[0] ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<fieldset>
			<legend><?php asde_e( 'Contiene' ); ?></legend>
			<?php foreach ( Alergenos::LISTA as $k => $n ) : ?>
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="alergenos[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $e['alergenos'], true ) ); ?>><span><?php echo esc_html( asde_t( $n ) ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<fieldset>
			<legend><?php asde_e( 'Puede contener trazas de' ); ?></legend>
			<?php foreach ( Alergenos::LISTA as $k => $n ) : ?>
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="trazas[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $e['trazas'], true ) ); ?>><span><?php echo esc_html( asde_t( $n ) ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<label class="asde-choice"><input type="checkbox" name="animal" value="1" <?php checked( $e['animal'] ); ?>><span><?php asde_e( 'Es de origen animal (carne, pescado, huevos, lácteos, miel…)' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="revisado" value="1" <?php checked( $e['revisado'] ); ?>><span><?php asde_e( 'He revisado los alérgenos de este ingrediente' ); ?></span></label>
		<p>
			<label for="asde-ing-coste"><?php asde_e( 'Coste por unidad habitual, en euros (opcional)' ); ?></label>
			<input type="text" inputmode="decimal" id="asde-ing-coste" name="coste" size="8" value="<?php echo null === $e['coste'] ? '' : esc_attr( rtrim( rtrim( number_format( (float) $e['coste'], 4, ',', '' ), '0' ), ',' ) ); ?>">
		</p>
		<p><button type="submit" class="button"><?php asde_e( 'Guardar el ingrediente' ); ?></button> <?php echo $e['id'] ? '<a href="' . esc_url( $url ) . '">' . esc_html( asde_t( 'Cancelar' ) ) . '</a>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
	</form>
</section>

<section class="asde-card" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php asde_e( 'Lista de ingredientes' ); ?></h3>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<input type="hidden" name="ingredientes" value="1">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivados" value="1" <?php checked( $archivados ); ?>><span><?php asde_e( 'Ver también los archivados' ); ?></span></label>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>
	<table class="asde-table">
		<thead>
			<tr>
				<th scope="col"><?php asde_e( 'Ingrediente' ); ?></th>
				<th scope="col"><?php asde_e( 'Contiene' ); ?></th>
				<th scope="col"><?php asde_e( 'Puede contener' ); ?></th>
				<th scope="col"><?php asde_e( 'Recetas' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lista as $i ) : ?>
				<?php $comun = IngredientesTable::COMUN === $i['grupo']; ?>
				<tr<?php echo $i['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
					<td data-label="<?php echo esc_attr( asde_a( 'Ingrediente' ) ); ?>">
						<?php echo esc_html( $comun ? asde_t( $i['nombre'] ) : $i['nombre'] ); ?>
						<?php echo $comun ? ' <span class="asde-badge">' . esc_html( asde_t( 'Biblioteca' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo ! $i['revisado'] ? ' <span class="asde-badge asde-badge--pago-parcial">' . esc_html( asde_t( 'Sin revisar' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo $i['animal'] ? ' <span class="asde-muted">' . esc_html( asde_t( '(origen animal)' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'Contiene' ) ); ?>"><?php echo $i['alergenos'] ? esc_html( Alergenos::texto( $i['alergenos'] ) ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Puede contener' ) ); ?>"><?php echo $i['trazas'] ? esc_html( Alergenos::texto( $i['trazas'] ) ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Recetas' ) ); ?>"><?php echo (int) ( $usos[ $i['id'] ] ?? 0 ); ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
						<?php if ( ! $comun ) : ?>
							<form method="post" class="asde-inline" action="<?php echo esc_url( $url ); ?>">
								<?php wp_nonce_field( 'asde_menus' ); ?>
								<input type="hidden" name="ingrediente" value="<?php echo (int) $i['id']; ?>">
								<a href="<?php echo esc_url( add_query_arg( 'ingrediente', (string) $i['id'], $url ) ); ?>"><?php asde_e( 'Editar' ); ?></a>
								<?php if ( $i['activo'] ) : ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="ingrediente_archivar"><?php asde_e( 'Archivar' ); ?></button>
								<?php else : ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="ingrediente_restaurar"><?php asde_e( 'Recuperar' ); ?></button>
								<?php endif; ?>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
