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
 * @var string   $url_emails
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
	'usuario' => 'Usuario',
	'persona' => 'Persona',
	'roles'   => 'Roles',
	'ambito'  => 'Ámbito',
	'estado'  => 'Estado',
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
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Usuarios del área privada</h2>
		<span class="asde-actions">
			<a href="<?php echo esc_url( $url_emails ); ?>">Registro de emails</a>
			<a class="button" href="<?php echo esc_url( $url_alta ); ?>">+ Dar acceso</a>
		</span>
	</header>

	<div data-asde-avisos aria-live="polite"></div>
	<?php if ( $aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status" data-asde-aviso-crm><?php echo esc_html( $aviso ); ?></div>
	<?php endif; ?>

	<?php if ( ! $total ) : ?>
		<p>Todavía no hay usuarios. Empieza por el <a href="<?php echo esc_url( $url_alta ); ?>">alta de usuarios</a>.</p>
	<?php else : ?>
		<p class="asde-muted asde-usuarios__total">
			<span data-asde-total aria-live="polite"><?php echo esc_html( sprintf( _n( '%d usuario', '%d usuarios', $total, 'asde-area-privada' ), $total ) ); ?></span>
			<button type="button" class="asde-btn-link" data-asde-limpiar hidden>Quitar filtros</button>
			<button type="button" class="asde-btn-link" data-asde-actualizar hidden title="Vuelve a leer la lista y los nombres del CRM, sin recargar la página">Actualizar</button>
		</p>

		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<div class="asde-solo-movil asde-orden-movil" data-asde-orden-movil hidden>
			<label for="asde-orden-usuarios">Ordenar por</label>
			<select id="asde-orden-usuarios" data-orden-col>
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select data-orden-dir aria-label="Sentido">
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
					<th scope="col"><span class="screen-reader-text">Acciones</span></th>
				</tr>
				<tr class="asde-filtros" hidden>
					<th scope="col">
						<input type="search" data-filtro="usuario" placeholder="Filtrar usuario…" aria-label="Filtrar por usuario" autocomplete="off">
					</th>
					<th scope="col">
						<input type="search" data-filtro="persona" placeholder="Filtrar nombre…" aria-label="Filtrar por persona" autocomplete="off">
					</th>
					<th scope="col">
						<select data-filtro="roles" aria-label="Filtrar por rol">
							<option value="">Todos los roles</option>
							<?php foreach ( Roles::ALL as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( Roles::label( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="ambito" aria-label="Filtrar por ámbito">
							<option value="">Todos los ámbitos</option>
							<?php foreach ( $ambitos as $slug => $nombre ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</th>
					<th scope="col">
						<select data-filtro="estado" aria-label="Filtrar por estado">
							<option value="">Todos los estados</option>
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
		<p class="asde-usuarios__vacio" data-asde-vacio hidden>Ningún usuario coincide con los filtros.</p>
		<div data-asde-paginacion></div>
	<?php endif; ?>
</section>
