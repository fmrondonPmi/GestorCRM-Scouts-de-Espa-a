<?php
/**
 * Presupuesto de una sección o del grupo (v0.55, doc 57 §5): gastos, ingresos, saldo y estado.
 *
 * @var string     $ronda
 * @var string     $url
 * @var string     $vista       Sección (castores…rovers) o «grupo»
 * @var array|null $reparto
 * @var string     $error
 * @var array[]    $partidas
 * @var array      $estado      EstadosTable::de()
 * @var array      $resultado   Servicio::resultado()
 * @var bool       $editable
 * @var string[]   $categorias
 * @var bool       $hay_previa  Hay presupuesto en la ronda anterior
 * @var bool       $vacio       Sin partidas propias
 * @var float|null $pct         % que va al grupo (null en el del grupo)
 * @var bool       $es_config
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Presupuestos\EstadosTable;
use AsdeBalears\AreaPrivada\Presupuestos\PartidasTable;
use AsdeBalears\AreaPrivada\Presupuestos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$e       = static fn( float $v ) => Calculadora::euros( $v );
$nombre  = Servicio::nombre( $vista );
$gastos  = array_values( array_filter( $partidas, static fn( $p ) => PartidasTable::GASTO === $p['tipo'] ) );
$ingres  = array_values( array_filter( $partidas, static fn( $p ) => PartidasTable::INGRESO === $p['tipo'] ) );
$bloq    = EstadosTable::BORRADOR !== $estado['estado'];
$campos  = static function ( string $accion, array $extra = array() ) use ( $ronda, $vista ): string {
	$html  = wp_nonce_field( 'asde_presupuestos', '_wpnonce', true, false );
	$html .= '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">';
	$html .= '<input type="hidden" name="ronda" value="' . esc_attr( $ronda ) . '">';
	$html .= '<input type="hidden" name="seccion" value="' . esc_attr( $vista ) . '">';
	foreach ( $extra as $k => $v ) {
		$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
	}
	return $html;
};
?>
<section class="asde-card">
	<h2><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Budget: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre ) ); ?> · <?php echo esc_html( $ronda ); ?></h2>
	<p>
		<span class="asde-badge asde-badge--presupuesto-<?php echo esc_attr( $estado['estado'] ); ?>"><?php echo esc_html( ( EstadosTable::etiquetas()[ $estado['estado'] ] ) ); ?></span>
		<?php if ( null !== $pct ) : ?>
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Of each fee, %1$s %% goes to the group and %2$s %% to this section.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ), rtrim( rtrim( number_format( 100 - $pct, 2, '.', '' ), '0' ), '.' ) ) ); ?>
		<?php endif; ?>
	</p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<?php if ( $bloq ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php echo esc_html( EstadosTable::APROBADO === $estado['estado'] ? __( 'Budget approved: it is locked. Treasury can reopen it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Budget sent to Treasury: it is locked until it is approved or reopened.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></div>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-saldo" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Available from the fees', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $resultado['disponible'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Planned income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>+ <?php echo esc_html( $e( $resultado['ingresos'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Planned expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td>− <?php echo esc_html( $e( $resultado['gastos'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><strong<?php echo $resultado['saldo'] < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $resultado['saldo'] ) ); ?></strong></td></tr>
			<?php if ( $resultado['recaudacion'] > 0 ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Balance not counting the project\'s fundraising', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $resultado['sin_recaudacion'] ) ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<?php if ( $resultado['saldo'] < 0 ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The budget does not balance: expenses exceed what is available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
	<p class="asde-help"><?php esc_html_e( 'Fundraising is a target, not money collected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-gastos" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $gastos ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php esc_html_e( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'How many?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount of each', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Spent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Left', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php foreach ( $gastos as $p ) : ?>
					<?php $gast = (float) ( $por_partida[ $p['id'] ] ?? 0 ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( asde_guardado( (string) $p['categoria'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( asde_guardado( (string) $p['concepto'] ) ); ?><?php echo wp_kses( '' !== $p['nota'] ? ' <span class="asde-muted">· ' . esc_html( $p['nota'] ) . '</span>' : '', asde_html_permitido() ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'How many?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $p['veces']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount of each', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $p['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $p['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Spent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $gast ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Left', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span<?php echo $p['total'] - $gast < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $p['total'] - $gast ) ); ?></span></td>
						<td>
							<?php if ( $editable ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo wp_kses( $campos( 'partida_quitar', array( 'id' => $p['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'There are no expenses yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-ingresos" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $ingres ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'How many?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount of each', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php foreach ( $ingres as $p ) : ?>
					<?php $proy = PartidasTable::PROYECTO === $p['categoria']; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $proy ? esc_html( sprintf( /* translators: %s: text */ __( 'Project "%s": fundraising target', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_guardado( (string) $p['concepto'] ) ) ) : esc_html( asde_guardado( (string) $p['concepto'] ) ); ?><?php echo wp_kses( '' !== $p['nota'] ? ' <span class="asde-muted">· ' . esc_html( $p['nota'] ) . '</span>' : '', asde_html_permitido() ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'How many?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $p['veces']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount of each', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $proy && $editable ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo wp_kses( $campos( 'proyecto' ), asde_html_permitido() ); ?><input type="number" name="importe" step="0.01" min="0" value="<?php echo esc_attr( number_format( $p['importe'], 2, '.', '' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Fundraising target (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"> <button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
							<?php else : ?>
								<?php echo esc_html( $e( $p['importe'] ) ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $p['total'] ) ); ?></td>
						<td>
							<?php if ( $editable && ! $proy ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo wp_kses( $campos( 'partida_quitar', array( 'id' => $p['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'There is no income yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-real" data-asde-inicio="cerrado">
	<h3><?php esc_html_e( 'Actual expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Budgeted in expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $ejecucion['presupuestado'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Actually spent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $ejecucion['gastado'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Left', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><strong<?php echo $ejecucion['queda'] < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $ejecucion['queda'] ) ); ?></strong></td></tr>
		</tbody>
	</table>
	<?php if ( $gastos_reales ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php
				$nombres = array();
				foreach ( $gastos as $p ) {
					$nombres[ $p['id'] ] = asde_guardado( (string) $p['concepto'] );
				}
				?>
				<?php foreach ( $gastos_reales as $g ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $g['fecha'] ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( asde_guardado( (string) $g['concepto'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $nombres[ $g['partida_id'] ] ?? '–' ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $g['importe'] ) ); ?></td>
						<td><form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo wp_kses( $campos( 'gasto_quitar', array( 'id' => $g['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'No actual expense has been recorded yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php if ( $gastos ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php echo wp_kses( $campos( 'gasto_nuevo' ), asde_html_permitido() ); ?>
			<div class="asde-grid-3">
				<p>
					<label for="asde-pg-partida"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-pg-partida" name="partida_id">
						<?php foreach ( $gastos as $p ) : ?>
							<option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html( asde_guardado( (string) $p['concepto'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-pg-fecha"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="date" id="asde-pg-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>"></p>
				<p><label for="asde-pg-importe"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-pg-importe" name="importe" min="0.01" step="0.01"></p>
				<p><label for="asde-pg-concepto"><?php esc_html_e( 'Description (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-pg-concepto" name="concepto" maxlength="160"></p>
			</div>
			<p><button type="submit" class="button"><?php esc_html_e( 'Record the expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card" data-asde-plegable="presupuesto-nueva" data-asde-inicio="abierto">
		<h3><?php esc_html_e( 'Add an item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
			<?php echo wp_kses( $campos( 'partida_nueva' ), asde_html_permitido() ); ?>
			<div class="asde-grid-3">
				<p>
					<label for="asde-pp-tipo"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-pp-tipo" name="tipo">
						<option value="<?php echo esc_attr( PartidasTable::GASTO ); ?>"><?php esc_html_e( 'Expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<option value="<?php echo esc_attr( PartidasTable::INGRESO ); ?>"><?php esc_html_e( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					</select>
				</p>
				<p>
					<label for="asde-pp-cat"><?php esc_html_e( 'Category (of an expense)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-pp-cat" name="categoria">
						<?php foreach ( $categorias as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( asde_guardado( (string) $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-pp-concepto"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-pp-concepto" name="concepto" maxlength="160"></p>
				<p><label for="asde-pp-veces"><?php esc_html_e( 'How many?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-pp-veces" name="veces" min="1" max="<?php echo (int) Servicio::MAX_VECES; ?>" step="1" value="1"></p>
				<p><label for="asde-pp-importe"><?php esc_html_e( 'Amount of each (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-pp-importe" name="importe" min="0" step="0.01" value="0"></p>
				<p><label for="asde-pp-nota"><?php esc_html_e( 'Note (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-pp-nota" name="nota" maxlength="255"></p>
			</div>
			<p class="asde-help"><?php esc_html_e( 'Example: Excursion, how many? 6, amount of each 35 € → 210 €. Income has no category: enter its description (grant, product sales…).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Add budget item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<?php if ( $hay_previa && $vacio ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo wp_kses( $campos( 'copiar' ), asde_html_permitido() ); ?><p><button type="submit" class="button"><?php esc_html_e( 'Copy the items from the previous round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p></form>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="asde-card" data-asde-plegable="presupuesto-estado" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Budget status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( EstadosTable::BORRADOR === $estado['estado'] ) : ?>
		<?php if ( $editable ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo wp_kses( $campos( 'enviar' ), asde_html_permitido() ); ?><button type="submit" class="button"><?php esc_html_e( 'Send to Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $es_config && EstadosTable::APROBADO !== $estado['estado'] ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo wp_kses( $campos( 'aprobar' ), asde_html_permitido() ); ?><button type="submit" class="button"><?php esc_html_e( 'Approve', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
	<?php endif; ?>
	<?php if ( $es_config && $bloq ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo wp_kses( $campos( 'reabrir' ), asde_html_permitido() ); ?><label for="asde-pp-motivo"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label> <input type="text" id="asde-pp-motivo" name="motivo" maxlength="200" required> <button type="submit" class="button"><?php esc_html_e( 'Reopen', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
	<?php endif; ?>
</section>
