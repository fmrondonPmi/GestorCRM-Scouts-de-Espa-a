<?php
/**
 * Pagar un pedido de la tienda con un ingreso del banco, elegido a mano (v0.64.8, petición del usuario: «en el banco no hay
 * manera de asociar un ingreso a un pedido»). Se abre en la ventana emergente desde el ingreso por conciliar; sin JavaScript,
 * en su página. El pedido queda pagado con la fecha y el importe del ingreso; deshacer el pago devuelve el ingreso a «por conciliar».
 *
 * @var bool       $hecho   Ya se ha asociado (desde la ventana): solo el aviso y cerrar
 * @var array|null $mov     El ingreso elegido (null si ya no está por conciliar)
 * @var array[]    $pedidos Pedidos sin pagar (con 'a_cobrar' y 'comprador')
 * @var string     $q
 * @var string     $url
 * @var string     $volver  Listado del que se viene
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : ' 12:00' ) ) );
?>
<?php if ( $hecho ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Order paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'Close this window to go back to the list: it now shows the update.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php elseif ( ! $mov ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Pay a shop order with this deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'That income is already reviewed or does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php else : ?>
	<section class="asde-card asde-banco-asociar" data-asde-no-plegable>
		<h2 class="asde-card__title"><?php esc_html_e( 'Pay a shop order with this deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p>
			<strong><?php echo esc_html( $fecha( $mov['fecha'] ) . ' · +' . Calculadora::euros( (float) $mov['importe'] ) ); ?></strong><br>
			<?php echo esc_html( trim( $mov['ordenante'] . ' ' . ( '' !== $mov['concepto'] ? $mov['concepto'] : ( Norma43::conceptos()[ $mov['concepto_comun'] ] ?? '—' ) ) ) ); ?>
		</p>
		<p class="asde-muted"><?php esc_html_e( 'Choose the order this deposit pays: it is marked as paid with the date and amount of the deposit. If the payment is undone later, the deposit goes back to being pending.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-en-ventana>
			<input type="hidden" name="cobrar_pedido" value="<?php echo (int) $mov['id']; ?>">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<label for="asde-pedido-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="search" id="asde-pedido-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Order number or buyer', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
		<?php if ( ! $pedidos ) : ?>
			<p><?php esc_html_e( 'There is no unpaid order with this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<table class="asde-table" data-asde-paginar>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pedidos as $p ) : ?>
						<?php $igual = abs( (float) $mov['importe'] - $p['a_cobrar'] ) < 0.005; ?>
						<tr<?php echo $igual ? ' class="asde-banco-asociar__igual"' : ''; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( PedidosTable::numero( $p ) ); ?></strong><br><span class="asde-muted"><?php echo esc_html( $p['comprador'] ); ?></span></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe"><?php echo esc_html( Calculadora::euros( $p['a_cobrar'] ) ); ?></td>
							<td data-label="">
								<form method="post" action="<?php echo esc_url( $url ); ?>"<?php echo $igual ? '' : ' data-asde-confirmar="' . esc_attr( __( 'The amount of the deposit is not the amount of the order. Mark the order as paid with this deposit anyway?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '"'; ?>>
									<?php wp_nonce_field( 'asde_banco_mov_' . (int) $mov['id'] ); ?>
									<input type="hidden" name="asde_action" value="pagar_pedido">
									<input type="hidden" name="mov" value="<?php echo (int) $mov['id']; ?>">
									<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
									<?php if ( ! $igual ) : // v0.64.13: quien pulsa ya ha confirmado el importe distinto (el servidor lo exige). ?>
										<input type="hidden" name="importe_distinto" value="1">
									<?php endif; ?>
									<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Pay this one', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php endif; ?>
