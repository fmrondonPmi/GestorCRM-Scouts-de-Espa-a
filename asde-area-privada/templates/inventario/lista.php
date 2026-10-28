<?php
/**
 * Inventario de material del grupo (doc 32).
 *
 * Se pintan todos los materiales y los filtros esconden filas: sin JavaScript se
 * aplican al pulsar «Ver» (en el servidor); con él, mientras se escribe (inventario.js).
 *
 * @var array<int, array> $materiales
 * @var array<int, array> $tipos      id => {id, nombre}
 * @var int               $tipo       Filtro por tipo (0 = todos, -1 = sin tipo)
 * @var string            $q
 * @var bool              $bajas      Se ven también los dados de baja
 * @var int               $umbral     Por debajo, «pocas unidades»; desde él, aviso al añadirlo a la lista de compra (P-62, v0.30)
 * @var int               $unidades
 * @var float             $valor
 * @var int               $n_activos
 * @var string            $url
 * @var string            $url_compra
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$normal = static fn( string $t ): string => mb_strtolower( remove_accents( $t ) );
$buscar = $normal( trim( $q ) );
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Equipment inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', '1', $url ) ); ?>"><?php esc_html_e( 'Add equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'importar', '1', $url ) ); ?>" data-asde-modal><?php esc_html_e( 'Import from CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p class="asde-muted">
		<?php
		echo esc_html(
			sprintf( /* translators: %d: number */ _n( '%d item of equipment', '%d items of equipment', $n_activos , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n_activos ) . ' · '
			. sprintf( /* translators: %d: number */ _n( '%d unit', '%d units', $unidades , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $unidades ) . ' · '
			. sprintf( /* translators: %s: text */ __( 'estimated value %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( (float) $valor ) )
		);
		?>
	</p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-inv-filtro>
		<label for="asde-inv-tipo"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-inv-tipo" name="tipo">
			<option value="0"><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $tipos as $t ) : ?>
				<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tipo, $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
			<?php endforeach; ?>
			<option value="-1" <?php selected( $tipo, -1 ); ?>><?php esc_html_e( 'No type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<label for="asde-inv-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-inv-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Description or serial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="bajas" value="1" <?php checked( $bajas ); ?>><span><?php esc_html_e( 'Also show the written-off ones', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $materiales ) : ?>
		<p><?php esc_html_e( 'There is no equipment in the inventory yet. Start with "Add equipment" or, if you already have it in a spreadsheet, with "Import from CSV".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="get" action="<?php echo esc_url( $url_compra ); ?>" data-asde-inv-compra data-umbral="<?php echo (int) $umbral; ?>">
			<input type="hidden" name="nueva" value="1">
			<table class="asde-table asde-table--inventario">
				<thead>
					<tr>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Add to the shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Year of purchase', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Serial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $materiales as $m ) : ?>
						<?php
						$nombre_tipo = $tipos[ $m['tipo'] ]['nombre'] ?? '';
						$texto       = $normal( $m['descripcion'] . ' ' . $m['num_serie'] . ' ' . $nombre_tipo );
						$tipo_fila   = $nombre_tipo ? (int) $m['tipo'] : -1;
						$activo      = null === $m['baja_en'];
						$pocas       = Servicio::pocas_unidades( (int) $m['cantidad'] );
						$oculta      = ( $tipo && $tipo !== $tipo_fila ) || ( '' !== $buscar && ! str_contains( $texto, $buscar ) );
						?>
						<tr data-tipo="<?php echo (int) $tipo_fila; ?>" data-texto="<?php echo esc_attr( $texto ); ?>"<?php echo $activo ? '' : ' class="asde-fila--cancelada"'; ?><?php echo $oculta ? ' hidden' : ''; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( $activo ) : ?>
									<input type="checkbox" name="m[]" value="<?php echo (int) $m['id']; ?>" data-cantidad="<?php echo (int) $m['cantidad']; ?>" data-nombre="<?php echo esc_attr( $m['descripcion'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Add "%s" to the shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['descripcion'] ) ); ?>">
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<a href="<?php echo esc_url( add_query_arg( 'material', $m['id'], $url ) ); ?>"><?php echo esc_html( $m['descripcion'] ); ?></a>
								<?php if ( ! $activo ) : ?>
									<span class="asde-badge asde-badge--uniforme-cancelado"><?php esc_html_e( 'Written off', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $m['notas'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( wp_trim_words( $m['notas'], 14 ) ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $nombre_tipo ? esc_html( $nombre_tipo ) : '<span class="asde-muted">—</span>'; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num">
								<?php if ( $activo && $pocas ) : ?>
									<span class="asde-badge asde-badge--inv-pocas" title="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Fewer than %d units', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $umbral ) ); ?>"><?php echo (int) $m['cantidad']; ?></span>
								<?php else : ?>
									<?php echo (int) $m['cantidad']; ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo null === $m['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $m['precio'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Year of purchase', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo null === $m['anio'] ? '<span class="asde-muted">—</span>' : (int) $m['anio']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Serial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $m['num_serie'] ? esc_html( $m['num_serie'] ) : '<span class="asde-muted">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-inv-vacio asde-muted" hidden><?php esc_html_e( 'No equipment matches the filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p class="asde-form__fila">
				<button type="submit" class="button"><?php esc_html_e( 'Create a shopping list with the ticked items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<span class="asde-muted" data-asde-inv-marcados></span>
			</p>
			<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Tick the equipment that needs to be bought (what is not yet in the inventory can be written on the list). If there are already %d units or more of any item, we will ask whether more really need to be bought. The list must be approved by Treasury before it is printed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $umbral ) ); ?></p>
		</form>
	<?php endif; ?>
</section>
