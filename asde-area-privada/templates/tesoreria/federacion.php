<?php
/**
 * Coste que paga el grupo a su organización federada: cuota asociativa, seguro
 * (tabla de primas o promedio) y otros conceptos.
 *
 * @var string     $ronda
 * @var array      $cfg
 * @var array|null $coste
 * @var float|null $ingresos Cuotas esperadas de las familias en la ronda
 * @var string     $error
 * @var bool       $editable
 * @var string     $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$fed        = $cfg['federacion'];
$categorias = Config::categorias_seguro( $cfg );
$ro         = $editable ? '' : ' disabled';
$e          = static fn( float $v ) => Calculadora::euros( $v );
$n          = static fn( float $v ) => number_format_i18n( $v, 2 ) . ' €';
$org        = Organizacion::nombre();
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Federation cost ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p>
		<?php esc_html_e( 'What the group pays to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( '' !== $org ? $org : __( 'their federated organisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?> <?php esc_html_e( 'for', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'all', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'their active members (children, young people, scouters and volunteers): association fee + insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $fed['conceptos'] ? esc_html( __( ' + other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?><?php esc_html_e( '. Each round starts with the organisation\'s amounts; they can be adjusted here for the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
</section>

<?php if ( $coste ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Calculation with the CRM\'s active members (today)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php esc_html_e( 'Insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $fed['anio_primas']; ?></th><th scope="col"><?php esc_html_e( 'Associates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Premium', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $categorias as $cat => $label ) : ?>
					<?php $c = $coste['categorias'][ $cat ]; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $label ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Associates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $c['n']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Premium', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $n( $c['prima'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $c['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Insurance by table', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo (int) $coste['total_socios']; ?></td><td></td><td><?php echo esc_html( $e( $coste['seguro_tabla'] ) ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( $coste['conceptos'] ) : ?>
			<table class="asde-table asde-table--resumen">
				<thead><tr><th scope="col"><?php esc_html_e( 'Other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Applies to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $coste['conceptos'] as $c ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $c['nombre'] ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Applies to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( 'grupo' === $c['aplica'] ? __( 'Once per group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( '%s × %d', $n( $c['unidad'] ), $c['n'] ) . ' (' . mb_strtolower( Organizacion::APLICA[ $c['aplica'] ] ?? '' ) . ')' ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e( $c['importe'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr class="asde-total"><th scope="row" colspan="2"><?php esc_html_e( 'Total of other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $coste['total_conceptos'] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>

		<table class="asde-table asde-table--resumen">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Association fee (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( $n( (float) $fed['cuota_asociativa'] ) ); ?> × <?php echo (int) $coste['total_socios']; ?>)</th><td><?php echo esc_html( $e( $coste['cuota_asociativa'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Insurance by premium table', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $coste['seguro_tabla'] ) ); ?></td></tr>
				<?php if ( $coste['conceptos'] ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $coste['total_conceptos'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Total (with premium table)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $coste['total_tabla'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Average insurance (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( $n( (float) $fed['seguro_promedio'] ) ); ?> × <?php echo (int) $coste['total_socios']; ?>)</th><td><?php echo esc_html( $e( $coste['seguro_promedio'] ) ); ?></td></tr>
				<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Total (with average insurance)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( $coste['total_promedio'] ) ); ?></td></tr>
				<?php if ( null !== $ingresos ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Expected fees from families (round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?>)</th><td><?php echo esc_html( $e( (float) $ingresos ) ); ?></td></tr>
					<tr class="asde-total"><th scope="row"><?php esc_html_e( 'Difference (fees − cost by table)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $e( (float) $ingresos - $coste['total_tabla'] ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php esc_html_e( 'Youth members by their age today (cut-off at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $fed['edad_corte']; ?> <?php esc_html_e( 'years). "Adults" includes support scouters and volunteers. Only members with an active membership count.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>
<?php endif; ?>

<form method="post" class="asde-form">
	<?php wp_nonce_field( 'asde_federacion' ); ?>
	<input type="hidden" name="asde_action" value="guardar_federacion">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
	<section class="asde-card">
		<h3><?php esc_html_e( 'Settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $editable ) : ?>
			<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'Read-only: the settings are changed by the Secretary\'s office, Coordination or Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<div class="asde-grid-3">
			<p><label for="asde-f-cuota"><?php esc_html_e( 'Association fee per member (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-f-cuota" name="federacion[cuota_asociativa]" step="0.01" min="0" value="<?php echo esc_attr( $fed['cuota_asociativa'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
			<p><label for="asde-f-medio"><?php esc_html_e( 'Average insurance per member (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-f-medio" name="federacion[seguro_promedio]" step="0.01" min="0" value="<?php echo esc_attr( $fed['seguro_promedio'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
			<p><label for="asde-f-anio"><?php esc_html_e( 'Year of the premiums', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-f-anio" name="federacion[anio_primas]" min="2000" max="2100" value="<?php echo esc_attr( $fed['anio_primas'] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
		</div>
		<fieldset>
			<legend><?php esc_html_e( 'Insurance premiums per member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<p class="asde-grid-3">
				<span><label for="asde-f-corte"><?php esc_html_e( 'Cut-off age for youth members (years)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-f-corte" name="federacion[edad_corte]" min="1" max="30" value="<?php echo esc_attr( $fed['edad_corte'] ); ?>"<?php echo esc_attr( $ro ); ?>></span>
			</p>
			<div class="asde-grid-3">
				<?php foreach ( $categorias as $cat => $label ) : ?>
					<p><label for="asde-f-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $label ); ?> (€)</label><input type="number" id="asde-f-<?php echo esc_attr( $cat ); ?>" name="federacion[primas][<?php echo esc_attr( $cat ); ?>]" step="0.01" min="0" value="<?php echo esc_attr( $fed['primas'][ $cat ] ); ?>"<?php echo esc_attr( $ro ); ?>></p>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Other items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<p class="asde-help"><?php esc_html_e( 'Any other amount the organisation charges: per member, per youth member, per adult or fixed per group (e.g. a fee for federal activities or a group contribution).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla propia del plugin: cada valor se escapa dentro de ella.
			echo wp_kses(
				TemplateLoader::render(
				'comun/conceptos',
				array(
					'conceptos' => $fed['conceptos'],
					'campo'     => 'federacion[conceptos]',
					'editable'  => $editable,
				)
				),
				asde_html_permitido()
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</fieldset>
		<?php if ( $editable ) : ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save federation cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		<?php endif; ?>
	</section>
</form>
