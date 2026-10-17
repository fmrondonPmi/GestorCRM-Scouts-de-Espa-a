<?php
/**
 * Ingresos del banco que mencionan un pedido sin pagar (v0.40, doc 48, fase 5). Solo los ve Tesorería.
 *
 * @var array[]                                                $sugerencias Cada uno: pedido, mov, coincide, a_cobrar
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var string                                                $volver
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

if ( ! $sugerencias ) {
	return;
}
$fecha = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
?>
<section class="asde-card" data-asde-plegable="tienda-banco" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php asde_e( 'Ingresos del banco que parecen pagos de pedidos' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'Estos ingresos del banco llevan en el concepto el nº de un pedido que sigue sin pagar. Compruébalos y confirma cada uno: no se marca nada solo.' ); ?></p>
	<table class="asde-table">
		<thead>
			<tr>
				<th scope="col"><?php asde_e( 'Pedido' ); ?></th>
				<th scope="col"><?php asde_e( 'Ingreso del banco' ); ?></th>
				<th scope="col"><?php asde_e( 'Importe' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Confirmar' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sugerencias as $s ) : ?>
				<?php
				$p = $s['pedido'];
				$m = $s['mov'];
				?>
				<tr>
					<td data-label="<?php echo esc_attr( asde_a( 'Pedido' ) ); ?>">
						<strong><?php echo esc_html( PedidosTable::numero( $p ) ); ?></strong><br>
						<span class="asde-muted"><?php echo esc_html( Servicio::comprador( $p, $personas[ $p['socio'] ] ?? null ) ); ?></span>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'Ingreso del banco' ) ); ?>">
						<?php echo esc_html( $fecha( $m['fecha'] ) ); ?><?php echo '' !== $m['ordenante'] ? ' · ' . esc_html( $m['ordenante'] ) : ''; ?><br>
						<span class="asde-muted"><?php echo esc_html( $m['concepto'] ); ?></span>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>">
						<?php echo esc_html( Calculadora::euros( $m['importe'] ) ); ?>
						<?php if ( $s['coincide'] ) : ?>
							<br><span class="asde-badge asde-badge--pago-pagado"><?php asde_e( 'Coincide con el pedido' ); ?></span>
						<?php else : ?>
							<br><span class="asde-badge asde-badge--pago-parcial"><?php echo esc_html( sprintf( asde_t( 'El pedido es de %s' ), Calculadora::euros( $s['a_cobrar'] ) ) ); ?></span>
						<?php endif; ?>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'Confirmar' ) ); ?>">
						<form method="post">
							<?php wp_nonce_field( 'asde_tienda_banco_' . $p['id'] . '_' . $m['id'] ); ?>
							<input type="hidden" name="asde_action" value="tienda_banco_pagar">
							<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
							<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
							<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
							<button type="submit" class="button"><?php asde_e( 'Marcar pagado con este ingreso' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
