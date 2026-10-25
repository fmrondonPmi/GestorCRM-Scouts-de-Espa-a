<?php
/**
 * Asociar un traspaso de la caja con el banco a su movimiento del banco (v0.59, P-219). Se abre en la ventana
 * emergente desde la caja; sin JavaScript, en su página. Primero salen los del mismo importe.
 *
 * @var array|null $apunte     El traspaso (null si no existe o no es con el banco)
 * @var array[]    $candidatos Movimientos del banco de signo contrario sin otro traspaso (con 'igual')
 * @var string     $url
 * @var string     $volver
 * @var bool       $hecho      Ya se ha asociado (desde la ventana)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha   = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$importe = static fn( float $v ) => ( $v > 0 ? '+' : '' ) . Calculadora::euros( $v );
?>
<section class="asde-card asde-banco-asociar" data-asde-no-plegable>
	<h2 class="asde-card__title"><?php asde_e( 'Asociar con el banco' ); ?></h2>
	<?php if ( $hecho ) : ?>
		<p><?php asde_e( 'Cierra esta ventana para volver a la caja: ya sale actualizada.' ); ?></p>
	<?php elseif ( ! $apunte ) : ?>
		<p><?php asde_e( 'Solo un traspaso con el banco se asocia con un movimiento del banco.' ); ?></p>
	<?php elseif ( $apunte['banco_id'] ) : ?>
		<p><?php asde_e( 'Este traspaso ya está asociado con su movimiento del banco: quita antes la asociación en la caja.' ); ?></p>
	<?php else : ?>
		<p><strong><?php echo esc_html( $fecha( $apunte['fecha'] ) . ' · ' . $importe( (float) $apunte['importe'] ) ); ?></strong><br><?php echo esc_html( LibroMayor::concepto_caja( $apunte ) ); ?></p>
		<p class="asde-muted"><?php asde_e( 'Elige el movimiento del banco que corresponde a este traspaso: así el libro mayor no lo cuenta como gasto ni como ingreso. Primero salen los del mismo importe.' ); ?></p>
		<?php if ( ! $candidatos ) : ?>
			<p><?php asde_e( 'No hay ningún movimiento del banco que encaje (de signo contrario y sin otro traspaso). Importa antes el extracto.' ); ?></p>
		<?php else : ?>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
						<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
						<th scope="col"><?php asde_e( 'Importe' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $candidatos as $m ) : ?>
						<tr<?php echo $m['igual'] ? ' class="asde-banco-asociar__igual"' : ''; ?>>
							<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo esc_html( trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $m['importe'] > 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( $importe( (float) $m['importe'] ) ); ?></td>
							<td data-label="">
								<form method="post" action="<?php echo esc_url( $url ); ?>">
									<?php wp_nonce_field( 'asde_caja' ); ?>
									<input type="hidden" name="asde_action" value="banco">
									<input type="hidden" name="caja" value="<?php echo esc_attr( $apunte['caja'] ); ?>">
									<input type="hidden" name="apunte" value="<?php echo (int) $apunte['id']; ?>">
									<input type="hidden" name="banco" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="button"><?php asde_e( 'Asociar' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
	<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Caja' ); ?></a></p>
</section>
