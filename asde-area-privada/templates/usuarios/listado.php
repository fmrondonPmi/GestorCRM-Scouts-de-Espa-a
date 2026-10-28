<?php
/**
 * Usuarios del área privada.
 *
 * El listado llega entero y el navegador filtra, ordena y pagina en memoria
 * (assets/js/usuarios.js): sin JavaScript se ven todos los usuarios, sin filtros.
 *
 * @var int      $total        Usuarios del listado
 * @var string   $filas        HTML de las filas (templates/usuarios/fila.php)
 * @var string   $aviso
 * @var string   $rol          Rol con el que se abre filtrado ('' = todos)
 * @var int      $por_pagina
 * @var string   $url
 * @var string   $url_alta
 * @var string   $ajax
 * @var string   $nonce_pagina
 * @var string   $nonce_refrescar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'usuario' => __( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'persona' => __( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'roles'   => __( 'Roles', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'ambito'  => __( 'Scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'estado'  => __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$ambitos  = array();
foreach ( Secciones::all() as $slug => $seccion ) {
	$ambitos[ $slug ] = $seccion['nombre'];
}
$ambitos[ Secciones::SIN_SECCION ] = Secciones::nombre( Secciones::SIN_SECCION );
$estados = array(
	Usuarios::INVITADO    => Usuarios::etiqueta_estado( Usuarios::INVITADO ),
	Usuarios::ACTIVO      => Usuarios::etiqueta_estado( Usuarios::ACTIVO ),
	Usuarios::DESACTIVADO => Usuarios::etiqueta_estado( Usuarios::DESACTIVADO ),
);
?>
<section class="asde-card"
	data-asde-usuarios
	data-url="<?php echo esc_url( $url ); ?>"
	data-por-pagina="<?php echo (int) $por_pagina; ?>"
	data-opciones="<?php echo esc_attr( implode( ',', Tabla::POR_PAGINA ) ); ?>"
	data-ajax="<?php echo esc_url( $ajax ); ?>"
	data-nonce="<?php echo esc_attr( $nonce_pagina ); ?>"
	data-nonce-refrescar="<?php echo esc_attr( $nonce_refrescar ); ?>"
	data-rol="<?php echo esc_attr( $rol ); ?>">
	<?php /* v0.25: «Dar acceso» y «Registro de emails» son hojas de la sección (templates/usuarios/hojas.php). */ ?>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php esc_html_e( 'Private area users', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	</header>

	<div data-asde-avisos aria-live="polite"></div>
	<?php if ( $aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status" data-asde-aviso-crm><?php echo esc_html( $aviso ); ?></div>
	<?php endif; ?>

	<?php if ( ! $total ) : ?>
		<p><?php esc_html_e( 'There are no users yet. Start with the', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_alta ); ?>"><?php esc_html_e( 'user registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
	<?php else : ?>
		<p class="asde-muted asde-usuarios__total">
			<span data-asde-total aria-live="polite"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d user', '%d users', $total , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $total ) ); ?></span>
			<button type="button" class="asde-btn-link" data-asde-limpiar hidden><?php esc_html_e( 'Clear filters', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<button type="button" class="asde-btn-link" data-asde-actualizar hidden title="<?php echo esc_attr( esc_attr__( 'Reads the list and the names from the CRM again, without reloading the page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Update', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>

		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<div class="asde-solo-movil asde-orden-movil" data-asde-orden-movil hidden>
			<label for="asde-orden-usuarios"><?php esc_html_e( 'Sort by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-orden-usuarios" data-orden-col>
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select data-orden-dir aria-label="<?php echo esc_attr( esc_attr__( 'Direction', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<option value="asc">A → Z</option>
				<option value="desc">Z → A</option>
			</select>
		</div>

		<table class="asde-table asde-table--usuarios" data-asde-tabla>
			<thead>
				<tr class="asde-cabeceras">
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col" data-col="<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $etiqueta ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
				<tr class="asde-filtros" hidden>
					<th scope="col">
						<input type="search" data-filtro="usuario" placeholder="<?php echo esc_attr( esc_attr__( 'Filter user…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Filter by user', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" autocomplete="off">
					</th>
					<th scope="col">
						<input type="search" data-filtro="persona" placeholder="<?php echo esc_attr( esc_attr__( 'Filter by name…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Filter by person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" autocomplete="off">
					</th>
					<th scope="col">
						<select data-filtro="roles" aria-label="<?php echo esc_attr( esc_attr__( 'Filter by role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<option value=""><?php esc_html_e( 'All roles', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							<?php foreach ( Roles::ALL as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( Roles::label( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="ambito" aria-label="<?php echo esc_attr( esc_attr__( 'Filter by scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<option value=""><?php esc_html_e( 'All scopes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							<?php foreach ( $ambitos as $slug => $nombre ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="estado" aria-label="<?php echo esc_attr( esc_attr__( 'Filter by status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<option value=""><?php esc_html_e( 'All statuses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							<?php foreach ( $estados as $slug => $nombre ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody data-asde-filas>
				<?php echo wp_kses( $filas, asde_html_permitido() ); ?>
			</tbody>
		</table>
		<p class="asde-usuarios__vacio" data-asde-vacio hidden><?php esc_html_e( 'No user matches the filters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div data-asde-paginacion></div>
	<?php endif; ?>
</section>
