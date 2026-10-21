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
		<h2 class="asde-card__title"><?php asde_e( 'Ventas e informes' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'Volver a la gestión' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Lo vendido en la tienda según los pedidos, sin contar los cancelados. Elige el periodo por la fecha en que se hizo el pedido; sin fechas salen todos.' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="informe" value="1">
		<label for="asde-inf-desde"><?php asde_e( 'Desde' ); ?></label>
		<input type="date" id="asde-inf-desde" name="desde" value="<?php echo esc_attr( $desde ); ?>">
		<label for="asde-inf-hasta"><?php asde_e( 'Hasta' ); ?></label>
		<input type="date" id="asde-inf-hasta" name="hasta" value="<?php echo esc_attr( $hasta ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>
	<p><strong><?php echo esc_html( sprintf( asde_n( '%d pedido en este periodo.', '%d pedidos en este periodo.', $n ), $n ) ); ?></strong></p>
</section>

<section class="asde-card" data-asde-plegable="tienda-informe-productos" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Ventas por producto' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['productos'] ); ?>" data-asde-descarga><?php asde_e( 'Descargar Excel' ); ?></a></span>
	</header>
	<?php if ( ! $productos ) : ?>
		<p><?php asde_e( 'No hay ventas en este periodo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Producto' ); ?></th>
					<th scope="col"><?php asde_e( 'Variante' ); ?></th>
					<th scope="col"><?php asde_e( 'Unidades' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
					<th scope="col"><?php asde_e( 'De ellos, pagado' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $productos as $d ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Producto' ) ); ?>"><?php echo esc_html( $d['producto'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Variante' ) ); ?>"><?php echo '' !== $d['variante'] ? esc_html( $d['variante'] ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Unidades' ) ); ?>"><?php echo (int) $d['unidades']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'De ellos, pagado' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['pagado'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row" colspan="2"><?php asde_e( 'Total' ); ?></th>
					<td><?php echo (int) array_sum( array_column( $productos, 'unidades' ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $productos, 'importe' ) ) ) ); ?></td>
					<td><?php echo esc_html( Calculadora::euros( array_sum( array_column( $productos, 'pagado' ) ) ) ); ?></td>
				</tr>
			</tfoot>
		</table>
		<p class="asde-help"><?php asde_e( 'Con los precios de cada pedido. Lo que está «por confirmar» (precio sin fijar) no suma importe.' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="tienda-informe-formas" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Ventas por forma de pago' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['formas'] ); ?>" data-asde-descarga><?php asde_e( 'Descargar Excel' ); ?></a></span>
	</header>
	<?php if ( ! $formas ) : ?>
		<p><?php asde_e( 'No hay ventas en este periodo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Forma de pago' ); ?></th>
					<th scope="col"><?php asde_e( 'Pedidos' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
					<th scope="col"><?php asde_e( 'Cobrado' ); ?></th>
					<th scope="col"><?php asde_e( 'Por cobrar' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $formas as $forma => $d ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Forma de pago' ) ); ?>"><?php echo esc_html( '' !== $forma ? asde_t( PedidosTable::FORMAS[ $forma ] ) : asde_t( 'Sin indicar' ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Pedidos' ) ); ?>"><?php echo (int) $d['pedidos']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cobrado' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['cobrado'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Por cobrar' ) ); ?>"><?php echo esc_html( Calculadora::euros( $d['pendiente'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row"><?php asde_e( 'Total' ); ?></th>
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
		<h3 class="asde-card__title"><?php asde_e( 'Pendientes de cobro' ); ?></h3>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $excel['pendientes'] ); ?>" data-asde-descarga><?php asde_e( 'Descargar Excel' ); ?></a></span>
	</header>
	<?php if ( ! $pendientes ) : ?>
		<p><?php asde_e( 'No hay pedidos sin pagar en este periodo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Pedido' ); ?></th>
					<th scope="col"><?php asde_e( 'Quién' ); ?></th>
					<th scope="col"><?php asde_e( 'Forma de pago' ); ?></th>
					<th scope="col"><?php asde_e( 'Días esperando' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pendientes as $p ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Pedido' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'pedido', $p['id'], $url ) ); ?>" data-asde-modal><?php echo esc_html( PedidosTable::numero( $p ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Quién' ) ); ?>"><?php echo esc_html( Servicio::comprador( $p, $personas[ $p['socio'] ] ?? null ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Forma de pago' ) ); ?>"><?php echo esc_html( asde_t( PedidosTable::FORMAS[ $p['forma_pago'] ] ?? 'Sin indicar' ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Días esperando' ) ); ?>"><?php echo (int) $p['dias']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( Calculadora::euros( Servicio::importe_a_cobrar( $p ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
