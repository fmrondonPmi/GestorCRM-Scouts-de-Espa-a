<?php
/**
 * Tesorería → Libro mayor (v0.59, doc 61): las cuentas (banco y cajas) con sus apuntes y el resumen por partida.
 *
 * @var array                 $periodo       Periodo::de()
 * @var array<string, string> $periodos
 * @var array                 $libro         LibroMayor::calcular()
 * @var bool                  $banco         El módulo del banco está activo
 * @var array|null            $banco_inicial El saldo inicial del banco (null si aún no se ha puesto)
 * @var string                $hoy
 * @var string                $url
 * @var string                $url_caja      Adonde van los formularios (la vista de la caja)
 * @var string                $url_apuntes   Excel de los apuntes
 * @var string                $url_resumen   Excel del resumen
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Frontend\Views\LibroMayorView;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$euros = static fn( float $v ) => Calculadora::euros( $v );
$total_e = array_sum( array_column( $libro['resumen'], 'entradas' ) );
$total_s = array_sum( array_column( $libro['resumen'], 'salidas' ) );
?>
<section class="asde-card" data-asde-no-plegable>
	<h2><?php esc_html_e( 'General ledger', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-lm-periodo" class="screen-reader-text"><?php esc_html_e( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-lm-periodo" name="periodo" data-asde-autoenviar>
			<?php foreach ( $periodos as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $periodo['clave'], $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_apuntes ); ?>" data-asde-descarga><?php esc_html_e( 'Excel of the entries', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_resumen ); ?>" data-asde-descarga><?php esc_html_e( 'Excel of the summary', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</form>
	<p class="asde-help"><?php esc_html_e( 'The bank and each cash box, with their balance at the start and end of the period, and the summary by item. Transfers between the group\'s accounts appear in each account, but not in the summary.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<table class="asde-table asde-table--resumen" data-asde-sin-paginar="resumen por secciones o categorias (pocas filas fijas)">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'At the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Inflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'At the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $libro['cuentas'] as $c ) : ?>
				<tr>
					<th scope="row" data-label="<?php echo esc_attr( esc_attr__( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $c['nombre'] ); ?><?php echo $c['sin_inicial'] ? ' <span class="asde-muted">(' . esc_html( __( 'no opening balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ')</span>' : ''; ?></th>
					<td data-label="<?php echo esc_attr( esc_attr__( 'At the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $euros( $c['inicial'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Inflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe--ingreso">+<?php echo esc_html( $euros( $c['entradas'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Outflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe--gasto">−<?php echo esc_html( $euros( $c['salidas'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'At the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $euros( $c['final'] ) ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $banco && ! $banco_inicial ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The bank has no opening balance yet: enter the account balance on the day from which you want to keep the general ledger (it appears on the statement). Anything before that date does not count.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<form method="post" action="<?php echo esc_url( $url_caja ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
			<?php wp_nonce_field( 'asde_caja' ); ?>
			<input type="hidden" name="asde_action" value="inicial">
			<input type="hidden" name="caja" value="<?php echo esc_attr( CajaTable::BANCO ); ?>">
			<input type="hidden" name="periodo" value="<?php echo esc_attr( $periodo['clave'] ); ?>">
			<label for="asde-lm-ini-fecha"><?php esc_html_e( 'Bank balance on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="date" id="asde-lm-ini-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
			<label for="asde-lm-ini-importe" class="screen-reader-text"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-lm-ini-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( esc_attr__( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	<?php elseif ( $banco && $banco_inicial ) : ?>
		<details class="asde-caja-anular">
			<?php /* translators: 1: date, 2: amount */ ?>
			<summary><?php echo esc_html( sprintf( /* translators: 2: text, 1: text */ __( 'Bank opening balance: %2$s on %1$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $banco_inicial['fecha'] ), $euros( (float) $banco_inicial['importe'] ) ) ); ?></summary>
			<form method="post" action="<?php echo esc_url( $url_caja ); ?>" class="asde-form__fila asde-form__fila--una-linea">
				<?php wp_nonce_field( 'asde_caja' ); ?>
				<input type="hidden" name="asde_action" value="anular">
				<input type="hidden" name="caja" value="<?php echo esc_attr( CajaTable::BANCO ); ?>">
				<input type="hidden" name="periodo" value="<?php echo esc_attr( $periodo['clave'] ); ?>">
				<input type="hidden" name="apunte" value="<?php echo (int) $banco_inicial['id']; ?>">
				<label class="screen-reader-text" for="asde-lm-anular"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-lm-anular" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason for changing it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
				<button type="submit" class="button"><?php esc_html_e( 'Cancel to change it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="libro-resumen" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Summary by item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $libro['resumen'] ) : ?>
		<p><?php esc_html_e( 'There are no entries in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--resumen" data-asde-sin-paginar="resumen por secciones o categorias (pocas filas fijas)">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Inflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Net', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $libro['resumen'] as $partida => $r ) : ?>
					<tr>
						<th scope="row" data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $partida ); ?></th>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Inflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $r['entradas'] > 0 ? esc_html( $euros( $r['entradas'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Outflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $r['salidas'] > 0 ? esc_html( $euros( $r['salidas'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Net', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( LibroMayorView::con_signo( round( $r['entradas'] - $r['salidas'], 2 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="asde-total">
					<th scope="row"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td><?php echo esc_html( $euros( round( $total_e, 2 ) ) ); ?></td>
					<td><?php echo esc_html( $euros( round( $total_s, 2 ) ) ); ?></td>
					<td><strong><?php echo esc_html( LibroMayorView::con_signo( round( $total_e - $total_s, 2 ) ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php foreach ( $libro['cuentas'] as $clave => $c ) : ?>
	<section class="asde-card" data-asde-plegable="libro-<?php echo esc_attr( $clave ); ?>" data-asde-inicio="cerrado">
		<h3><?php echo esc_html( $c['nombre'] ); ?></h3>
		<?php if ( ! $c['filas'] ) : ?>
			<p><?php esc_html_e( 'There are no entries in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<table class="asde-table asde-table--caja" data-asde-paginar>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><em><?php esc_html_e( 'Balance at the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></em></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $euros( $c['inicial'] ) ); ?></td>
					</tr>
					<?php foreach ( $c['filas'] as $f ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $f['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['concepto'] ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['partida'] ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe <?php echo $f['importe'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( LibroMayorView::con_signo( $f['importe'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $euros( $f['saldo'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
