<?php
/**
 * Tesorería · movimientos de una familia, solo para verlos (v0.62.1). Se abre en una ventana
 * desde el nº de familia de «Familias»; sin JavaScript, en su página.
 *
 * @var array{id:int, numero:string, miembros:string[]} $familia
 * @var string  $ronda
 * @var array[] $movimientos Vigentes (sin anulados), de todas las rondas, más recientes primero
 * @var array[] $otros       HistorialPagos::otros() sin lo eliminado: pagos de eventos, uniformes y banco
 * @var string  $url_ficha   La ficha de pagos de la familia en Tesorería (registrar, eliminar…)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

$lineas = array();
foreach ( $movimientos as $m ) {
	$lineas[] = array(
		'fecha'   => (string) $m['fecha'],
		'que'     => MovimientosTable::PAGO === $m['tipo'] ? __( 'Payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'ronda'   => (string) $m['ronda'],
		'importe' => (float) $m['importe'],
		'detalle' => trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ),
	);
}
foreach ( $otros as $o ) {
	$lineas[] = array(
		'fecha'   => substr( (string) $o['cuando'], 0, 10 ),
		'que'     => (string) $o['que'],
		'ronda'   => '',
		'importe' => null,
		'detalle' => (string) $o['detalle'],
	);
}
usort( $lineas, static fn( $a, $b ) => strcmp( $b['fecha'], $a['fecha'] ) );
?>
<section class="asde-card asde-familia-movimientos">
	<h2 class="asde-card__title">
		<?php /* translators: %s: family number */ echo esc_html( sprintf( __( 'Family %s: transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $familia['numero'] ) ); ?>
	</h2>
	<?php if ( ! $lineas ) : ?>
		<p><?php esc_html_e( 'There are no transactions yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $l['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $l['que'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $l['ronda'] ? esc_html( $l['ronda'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo null !== $l['importe'] ? esc_html( Calculadora::euros( $l['importe'] ) ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $l['detalle'] ? esc_html( $l['detalle'] ) : '<span class="asde-muted">—</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="asde-help"><a href="<?php echo esc_url( $url_ficha ); ?>"><?php esc_html_e( 'Open the family\'s payment file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>
