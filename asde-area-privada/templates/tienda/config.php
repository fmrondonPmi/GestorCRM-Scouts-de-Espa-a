<?php
/**
 * Configuración de la tienda del grupo.
 *
 * @var array  $cfg
 * @var string $url
 * @var string $enlace Enlace de la tienda pública del grupo (v0.39, fase 4)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.

defined( 'ABSPATH' ) || exit;

?>
<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_tienda_admin' ); ?>
	<input type="hidden" name="asde_action" value="tienda_config">

	<section class="asde-card">
		<h2 class="asde-card__title"><?php asde_e( 'Tienda · configuración' ); ?></h2>
		<label class="asde-choice"><input type="checkbox" name="abierto" value="1" <?php checked( $cfg['abierto'] ); ?>><span><?php asde_e( 'Se aceptan pedidos (si no, las familias ven que están cerrados)' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="datafono" value="1" <?php checked( $cfg['datafono'] ); ?>><span><?php asde_e( 'Aceptamos pagar con datáfono (tarjeta, en persona; lo marca Tesorería al cobrar)' ); ?></span></label>
		<p class="asde-form__fila">
			<label for="asde-uc-iban"><?php asde_e( 'Cuenta del grupo (IBAN) para las transferencias' ); ?> <input type="text" id="asde-uc-iban" name="iban" value="<?php echo esc_attr( $cfg['iban'] ); ?>" maxlength="42" autocomplete="off" placeholder="ES00 0000 0000 0000 0000 0000"></label>
			<label for="asde-uc-bizum"><?php asde_e( 'Teléfono de Bizum del grupo' ); ?> <input type="text" id="asde-uc-bizum" name="bizum" value="<?php echo esc_attr( $cfg['bizum'] ); ?>" maxlength="20" inputmode="tel" autocomplete="off"></label>
		</p>
		<p>
			<label for="asde-uc-texto"><?php asde_e( 'Cómo pagar (lo ven las familias al pedir)' ); ?></label>
			<textarea id="asde-uc-texto" name="texto_pago" rows="3" maxlength="600" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: en efectivo en el local los sábados, o por ingreso en la cuenta ES00 0000… indicando el nº de pedido.' ) ); ?>"><?php echo esc_textarea( $cfg['texto_pago'] ); ?></textarea>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Pedidos sin pagar' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Los pedidos de socios y familias que se hacen en la tienda y no se pagan: se les recuerda por email y, si lo indicas, se cancelan solos y lo que pidieron vuelve a la tienda. Cuenta desde el día del pedido; con 0 no se hace.' ); ?></p>
		<p>
			<label for="asde-uc-aviso"><?php asde_e( 'Días para recordar el pago (0 = no recordar)' ); ?></label>
			<input type="number" id="asde-uc-aviso" name="dias_aviso_socios" value="<?php echo (int) $cfg['dias_aviso_socios']; ?>" min="0" max="90" inputmode="numeric">
		</p>
		<p>
			<label for="asde-uc-cancelar"><?php asde_e( 'Días para cancelar un pedido sin pagar (0 = nunca)' ); ?></label>
			<input type="number" id="asde-uc-cancelar" name="dias_cancelar_socios" value="<?php echo (int) $cfg['dias_cancelar_socios']; ?>" min="0" max="180" inputmode="numeric">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Tienda pública' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Con la tienda pública, cualquiera con el enlace puede comprar sin iniciar sesión lo que el grupo tenga a la venta «para todos» (nunca lo «solo socios»), pagando por transferencia o Bizum. Sus datos se guardan cifrados y se borran a los 90 días de entregar o cancelar el pedido.' ); ?></p>
		<label class="asde-choice"><input type="checkbox" name="publica" value="1" <?php checked( $cfg['publica'] ); ?>><span><?php asde_e( 'Abrir la tienda pública (compra sin sesión)' ); ?></span></label>
		<p>
			<label for="asde-uc-dias"><?php asde_e( 'Días para pagar una compra sin sesión antes de cancelarla (0 = nunca)' ); ?></label>
			<input type="number" id="asde-uc-dias" name="dias_sin_pagar" value="<?php echo (int) $cfg['dias_sin_pagar']; ?>" min="0" max="90" inputmode="numeric">
		</p>
		<?php if ( $cfg['publica'] && '' !== $enlace ) : ?>
			<p><?php asde_e( 'Enlace de la tienda (compártelo donde quieras):' ); ?><br><input type="text" readonly value="<?php echo esc_attr( $enlace ); ?>" class="asde-input-ancho" aria-label="<?php echo esc_attr( asde_a( 'Enlace de la tienda' ) ); ?>"></p>
			<p class="asde-help"><?php asde_e( 'Si el enlace se ha compartido donde no debía, crea uno nuevo: el anterior deja de valer (guarda antes los cambios de esta pantalla).' ); ?></p>
			<p><button type="submit" class="asde-btn-link" name="asde_action" value="tienda_enlace_renovar" formnovalidate><?php asde_e( 'Crear un enlace nuevo' ); ?></button></p>
		<?php endif; ?>
	</section>

	<p><button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button></p>
</form>
