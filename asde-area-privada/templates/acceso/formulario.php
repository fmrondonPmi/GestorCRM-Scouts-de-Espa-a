<?php
/**
 * Acceso al área privada.
 *
 * @var array<string, array{id:string, numero:string, nombre:string}> $grupos
 * @var string $grupo          Grupo seleccionado
 * @var string $usuario
 * @var string $error
 * @var bool   $pedir_codigo
 * @var bool   $activada       Viene de activar la cuenta o cambiar la contraseña
 * @var string $redirect_to
 * @var string $url_recuperar
 * @var string $url_solicitar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Identidad;

defined( 'ABSPATH' ) || exit;

$org     = \AsdeBalears\AreaPrivada\Domain\Organizacion::nombre();
$ejemplo = \AsdeBalears\AreaPrivada\Domain\NumeroSocio::ejemplo_tutor();
?>
<section class="asde-card asde-card--narrow asde-acceso">
	<h2><?php asde_e( 'Acceso socios' ); ?></h2>
	<p><?php echo esc_html( '' !== $org ? sprintf( asde_t( 'Área privada de los grupos de %s.' ), $org ) : asde_t( 'Área privada de los Grupos Scouts.' ) ); ?></p>

	<?php if ( $activada ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php asde_e( 'Contraseña guardada. Ya puedes entrar con tu nº de socio/a (o tu email) y la contraseña que acabas de elegir.' ); ?></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<?php if ( ! $grupos ) : ?>
		<p><?php asde_e( 'El acceso no está disponible en este momento. Inténtalo más tarde.' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form" autocomplete="on">
			<?php wp_nonce_field( 'asde_acceso' ); ?>
			<input type="hidden" name="asde_action" value="acceder">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<p>
				<label for="asde-acc-grupo"><?php asde_e( 'Grupo Scout' ); ?></label>
				<select id="asde-acc-grupo" name="grupo" required>
					<?php if ( count( $grupos ) > 1 ) : ?>
						<option value=""><?php asde_e( 'Elige tu grupo…' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $grupos as $id => $g ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $grupo, $id ); ?>><?php echo esc_html( \AsdeBalears\AreaPrivada\Domain\Grupos::etiqueta( $g ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="asde-acc-usuario"><?php echo esc_html( Identidad::etiqueta_campo() ); ?></label>
				<input type="text" id="asde-acc-usuario" name="usuario" value="<?php echo esc_attr( $usuario ); ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required>
				<span class="asde-help"><?php asde_e( 'Las familias: nº de socio/a del menor seguido de una' ); ?> <strong>T</strong><?php echo '' !== $ejemplo ? ' (p. ej. ' . esc_html( $ejemplo ) . ')' : ''; ?><?php asde_e( '. También sirve el email al que te llegó la invitación' ); ?><?php echo Identidad::es_asde() ? esc_html( asde_t( ", o tu nº de socio/a local de siempre" ) ) : esc_html( asde_t( ", o tu nº de socio/a de Scouts de España" ) ); ?>.</span>
			</p>
			<p>
				<label for="asde-acc-clave"><?php asde_e( 'Contraseña' ); ?></label>
				<input type="password" id="asde-acc-clave" name="clave" autocomplete="current-password" required>
			</p>
			<?php /* v0.35.1 (petición del usuario): plegado; abierto cuando Wordfence pide el código. */ ?>
			<details class="asde-acceso__codigo<?php echo $pedir_codigo ? ' asde-destacado' : ''; ?>"<?php echo $pedir_codigo ? ' open' : ''; ?>>
				<summary><?php asde_e( 'Código de verificación' ); ?> <?php echo $pedir_codigo ? '' : '<span class="asde-help asde-help--inline">' . esc_html( asde_t( '(solo si tienes activada la verificación en dos pasos)' ) ) . '</span>'; ?></summary>
				<p>
					<label for="asde-acc-codigo" class="screen-reader-text"><?php asde_e( 'Código de verificación' ); ?></label>
					<input type="text" id="asde-acc-codigo" name="wfls-token" autocomplete="one-time-code" spellcheck="false" maxlength="24" <?php echo $pedir_codigo ? 'required autofocus' : ''; ?>>
					<span class="asde-help"><?php asde_e( 'Las 6 cifras de tu app de autenticación o, si has perdido el móvil, uno de tus códigos de recuperación.' ); ?></span>
				</p>
			</details>
			<label class="asde-choice"><input type="checkbox" name="recordar" value="1"><span><?php asde_e( 'Mantener la sesión iniciada en este dispositivo' ); ?></span></label>
			<p><button type="submit" class="button"><?php asde_e( 'Entrar' ); ?></button></p>
		</form>
		<p class="asde-muted">
			<a href="<?php echo esc_url( $url_recuperar ); ?>"><?php asde_e( '¿Has olvidado tu contraseña?' ); ?></a><br>
			<?php asde_e( '¿Aún no tienes cuenta?' ); ?> <a href="<?php echo esc_url( $url_solicitar ); ?>"><?php asde_e( 'Solicita el acceso' ); ?></a><?php asde_e( ': comprobamos tus datos con los del grupo y te llega una invitación por email.' ); ?>
		</p>
	<?php endif; ?>
</section>
