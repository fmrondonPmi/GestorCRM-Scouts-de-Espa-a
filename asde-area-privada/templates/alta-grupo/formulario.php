<?php
/**
 * «Alta de grupo» (pública, v0.50, doc 50 fase 5): el formulario al que lleva una invitación de la oficina.
 *
 * @var string   $token
 * @var bool     $verificada Ya se ha comprobado el acceso al CRM con este nº de grupo (la credencial no vuelve en el HTML)
 * @var string   $mensaje
 * @var string[] $errores
 * @var bool     $limite
 * @var string   $sello
 * @var array    $valores
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php asde_e( 'Alta de grupo' ); ?></h2>
	<p><?php asde_e( 'La oficina te ha invitado a dar de alta vuestro grupo en el área privada. Rellena los datos, comprueba el acceso al CRM y envíalos: la oficina los revisará.' ); ?></p>

	<?php if ( $limite ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php asde_e( 'Has hecho varios intentos seguidos. Espera una hora antes de volver a intentarlo.' ); ?></div>
	<?php endif; ?>
	<?php if ( $errores ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $mensaje ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( $mensaje ); ?></div>
	<?php endif; ?>

	<form method="post" class="asde-form" novalidate autocomplete="off">
		<?php wp_nonce_field( 'asde_alta_grupo' ); ?>
		<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
		<p class="asde-trampa" aria-hidden="true"><label for="asde-ag-web"><?php asde_e( 'No escribas nada aquí' ); ?></label><input type="text" id="asde-ag-web" name="web" value="" tabindex="-1" autocomplete="off"></p>

		<h3><?php asde_e( 'El grupo' ); ?></h3>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-numero"><?php asde_e( 'Nº de grupo' ); ?> <input type="text" id="asde-ag-numero" name="numero" value="<?php echo esc_attr( $valores['numero'] ); ?>" maxlength="3" inputmode="numeric" size="4" required></label>
			<label for="asde-ag-nombre"><?php asde_e( 'Nombre del grupo' ); ?> <input type="text" id="asde-ag-nombre" name="nombre" value="<?php echo esc_attr( $valores['nombre'] ); ?>" maxlength="120" required></label>
		</p>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-sede"><?php asde_e( 'Dirección de la sede' ); ?> <input type="text" id="asde-ag-sede" name="sede" value="<?php echo esc_attr( $valores['sede'] ); ?>" maxlength="255"></label>
			<label for="asde-ag-email"><?php asde_e( 'Email del grupo' ); ?> <input type="email" id="asde-ag-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" required></label>
		</p>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-cif"><?php asde_e( 'CIF (opcional)' ); ?> <input type="text" id="asde-ag-cif" name="cif" value="<?php echo esc_attr( $valores['cif'] ); ?>" maxlength="20" size="12"></label>
			<label for="asde-ag-den"><?php asde_e( 'Denominación fiscal (opcional)' ); ?> <input type="text" id="asde-ag-den" name="denominacion" value="<?php echo esc_attr( $valores['denominacion'] ); ?>" maxlength="190"></label>
		</p>

		<h3><?php asde_e( 'Acceso al CRM' ); ?></h3>
		<?php if ( $verificada ) : ?>
			<p class="asde-notice asde-notice--success" role="status"><?php asde_e( 'Acceso comprobado. La contraseña queda guardada cifrada y no se vuelve a mostrar.' ); ?></p>
		<?php else : ?>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-ag-usuario"><?php asde_e( 'Usuario del CRM del grupo' ); ?> <input type="text" id="asde-ag-usuario" name="usuario" value="<?php echo esc_attr( $valores['usuario'] ); ?>" maxlength="120" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
				<label for="asde-ag-clave"><?php asde_e( 'Contraseña' ); ?> <input type="password" id="asde-ag-clave" name="clave" value="" autocomplete="new-password"></label>
			</p>
		<?php endif; ?>
		<p><button type="submit" class="button asde-button-secundario" name="asde_action" value="alta_grupo_comprobar" formnovalidate><?php asde_e( 'Comprobar acceso' ); ?></button></p>

		<h3><?php asde_e( 'La primera persona con acceso' ); ?></h3>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-persona"><?php asde_e( 'Nombre y apellidos' ); ?> <input type="text" id="asde-ag-persona" name="persona" value="<?php echo esc_attr( $valores['persona'] ); ?>" maxlength="120" required></label>
			<label for="asde-ag-dni"><?php asde_e( 'DNI o NIE' ); ?> <input type="text" id="asde-ag-dni" name="dni" value="<?php echo esc_attr( $valores['dni'] ); ?>" maxlength="20" size="12" autocapitalize="characters" spellcheck="false" required></label>
			<label for="asde-ag-rol"><?php asde_e( 'Rol' ); ?>
				<select id="asde-ag-rol" name="rol" required>
					<?php foreach ( GrupoSolicitudes::ROLES as $rol => $etiqueta ) : ?>
						<option value="<?php echo esc_attr( $rol ); ?>" <?php selected( $valores['rol'], $rol ); ?>><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>

		<p class="asde-help"><?php asde_e( 'Estos datos se guardan cifrados y solo los ve la oficina. Tras enviar, recibirás aviso cuando se revise.' ); ?></p>
		<p><button type="submit" class="button" name="asde_action" value="alta_grupo_enviar" <?php disabled( ! $verificada ); ?>><?php asde_e( 'Enviar el alta' ); ?></button></p>
	</form>
</section>
