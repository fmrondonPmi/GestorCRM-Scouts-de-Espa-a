<?php
/**
 * «Alta de scouters» (pública, v0.16, doc 31).
 *
 * @var array|null           $grupo     Grupo del enlace (null si el enlace no vale)
 * @var bool                 $enviado
 * @var bool                 $limite
 * @var bool                 $sin_crm
 * @var string[]             $errores
 * @var array                $valores   Lo escrito (para no tener que repetirlo)
 * @var string               $clave
 * @var string               $sello
 * @var string               $contacto  Email de contacto del grupo ('' si no hay)
 * @var string               $url_login
 * @var string               $url_recuperar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\AltaScouterService as A;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php asde_e( 'Alta de scouters' ); ?></h2>

	<?php if ( ! $grupo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este enlace no es válido o ya no está en uso. Pide el enlace actual al equipo de coordinación de tu grupo.' ); ?></div>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php asde_e( 'Ir a Acceso socios' ); ?></a></p>
	<?php elseif ( $enviado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php asde_e( 'Gracias.' ); ?></strong>
			<?php asde_e( 'Si el DNI o NIE es el de un scouter o voluntario/a del grupo, te llegará un email para activar tu cuenta al email de tu ficha del grupo. Si tu ficha no tiene email, o si has pedido una responsabilidad, el equipo de coordinación revisará tu alta y te escribirá.' ); ?>
		</div>
		<p><?php asde_e( 'Si ya tienes cuenta, entra con tu nº de socio/a o con tu email; si no recuerdas la contraseña,' ); ?> <a href="<?php echo esc_url( $url_recuperar ); ?>"><?php asde_e( 'pide una nueva' ); ?></a>.</p>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php asde_e( 'Ir a Acceso socios' ); ?></a></p>
	<?php else : ?>
		<?php if ( $limite ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php asde_e( 'Has hecho varios intentos seguidos. Espera una hora antes de volver a intentarlo.' ); ?></div>
		<?php elseif ( $sin_crm ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php asde_e( 'Ahora no podemos comprobar tus datos. Inténtalo más tarde.' ); ?></div>
		<?php endif; ?>
		<?php if ( $errores ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
		<?php endif; ?>

		<p><?php echo esc_html( sprintf( asde_t( 'Si eres scouter o voluntario/a del %s, date de alta en el área privada. Comprobaremos tu DNI con tu ficha del grupo.' ), Grupos::etiqueta( $grupo ) ) ); ?></p>
		<form method="post" class="asde-form" novalidate>
			<?php wp_nonce_field( 'asde_alta_scouter' ); ?>
			<input type="hidden" name="asde_action" value="alta_scouter">
			<input type="hidden" name="grupo" value="<?php echo esc_attr( (string) $grupo['id'] ); ?>">
			<input type="hidden" name="clave" value="<?php echo esc_attr( $clave ); ?>">
			<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
			<p class="asde-trampa" aria-hidden="true"><label for="asde-alta-web"><?php asde_e( 'No escribas nada aquí' ); ?></label><input type="text" id="asde-alta-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
			<p>
				<label for="asde-alta-doc"><?php asde_e( 'Tu DNI o NIE' ); ?></label>
				<input type="text" id="asde-alta-doc" name="documento" value="<?php echo esc_attr( $valores['documento'] ); ?>" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
			</p>
			<fieldset>
				<legend><?php asde_e( '¿Cómo colaboras en el grupo?' ); ?></legend>
				<label class="asde-choice"><input type="radio" name="modo" value="<?php echo esc_attr( A::MODO_SCOUTER ); ?>" <?php checked( $valores['modo'], A::MODO_SCOUTER ); ?>><span><?php asde_e( 'Soy scouter de una o varias secciones' ); ?></span></label>
				<div class="asde-subopciones">
					<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
						<label class="asde-choice"><input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $valores['secciones'], true ) ); ?>><span><?php echo Secciones::badge( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></label>
					<?php endforeach; ?>
				</div>
				<label class="asde-choice"><input type="radio" name="modo" value="<?php echo esc_attr( A::MODO_SIN_SECCION ); ?>" <?php checked( $valores['modo'], A::MODO_SIN_SECCION ); ?>><span><?php asde_e( 'Soy voluntario/a sin sección (Secretaría, Coordinación, Tesorería o Intendencia)' ); ?></span></label>
			</fieldset>
			<fieldset>
				<legend><?php asde_e( 'Responsabilidades en el equipo del grupo (opcional)' ); ?></legend>
				<?php foreach ( AltaService::ROLES_RESPONSABILIDAD as $rol ) : ?>
					<label class="asde-choice"><input type="checkbox" name="responsabilidades[]" value="<?php echo esc_attr( $rol ); ?>" <?php checked( in_array( $rol, $valores['responsabilidades'], true ) ); ?>><span><?php echo esc_html( Roles::label( $rol ) ); ?></span></label>
				<?php endforeach; ?>
				<p class="asde-help"><?php asde_e( 'Las confirma el equipo de coordinación antes de que tengan efecto. Mientras, entras como scouter de tus secciones.' ); ?></p>
			</fieldset>
			<p>
				<label for="asde-alta-email"><?php asde_e( 'Tu email' ); ?></label>
				<input type="email" id="asde-alta-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
				<span class="asde-help"><?php asde_e( 'La activación se envía al email de tu ficha del grupo. Este solo se usa si tu ficha no tiene email: entonces el equipo de coordinación revisará tu alta.' ); ?></span>
			</p>
			<p class="asde-help"><?php asde_e( 'Usamos estos datos solo para comprobar tu alta con tu ficha del grupo. Se guardan cifrados y se borran 30 días después de revisarla.' ); ?></p>
			<p><button type="submit" class="button"><?php asde_e( 'Darme de alta' ); ?></button></p>
		</form>
		<p class="asde-muted"><?php asde_e( '¿Ya tienes cuenta?' ); ?> <a href="<?php echo esc_url( $url_login ); ?>"><?php asde_e( 'Entra aquí' ); ?></a>.</p>
	<?php endif; ?>
</section>
