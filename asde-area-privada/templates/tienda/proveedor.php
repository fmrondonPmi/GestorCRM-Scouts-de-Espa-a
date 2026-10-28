<?php
/**
 * Tienda · Pedido de camisas (v0.48, doc 48, T4): lo pagado que hay que pedir y los pedidos al proveedor.
 *
 * @var bool       $puede     Si puede hacer y recibir pedidos (Coordinación)
 * @var array[]    $por_pedir Pedidos de clientes pagados y sin pedir
 * @var array[]    $lineas    Lo que hay que pedir (clave, nombre, etiqueta, cantidad)
 * @var int        $sin_pagar Pedidos sin pagar que aún no entran
 * @var array[]    $hechos    Pedidos al proveedor ya hechos (los más recientes primero)
 * @var string     $url
 * @var callable   $excel     id => enlace del Excel
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y', strtotime( $f . ' UTC' ) ) : '';
?>
<section class="asde-card" data-asde-plegable="proveedor-pedir" data-asde-inicio="abierto">
	<h2 class="asde-card__title"><?php esc_html_e( 'To order from the supplier', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p class="asde-muted">
		<?php esc_html_e( 'What customers have already paid for and the shop does not have in stock (products marked "ordered from the supplier").', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		<?php echo $sin_pagar ? ' ' . esc_html( sprintf( /* translators: %d: number */ _n( 'There is also %d unpaid order: it does not count until it is paid.', 'There are also %d unpaid orders: they do not count until they are paid.', $sin_pagar , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $sin_pagar ) ) : ''; ?>
	</p>
	<?php if ( ! $lineas ) : ?>
		<p><?php esc_html_e( 'There is nothing paid pending to order yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size or model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['nombre'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Size or model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['etiqueta'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $l['cantidad']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( 'From %d customer order.', 'From %d customer orders.', count( $por_pedir ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $por_pedir ) ) ); ?></p>
		<?php if ( $puede ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
				<?php wp_nonce_field( 'asde_proveedor_crear' ); ?>
				<input type="hidden" name="asde_action" value="proveedor_crear">
				<?php foreach ( $por_pedir as $p ) : ?>
					<input type="hidden" name="pedidos[]" value="<?php echo (int) $p['id']; ?>">
				<?php endforeach; ?>
				<p class="asde-form__fila asde-form__fila--una-linea">
					<label><?php esc_html_e( 'Order name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nombre" maxlength="60" value="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Order %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
					<button type="submit" class="button"><?php esc_html_e( 'Create the supplier order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="proveedor-hechos" data-asde-inicio="abierto">
	<h2 class="asde-card__title"><?php esc_html_e( 'Supplier orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<?php if ( ! $hechos ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'No supplier order has been placed yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $hechos as $h ) : ?>
		<?php $llego = null !== $h['recibido_en']; ?>
		<article class="asde-pedido">
			<header class="asde-pedido__cabecera">
				<strong><?php echo esc_html( $h['nombre'] ); ?></strong>
				<span class="asde-badge asde-badge--uniforme-<?php echo $llego ? 'recibido' : 'pedido'; ?>"><?php echo $llego ? esc_html( sprintf( /* translators: %s: text */ __( 'Received on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $h['recibido_en'] ) ) ) : esc_html( __( 'Ordered, pending receipt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span>
				<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: number */ __( 'Made on %1$s · %2$d customer orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $h['creado'] ), $h['pedidos'] ) ); ?></span>
				<span class="asde-acciones"><a href="<?php echo esc_url( $excel( $h['id'] ) ); ?>" data-asde-sin-carga><?php esc_html_e( 'Download in Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
			</header>
			<?php if ( ! $llego && $puede ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php wp_nonce_field( 'asde_proveedor_' . $h['id'] ); ?>
					<input type="hidden" name="asde_action" value="proveedor_recibir">
					<input type="hidden" name="pedido_proveedor" value="<?php echo (int) $h['id']; ?>">
			<?php endif; ?>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size or model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ordered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $h['lineas'] as $l ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['nombre'] ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Size or model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['etiqueta'] ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Ordered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $l['cantidad']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( $llego ) : ?>
									<?php echo (int) ( $l['recibida'] ?? $l['cantidad'] ); ?>
								<?php elseif ( $puede ) : ?>
									<input type="number" name="recibida[<?php echo esc_attr( $l['clave'] ); ?>]" value="<?php echo (int) $l['cantidad']; ?>" min="0" max="99999" step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Received: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['nombre'] . ' ' . $l['etiqueta'] ) ); ?>">
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! $llego && $puede ) : ?>
					<p class="asde-form__fila asde-form__fila--una-linea">
						<button type="submit" class="button"><?php esc_html_e( 'Mark as received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
						<span class="asde-help"><?php esc_html_e( 'Anything that arrives in excess is added to the shop\'s stock; until it arrives, customers\' orders cannot be delivered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					</p>
				</form>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
