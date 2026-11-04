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
	'fecha'   => __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'actor'   => __( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'accion'  => __( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'persona' => __( 'To whom', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'permiso' => __( 'Permission', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card" data-asde-no-plegable>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php esc_html_e( 'Access history', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	</header>
	<p><?php esc_html_e( 'Who granted or withdrew each permission, to whom and when, and those that expired by themselves. Nothing is deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="vista" value="historial">
		<label for="asde-acc-h-accion"><?php esc_html_e( 'What happened', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-acc-h-accion" name="accion" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( Auditoria::acciones() as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $accion, $k ); ?>><?php echo esc_html(  $etiqueta  ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-persona"><?php esc_html_e( 'To whom', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-acc-h-persona" name="persona" data-asde-autoenviar>
			<option value="0"><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $personas as $id => $texto ) : ?>
				<option value="<?php echo (int) $id; ?>" <?php selected( $persona, $id ); ?>><?php echo esc_html( $texto ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-permiso"><?php esc_html_e( 'Permission', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-acc-h-permiso" name="permiso" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( Catalogo::todos() as $p ) : ?>
				<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $permiso, $p ); ?>><?php echo esc_html( Catalogo::descripcion( $p ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-acc-h-periodo"><?php esc_html_e( 'When', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-acc-h-periodo" name="periodo" data-asde-autoenviar>
			<option value="0"><?php esc_html_e( 'Always', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<option value="30" <?php selected( $periodo, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<option value="365" <?php selected( $periodo, 365 ); ?>><?php esc_html_e( 'Last year', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
		<?php if ( $filtrado ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'clear filters', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php esc_html_e( 'There is nothing in the history', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $filtrado ? esc_html( __( ' with this filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>.</p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginada="servidor">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $f['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $cuenta( (int) $f['actor'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( Auditoria::acciones()[ $f['accion'] ] ?? $f['accion'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'To whom', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $f['objetivo'] ? esc_html( $cuenta( (int) $f['objetivo'] ) ) : ( '' !== $f['rol'] ? esc_html( sprintf( /* translators: %s: text */ __( 'Role %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Roles::label( $f['rol'] ) ) ) : '—' ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Permission', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo wp_kses( Catalogo::existe( $f['permiso'] ) ? esc_html( Catalogo::descripcion( $f['permiso'] ) ) : '<span class="asde-muted">—</span>', asde_html_permitido() ); ?>
							<?php if ( Catalogo::existe( $f['permiso'] ) && Catalogo::sensible( $f['permiso'] ) ) : ?>
								<span class="asde-badge asde-badge--sensible"><?php esc_html_e( 'Sensitive', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php
							$detalle = array();
							if ( $f['caduca'] ) {
								$detalle[] = sprintf( /* translators: %s: text */ __( 'until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $f['caduca'] . ' 12:00' ) ) );
							} elseif ( Auditoria::CONCEDE === $f['accion'] ) {
								$detalle[] = __( 'no expiry', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
							}
							if ( '' !== $f['motivo'] ) {
								$detalle[] = $f['motivo'];
							}
							echo wp_kses( $detalle ? esc_html( implode( ' · ', $detalle ) ) : '<span class="asde-muted">—</span>', asde_html_permitido() );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo wp_kses( $tabla->paginacion(), asde_html_permitido() ); ?>
	<?php endif; ?>
</section>
