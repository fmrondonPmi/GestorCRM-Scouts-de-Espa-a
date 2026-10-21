<?php
/**
 * Tienda · Pedido de camisas (v0.48, doc 52, T4): lo pagado que hay que pedir y los pedidos al proveedor.
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
	<h2 class="asde-card__title"><?php asde_e( 'Para pedir al proveedor' ); ?></h2>
	<p class="asde-muted">
		<?php asde_e( 'Lo que los clientes ya han pagado y la tienda no tiene en existencias (los productos marcados «se encarga al proveedor»).' ); ?>
		<?php echo $sin_pagar ? ' ' . esc_html( sprintf( asde_n( 'Además hay %d pedido sin pagar: no entra hasta que se pague.', 'Además hay %d pedidos sin pagar: no entran hasta que se paguen.', $sin_pagar ), $sin_pagar ) ) : ''; ?>
	</p>
	<?php if ( ! $lineas ) : ?>
		<p><?php asde_e( 'Todavía no hay nada pagado pendiente de pedir.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Producto' ); ?></th>
					<th scope="col"><?php asde_e( 'Talla o modelo' ); ?></th>
					<th scope="col"><?php asde_e( 'Cantidad' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Producto' ) ); ?>"><?php echo esc_html( $l['nombre'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Talla o modelo' ) ); ?>"><?php echo esc_html( $l['etiqueta'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cantidad' ) ); ?>"><?php echo (int) $l['cantidad']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( 'De %d pedido de cliente.', 'De %d pedidos de clientes.', count( $por_pedir ) ), count( $por_pedir ) ) ); ?></p>
		<?php if ( $puede ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
				<?php wp_nonce_field( 'asde_proveedor_crear' ); ?>
				<input type="hidden" name="asde_action" value="proveedor_crear">
				<?php foreach ( $por_pedir as $p ) : ?>
					<input type="hidden" name="pedidos[]" value="<?php echo (int) $p['id']; ?>">
				<?php endforeach; ?>
				<p class="asde-form__fila asde-form__fila--una-linea">
					<label><?php asde_e( 'Nombre del pedido' ); ?> <input type="text" name="nombre" maxlength="60" value="<?php echo esc_attr( sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
					<button type="submit" class="button"><?php asde_e( 'Crear el pedido al proveedor' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="proveedor-hechos" data-asde-inicio="abierto">
	<h2 class="asde-card__title"><?php asde_e( 'Pedidos al proveedor' ); ?></h2>
	<?php if ( ! $hechos ) : ?>
		<p class="asde-muted"><?php asde_e( 'Todavía no se ha hecho ningún pedido al proveedor.' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $hechos as $h ) : ?>
		<?php $llego = null !== $h['recibido_en']; ?>
		<article class="asde-pedido">
			<header class="asde-pedido__cabecera">
				<strong><?php echo esc_html( $h['nombre'] ); ?></strong>
				<span class="asde-badge asde-badge--uniforme-<?php echo $llego ? 'recibido' : 'pedido'; ?>"><?php echo $llego ? esc_html( sprintf( asde_t( 'Recibido el %s' ), $fecha( $h['recibido_en'] ) ) ) : esc_html( asde_t( 'Pedido, pendiente de recibir' ) ); ?></span>
				<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Hecho el %1$s · %2$d pedidos de clientes' ), $fecha( $h['creado'] ), $h['pedidos'] ) ); ?></span>
				<span class="asde-acciones"><a href="<?php echo esc_url( $excel( $h['id'] ) ); ?>" data-asde-sin-carga><?php asde_e( 'Descargar en Excel' ); ?></a></span>
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
						<th scope="col"><?php asde_e( 'Producto' ); ?></th>
						<th scope="col"><?php asde_e( 'Talla o modelo' ); ?></th>
						<th scope="col"><?php asde_e( 'Pedidas' ); ?></th>
						<th scope="col"><?php asde_e( 'Recibidas' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $h['lineas'] as $l ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Producto' ) ); ?>"><?php echo esc_html( $l['nombre'] ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Talla o modelo' ) ); ?>"><?php echo esc_html( $l['etiqueta'] ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Pedidas' ) ); ?>"><?php echo (int) $l['cantidad']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Recibidas' ) ); ?>">
								<?php if ( $llego ) : ?>
									<?php echo (int) ( $l['recibida'] ?? $l['cantidad'] ); ?>
								<?php elseif ( $puede ) : ?>
									<input type="number" name="recibida[<?php echo esc_attr( $l['clave'] ); ?>]" value="<?php echo (int) $l['cantidad']; ?>" min="0" max="99999" step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Recibidas: %s' ), $l['nombre'] . ' ' . $l['etiqueta'] ) ); ?>">
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
						<button type="submit" class="button"><?php asde_e( 'Marcar como recibido' ); ?></button>
						<span class="asde-help"><?php asde_e( 'Lo que llegue de más suma a las existencias de la tienda; hasta que llegue, los pedidos de los clientes no se pueden entregar.' ); ?></span>
					</p>
				</form>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
