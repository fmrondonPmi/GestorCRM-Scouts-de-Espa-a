<?php
/**
 * Gestión de accesos · accesos por persona, listado (v0.53, doc 56).
 *
 * @var array[]                                $filas   Página: user (WP_User), nombre, roles, extras
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var string                                 $buscar
 * @var string                                 $rol     Filtro ('' = todos)
 * @var array<string, string>                  $roles   rol => etiqueta
 * @var string                                 $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'nombre' => asde_t( 'Persona' ),
	'rol'    => asde_t( 'Rol' ),
	'extras' => asde_t( 'Permisos sueltos' ),
);
?>
<section class="asde-card" data-asde-no-plegable>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php asde_e( 'Accesos por persona' ); ?></h2>
	</header>
	<p><?php asde_e( 'Elige a una persona para ver qué le da su rol y qué permisos sueltos tiene, y para darle o quitarle alguno.' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="vista" value="personas">
		<label for="asde-acc-buscar"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-acc-buscar" name="buscar" value="<?php echo esc_attr( $buscar ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre o usuario' ) ); ?>">
		<label for="asde-acc-rol"><?php asde_e( 'Rol' ); ?></label>
		<select id="asde-acc-rol" name="rol" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( $roles as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rol, $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php asde_e( 'No hay personas con ese filtro.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Persona' ) ); ?>">
							<strong><?php echo esc_html( '' !== $f['nombre'] ? $f['nombre'] : $f['user']->user_login ); ?></strong>
							<?php if ( '' !== $f['nombre'] ) : ?><br><span class="asde-muted"><?php echo esc_html( $f['user']->user_login ); ?></span><?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Rol' ) ); ?>"><?php echo esc_html( $f['roles'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Permisos sueltos' ) ); ?>"><?php echo $f['extras'] ? (int) $f['extras'] : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( 'usuario', (int) $f['user']->ID, $url ) ); ?>"><?php asde_e( 'Ver accesos' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
