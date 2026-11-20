<?php
/**
 * Pagar un pedido de la tienda con un ingreso del banco, elegido desde el pedido (v0.64.18, petición del usuario: el pago de un
 * pedido se registra desde la tienda, desde Tesorería y desde el banco). Se abre en la ventana emergente desde la lista de pedidos
 * por cobrar de Tesorería; sin JavaScript, en su página. Los ingresos que llevan el nº del pedido o su importe salen primero.
 *
 * @var bool       $hecho  Ya se ha pagado (desde la ventana): solo el aviso
 * @var array|null $pedido El pedido por pagar, con 'a_cobrar' y 'comprador' (null si ya no está por pagar)
 * @var array[]    $movs   Ingresos por conciliar, con 'codigo' (lleva el nº del pedido) e 'igual' (mismo importe)
 * @var string     $q
 * @var string     $url
 * @var string     $volver Pantalla de la que se viene
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$volver = $volver ?? $url;
$fecha  = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : ' 12:00' ) ) );
?>
<?php if ( $hecho ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Order paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'Close this window to go back to the list: it now shows the update.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php elseif ( ! $pedido ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Pay this order with a bank deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'That order is already paid or cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php else : ?>
	<section class="asde-card asde-banco-asociar" data-asde-no-plegable>
		<h2 class="asde-card__title"><?php esc_html_e( 'Pay this order with a bank deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p>
			<strong><?php echo esc_html( PedidosTable::numero( $pedido ) . ' · ' . Calculadora::euros( $pedido['a_cobrar'] ) ); ?></strong><br>
			<?php echo esc_html( $pedido['comprador'] . ' — ' . Servicio::descripcion( $pedido['lineas'] ) ); ?>
		</p>
		<p class="asde-muted"><?php esc_html_e( 'Choose the deposit that pays this order: it is marked as paid with the date and amount of the deposit. The ones with the order number or the same amount come first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-en-ventana>
			<input type="hidden" name="pagar_pedido" value="<?php echo (int) $pedido['id']; ?>">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<label for="asde-ingreso-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="search" id="asde-ingreso-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Description, payer or reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
		<?php if ( ! $movs ) : ?>
			<p><?php esc_html_e( 'There is no deposit to reconcile with this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<table class="asde-table" data-asde-paginar>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $movs as $m ) : ?>
						<tr<?php echo $m['codigo'] || $m['igual'] ? ' class="asde-banco-asociar__igual"' : ''; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : ( Norma43::conceptos()[ $m['concepto_comun'] ] ?? '—' ) ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe">+<?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
							<td data-label="">
								<form method="post" action="<?php echo esc_url( $url ); ?>"<?php echo $m['igual'] ? '' : ' data-asde-confirmar="' . esc_attr( __( 'The amount of the deposit is not the amount of the order. Mark the order as paid with this deposit anyway?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '"'; ?>>
									<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
									<input type="hidden" name="asde_action" value="pagar_pedido">
									<input type="hidden" name="desde" value="pedido">
									<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
									<input type="hidden" name="pedido" value="<?php echo (int) $pedido['id']; ?>">
									<?php if ( ! $m['igual'] ) : // El servidor exige la confirmación del importe distinto (v0.64.13). ?>
										<input type="hidden" name="importe_distinto" value="1">
									<?php endif; ?>
									<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Use this one', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php endif; ?>
