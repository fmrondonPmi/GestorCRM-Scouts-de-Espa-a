<?php
/**
 * Ventana «Nuevo ingrediente» (v0.60.2): se abre desde el botón de una receta que se está rellenando (la ventana «Nuevo plato» del
 * menú o el formulario del recetario), crea el ingrediente por fetch y lo deja elegido sin recargar la página. Va fuera del
 * formulario de la receta (los formularios no se anidan).
 *
 * @var string $url_envio La pantalla del recetario, a la que se envía el formulario
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Escalado;

defined( 'ABSPATH' ) || exit;
?>
<dialog class="asde-modal asde-modal--mini asde-menu-nueva asde-ing-nuevo" aria-labelledby="asde-ing-nuevo-titulo" data-asde-ing-dialogo data-asde-ing-fallo="<?php echo esc_attr( esc_attr__( 'The ingredient could not be saved. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
	<form method="post" action="<?php echo esc_url( $url_envio ); ?>" class="asde-form asde-menu-nueva__form" data-asde-ing-form data-asde-sin-carga>
		<?php wp_nonce_field( 'asde_menus' ); ?>
		<input type="hidden" name="asde_action" value="ingrediente_crear_json">
		<h3 id="asde-ing-nuevo-titulo"><?php esc_html_e( 'New ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<div class="asde-menu-nueva__fila">
			<input type="text" name="nombre" maxlength="120" required aria-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<select name="unidad" aria-label="<?php echo esc_attr( esc_attr__( 'Usual unit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" title="<?php echo esc_attr( esc_attr__( 'Usual unit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<?php foreach ( Escalado::unidades() as $k => $u ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $u[0] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" inputmode="decimal" name="coste" size="6" aria-label="<?php echo esc_attr( esc_attr__( 'Cost per usual unit, in euros (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" title="<?php echo esc_attr( esc_attr__( 'Cost per usual unit, in euros (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" placeholder="€">
		</div>
		<fieldset class="asde-ing-nuevo__alergenos">
			<legend><?php esc_html_e( 'Contains', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( Alergenos::lista() as $k => $n ) : ?>
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="alergenos[]" value="<?php echo esc_attr( $k ); ?>"><span><?php echo esc_html( $n ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<label class="asde-choice"><input type="checkbox" name="animal" value="1"><span><?php esc_html_e( 'It is of animal origin (meat, fish, eggs, dairy, honey…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="revisado" value="1"><span><?php esc_html_e( 'I have checked the allergens of this ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p class="asde-notice asde-notice--error" role="alert" data-asde-ing-error hidden></p>
		<p class="asde-form__acciones">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<button type="button" class="button asde-button-secundario" data-asde-ing-cerrar><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>
	</form>
</dialog>
