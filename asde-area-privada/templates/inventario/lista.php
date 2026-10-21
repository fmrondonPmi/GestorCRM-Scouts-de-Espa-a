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
		<h2 class="asde-card__title"><?php asde_e( 'Inventario de material' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', '1', $url ) ); ?>"><?php asde_e( 'Añadir material' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'importar', '1', $url ) ); ?>" data-asde-modal><?php asde_e( 'Importar desde CSV' ); ?></a>
		</span>
	</header>
	<p class="asde-muted">
		<?php
		echo esc_html(
			sprintf( asde_n( '%d material', '%d materiales', $n_activos ), $n_activos ) . ' · '
			. sprintf( asde_n( '%d unidad', '%d unidades', $unidades ), $unidades ) . ' · '
			. sprintf( asde_t( 'valor estimado %s' ), Calculadora::euros( (float) $valor ) )
		);
		?>
	</p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-inv-filtro>
		<label for="asde-inv-tipo"><?php asde_e( 'Tipo' ); ?></label>
		<select id="asde-inv-tipo" name="tipo">
			<option value="0"><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( $tipos as $t ) : ?>
				<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tipo, $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
			<?php endforeach; ?>
			<option value="-1" <?php selected( $tipo, -1 ); ?>><?php asde_e( 'Sin tipo' ); ?></option>
		</select>
		<label for="asde-inv-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-inv-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Descripción o nº de serie' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="bajas" value="1" <?php checked( $bajas ); ?>><span><?php asde_e( 'Ver también los dados de baja' ); ?></span></label>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<?php if ( ! $materiales ) : ?>
		<p><?php asde_e( 'Todavía no hay material en el inventario. Empieza con «Añadir material» o, si ya lo tienes en una hoja de cálculo, con «Importar desde CSV».' ); ?></p>
	<?php else : ?>
		<form method="get" action="<?php echo esc_url( $url_compra ); ?>" data-asde-inv-compra data-umbral="<?php echo (int) $umbral; ?>">
			<input type="hidden" name="nueva" value="1">
			<table class="asde-table asde-table--inventario">
				<thead>
					<tr>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Añadir a la lista de compra' ); ?></span></th>
						<th scope="col"><?php asde_e( 'Descripción' ); ?></th>
						<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'Cantidad' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'Precio' ); ?></th>
						<th scope="col"><?php asde_e( 'Año de compra' ); ?></th>
						<th scope="col"><?php asde_e( 'Nº de serie' ); ?></th>
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
							<td data-label="<?php echo esc_attr( asde_a( 'Comprar' ) ); ?>">
								<?php if ( $activo ) : ?>
									<input type="checkbox" name="m[]" value="<?php echo (int) $m['id']; ?>" data-cantidad="<?php echo (int) $m['cantidad']; ?>" data-nombre="<?php echo esc_attr( $m['descripcion'] ); ?>" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Añadir «%s» a la lista de compra' ), $m['descripcion'] ) ); ?>">
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Descripción' ) ); ?>">
								<a href="<?php echo esc_url( add_query_arg( 'material', $m['id'], $url ) ); ?>"><?php echo esc_html( $m['descripcion'] ); ?></a>
								<?php if ( ! $activo ) : ?>
									<span class="asde-badge asde-badge--uniforme-cancelado"><?php asde_e( 'De baja' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $m['notas'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( wp_trim_words( $m['notas'], 14 ) ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>"><?php echo '' !== $nombre_tipo ? esc_html( $nombre_tipo ) : '<span class="asde-muted">—</span>'; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Cantidad' ) ); ?>" class="asde-num">
								<?php if ( $activo && $pocas ) : ?>
									<span class="asde-badge asde-badge--inv-pocas" title="<?php echo esc_attr( sprintf( asde_t( 'Menos de %d unidades' ), $umbral ) ); ?>"><?php echo (int) $m['cantidad']; ?></span>
								<?php else : ?>
									<?php echo (int) $m['cantidad']; ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Precio' ) ); ?>" class="asde-num"><?php echo null === $m['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $m['precio'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Año de compra' ) ); ?>"><?php echo null === $m['anio'] ? '<span class="asde-muted">—</span>' : (int) $m['anio']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Nº de serie' ) ); ?>"><?php echo '' !== $m['num_serie'] ? esc_html( $m['num_serie'] ) : '<span class="asde-muted">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-inv-vacio asde-muted" hidden><?php asde_e( 'Ningún material coincide con el filtro.' ); ?></p>
			<p class="asde-form__fila">
				<button type="submit" class="button"><?php asde_e( 'Crear una lista de compra con los marcados' ); ?></button>
				<span class="asde-muted" data-asde-inv-marcados></span>
			</p>
			<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Marca el material que hay que comprar (lo que todavía no está en el inventario lo podrás escribir en la lista). Si de alguno ya hay %d unidades o más, te preguntaremos si de verdad hace falta comprar más. La lista la tiene que aprobar Tesorería antes de imprimirla.' ), $umbral ) ); ?></p>
		</form>
	<?php endif; ?>
</section>
