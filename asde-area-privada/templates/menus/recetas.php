<?php
/**
 * Recetario del grupo (v0.41, doc 49, B1): las recetas del grupo y la biblioteca común de ejemplo.
 *
 * @var array[]              $propias
 * @var array[]              $comunes
 * @var array<int, array>    $alergenos  receta => Recetario::alergenos()
 * @var string               $tipo
 * @var string               $q
 * @var bool                 $archivadas
 * @var string               $url
 * @var string               $url_nueva
 * @var string               $url_ing
 * @var string               $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;

defined( 'ABSPATH' ) || exit;

/** Una fila de receta. */
$fila = static function ( array $r, bool $propia ) use ( $alergenos ): void {
	$a = $alergenos[ $r['id'] ] ?? array( 'contiene' => array(), 'trazas' => array(), 'sin_revisar' => array() );
	?>
	<tr<?php echo $r['activa'] ? '' : ' class="asde-fila--cancelada"'; ?>>
		<td data-label="<?php echo esc_attr( asde_a( 'Receta' ) ); ?>">
			<a href="<?php echo esc_url( Pages::url( 'eventos_recetas', array( 'receta' => (string) $r['id'] ) ) ); ?>"><?php echo esc_html( $propia ? $r['nombre'] : asde_t( $r['nombre'] ) ); ?></a>
			<?php echo $r['activa'] ? '' : ' <span class="asde-badge">' . esc_html( asde_t( 'Archivada' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</td>
		<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>"><?php echo esc_html( asde_t( RecetasTable::TIPOS[ $r['tipo'] ] ?? 'Otro' ) ); ?></td>
		<td data-label="<?php echo esc_attr( asde_a( 'Porciones base' ) ); ?>"><?php echo (int) $r['porciones']; ?></td>
		<td data-label="<?php echo esc_attr( asde_a( 'Alérgenos' ) ); ?>">
			<?php if ( $a['contiene'] ) : ?>
				<strong><?php echo esc_html( Alergenos::texto( $a['contiene'] ) ); ?></strong>
			<?php else : ?>
				<span class="asde-muted"><?php asde_e( 'Ninguno anotado' ); ?></span>
			<?php endif; ?>
			<?php if ( $a['trazas'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Puede contener: %s' ), Alergenos::texto( $a['trazas'] ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( $a['sin_revisar'] ) : ?>
				<br><span class="asde-badge asde-badge--pago-parcial"><?php asde_e( 'Ingredientes sin revisar' ); ?></span>
			<?php endif; ?>
		</td>
		<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
			<form method="post" class="asde-inline">
				<?php wp_nonce_field( 'asde_menus' ); ?>
				<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
				<?php if ( ! $propia ) : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_copiar"><?php asde_e( 'Copiar a mi grupo' ); ?></button>
				<?php elseif ( $r['activa'] ) : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_copiar"><?php asde_e( 'Duplicar' ); ?></button>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_archivar"><?php asde_e( 'Archivar' ); ?></button>
				<?php else : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_restaurar"><?php asde_e( 'Recuperar' ); ?></button>
				<?php endif; ?>
			</form>
		</td>
	</tr>
	<?php
};
$cabecera = static function (): void {
	?>
	<thead>
		<tr>
			<th scope="col"><?php asde_e( 'Receta' ); ?></th>
			<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
			<th scope="col"><?php asde_e( 'Porciones base' ); ?></th>
			<th scope="col"><?php asde_e( 'Alérgenos' ); ?></th>
			<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
		</tr>
	</thead>
	<?php
};
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Recetario' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_nueva ); ?>"><?php asde_e( 'Añadir receta' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_ing ); ?>"><?php asde_e( 'Ingredientes' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_csv ); ?>"><?php asde_e( 'Cargar desde CSV' ); ?></a>
		</span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Las recetas del grupo, con sus ingredientes. Los alérgenos de cada receta salen de los de sus ingredientes: no se escriben a mano. Aquí no hay datos de ninguna persona.' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<label for="asde-rc-tipo"><?php asde_e( 'Tipo' ); ?></label>
		<select id="asde-rc-tipo" name="tipo">
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( RecetasTable::TIPOS as $k => $n ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $tipo, $k ); ?>><?php echo esc_html( asde_t( $n ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-rc-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-rc-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre de la receta' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivadas" value="1" <?php checked( $archivadas ); ?>><span><?php asde_e( 'Ver también las archivadas' ); ?></span></label>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<h3><?php asde_e( 'Recetas del grupo' ); ?></h3>
	<?php if ( ! $propias ) : ?>
		<p><?php asde_e( 'Todavía no hay recetas del grupo. Copia una de la biblioteca de ejemplo, añade una nueva o carga varias desde un CSV.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<?php $cabecera(); ?>
			<tbody><?php foreach ( $propias as $r ) { $fila( $r, true ); } ?></tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="menus-biblioteca" data-asde-inicio="cerrado">
	<h3 class="asde-card__title"><?php asde_e( 'Biblioteca de ejemplo' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'Recetas de ejemplo que vienen con la aplicación, iguales para todos los grupos. Copia la que te sirva y edítala: la de la biblioteca no cambia. Comprueba siempre los alérgenos de las marcas que uses antes de fiarte de una receta.' ); ?></p>
	<?php if ( ! $comunes ) : ?>
		<p><?php asde_e( 'No hay recetas con ese filtro.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<?php $cabecera(); ?>
			<tbody><?php foreach ( $comunes as $r ) { $fila( $r, false ); } ?></tbody>
		</table>
	<?php endif; ?>
</section>
