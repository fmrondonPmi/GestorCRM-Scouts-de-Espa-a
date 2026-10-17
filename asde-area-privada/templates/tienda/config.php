<?php
/**
 * Configuración de los pedidos de uniforme del grupo.
 *
 * @var array  $cfg
 * @var string $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

$euros  = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
$imagen = static function ( string $campo, int $adjunto, string $alt ): string {
	$url  = Config::imagen( $adjunto, 'thumbnail' );
	$html = '' !== $url ? '<img class="asde-config-img" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"> <label class="asde-choice asde-choice--inline"><input type="checkbox" name="' . esc_attr( $campo ) . '_quitar" value="1"><span>' . esc_html( asde_t( 'Quitar' ) ) . '</span></label><br>' : '';
	return $html . '<input type="file" name="' . esc_attr( $campo ) . '" accept="image/png,image/jpeg,image/webp,image/gif">';
};
?>
<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_tienda_admin' ); ?>
	<input type="hidden" name="asde_action" value="tienda_config">

	<section class="asde-card">
		<h2 class="asde-card__title"><?php asde_e( 'Pedidos de uniforme · configuración' ); ?></h2>
		<label class="asde-choice"><input type="checkbox" name="abierto" value="1" <?php checked( $cfg['abierto'] ); ?>><span><?php asde_e( 'Se aceptan pedidos (si no, las familias ven que están cerrados)' ); ?></span></label>
		<p>
			<label for="asde-uc-precio"><?php asde_e( 'Precio de la camisa (€)' ); ?></label>
			<input type="text" id="asde-uc-precio" name="precio_camisa" value="<?php echo esc_attr( $euros( $cfg['precio_camisa'] ) ); ?>" inputmode="decimal" size="8">
		</p>
		<label class="asde-choice"><input type="checkbox" name="primera_gratis" value="1" <?php checked( $cfg['primera_gratis'] ); ?>><span><?php asde_e( 'La primera camisa de cada scouter es gratis (desde la segunda, paga el precio)' ); ?></span></label>
		<p>
			<label for="asde-uc-texto"><?php asde_e( 'Cómo pagar (lo ven las familias al pedir)' ); ?></label>
			<textarea id="asde-uc-texto" name="texto_pago" rows="3" maxlength="600" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: en efectivo en el local los sábados, o por ingreso en la cuenta ES00 0000… indicando el nº de pedido.' ) ); ?>"><?php echo esc_textarea( $cfg['texto_pago'] ); ?></textarea>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Modelos y tallas' ); ?></h3>
		<?php foreach ( $cfg['modelos'] as $id => $m ) : ?>
			<fieldset>
				<legend><?php echo esc_html( $m['nombre'] ); ?></legend>
				<p>
					<label><?php asde_e( 'Nombre' ); ?> <input type="text" name="modelo_nombre_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $m['nombre'] ); ?>" maxlength="60"></label>
				</p>
				<p>
					<label><?php asde_e( 'Tallas, separadas por comas' ); ?> <input type="text" name="modelo_tallas_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( implode( ', ', $m['tallas'] ) ); ?>" size="50"></label>
				</p>
				<p><?php asde_e( 'Guía de tallas (imagen con las medidas):' ); ?><br><?php echo $imagen( 'guia_' . $id, $m['guia'], esc_html( asde_t( 'Guía de tallas: ' ) ) . $m['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			</fieldset>
		<?php endforeach; ?>
		<p>
			<label for="asde-uc-especiales"><?php asde_e( 'Tallas especiales (se marcan como tales al pedir)' ); ?></label>
			<input type="text" id="asde-uc-especiales" name="tallas_especiales" value="<?php echo esc_attr( implode( ', ', $cfg['tallas_especiales'] ) ); ?>" size="20">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Insignias' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Si dejas el precio vacío, sale como «precio por confirmar»: se puede pedir, y al validar el pago Tesorería cobra el importe real.' ); ?></p>
		<?php foreach ( $cfg['insignias'] as $id => $i ) : ?>
			<fieldset class="asde-insignia-config">
				<legend><?php echo esc_html( $i['nombre'] ); ?></legend>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Nombre' ); ?> <input type="text" name="insignia_nombre_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $i['nombre'] ); ?>" maxlength="80"></label>
					<label><?php asde_e( 'Dónde va' ); ?> <input type="text" name="insignia_lugar_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $i['lugar'] ); ?>" maxlength="60" placeholder="<?php echo esc_attr( asde_a( 'p. ej. Brazo derecho' ) ); ?>"></label>
					<label><?php asde_e( 'Precio (€)' ); ?> <input type="text" name="insignia_precio_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $euros( $i['precio'] ) ); ?>" inputmode="decimal" size="12" placeholder="<?php echo esc_attr( asde_a( 'por confirmar' ) ); ?>"></label>
				</p>
				<p><?php asde_e( 'Imagen:' ); ?><br><?php echo $imagen( 'insignia_imagen_' . $id, $i['imagen'], $i['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<p>
					<label class="asde-choice asde-choice--inline"><input type="checkbox" name="insignia_activa_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $i['activa'] ); ?>><span><?php asde_e( 'Se puede pedir' ); ?></span></label>
					<label class="asde-choice asde-choice--inline"><input type="checkbox" name="insignia_quitar_<?php echo esc_attr( $id ); ?>" value="1"><span><?php asde_e( 'Quitarla de la lista' ); ?></span></label>
				</p>
			</fieldset>
		<?php endforeach; ?>
		<?php for ( $n = 0; $n < 2; $n++ ) : ?>
			<fieldset class="asde-insignia-config">
				<legend><?php asde_e( 'Nueva insignia' ); ?></legend>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Nombre' ); ?> <input type="text" name="nueva_nombre_<?php echo (int) $n; ?>" maxlength="80"></label>
					<label><?php asde_e( 'Dónde va' ); ?> <input type="text" name="nueva_lugar_<?php echo (int) $n; ?>" maxlength="60"></label>
					<label><?php asde_e( 'Precio (€)' ); ?> <input type="text" name="nueva_precio_<?php echo (int) $n; ?>" inputmode="decimal" size="12" placeholder="<?php echo esc_attr( asde_a( 'por confirmar' ) ); ?>"></label>
				</p>
				<p><?php asde_e( 'Imagen:' ); ?><br><input type="file" name="nueva_imagen_<?php echo (int) $n; ?>" accept="image/png,image/jpeg,image/webp,image/gif"></p>
			</fieldset>
		<?php endfor; ?>
		<p><?php asde_e( 'Guía de colocación («dónde va cada insignia»):' ); ?><br><?php echo $imagen( 'guia_insignias', $cfg['guia_insignias'], esc_html( asde_t( 'Guía de colocación de las insignias' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
	</section>

	<p><button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button></p>
</form>
