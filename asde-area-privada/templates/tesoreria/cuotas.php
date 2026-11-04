<?php
/**
 * Configuración de cuotas y plazos de una ronda.
 *
 * @var string $ronda
 * @var array  $cfg
 * @var bool   $editable
 * @var string $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$tramos = array_values( $cfg['cuotas']['anualidad'] );
$plazos = array_values( $cfg['plazos'] );
$ro     = $editable ? '' : ' disabled';
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Fees and instalments ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p><?php esc_html_e( 'What each family pays in the round. The members of the ticked sections (youth members) pay; scouters and volunteers do not pay.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( ! $editable ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'Read-only: the settings are changed by the Secretary\'s office, Coordination or Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
</section>

<form method="post" class="asde-form">
	<?php wp_nonce_field( 'asde_cuotas' ); ?>
	<input type="hidden" name="asde_action" value="guardar_cuotas">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">

	<section class="asde-card">
		<h3><?php esc_html_e( 'Amounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<div class="asde-grid-2">
			<p><label for="asde-c-insc"><?php esc_html_e( 'Registration per child (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-c-insc" name="cuotas[inscripcion]" step="0.01" min="0" value="<?php echo esc_attr( $cfg['cuotas']['inscripcion'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
			<p><label for="asde-c-seg"><?php esc_html_e( 'Insurance per child (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-c-seg" name="cuotas[seguro]" step="0.01" min="0" value="<?php echo esc_attr( $cfg['cuotas']['seguro'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
		</div>
		<fieldset>
			<legend><?php esc_html_e( 'Annual family fee by number of children', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<p class="asde-help"><?php esc_html_e( 'Total amount of the annual fee. From the last tier with an amount, the annual fee no longer increases (cap).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<div class="asde-tramos">
				<?php for ( $i = 0; $i < max( 5, count( $tramos ) + 1 ); $i++ ) : ?>
					<p>
						<label for="asde-c-anu-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d son', '%d sons', $i + 1 , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1 ) ); ?></label>
						<input type="number" id="asde-c-anu-<?php echo (int) $i; ?>" name="cuotas[anualidad][]" step="0.01" min="0" value="<?php echo isset( $tramos[ $i ] ) ? esc_attr( $tramos[ $i ] ) : ''; ?>" placeholder="<?php echo $i >= count( $tramos ) ? esc_attr( sprintf( /* translators: %d: number */ __( 'same as %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $tramos ) ) ) : ''; ?>"<?php echo esc_attr( $ro ); ?>>
					</p>
				<?php endfor; ?>
			</div>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Sections that pay a fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( Secciones::all() as $slug => $s ) : ?>
				<label class="asde-choice asde-choice--inline">
					<input type="checkbox" name="secciones_pagan[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $cfg['secciones_pagan'], true ) ); ?><?php echo esc_attr( $ro ); ?>>
					<span><?php echo wp_kses( Secciones::badge( $slug ), asde_html_permitido() ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( 'Result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<table class="asde-table asde-table--resumen" data-asde-sin-paginar="resumen por secciones o categorias (pocas filas fijas)">
			<thead><tr><th scope="col"><?php esc_html_e( 'Children', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Annual fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php for ( $h = 1; $h <= 5; $h++ ) : ?>
					<?php $c = Config::cuota( $cfg, $h ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Children', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $h; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $c['inscripcion'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $c['seguro'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Annual fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $c['anualidad'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( Calculadora::euros( $c['total'] ) ); ?></strong></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( 'Payment instalments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Each instalment is a percentage of the family\'s fee with its deadline. They must add up to 100 %. Leave the rows you don\'t use blank.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div class="asde-plazos">
			<?php for ( $i = 0; $i < max( 4, count( $plazos ) + 1 ); $i++ ) : ?>
				<?php $pl = $plazos[ $i ] ?? array( 'nombre' => '', 'fecha' => '', 'porcentaje' => '' ); ?>
				<div class="asde-grid-3">
					<p><label for="asde-pl-n-<?php echo (int) $i; ?>"><?php esc_html_e( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) ( $i + 1 ); ?></label><input type="text" id="asde-pl-n-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][nombre]" value="<?php echo esc_attr( $pl['nombre'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'e.g. First instalment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"<?php echo esc_attr( $ro ); ?>></p>
					<p><label for="asde-pl-f-<?php echo (int) $i; ?>"><?php esc_html_e( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="date" id="asde-pl-f-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][fecha]" value="<?php echo esc_attr( $pl['fecha'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
					<p><label for="asde-pl-p-<?php echo (int) $i; ?>"><?php esc_html_e( '% of the fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-pl-p-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][porcentaje]" step="0.01" min="0" max="100" value="<?php echo esc_attr( $pl['porcentaje'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
				</div>
			<?php endfor; ?>
		</div>
		<?php $suma = Config::suma_plazos( $cfg ); ?>
		<p class="asde-muted"><?php esc_html_e( 'Current sum:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( number_format_i18n( $suma, 2 ) ); ?> %</strong><?php echo abs( $suma - 100 ) > 0.01 ? ' <span class="asde-help asde-help--warning">(debería ser 100 %)</span>' : ''; ?></p>
	</section>

	<?php if ( $editable ) : ?>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save the round\'s fees', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></button></p>
	<?php endif; ?>
</form>
