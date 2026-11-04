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
	'nombre' => __( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'rol'    => __( 'Role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'extras' => __( 'Individual permissions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card" data-asde-no-plegable>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php esc_html_e( 'Access by person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	</header>
	<p><?php esc_html_e( 'Choose a person to see what their role gives them and which individual permissions they have, and to grant or remove some.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="vista" value="personas">
		<label for="asde-acc-buscar"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-acc-buscar" name="buscar" value="<?php echo esc_attr( $buscar ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Name or username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label for="asde-acc-rol"><?php esc_html_e( 'Role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-acc-rol" name="rol" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $roles as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rol, $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php esc_html_e( 'There are no people with that filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginada="servidor">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<strong><?php echo esc_html( '' !== $f['nombre'] ? $f['nombre'] : $f['user']->user_login ); ?></strong>
							<?php if ( '' !== $f['nombre'] ) : ?><br><span class="asde-muted"><?php echo esc_html( $f['user']->user_login ); ?></span><?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['roles'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Individual permissions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $f['extras'] ? (int) $f['extras'] : '<span class="asde-muted">—</span>', asde_html_permitido() ); ?></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( 'usuario', (int) $f['user']->ID, $url ) ); ?>"><?php esc_html_e( 'View access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo wp_kses( $tabla->paginacion(), asde_html_permitido() ); ?>
	<?php endif; ?>
</section>
