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
	<h2><?php asde_e( 'Coste federativo ·' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p>
		<?php asde_e( 'Lo que el grupo paga a' ); ?> <?php echo esc_html( '' !== $org ? $org : asde_t( 'su organización federada' ) ); ?> <?php asde_e( 'por' ); ?> <strong><?php asde_e( 'todos' ); ?></strong> <?php asde_e( 'sus asociados activos (niños, jóvenes, scouters y voluntariado): cuota asociativa + seguro' ); ?><?php echo $fed['conceptos'] ? esc_html( asde_t( ' + otros conceptos' ) ) : ''; ?><?php asde_e( '. Cada ronda empieza con los importes de la organización; aquí se pueden ajustar para el grupo.' ); ?>
	</p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
</section>

<?php if ( $coste ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Cálculo con los socios activos del CRM (hoy)' ); ?></h3>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php asde_e( 'Seguro' ); ?> <?php echo (int) $fed['anio_primas']; ?></th><th scope="col"><?php asde_e( 'Asociados' ); ?></th><th scope="col"><?php asde_e( 'Prima' ); ?></th><th scope="col"><?php asde_e( 'Importe' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $categorias as $cat => $label ) : ?>
					<?php $c = $coste['categorias'][ $cat ]; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Categoría' ) ); ?>"><?php echo esc_html( $label ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Asociados' ) ); ?>"><?php echo (int) $c['n']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Prima' ) ); ?>"><?php echo esc_html( $n( $c['prima'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( $e( $c['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="asde-total"><th scope="row"><?php asde_e( 'Seguro según tabla' ); ?></th><td><?php echo (int) $coste['total_socios']; ?></td><td></td><td><?php echo esc_html( $e( $coste['seguro_tabla'] ) ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( $coste['conceptos'] ) : ?>
			<table class="asde-table asde-table--resumen">
				<thead><tr><th scope="col"><?php asde_e( 'Otros conceptos' ); ?></th><th scope="col"><?php asde_e( 'Se aplica a' ); ?></th><th scope="col"><?php asde_e( 'Importe' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $coste['conceptos'] as $c ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo esc_html( $c['nombre'] ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Se aplica a' ) ); ?>"><?php echo esc_html( 'grupo' === $c['aplica'] ? asde_t( 'Una vez por grupo' ) : sprintf( '%s × %d', $n( $c['unidad'] ), $c['n'] ) . ' (' . mb_strtolower( Organizacion::APLICA[ $c['aplica'] ] ?? '' ) . ')' ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( $e( $c['importe'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr class="asde-total"><th scope="row" colspan="2"><?php asde_e( 'Total de otros conceptos' ); ?></th><td><?php echo esc_html( $e( $coste['total_conceptos'] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>

		<table class="asde-table asde-table--resumen">
			<tbody>
				<tr><th scope="row"><?php asde_e( 'Cuota asociativa (' ); ?><?php echo esc_html( $n( (float) $fed['cuota_asociativa'] ) ); ?> × <?php echo (int) $coste['total_socios']; ?>)</th><td><?php echo esc_html( $e( $coste['cuota_asociativa'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Seguro según tabla de primas' ); ?></th><td><?php echo esc_html( $e( $coste['seguro_tabla'] ) ); ?></td></tr>
				<?php if ( $coste['conceptos'] ) : ?>
					<tr><th scope="row"><?php asde_e( 'Otros conceptos' ); ?></th><td><?php echo esc_html( $e( $coste['total_conceptos'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="asde-total"><th scope="row"><?php asde_e( 'Total (con tabla de primas)' ); ?></th><td><?php echo esc_html( $e( $coste['total_tabla'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Seguro promedio (' ); ?><?php echo esc_html( $n( (float) $fed['seguro_promedio'] ) ); ?> × <?php echo (int) $coste['total_socios']; ?>)</th><td><?php echo esc_html( $e( $coste['seguro_promedio'] ) ); ?></td></tr>
				<tr class="asde-total"><th scope="row"><?php asde_e( 'Total (con seguro promedio)' ); ?></th><td><?php echo esc_html( $e( $coste['total_promedio'] ) ); ?></td></tr>
				<?php if ( null !== $ingresos ) : ?>
					<tr><th scope="row"><?php asde_e( 'Cuotas esperadas de las familias (ronda' ); ?> <?php echo esc_html( $ronda ); ?>)</th><td><?php echo esc_html( $e( (float) $ingresos ) ); ?></td></tr>
					<tr class="asde-total"><th scope="row"><?php asde_e( 'Diferencia (cuotas − coste con tabla)' ); ?></th><td><?php echo esc_html( $e( (float) $ingresos - $coste['total_tabla'] ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php asde_e( 'Educandos según su edad hoy (corte en' ); ?> <?php echo (int) $fed['edad_corte']; ?> <?php asde_e( 'años). «Adultos» incluye a scouters y voluntariado de apoyo. Solo cuentan los socios con membresía activa.' ); ?></p>
	</section>
<?php endif; ?>

<form method="post" class="asde-form">
	<?php wp_nonce_field( 'asde_federacion' ); ?>
	<input type="hidden" name="asde_action" value="guardar_federacion">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
	<section class="asde-card">
		<h3><?php asde_e( 'Configuración' ); ?></h3>
		<?php if ( ! $editable ) : ?>
			<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Solo lectura: la configuración la cambian Secretaría, Coordinación o Tesorería.' ); ?></div>
		<?php endif; ?>
		<div class="asde-grid-3">
			<p><label for="asde-f-cuota"><?php asde_e( 'Cuota asociativa por asociado (€)' ); ?></label><input type="number" id="asde-f-cuota" name="federacion[cuota_asociativa]" step="0.01" min="0" value="<?php echo esc_attr( $fed['cuota_asociativa'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
			<p><label for="asde-f-medio"><?php asde_e( 'Seguro promedio por asociado (€)' ); ?></label><input type="number" id="asde-f-medio" name="federacion[seguro_promedio]" step="0.01" min="0" value="<?php echo esc_attr( $fed['seguro_promedio'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
			<p><label for="asde-f-anio"><?php asde_e( 'Año de las primas' ); ?></label><input type="number" id="asde-f-anio" name="federacion[anio_primas]" min="2000" max="2100" value="<?php echo esc_attr( $fed['anio_primas'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
		</div>
		<fieldset>
			<legend><?php asde_e( 'Primas del seguro por asociado' ); ?></legend>
			<p class="asde-grid-3">
				<span><label for="asde-f-corte"><?php asde_e( 'Edad de corte de los educandos (años)' ); ?></label><input type="number" id="asde-f-corte" name="federacion[edad_corte]" min="1" max="30" value="<?php echo esc_attr( $fed['edad_corte'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></span>
			</p>
			<div class="asde-grid-3">
				<?php foreach ( $categorias as $cat => $label ) : ?>
					<p><label for="asde-f-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $label ); ?> (€)</label><input type="number" id="asde-f-<?php echo esc_attr( $cat ); ?>" name="federacion[primas][<?php echo esc_attr( $cat ); ?>]" step="0.01" min="0" value="<?php echo esc_attr( $fed['primas'][ $cat ] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<fieldset>
			<legend><?php asde_e( 'Otros conceptos' ); ?></legend>
			<p class="asde-help"><?php asde_e( 'Cualquier otro importe que cobre la organización: por asociado, por educando, por adulto o fijo por grupo (p. ej. una cuota de actividades federales o una aportación del grupo).' ); ?></p>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla propia del plugin: cada valor se escapa dentro de ella.
			echo TemplateLoader::render(
				'comun/conceptos',
				array(
					'conceptos' => $fed['conceptos'],
					'campo'     => 'federacion[conceptos]',
					'editable'  => $editable,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</fieldset>
		<?php if ( $editable ) : ?>
			<p><button type="submit" class="button"><?php asde_e( 'Guardar coste federativo' ); ?></button></p>
		<?php endif; ?>
	</section>
</form>
