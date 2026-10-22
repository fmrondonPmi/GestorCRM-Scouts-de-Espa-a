<?php
/**
 * Abonos del datáfono en el banco (v0.46, doc 48, T3). Solo los ve Tesorería.
 *
 * @var array[]  $abonos Cada uno: mov (ingreso del banco), pedidos (cobrados con datáfono), suma, exacto
 * @var string   $volver
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

if ( ! $abonos ) {
	return;
}
$fecha = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
?>
<section class="asde-card" data-asde-plegable="tienda-datafono" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php asde_e( 'Ingresos del banco que parecen el abono del datáfono' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'El banco abona de una vez lo cobrado con el datáfono, menos su comisión. Estos ingresos cuadran con los cobros de los últimos días. Compruébalos y confirma cada uno: no se concilia nada solo.' ); ?></p>
	<?php foreach ( $abonos as $a ) : ?>
		<?php $m = $a['mov']; ?>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_tienda_datafono_' . $m['id'] ); ?>
			<input type="hidden" name="asde_action" value="tienda_banco_datafono">
			<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<p>
				<strong><?php echo esc_html( $fecha( $m['fecha'] ) ); ?> · <?php echo esc_html( Calculadora::euros( $m['importe'] ) ); ?></strong>
				<?php if ( $a['exacto'] ) : ?>
					<span class="asde-badge asde-badge--pago-pagado"><?php asde_e( 'Coincide con lo cobrado' ); ?></span>
				<?php else : ?>
					<span class="asde-badge asde-badge--pago-parcial"><?php echo esc_html( sprintf( asde_t( 'Se cobró %s (comisión del banco)' ), Calculadora::euros( $a['suma'] ) ) ); ?></span>
				<?php endif; ?>
				<?php echo '' !== (string) $m['concepto'] ? '<br><span class="asde-muted">' . esc_html( $m['concepto'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<ul>
				<?php foreach ( $a['pedidos'] as $p ) : ?>
					<li>
						<input type="hidden" name="pedidos[]" value="<?php echo (int) $p['id']; ?>">
						<?php echo esc_html( PedidosTable::numero( $p ) ); ?> · <?php echo esc_html( $fecha( (string) $p['pago_fecha'] ) ); ?> · <?php echo esc_html( Calculadora::euros( (float) $p['pago_importe'] ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><button type="submit" class="button"><?php asde_e( 'Conciliar este abono' ); ?></button></p>
		</form>
	<?php endforeach; ?>
</section>
