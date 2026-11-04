<?php
/**
 * Agenda de eventos del grupo.
 *
 * @var array                $cfg
 * @var array[]              $lista
 * @var array<string, int>   $cuentas     filtro => nº de eventos
 * @var string               $filtro
 * @var string               $seccion
 * @var string               $tipo
 * @var bool                 $global      Ve todos los eventos del grupo
 * @var int                  $por_validar Presupuestos que esperan a Tesorería (solo Tesorería)
 * @var bool                 $puede_crear
 * @var string               $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php if ( $puede_crear ) : ?>
			<span class="asde-actions"><a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', '1', $url ) ); ?>"><?php esc_html_e( 'New event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
		<?php endif; ?>
	</header>
	<p class="asde-muted">
		<?php echo esc_html( $global ? __( 'All the group\'s events.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'The events of your sections and those whose team you are on.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		<?php esc_html_e( 'An event starts as a draft; if it has a cost, Treasury validates its budget (and with it, the price) before it is published.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</p>
	<?php if ( $por_validar ) : ?>
		<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d budget is waiting for Treasury to validate it.', '%d budgets are waiting for Treasury to validate them.', $por_validar , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $por_validar ) ); ?></p>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-ev-estado"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-ev-estado" name="estado">
			<?php foreach ( ( EventosTable::filtros() ) as $f => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $filtro, $f ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuentas[ $f ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-ev-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-ev-seccion" name="seccion">
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( Secciones::all() as $s => $def ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( $def['nombre'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-ev-tipo"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-ev-tipo" name="tipo">
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $cfg['tipos'] as $t => $def ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tipo, $t ); ?>><?php echo esc_html( Config::nombre_tipo( $cfg, $t ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $lista ) : ?>
		<p><?php esc_html_e( 'There are no events with these filters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--eventos" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Dates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $e ) : ?>
					<?php $estado = EventosTable::estado_visible( $e ); ?>
					<tr<?php echo 'cancelado' === $estado ? ' class="asde-fila--cancelada"' : ''; ?>>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'evento', $e['id'], $url ) ); ?>"><strong><?php echo esc_html( $e['nombre'] ); ?></strong></a>
							<br><span class="asde-muted"><?php echo esc_html( EventosTable::numero( $e ) . ' · ' . Config::nombre_tipo( $cfg, $e['tipo'] ) ); ?></span>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Dates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( EventosTable::cuando( $e ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php
							foreach ( $e['secciones'] as $s ) {
								echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
							}
							?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-badge asde-badge--evento-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( ( EventosTable::estados()[ $estado ] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( ! $e['con_coste'] ) : ?>
								<span class="asde-muted"><?php esc_html_e( 'No cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php elseif ( ! empty( $e['presupuesto']['precios'] ) ) : ?>
								<?php
								echo esc_html(
									implode(
										' · ',
										array_map(
											static fn( $s, $p ) => ( 1 < count( $e['presupuesto']['precios'] ) ? Secciones::nombre( (string) $s ) . ' ' : '' ) . Calculadora::euros( (float) $p ),
											array_keys( $e['presupuesto']['precios'] ),
											$e['presupuesto']['precios']
										)
									)
								);
								?>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'To validate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
