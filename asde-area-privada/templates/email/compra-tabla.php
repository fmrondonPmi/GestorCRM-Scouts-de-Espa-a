<?php
/**
 * Tabla del material de una lista de compra, para los emails (v0.30: la usan el aviso a
 * Tesorería y el de la revisión a quien la pidió). El material nuevo, que no estaba en el
 * inventario (P-63), lo dice debajo de su descripción.
 *
 * @var array $lista La lista (ComprasTable::obtener)
 * @var array $col   Colores del email (Colores::para_email)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$td = 'padding:6px 8px;border-bottom:1px solid #dddddd;';
?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;margin:0 0 16px;border-collapse:collapse;">
	<tr>
		<th align="left" style="<?php echo esc_attr( $td ); ?>"><?php asde_e( 'Material' ); ?></th>
		<th align="right" style="<?php echo esc_attr( $td ); ?>"><?php asde_e( 'A comprar' ); ?></th>
		<th align="right" style="<?php echo esc_attr( $td ); ?>"><?php asde_e( 'Importe' ); ?></th>
	</tr>
	<?php foreach ( $lista['lineas'] as $l ) : ?>
		<tr>
			<td style="<?php echo esc_attr( $td ); ?>">
				<?php echo esc_html( $l['descripcion'] ); ?>
				<?php if ( empty( $l['material'] ) ) : ?>
					<br><span style="font-size:12px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Material nuevo (no está en el inventario)' ); ?></span>
				<?php endif; ?>
			</td>
			<td align="right" style="<?php echo esc_attr( $td ); ?>"><?php echo (int) $l['cantidad']; ?></td>
			<td align="right" style="<?php echo esc_attr( $td ); ?>"><?php echo null === $l['precio'] ? '—' : esc_html( Calculadora::euros( $l['precio'] * $l['cantidad'] ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<th align="left" colspan="2" style="padding:8px;"><?php asde_e( 'Total estimado' ); ?></th>
		<th align="right" style="padding:8px;"><?php echo esc_html( Calculadora::euros( $lista['total'] ) ); ?></th>
	</tr>
</table>
