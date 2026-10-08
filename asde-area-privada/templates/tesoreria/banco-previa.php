<?php
/**
 * Vista previa de un fichero del banco antes de importarlo.
 *
 * @var string                                 $token
 * @var array                                  $previa  formato, fichero, codificacion…
 * @var array                                  $lectura cuentas, movimientos, errores, avisos (+ columnas y mapeo en CSV)
 * @var array[]                                $movs    Página que se muestra
 * @var int                                    $total
 * @var int                                    $nuevos
 * @var int                                    $posibles
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var string                                 $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\CsvBanco;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha    = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) ) : '—';
$es_csv   = 'csv' === $previa['formato'];
$columnas = array(
	'fecha'    => asde_t( 'Fecha' ),
	'concepto' => asde_t( 'Concepto' ),
	'importe'  => asde_t( 'Importe' ),
	'estado'   => asde_t( 'En la web' ),
);
?>
<section class="asde-card">
	<h2><?php asde_e( 'Vista previa:' ); ?> <?php echo esc_html( $previa['fichero'] ); ?></h2>
	<p class="asde-muted">
		<?php asde_e( 'Formato:' ); ?> <strong><?php echo $es_csv ? 'CSV' : 'Norma 43'; ?></strong> <?php asde_e( '· Juego de caracteres:' ); ?> <?php echo esc_html( $lectura['codificacion'] ); ?>
		<?php if ( ! $es_csv && ! empty( $lectura['cuentas'][0]['modalidad'] ) ) : ?>
			<?php asde_e( '· Modalidad' ); ?> <?php echo esc_html( $lectura['cuentas'][0]['modalidad'] ); ?><?php echo '3' === $lectura['cuentas'][0]['modalidad'] ? esc_html( asde_t( ' (con ordenante y concepto SEPA)' ) ) : ''; ?>
		<?php endif; ?>
	</p>

	<?php if ( $lectura['ya_importado'] ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este mismo fichero ya se importó el' ); ?> <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $lectura['ya_importado']['creado'] . ' UTC' ) ) ); ?><?php asde_e( '. Los movimientos repetidos se ignoran.' ); ?></div>
	<?php endif; ?>
	<?php foreach ( $lectura['errores'] as $e ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
	<?php endforeach; ?>
	<?php foreach ( array_slice( $lectura['avisos'], 0, 10 ) as $a ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( $a ); ?></div>
	<?php endforeach; ?>
	<?php if ( count( $lectura['avisos'] ) > 10 ) : ?>
		<p class="asde-muted">… y <?php echo count( $lectura['avisos'] ) - 10; ?> <?php asde_e( 'avisos más.' ); ?></p>
	<?php endif; ?>

	<?php if ( $lectura['cuentas'] ) : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Cuenta' ); ?></th><th scope="col"><?php asde_e( 'Periodo' ); ?></th><th scope="col"><?php asde_e( 'Saldo inicial' ); ?></th><th scope="col"><?php asde_e( 'Abonos' ); ?></th><th scope="col"><?php asde_e( 'Cargos' ); ?></th><th scope="col"><?php asde_e( 'Saldo final' ); ?></th><th scope="col"><?php asde_e( 'Movimientos' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $lectura['cuentas'] as $c ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Cuenta' ) ); ?>"><?php echo esc_html( $c['cuenta'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Periodo' ) ); ?>"><?php echo esc_html( $fecha( $c['desde'] ) . ' – ' . $fecha( $c['hasta'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Saldo inicial' ) ); ?>"><?php echo null !== $c['saldo_inicial'] ? esc_html( Calculadora::euros( $c['saldo_inicial'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Abonos' ) ); ?>">+<?php echo esc_html( Calculadora::euros( $c['abonos'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cargos' ) ); ?>">−<?php echo esc_html( Calculadora::euros( $c['cargos'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Saldo final' ) ); ?>"><?php echo null !== $c['saldo_final'] ? esc_html( Calculadora::euros( $c['saldo_final'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Movimientos' ) ); ?>"><?php echo (int) $c['movimientos']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $es_csv && ! $lectura['errores'] ) : ?>
			<p class="asde-muted"><?php asde_e( '✓ Los totales de cada cuenta (registro 33) y el nº de registros (88) cuadran con los movimientos leídos.' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $es_csv && $lectura['columnas'] ) : ?>
	<section class="asde-card">
		<details <?php echo $lectura['necesita_mapeo'] ? 'open' : ''; ?>>
			<summary><strong><?php asde_e( 'Columnas del CSV' ); ?></strong> <?php echo $lectura['necesita_mapeo'] ? esc_html( asde_t( '(elige a qué corresponde cada una)' ) ) : esc_html( asde_t( '(reconocidas automáticamente; puedes cambiarlas)' ) ); ?></summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_banco_previa' ); ?>
				<input type="hidden" name="asde_action" value="mapear">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<div class="asde-grid-2">
					<?php foreach ( asde_ta( CsvBanco::CAMPOS ) as $campo => $etiqueta ) : ?>
						<p>
							<label for="asde-col-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $etiqueta ); ?></label>
							<select id="asde-col-<?php echo esc_attr( $campo ); ?>" name="col_<?php echo esc_attr( $campo ); ?>">
								<option value="">—</option>
								<?php foreach ( $lectura['columnas'] as $i => $titulo ) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected( $lectura['mapeo'][ $campo ] ?? -1, $i ); ?>><?php echo esc_html( '' !== $titulo ? $titulo : sprintf( asde_t( 'Columna %d' ), $i + 1 ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endforeach; ?>
				</div>
				<p class="asde-help"><?php asde_e( 'Hace falta la fecha y el importe (o el cargo y el abono). La elección se recuerda para los próximos ficheros con las mismas columnas.' ); ?></p>
				<p><button type="submit" class="button"><?php asde_e( 'Volver a leer con estas columnas' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>

<?php if ( $movs ) : ?>
	<section class="asde-card">
		<h3><?php echo (int) $total; ?> <?php asde_e( 'movimientos ·' ); ?> <?php echo (int) $nuevos; ?> <?php asde_e( 'nuevos' ); ?></h3>
		<?php if ( $posibles ) : ?>
			<p class="asde-help asde-help--warning"><?php echo (int) $posibles; ?> <?php asde_e( 'nuevos coinciden en cuenta, fecha e importe con otro ya importado (¿el mismo movimiento desde otro formato?). Se importarán; si sobran, márcalos como revisados.' ); ?></p>
		<?php endif; ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movs as $m ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
							<?php if ( '' !== $m['ordenante'] ) : ?>
								<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
							<?php endif; ?>
							<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : ( asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ) ); ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $m['importe'] > 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $m['importe'] > 0 ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'En la web' ) ); ?>">
							<?php if ( $m['duplicado'] ) : ?>
								<span class="asde-muted"><?php asde_e( 'Ya importado' ); ?></span>
							<?php elseif ( $m['posible_duplicado'] ) : ?>
								<span class="asde-help asde-help--warning"><?php asde_e( 'Nuevo (posible duplicado)' ); ?></span>
							<?php else : ?>
								<strong><?php asde_e( 'Nuevo' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
<?php endif; ?>

<section class="asde-card">
	<form method="post" class="asde-actions">
		<?php wp_nonce_field( 'asde_banco_previa' ); ?>
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
		<?php if ( ! $lectura['errores'] && $nuevos ) : ?>
			<button type="submit" name="asde_action" value="importar" class="button"><?php asde_e( 'Importar' ); ?> <?php echo (int) $nuevos; ?> <?php asde_e( 'movimientos nuevos' ); ?></button>
		<?php elseif ( ! $lectura['errores'] ) : ?>
			<span><?php asde_e( 'No hay nada nuevo que importar.' ); ?></span>
		<?php else : ?>
			<span><?php asde_e( 'Con errores no se puede importar: revisa el fichero o elige bien el formato y las columnas.' ); ?></span>
		<?php endif; ?>
		<button type="submit" name="asde_action" value="cancelar" class="asde-btn-link"><?php asde_e( 'Cancelar' ); ?></button>
	</form>
</section>
