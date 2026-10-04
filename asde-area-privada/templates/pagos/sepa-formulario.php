<?php
/**
 * Formulario de la autorización SEPA (se incluye desde pagos/sepa.php, con sus variables).
 *
 * @var array    $familia
 * @var array    $acreedor
 * @var string[] $texto
 * @var callable $campo    Valor escrito en el intento anterior, o el de por defecto
 */
defined( 'ABSPATH' ) || exit;

$fid = (int) $familia['id'];
?>
<form method="post" class="asde-form" autocomplete="on">
	<?php wp_nonce_field( 'asde_sepa_firmar_' . $fid ); ?>
	<input type="hidden" name="asde_action" value="sepa_firmar">
	<input type="hidden" name="familia" value="<?php echo absint( $fid ); ?>">

	<fieldset>
		<legend><?php asde_e( 'Datos del deudor (titular de la cuenta)' ); ?></legend>
		<p>
			<label for="asde-sepa-titular-<?php echo absint( $fid ); ?>"><?php asde_e( 'Nombre y apellidos del titular' ); ?></label>
			<input type="text" id="asde-sepa-titular-<?php echo absint( $fid ); ?>" name="titular" maxlength="70" required autocomplete="name" value="<?php echo esc_attr( $campo( 'titular' ) ); ?>">
		</p>
		<p>
			<label for="asde-sepa-iban-<?php echo absint( $fid ); ?>"><?php asde_e( 'IBAN de la cuenta' ); ?></label>
			<input type="text" id="asde-sepa-iban-<?php echo absint( $fid ); ?>" name="iban" maxlength="40" required inputmode="text" autocapitalize="characters" spellcheck="false" placeholder="<?php echo esc_attr( asde_a( 'ES00 0000 0000 0000 0000 0000' ) ); ?>" value="<?php echo esc_attr( $campo( 'iban' ) ); ?>">
			<span class="asde-help"><?php asde_e( 'Cuenta de una entidad de la zona SEPA. Se guarda cifrada y solo se enseña enmascarada.' ); ?></span>
		</p>
		<p>
			<label for="asde-sepa-calle-<?php echo absint( $fid ); ?>"><?php asde_e( 'Dirección (calle y número)' ); ?></label>
			<input type="text" id="asde-sepa-calle-<?php echo absint( $fid ); ?>" name="calle" maxlength="70" required autocomplete="street-address" value="<?php echo esc_attr( $campo( 'calle' ) ); ?>">
		</p>
		<div class="asde-grid-2">
			<p>
				<label for="asde-sepa-cp-<?php echo absint( $fid ); ?>"><?php asde_e( 'Código postal' ); ?></label>
				<input type="text" id="asde-sepa-cp-<?php echo absint( $fid ); ?>" name="cp" maxlength="10" required autocomplete="postal-code" value="<?php echo esc_attr( $campo( 'cp' ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-poblacion-<?php echo absint( $fid ); ?>"><?php asde_e( 'Población' ); ?></label>
				<input type="text" id="asde-sepa-poblacion-<?php echo absint( $fid ); ?>" name="poblacion" maxlength="50" required autocomplete="address-level2" value="<?php echo esc_attr( $campo( 'poblacion' ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-provincia-<?php echo absint( $fid ); ?>"><?php asde_e( 'Provincia' ); ?></label>
				<input type="text" id="asde-sepa-provincia-<?php echo absint( $fid ); ?>" name="provincia" maxlength="40" autocomplete="address-level1" value="<?php echo esc_attr( $campo( 'provincia', asde_t( 'Illes Balears' ) ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-pais-<?php echo absint( $fid ); ?>"><?php asde_e( 'País' ); ?></label>
				<input type="text" id="asde-sepa-pais-<?php echo absint( $fid ); ?>" name="pais" maxlength="40" autocomplete="country-name" value="<?php echo esc_attr( $campo( 'pais', 'España' ) ); ?>">
			</p>
		</div>
	</fieldset>

	<fieldset>
		<legend><?php asde_e( 'Datos del acreedor' ); ?></legend>
		<p class="asde-muted">
			<strong><?php echo esc_html( $acreedor['nombre'] ); ?></strong> <?php asde_e( '· Identificador del acreedor' ); ?> <?php echo esc_html( $acreedor['ics'] ); ?>
			<?php echo '' !== trim( $acreedor['calle'] . $acreedor['poblacion'] ) ? '<br>' . esc_html( trim( $acreedor['calle'] . ', ' . trim( $acreedor['cp'] . ' ' . $acreedor['poblacion'] ), ' ,' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
	</fieldset>

	<div class="asde-destacado">
		<?php foreach ( $texto as $parrafo ) : ?>
			<p class="asde-muted"><?php echo esc_html( $parrafo ); ?></p>
		<?php endforeach; ?>
	</div>

	<p>
		<label class="asde-choice">
			<input type="checkbox" name="acepto" value="1" required>
			<span><?php asde_e( 'Soy el titular de la cuenta (o estoy autorizado/a a firmar por él o ella) y' ); ?> <strong><?php asde_e( 'firmo esta orden de domiciliación de adeudo directo SEPA' ); ?></strong><?php asde_e( '. La firma electrónica queda registrada con la fecha y la hora.' ); ?></span>
		</label>
	</p>
	<p><button type="submit" class="button"><?php asde_e( 'Firmar la autorización' ); ?></button></p>
</form>
