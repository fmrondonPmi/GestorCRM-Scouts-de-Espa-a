<?php
/**
 * Presupuestos (v0.54, doc 57): de la cuota al dinero de cada sección.
 *
 * @var string     $ronda
 * @var array      $cfg
 * @var array|null $reparto Resultado de Presupuestos\Reparto::calcular()
 * @var string     $error
 * @var string     $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$pre = $cfg['presupuesto'];
$e   = static fn( float $v ) => Calculadora::euros( $v );
$pct = static fn( float $v ) => rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
$sec = Secciones::all();
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Budgets ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p><?php esc_html_e( 'From the families\' fee, each youth member\'s registration and insurance are set aside: with that money the group pays the federation cost of all members. Of the rest (the annual fee), a percentage goes to the group\'s funds and the remainder to the budget of each member\'s section. It is calculated with the round\'s expected fees and the CRM\'s active members.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
</section>

<?php if ( $reparto ) : ?>
	<?php $t = $reparto['total']; $g = $reparto['grupo']; ?>
	<section class="asde-card" data-asde-plegable="presupuestos-resumen" data-asde-inicio="abierto">
		<h3><?php esc_html_e( 'Fees, federation cost and what is left', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<table class="asde-table asde-table--resumen">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Expected fees from families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $t['cuota'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Registration and insurance (they pay the federation cost)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $t['inscripcion'] ) ); ?></td></tr>
				<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Left to share out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $t['bolsa'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Collected to date (informative)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $t['cobrado'] ) ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( $t['no_cubren'] > 0 ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'There are %d youth members whose fee does not cover the registration and insurance (scholarships or adjustments): they contribute nothing to their section and the group makes up the difference.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $t['no_cubren'] ) ); ?></div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_presupuestos' ); ?>
	<input type="hidden" name="asde_action" value="guardar_presupuestos">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">

	<section class="asde-card" data-asde-plegable="presupuestos-secciones" data-asde-inicio="abierto">
		<h3><?php esc_html_e( 'Split by section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p>
			<label for="asde-p-pct"><?php esc_html_e( '% for the group\'s funds (default)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-p-pct" name="presupuesto[pct_grupo]" step="0.01" min="0" max="100" value="<?php echo esc_attr( $pct( (float) $pre['pct_grupo'] ) ); ?>">
		</p>
		<table class="asde-table asde-table--resumen">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Youth members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Registration and insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Net', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( '% to the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To the section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
					<?php $s = $reparto['secciones'][ $slug ] ?? null; ?>
					<tr>
						<th scope="row" data-label="<?php echo esc_attr( esc_attr__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $sec[ $slug ]['nombre'] ); ?></th>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Youth members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $s ? (int) $s['educandos'] : '–'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $s ? esc_html( $e( $s['cuota'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Registration and insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $s ? esc_html( $e( $s['inscripcion'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Net', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $s ? esc_html( $e( $s['neto'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( '% to the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<input type="number" name="presupuesto[pct_seccion][<?php echo esc_attr( $slug ); ?>]" step="0.01" min="0" max="100" placeholder="<?php echo esc_attr( $pct( (float) $pre['pct_grupo'] ) ); ?>" value="<?php echo esc_attr( isset( $pre['pct_seccion'][ $slug ] ) ? $pct( (float) $pre['pct_seccion'][ $slug ] ) : '' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ esc_attr__( '%% to the group of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $sec[ $slug ]['nombre'] ) ); ?>">
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'To the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $s ? esc_html( $e( $s['a_grupo'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'To the section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo $s ? esc_html( $e( $s['a_seccion'] ) ) : '–'; ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-help"><?php esc_html_e( 'Leave a section\'s percentage empty to use the group\'s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>

	<?php if ( $reparto ) : ?>
		<section class="asde-card" data-asde-plegable="presupuestos-grupo" data-asde-inicio="abierto">
			<h3><?php esc_html_e( 'Group funds', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<table class="asde-table asde-table--resumen">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Part of the youth members\' fees', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $g['a_grupo'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Families\' registration and insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>+ <?php echo esc_html( $e( $g['inscripcion'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Federation cost of youth members with a fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $g['educandos'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Federation cost of scouters and volunteers', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $g['adultos'] ) ); ?></td></tr>
					<?php if ( $g['sin_cuota'] > 0 ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Federation cost of youth members without a fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $g['sin_cuota'] ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $g['fijos'] > 0 ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Fixed items per group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $g['fijos'] ) ); ?></td></tr>
					<?php endif; ?>
					<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Available for the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $g['disponible'] ) ); ?></td></tr>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $presupuestos ) ) : ?>
		<section class="asde-card" data-asde-plegable="presupuestos-estados" data-asde-inicio="abierto">
			<h3><?php esc_html_e( 'Budget of each section and of the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<table class="asde-table asde-table--resumen">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Available', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Spent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fundraising target', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $presupuestos as $clave => $pr ) : ?>
						<?php $r = $pr['resultado']; ?>
						<tr>
							<th scope="row" data-label="<?php echo esc_attr( esc_attr__( 'Budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( array( 'ronda' => $ronda, 'vista' => $clave ), $url ) ); ?>"><?php echo esc_html( \AsdeBalears\AreaPrivada\Presupuestos\Servicio::nombre( $clave ) ); ?></a></th>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Available', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $r['disponible'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $r['gastos'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Spent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $pr['ejecucion']['gastado'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Fundraising target', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $r['recaudacion'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong<?php echo $r['saldo'] < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $r['saldo'] ) ); ?></strong></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( asde_t( \AsdeBalears\AreaPrivada\Presupuestos\EstadosTable::ETIQUETAS[ $pr['estado'] ] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<section class="asde-card" data-asde-plegable="presupuestos-categorias" data-asde-inicio="cerrado">
		<h3><?php esc_html_e( 'Expense categories', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'One per line. They are the ones chosen when adding an expense to a budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p><label for="asde-p-cats"><?php esc_html_e( 'Categories', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><textarea id="asde-p-cats" name="presupuesto[categorias]" rows="8"><?php echo esc_textarea( implode( "\n", $pre['categorias'] ) ); ?></textarea></p>
	</section>

	<section class="asde-card" data-asde-plegable="presupuestos-proyectos" data-asde-inicio="cerrado">
		<h3><?php esc_html_e( 'Fundraising project of each section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Each section has a project with its fundraising target; this is the name that will be used in its budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div class="asde-grid-3">
			<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
				<p>
					<label for="asde-p-proy-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $sec[ $slug ]['nombre'] ); ?></label>
					<input type="text" id="asde-p-proy-<?php echo esc_attr( $slug ); ?>" name="presupuesto[proyectos][<?php echo esc_attr( $slug ); ?>]" maxlength="80" value="<?php echo esc_attr( $pre['proyectos'][ $slug ] ); ?>">
				</p>
			<?php endforeach; ?>
		</div>
	</section>

	<p><button type="submit" class="button"><?php esc_html_e( 'Save the split', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
</form>
