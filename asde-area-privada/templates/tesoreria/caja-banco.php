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
	<h2 class="asde-card__title"><?php esc_html_e( 'Link with the bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<?php if ( $hecho ) : ?>
		<p><?php esc_html_e( 'Close this window to go back to the cash box: it now shows the update.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( ! $apunte ) : ?>
		<p><?php esc_html_e( 'Only a transfer with the bank is linked to a bank transaction.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( $apunte['banco_id'] ) : ?>
		<p><?php esc_html_e( 'This transfer is already linked to its bank transaction: remove the link first in the cash box.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<p><strong><?php echo esc_html( $fecha( $apunte['fecha'] ) . ' · ' . $importe( (float) $apunte['importe'] ) ); ?></strong><br><?php echo esc_html( LibroMayor::concepto_caja( $apunte ) ); ?></p>
		<p class="asde-muted"><?php esc_html_e( 'Choose the bank transaction that corresponds to this transfer: that way the general ledger does not count it as an expense or income. Those of the same amount come first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php if ( ! $candidatos ) : ?>
			<p><?php esc_html_e( 'No bank transaction fits (of the opposite sign and without another transfer). Import the statement first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $candidatos as $m ) : ?>
						<tr<?php echo $m['igual'] ? ' class="asde-banco-asociar__igual"' : ''; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : ( Norma43::conceptos()[ $m['concepto_comun'] ] ?? '—' ) ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe <?php echo $m['importe'] > 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( $importe( (float) $m['importe'] ) ); ?></td>
							<td data-label="">
								<form method="post" action="<?php echo esc_url( $url ); ?>">
									<?php wp_nonce_field( 'asde_caja' ); ?>
									<input type="hidden" name="asde_action" value="banco">
									<input type="hidden" name="caja" value="<?php echo esc_attr( $apunte['caja'] ); ?>">
									<input type="hidden" name="apunte" value="<?php echo (int) $apunte['id']; ?>">
									<input type="hidden" name="banco" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
	<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Cash box', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>
