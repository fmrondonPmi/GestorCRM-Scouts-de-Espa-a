<?php
/**
 * Gestión de accesos · historial (v0.53, doc 56): quién concedió o retiró qué, a quién y cuándo.
 *
 * @var array[]                                $filas   Página que se muestra
 * @var int                                    $total
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var callable                               $cuenta  user_id => texto para mostrar
 * @var string                                 $accion  Filtro ('' = todas)
 * @var int                                    $persona Filtro (0 = todas)
 * @var string                                 $permiso Filtro ('' = todos)
 * @var int                                    $periodo Filtro en días (0 = todo)
 * @var array<int, string>                     $personas Personas con algo en el historial
 * @var string                                 $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Accesos\Auditoria;
use AsdeBalears\AreaPrivada\Accesos\Catalogo;
use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

$filtrado   = $accion || $persona || $permiso || $periodo;
$columnas   = array(
	'fecha'   => asde_t( 'Fecha' ),
	'actor'   => asde_t( 'Quién' ),
	'accion'  => asde_t( 'Qué' ),
	'persona' => asde_t( 'A quién' ),
	'permiso' => asde_t( 'Permiso' ),
);
?>
<section class="asde-card" data-asde-no-plegable>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php asde_e( 'Historial de accesos' ); ?></h2>
	</header>
	<p><?php asde_e( 'Quién concedió o retiró cada permiso, a quién y cuándo, y los que han caducado solos. No se borra nada.' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="vista" value="historial">
		<label for="asde-acc-h-accion"><?php asde_e( 'Qué pasó' ); ?></label>
		<select id="asde-acc-h-accion" name="accion" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todo' ); ?></option>
			<?php foreach ( Auditoria::ACCIONES as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $accion, $k ); ?>><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-persona"><?php asde_e( 'A quién' ); ?></label>
		<select id="asde-acc-h-persona" name="persona" data-asde-autoenviar>
			<option value="0"><?php asde_e( 'Todas' ); ?></option>
			<?php foreach ( $personas as $id => $texto ) : ?>
				<option value="<?php echo (int) $id; ?>" <?php selected( $persona, $id ); ?>><?php echo esc_html( $texto ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-permiso"><?php asde_e( 'Permiso' ); ?></label>
		<select id="asde-acc-h-permiso" name="permiso" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( Catalogo::todos() as $p ) : ?>
				<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $permiso, $p ); ?>><?php echo esc_html( Catalogo::descripcion( $p ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-periodo"><?php asde_e( 'Cuándo' ); ?></label>
		<select id="asde-acc-h-periodo" name="periodo" data-asde-autoenviar>
			<option value="0"><?php asde_e( 'Siempre' ); ?></option>
			<option value="30" <?php selected( $periodo, 30 ); ?>><?php asde_e( 'Últimos 30 días' ); ?></option>
			<option value="365" <?php selected( $periodo, 365 ); ?>><?php asde_e( 'Último año' ); ?></option>
		</select>
		<noscript><button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button></noscript>
		<?php if ( $filtrado ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'quitar filtros' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php asde_e( 'No hay nada en el historial' ); ?><?php echo $filtrado ? esc_html( asde_t( ' con este filtro' ) ) : ''; ?>.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php asde_e( 'Detalle' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $f['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Quién' ) ); ?>"><?php echo esc_html( $cuenta( (int) $f['actor'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Qué' ) ); ?>"><?php echo esc_html( asde_t( Auditoria::ACCIONES[ $f['accion'] ] ?? $f['accion'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'A quién' ) ); ?>"><?php echo (int) $f['objetivo'] ? esc_html( $cuenta( (int) $f['objetivo'] ) ) : ( '' !== $f['rol'] ? esc_html( sprintf( asde_t( 'Rol %s' ), Roles::label( $f['rol'] ) ) ) : '—' ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Permiso' ) ); ?>">
							<?php echo Catalogo::existe( $f['permiso'] ) ? esc_html( Catalogo::descripcion( $f['permiso'] ) ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( Catalogo::existe( $f['permiso'] ) && Catalogo::sensible( $f['permiso'] ) ) : ?>
								<span class="asde-badge asde-badge--sensible"><?php asde_e( 'Sensible' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>">
							<?php
							$detalle = array();
							if ( $f['caduca'] ) {
								$detalle[] = sprintf( asde_t( 'hasta el %s' ), wp_date( 'd/m/Y', strtotime( $f['caduca'] . ' 12:00' ) ) );
							} elseif ( Auditoria::CONCEDE === $f['accion'] ) {
								$detalle[] = asde_t( 'sin caducidad' );
							}
							if ( '' !== $f['motivo'] ) {
								$detalle[] = $f['motivo'];
							}
							echo $detalle ? esc_html( implode( ' · ', $detalle ) ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
