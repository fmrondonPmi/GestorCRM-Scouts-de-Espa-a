<?php
/**
 * Configuración de cuotas y plazos de una ronda.
 *
 * @var string $ronda
 * @var array  $cfg
 * @var bool   $editable
 * @var string $url
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$tramos = array_values( $cfg['cuotas']['anualidad'] );
$plazos = array_values( $cfg['plazos'] );
$ro     = $editable ? '' : ' disabled';
?>
<section class="asde-card">
	<h2><?php asde_e( 'Cuotas y plazos ·' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p><?php asde_e( 'Lo que paga cada familia en la ronda. Pagan los socios de las secciones marcadas (educandos); los scouters y el voluntariado no pagan.' ); ?></p>
	<?php if ( ! $editable ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Solo lectura: la configuración la cambian Secretaría, Coordinación o Tesorería.' ); ?></div>
	<?php endif; ?>
</section>

<form method="post" class="asde-form">
	<?php wp_nonce_field( 'asde_cuotas' ); ?>
	<input type="hidden" name="asde_action" value="guardar_cuotas">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">

	<section class="asde-card">
		<h3><?php asde_e( 'Importes' ); ?></h3>
		<div class="asde-grid-2">
			<p><label for="asde-c-insc"><?php asde_e( 'Inscripción por hijo (€)' ); ?></label><input type="number" id="asde-c-insc" name="cuotas[inscripcion]" step="0.01" min="0" value="<?php echo esc_attr( $cfg['cuotas']['inscripcion'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
			<p><label for="asde-c-seg"><?php asde_e( 'Seguro por hijo (€)' ); ?></label><input type="number" id="asde-c-seg" name="cuotas[seguro]" step="0.01" min="0" value="<?php echo esc_attr( $cfg['cuotas']['seguro'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
		</div>
		<fieldset>
			<legend><?php asde_e( 'Anualidad familiar según nº de hijos' ); ?></legend>
			<p class="asde-help"><?php asde_e( 'Importe total de la anualidad. A partir del último tramo con importe, la anualidad ya no aumenta (tope).' ); ?></p>
			<div class="asde-tramos">
				<?php for ( $i = 0; $i < max( 5, count( $tramos ) + 1 ); $i++ ) : ?>
					<p>
						<label for="asde-c-anu-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( asde_n( '%d hijo', '%d hijos', $i + 1 ), $i + 1 ) ); ?></label>
						<input type="number" id="asde-c-anu-<?php echo (int) $i; ?>" name="cuotas[anualidad][]" step="0.01" min="0" value="<?php echo isset( $tramos[ $i ] ) ? esc_attr( $tramos[ $i ] ) : ''; ?>" placeholder="<?php echo $i >= count( $tramos ) ? esc_attr( sprintf( asde_t( 'igual que %d' ), count( $tramos ) ) ) : ''; ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					</p>
				<?php endfor; ?>
			</div>
		</fieldset>
		<fieldset>
			<legend><?php asde_e( 'Secciones que pagan cuota' ); ?></legend>
			<?php foreach ( Secciones::all() as $slug => $s ) : ?>
				<label class="asde-choice asde-choice--inline">
					<input type="checkbox" name="secciones_pagan[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $cfg['secciones_pagan'], true ) ); ?><?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<span><?php echo Secciones::badge( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( 'Resultado' ); ?></h3>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php asde_e( 'Hijos' ); ?></th><th scope="col"><?php asde_e( 'Inscripción' ); ?></th><th scope="col"><?php asde_e( 'Seguro' ); ?></th><th scope="col"><?php asde_e( 'Anualidad' ); ?></th><th scope="col"><?php asde_e( 'Total' ); ?></th></tr></thead>
			<tbody>
				<?php for ( $h = 1; $h <= 5; $h++ ) : ?>
					<?php $c = Config::cuota( $cfg, $h ); ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Hijos' ); ?>"><?php echo (int) $h; ?></td>
						<td data-label="<?php echo asde_a( 'Inscripción' ); ?>"><?php echo esc_html( Calculadora::euros( $c['inscripcion'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Seguro' ); ?>"><?php echo esc_html( Calculadora::euros( $c['seguro'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Anualidad' ); ?>"><?php echo esc_html( Calculadora::euros( $c['anualidad'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Total' ); ?>"><strong><?php echo esc_html( Calculadora::euros( $c['total'] ) ); ?></strong></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( 'Plazos de pago' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Cada plazo es un porcentaje de la cuota de la familia con su fecha límite. Deben sumar 100 %. Deja en blanco las filas que no uses.' ); ?></p>
		<div class="asde-plazos">
			<?php for ( $i = 0; $i < max( 4, count( $plazos ) + 1 ); $i++ ) : ?>
				<?php $pl = $plazos[ $i ] ?? array( 'nombre' => '', 'fecha' => '', 'porcentaje' => '' ); ?>
				<div class="asde-grid-3">
					<p><label for="asde-pl-n-<?php echo (int) $i; ?>"><?php asde_e( 'Plazo' ); ?> <?php echo (int) ( $i + 1 ); ?></label><input type="text" id="asde-pl-n-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][nombre]" value="<?php echo esc_attr( $pl['nombre'] ); ?>" placeholder="<?php echo asde_a( 'p. ej. Primer plazo' ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
					<p><label for="asde-pl-f-<?php echo (int) $i; ?>"><?php asde_e( 'Fecha límite' ); ?></label><input type="date" id="asde-pl-f-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][fecha]" value="<?php echo esc_attr( $pl['fecha'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
					<p><label for="asde-pl-p-<?php echo (int) $i; ?>"><?php asde_e( '% de la cuota' ); ?></label><input type="number" id="asde-pl-p-<?php echo (int) $i; ?>" name="plazos[<?php echo (int) $i; ?>][porcentaje]" step="0.01" min="0" max="100" value="<?php echo esc_attr( $pl['porcentaje'] ); ?>"<?php echo $ro; // phpcs:ignore WordPress.Security.EscapeOutput ?>></p>
				</div>
			<?php endfor; ?>
		</div>
		<?php $suma = Config::suma_plazos( $cfg ); ?>
		<p class="asde-muted"><?php asde_e( 'Suma actual:' ); ?> <strong><?php echo esc_html( number_format_i18n( $suma, 2 ) ); ?> %</strong><?php echo abs( $suma - 100 ) > 0.01 ? ' <span class="asde-help asde-help--warning">(debería ser 100 %)</span>' : ''; ?></p>
	</section>

	<?php if ( $editable ) : ?>
		<p><button type="submit" class="button"><?php asde_e( 'Guardar cuotas de la ronda' ); ?> <?php echo esc_html( $ronda ); ?></button></p>
	<?php endif; ?>
</form>
