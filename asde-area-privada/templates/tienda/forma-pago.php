<?php
/**
 * Tienda · cómo se va a pagar (v0.39, doc 48): transferencia, Bizum o, solo para socios/as, en físico a Tesorería.
 *
 * @var array  $cfg         Configuración de la tienda (cuenta y Bizum del grupo)
 * @var bool   $es_socio    Si puede elegir «en físico»
 * @var bool   $obligatoria Si hay que elegir una (pedidos del catálogo)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

$detalle = array(
	'transferencia' => '' !== $cfg['iban'] ? $cfg['iban'] : '',
	'bizum'         => '' !== $cfg['bizum'] ? $cfg['bizum'] : '',
	'fisico'        => '',
	'datafono'      => '',
);
?>
<fieldset class="asde-forma-pago">
	<legend><?php esc_html_e( 'How are you going to pay?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
	<?php foreach ( asde_ta( PedidosTable::FORMAS ) as $forma => $etiqueta ) : ?>
		<?php
		if ( ( 'fisico' === $forma && ! $es_socio ) || ( 'datafono' === $forma && empty( $cfg['datafono'] ) ) ) {
			continue;
		}
		?>
		<label class="asde-choice"><input type="radio" name="forma_pago" value="<?php echo esc_attr( $forma ); ?>" <?php echo $obligatoria ? 'required' : ''; ?>><span><?php echo esc_html( $etiqueta ); ?><?php echo wp_kses( '' !== $detalle[ $forma ] ? ' <span class="asde-muted">· ' . esc_html( $detalle[ $forma ] ) . '</span>' : '', asde_html_permitido() ); ?></span></label>
	<?php endforeach; ?>
	<p class="asde-help"><?php esc_html_e( 'State the order no. in the description. When Treasury receives the payment, you will see it as paid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</fieldset>
