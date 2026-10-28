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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\CsvBanco;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha    = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) ) : '—';
$es_csv   = 'csv' === $previa['formato'];
$columnas = array(
	'fecha'    => __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'concepto' => __( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'importe'  => __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'estado'   => __( 'On the website', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Preview:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $previa['fichero'] ); ?></h2>
	<p class="asde-muted">
		<?php esc_html_e( 'Format:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo $es_csv ? 'CSV' : 'Norma 43'; ?></strong> <?php esc_html_e( '· Character set:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $lectura['codificacion'] ); ?>
		<?php if ( ! $es_csv && ! empty( $lectura['cuentas'][0]['modalidad'] ) ) : ?>
			<?php esc_html_e( '· Mode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $lectura['cuentas'][0]['modalidad'] ); ?><?php echo '3' === $lectura['cuentas'][0]['modalidad'] ? esc_html( __( ' (with SEPA payer and description)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>
		<?php endif; ?>
	</p>

	<?php if ( $lectura['ya_importado'] ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'This same file was already imported on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $lectura['ya_importado']['creado'] . ' UTC' ) ) ); ?><?php esc_html_e( '. Repeated transactions are ignored.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
	<?php foreach ( $lectura['errores'] as $e ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
	<?php endforeach; ?>
	<?php foreach ( array_slice( $lectura['avisos'], 0, 10 ) as $a ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( $a ); ?></div>
	<?php endforeach; ?>
	<?php if ( count( $lectura['avisos'] ) > 10 ) : ?>
		<p class="asde-muted">… y <?php echo count( $lectura['avisos'] ) - 10; ?> <?php esc_html_e( 'more notices.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php if ( $lectura['cuentas'] ) : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Opening balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Credits', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Final balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $lectura['cuentas'] as $c ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $c['cuenta'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $c['desde'] ) . ' – ' . $fecha( $c['hasta'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Opening balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo null !== $c['saldo_inicial'] ? esc_html( Calculadora::euros( $c['saldo_inicial'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Credits', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">+<?php echo esc_html( Calculadora::euros( $c['abonos'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">−<?php echo esc_html( Calculadora::euros( $c['cargos'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Final balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo null !== $c['saldo_final'] ? esc_html( Calculadora::euros( $c['saldo_final'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $c['movimientos']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $es_csv && ! $lectura['errores'] ) : ?>
			<p class="asde-muted"><?php esc_html_e( '✓ The totals of each account (record 33) and the number of records (88) match the transactions read.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $es_csv && $lectura['columnas'] ) : ?>
	<section class="asde-card">
		<details <?php echo $lectura['necesita_mapeo'] ? 'open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'CSV columns', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo $lectura['necesita_mapeo'] ? esc_html( __( '(choose what each one corresponds to)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( '(recognised automatically; you can change them)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></summary>
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
									<option value="<?php echo (int) $i; ?>" <?php selected( $lectura['mapeo'][ $campo ] ?? -1, $i ); ?>><?php echo esc_html( '' !== $titulo ? $titulo : sprintf( /* translators: %d: number */ __( 'Column %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1 ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endforeach; ?>
				</div>
				<p class="asde-help"><?php esc_html_e( 'The date and amount (or the debit and credit) are required. The choice is remembered for the next files with the same columns.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Read again with these columns', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>

<?php if ( $movs ) : ?>
	<section class="asde-card" data-asde-plegable="banco-previa">
		<h3><?php echo (int) $total; ?> <?php esc_html_e( 'transactions ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $nuevos; ?> <?php esc_html_e( 'new', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( $posibles ) : ?>
			<p class="asde-help asde-help--warning"><?php echo (int) $posibles; ?> <?php esc_html_e( 'new ones match another already imported one in account, date and amount (the same transaction from another format?). They will be imported; if they are surplus, mark them as reviewed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movs as $m ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( '' !== $m['ordenante'] ) : ?>
								<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
							<?php endif; ?>
							<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : ( asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ) ); ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe <?php echo $m['importe'] > 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $m['importe'] > 0 ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'On the website', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $m['duplicado'] ) : ?>
								<span class="asde-muted"><?php esc_html_e( 'Already imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php elseif ( $m['posible_duplicado'] ) : ?>
								<span class="asde-help asde-help--warning"><?php esc_html_e( 'New (possible duplicate)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php else : ?>
								<strong><?php esc_html_e( 'New', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo wp_kses( $tabla->paginacion(), asde_html_permitido() ); ?>
	</section>
<?php endif; ?>

<section class="asde-card">
	<form method="post" class="asde-actions">
		<?php wp_nonce_field( 'asde_banco_previa' ); ?>
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
		<?php if ( ! $lectura['errores'] && $nuevos ) : ?>
			<button type="submit" name="asde_action" value="importar" class="button"><?php esc_html_e( 'Import', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $nuevos; ?> <?php esc_html_e( 'new transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<?php elseif ( ! $lectura['errores'] ) : ?>
			<span><?php esc_html_e( 'There is nothing new to import.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php else : ?>
			<span><?php esc_html_e( 'It cannot be imported with errors: check the file or choose the format and columns correctly.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php endif; ?>
		<button type="submit" name="asde_action" value="cancelar" class="asde-btn-link"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
</section>
