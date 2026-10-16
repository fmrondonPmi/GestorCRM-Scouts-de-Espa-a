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
		<h2 class="asde-card__title"><?php asde_e( 'Eventos' ); ?></h2>
		<?php if ( $puede_crear ) : ?>
			<span class="asde-actions"><a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', '1', $url ) ); ?>"><?php asde_e( 'Nuevo evento' ); ?></a></span>
		<?php endif; ?>
	</header>
	<p class="asde-muted">
		<?php echo esc_html( $global ? asde_t( 'Todos los eventos del grupo.' ) : asde_t( 'Los eventos de tus secciones y aquellos en cuyo equipo estás.' ) ); ?>
		<?php asde_e( 'Un evento nace como borrador; si tiene coste, Tesorería valida su presupuesto (y con él, el precio) antes de publicarlo.' ); ?>
	</p>
	<?php if ( $por_validar ) : ?>
		<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( asde_n( '%d presupuesto espera a que Tesorería lo valide.', '%d presupuestos esperan a que Tesorería los valide.', $por_validar ), $por_validar ) ); ?></p>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-ev-estado"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-ev-estado" name="estado">
			<?php foreach ( asde_ta( EventosTable::FILTROS ) as $f => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $filtro, $f ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuentas[ $f ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-ev-seccion"><?php asde_e( 'Sección' ); ?></label>
		<select id="asde-ev-seccion" name="seccion">
			<option value=""><?php asde_e( 'Todas' ); ?></option>
			<?php foreach ( Secciones::all() as $s => $def ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( $def['nombre'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-ev-tipo"><?php asde_e( 'Tipo' ); ?></label>
		<select id="asde-ev-tipo" name="tipo">
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( $cfg['tipos'] as $t => $def ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tipo, $t ); ?>><?php echo esc_html( Config::nombre_tipo( $cfg, $t ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<?php if ( ! $lista ) : ?>
		<p><?php asde_e( 'No hay eventos con estos filtros.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--eventos">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Evento' ); ?></th>
					<th scope="col"><?php asde_e( 'Fechas' ); ?></th>
					<th scope="col"><?php asde_e( 'Secciones' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<th scope="col"><?php asde_e( 'Precio' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $e ) : ?>
					<?php $estado = EventosTable::estado_visible( $e ); ?>
					<tr<?php echo 'cancelado' === $estado ? ' class="asde-fila--cancelada"' : ''; ?>>
						<td data-label="<?php echo esc_attr( asde_a( 'Evento' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'evento', $e['id'], $url ) ); ?>"><strong><?php echo esc_html( $e['nombre'] ); ?></strong></a>
							<br><span class="asde-muted"><?php echo esc_html( EventosTable::numero( $e ) . ' · ' . Config::nombre_tipo( $cfg, $e['tipo'] ) ); ?></span>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Fechas' ) ); ?>"><?php echo esc_html( EventosTable::cuando( $e ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Secciones' ) ); ?>">
							<?php
							foreach ( $e['secciones'] as $s ) {
								echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>"><span class="asde-badge asde-badge--evento-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( EventosTable::ESTADOS[ $estado ] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Precio' ) ); ?>">
							<?php if ( ! $e['con_coste'] ) : ?>
								<span class="asde-muted"><?php asde_e( 'Sin coste' ); ?></span>
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
								<span class="asde-muted"><?php asde_e( 'Por validar' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
