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
	<h2><?php asde_e( 'Presupuestos ·' ); ?> <?php echo esc_html( $ronda ); ?></h2>
	<p><?php asde_e( 'De la cuota anual de las familias se descuenta el coste federativo (ese dinero ya está comprometido). Del resto, un porcentaje va a las arcas del grupo y lo demás al presupuesto de la sección de cada asociado. Se calcula con las cuotas previstas de la ronda y los socios activos del CRM.' ); ?></p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
</section>

<?php if ( $reparto ) : ?>
	<?php $t = $reparto['total']; $g = $reparto['grupo']; ?>
	<section class="asde-card" data-asde-plegable="presupuestos-resumen" data-asde-inicio="abierto">
		<h3><?php asde_e( 'Cuotas, coste federativo y lo que queda' ); ?></h3>
		<table class="asde-table asde-table--resumen">
			<tbody>
				<tr><th scope="row"><?php asde_e( 'Cuotas previstas de las familias' ); ?></th><td><?php echo esc_html( $e( $t['cuota'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Coste federativo (todos los asociados)' ); ?></th><td>− <?php echo esc_html( $e( $t['coste_fed'] ) ); ?></td></tr>
				<tr class="asde-total"><th scope="row"><?php asde_e( 'Queda para repartir' ); ?></th><td><?php echo esc_html( $e( $t['bolsa'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Cobrado hasta hoy (informativo)' ); ?></th><td><?php echo esc_html( $e( $t['cobrado'] ) ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( $t['negativos'] > 0 ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( asde_t( 'Hay %d educandos con una cuota inferior a su coste federativo (becas o ajustes): aportan un importe negativo.' ), (int) $t['negativos'] ) ); ?></div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_presupuestos' ); ?>
	<input type="hidden" name="asde_action" value="guardar_presupuestos">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">

	<section class="asde-card" data-asde-plegable="presupuestos-secciones" data-asde-inicio="abierto">
		<h3><?php asde_e( 'Reparto por sección' ); ?></h3>
		<p>
			<label for="asde-p-pct"><?php asde_e( '% para las arcas del grupo (por defecto)' ); ?></label>
			<input type="number" id="asde-p-pct" name="presupuesto[pct_grupo]" step="0.01" min="0" max="100" value="<?php echo esc_attr( $pct( (float) $pre['pct_grupo'] ) ); ?>">
		</p>
		<table class="asde-table asde-table--resumen">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Sección' ); ?></th>
					<th scope="col"><?php asde_e( 'Educandos' ); ?></th>
					<th scope="col"><?php asde_e( 'Cuota' ); ?></th>
					<th scope="col"><?php asde_e( 'Coste federativo' ); ?></th>
					<th scope="col"><?php asde_e( 'Neto' ); ?></th>
					<th scope="col"><?php asde_e( '% al grupo' ); ?></th>
					<th scope="col"><?php asde_e( 'Al grupo' ); ?></th>
					<th scope="col"><?php asde_e( 'A la sección' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
					<?php $s = $reparto['secciones'][ $slug ] ?? null; ?>
					<tr>
						<th scope="row" data-label="<?php echo esc_attr( asde_a( 'Sección' ) ); ?>"><?php echo esc_html( $sec[ $slug ]['nombre'] ); ?></th>
						<td data-label="<?php echo esc_attr( asde_a( 'Educandos' ) ); ?>"><?php echo $s ? (int) $s['educandos'] : '–'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cuota' ) ); ?>"><?php echo $s ? esc_html( $e( $s['cuota'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Coste federativo' ) ); ?>"><?php echo $s ? esc_html( $e( $s['coste_fed'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Neto' ) ); ?>"><?php echo $s ? esc_html( $e( $s['neto'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( '% al grupo' ) ); ?>">
							<input type="number" name="presupuesto[pct_seccion][<?php echo esc_attr( $slug ); ?>]" step="0.01" min="0" max="100" placeholder="<?php echo esc_attr( $pct( (float) $pre['pct_grupo'] ) ); ?>" value="<?php echo esc_attr( isset( $pre['pct_seccion'][ $slug ] ) ? $pct( (float) $pre['pct_seccion'][ $slug ] ) : '' ); ?>" aria-label="<?php echo esc_attr( sprintf( asde_a( '%% al grupo de %s' ), $sec[ $slug ]['nombre'] ) ); ?>">
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Al grupo' ) ); ?>"><?php echo $s ? esc_html( $e( $s['a_grupo'] ) ) : '–'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'A la sección' ) ); ?>"><strong><?php echo $s ? esc_html( $e( $s['a_seccion'] ) ) : '–'; ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-help"><?php asde_e( 'Deja el porcentaje de una sección vacío para que use el del grupo.' ); ?></p>
	</section>

	<?php if ( $reparto ) : ?>
		<section class="asde-card" data-asde-plegable="presupuestos-grupo" data-asde-inicio="abierto">
			<h3><?php asde_e( 'Arcas del grupo' ); ?></h3>
			<table class="asde-table asde-table--resumen">
				<tbody>
					<tr><th scope="row"><?php asde_e( 'Parte de las cuotas de los educandos' ); ?></th><td><?php echo esc_html( $e( $g['a_grupo'] ) ); ?></td></tr>
					<tr><th scope="row"><?php asde_e( 'Coste federativo de scouters y voluntariado' ); ?></th><td>− <?php echo esc_html( $e( $g['adultos'] ) ); ?></td></tr>
					<?php if ( $g['sin_cuota'] > 0 ) : ?>
						<tr><th scope="row"><?php asde_e( 'Coste federativo de educandos sin cuota' ); ?></th><td>− <?php echo esc_html( $e( $g['sin_cuota'] ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $g['fijos'] > 0 ) : ?>
						<tr><th scope="row"><?php asde_e( 'Conceptos fijos por grupo' ); ?></th><td>− <?php echo esc_html( $e( $g['fijos'] ) ); ?></td></tr>
					<?php endif; ?>
					<tr class="asde-total"><th scope="row"><?php asde_e( 'Disponible para el grupo' ); ?></th><td><?php echo esc_html( $e( $g['disponible'] ) ); ?></td></tr>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<section class="asde-card" data-asde-plegable="presupuestos-proyectos" data-asde-inicio="cerrado">
		<h3><?php asde_e( 'Proyecto de recaudación de cada sección' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Cada sección tiene un proyecto con su objetivo de dinero a recaudar; este es el nombre que se usará en su presupuesto.' ); ?></p>
		<div class="asde-grid-3">
			<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
				<p>
					<label for="asde-p-proy-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $sec[ $slug ]['nombre'] ); ?></label>
					<input type="text" id="asde-p-proy-<?php echo esc_attr( $slug ); ?>" name="presupuesto[proyectos][<?php echo esc_attr( $slug ); ?>]" maxlength="80" value="<?php echo esc_attr( $pre['proyectos'][ $slug ] ); ?>">
				</p>
			<?php endforeach; ?>
		</div>
	</section>

	<p><button type="submit" class="button"><?php asde_e( 'Guardar el reparto' ); ?></button></p>
</form>
