<?php
/**
 * Ventas e informes de la tienda (v0.40, doc 48, fase 5): por producto, por forma de pago y pendientes de cobro.
 *
 * @var string                                                $desde       Y-m-d o ''
 * @var string                                                $hasta       Y-m-d o ''
 * @var int                                                   $n           Pedidos del periodo
 * @var array[]                                               $productos   Ventas por producto y variante
 * @var array<string, array>                                  $formas      Ventas por forma de pago
 * @var array[]                                               $pendientes  Pedidos sin pagar, con 'dias'
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var string                                                $url
 * @var array<string, string>                                 $excel       Enlaces de descarga
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' UTC' ) );
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Sales and reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Back to management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'What was sold in the shop according to the orders, not counting cancelled ones. Choose the period by the date the order was placed; without dates all are shown.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="informe" value="1">
		<label for="asde-inf-desde"><?php esc_html_e( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="date" id="asde-inf-desde" name="desde" value="<?php echo esc_attr( $desde ); ?>">
		<label for="asde-inf-hasta"><?php esc_html_e( 'Until', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="date" id="asde-inf-hasta" name="hasta" value="<?php echo esc_attr( $hasta ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
	<p><strong><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d order in this period.', '%d orders in this period.', $n , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ); ?></strong></p>
</section>

<section class="asde-card" data-asde-plegable="tienda-informe-productos" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Sales by product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['productos'] ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<?php if ( ! $productos ) : ?>
		<p><?php esc_html_e( 'There are no sales in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Units', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Of which, paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $productos as $d ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $d['producto'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( '' !== $d['variante'] ? esc_html( $d['variante'] ) : '<span class="asde-muted">—</span>', asde_html_permitido() ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Units', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $d['unidades']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Of which, paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['pagado'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row" colspan="2"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td><?php echo (int) array_sum( array_column( $productos, 'unidades' ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $productos, 'importe' ) ) ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $productos, 'pagado' ) ) ) ); ?></td>
				</tr>
			</tfoot>
		</table>
		<p class="asde-help"><?php esc_html_e( 'With the prices of each order. What is "to be confirmed" (price not fixed) adds no amount.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="tienda-informe-formas" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Sales by payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['formas'] ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<?php if ( ! $formas ) : ?>
		<p><?php esc_html_e( 'There are no sales in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To collect', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $formas as $forma => $d ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( '' !== $forma ? ( PedidosTable::formas()[ $forma ] ) : __( 'Not stated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $d['pedidos']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['cobrado'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'To collect', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['pendiente'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td><?php echo (int) array_sum( array_column( $formas, 'pedidos' ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $formas, 'total' ) ) ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $formas, 'cobrado' ) ) ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $formas, 'pendiente' ) ) ) ); ?></td>
				</tr>
			</tfoot>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="tienda-informe-pendientes" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Pending collections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['pendientes'] ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<?php if ( ! $pendientes ) : ?>
		<p><?php esc_html_e( 'There are no unpaid orders in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Days waiting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pendientes as $p ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'pedido', $p['id'], $url ) ); ?>" data-asde-modal><?php echo esc_html( PedidosTable::numero( $p ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Servicio::comprador( $p, $personas[ $p['socio'] ] ?? null ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( PedidosTable::formas()[ $p['forma_pago'] ] ?? 'Sin indicar' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Days waiting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $p['dias']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( Servicio::importe_a_cobrar( $p ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
