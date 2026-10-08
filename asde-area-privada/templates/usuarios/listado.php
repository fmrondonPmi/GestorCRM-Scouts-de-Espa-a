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
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'usuario' => asde_t( 'Usuario' ),
	'persona' => asde_t( 'Persona' ),
	'roles'   => asde_t( 'Roles' ),
	'ambito'  => asde_t( 'Ámbito' ),
	'estado'  => asde_t( 'Estado' ),
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
		<h2 class="asde-card__title"><?php asde_e( 'Usuarios del área privada' ); ?></h2>
	</header>

	<div data-asde-avisos aria-live="polite"></div>
	<?php if ( $aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status" data-asde-aviso-crm><?php echo esc_html( $aviso ); ?></div>
	<?php endif; ?>

	<?php if ( ! $total ) : ?>
		<p><?php asde_e( 'Todavía no hay usuarios. Empieza por el' ); ?> <a href="<?php echo esc_url( $url_alta ); ?>"><?php asde_e( 'alta de usuarios' ); ?></a>.</p>
	<?php else : ?>
		<p class="asde-muted asde-usuarios__total">
			<span data-asde-total aria-live="polite"><?php echo esc_html( sprintf( asde_n( '%d usuario', '%d usuarios', $total ), $total ) ); ?></span>
			<button type="button" class="asde-btn-link" data-asde-limpiar hidden><?php asde_e( 'Quitar filtros' ); ?></button>
			<button type="button" class="asde-btn-link" data-asde-actualizar hidden title="<?php echo esc_attr( asde_a( 'Vuelve a leer la lista y los nombres del CRM, sin recargar la página' ) ); ?>"><?php asde_e( 'Actualizar' ); ?></button>
		</p>

		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<div class="asde-solo-movil asde-orden-movil" data-asde-orden-movil hidden>
			<label for="asde-orden-usuarios"><?php asde_e( 'Ordenar por' ); ?></label>
			<select id="asde-orden-usuarios" data-orden-col>
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select data-orden-dir aria-label="<?php echo esc_attr( asde_a( 'Sentido' ) ); ?>">
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
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
				<tr class="asde-filtros" hidden>
					<th scope="col">
						<input type="search" data-filtro="usuario" placeholder="<?php echo esc_attr( asde_a( 'Filtrar usuario…' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Filtrar por usuario' ) ); ?>" autocomplete="off">
					</th>
					<th scope="col">
						<input type="search" data-filtro="persona" placeholder="<?php echo esc_attr( asde_a( 'Filtrar nombre…' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Filtrar por persona' ) ); ?>" autocomplete="off">
					</th>
					<th scope="col">
						<select data-filtro="roles" aria-label="<?php echo esc_attr( asde_a( 'Filtrar por rol' ) ); ?>">
							<option value=""><?php asde_e( 'Todos los roles' ); ?></option>
							<?php foreach ( Roles::ALL as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( Roles::label( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="ambito" aria-label="<?php echo esc_attr( asde_a( 'Filtrar por ámbito' ) ); ?>">
							<option value=""><?php asde_e( 'Todos los ámbitos' ); ?></option>
							<?php foreach ( $ambitos as $slug => $nombre ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="estado" aria-label="<?php echo esc_attr( asde_a( 'Filtrar por estado' ) ); ?>">
							<option value=""><?php asde_e( 'Todos los estados' ); ?></option>
							<?php foreach ( $estados as $slug => $nombre ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody data-asde-filas>
				<?php echo $filas; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</tbody>
		</table>
		<p class="asde-usuarios__vacio" data-asde-vacio hidden><?php asde_e( 'Ningún usuario coincide con los filtros.' ); ?></p>
		<div data-asde-paginacion></div>
	<?php endif; ?>
</section>
