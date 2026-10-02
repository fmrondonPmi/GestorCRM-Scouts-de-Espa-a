<?php
/**
 * «Solicitar acceso» (pública).
 *
 * @var array<string, array> $grupos   Grupos con conexión al CRM
 * @var string               $grupo    Grupo elegido
 * @var string               $resultado Resultado del envío ('' si aún no se ha enviado)
 * @var string               $contacto Email de contacto del grupo ('' si no hay)
 * @var array<string,string> $valores  Lo escrito (para no tener que repetirlo)
 * @var string[]             $errores
 * @var string               $sello
 * @var string               $url_login
 * @var string               $url_recuperar
 */
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService as S;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

$pedir_email = static function ( string $contacto ): string {
	return '' !== $contacto
		? sprintf( 'Pide el acceso por email a <a href="mailto:%1$s">%2$s</a>, con el nombre del socio/a y tu relación con él o ella.', esc_attr( $contacto ), esc_html( $contacto ) )
		: 'Pide el acceso a la Secretaría de tu grupo, con el nombre del socio/a y tu relación con él o ella.';
};
$terminado  = in_array( $resultado, array( S::OK, S::YA_PEDIDA, S::YA_TIENE ), true );
$ko         = in_array( $resultado, array( S::NO_EXISTE, S::NO_COINCIDE, S::NO_ELEGIBLE, 'sin_crm', 'limite' ), true );
$con_nombre = S::PEDIR_NOMBRE === $resultado || '' !== $valores['nombre_menor'];
?>
<section class="asde-card asde-card--narrow">
	<h2>Solicitar acceso</h2>

	<?php if ( S::OK === $resultado || S::YA_PEDIDA === $resultado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php echo S::OK === $resultado ? 'Solicitud enviada.' : 'Ya teníamos tu solicitud.'; ?></strong>
			Un scouter del grupo la revisará y, cuando la apruebe, te llegará un email a <strong><?php echo esc_html( $valores['email'] ); ?></strong> para activar tu cuenta.
		</div>
		<p><a href="<?php echo esc_url( $url_login ); ?>">Volver a Acceso socios</a></p>
	<?php elseif ( S::YA_TIENE === $resultado ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong>Ya tienes una cuenta en el área privada.</strong> Entra con tu nº de socio/a o con tu email. Si no recuerdas la contraseña, <a href="<?php echo esc_url( $url_recuperar ); ?>">pide una nueva</a>.
		</div>
		<p><a href="<?php echo esc_url( $url_login ); ?>">Ir a Acceso socios</a></p>
	<?php endif; ?>

	<?php if ( ! $terminado ) : ?>
		<?php if ( $ko ) : ?>
			<div class="asde-notice asde-notice--error" role="alert">
				<?php if ( 'limite' === $resultado ) : ?>
					Has hecho varias solicitudes seguidas. Espera una hora antes de volver a intentarlo, o <?php echo wp_kses( lcfirst( $pedir_email( $contacto ) ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php elseif ( 'sin_crm' === $resultado ) : ?>
					Ahora no podemos comprobar tus datos. Inténtalo más tarde o <?php echo wp_kses( lcfirst( $pedir_email( $contacto ) ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php elseif ( S::NO_ELEGIBLE === $resultado ) : ?>
					Con esos datos no se puede dar acceso desde aquí. <?php echo wp_kses( $pedir_email( $contacto ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php else : ?>
					<strong>No hemos podido comprobar tus datos</strong>: no hay ningún socio/a del grupo con ese documento y esa fecha de nacimiento. <?php echo wp_kses( $pedir_email( $contacto ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php endif; ?>
			</div>
		<?php elseif ( S::PEDIR_NOMBRE === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status">
				<?php echo '' === trim( $valores['documento'] ) ? 'Sin nº de documento, lo comprobamos con su nombre.' : 'No hay ningún socio/a con ese nº de documento.'; ?>
				Si es <strong>menor de 14 años</strong> y no tiene DNI, escribe su nombre y apellidos tal como constan en el grupo.
			</div>
		<?php elseif ( S::MENOR_14 === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status">Los socios menores de 14 años entran con la cuenta de su familia: la pide su madre, padre o tutor/a. Lo hemos marcado abajo; revisa tus datos y vuelve a enviarlo.</div>
		<?php elseif ( S::ADULTO === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status">Ese socio/a ya es mayor de edad: pide el acceso la propia persona. Lo hemos marcado abajo; revisa los datos y vuelve a enviarlo.</div>
		<?php endif; ?>
		<?php if ( $errores ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
		<?php endif; ?>

		<?php if ( ! $grupos ) : ?>
			<p>Las solicitudes de acceso no están disponibles en este momento.</p>
		<?php else : ?>
			<?php $org = Organizacion::nombre(); ?>
			<p>Si eres socio/a de un grupo<?php echo '' !== $org ? ' de ' . esc_html( $org ) : ''; ?>, o su madre, padre o tutor/a, y aún no tienes cuenta en el área privada, pídela aquí. Comprobaremos los datos con los del grupo y un scouter aprobará la solicitud.</p>
			<form method="post" class="asde-form" novalidate>
				<?php wp_nonce_field( 'asde_solicitar' ); ?>
				<input type="hidden" name="asde_action" value="solicitar">
				<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
				<p class="asde-trampa" aria-hidden="true"><label for="asde-sol-web">No escribas nada aquí</label><input type="text" id="asde-sol-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
				<p>
					<label for="asde-sol-grupo">Grupo Scout</label>
					<select id="asde-sol-grupo" name="grupo" required>
						<?php if ( count( $grupos ) > 1 ) : ?>
							<option value="">Elige el grupo…</option>
						<?php endif; ?>
						<?php foreach ( $grupos as $id => $g ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $grupo, $id ); ?>><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<fieldset>
					<legend>¿Quién pide el acceso?</legend>
					<label class="asde-choice"><input type="radio" name="tipo" value="<?php echo esc_attr( Solicitudes::TUTOR ); ?>" <?php checked( $valores['tipo'], Solicitudes::TUTOR ); ?>><span>Soy su madre, padre o tutor/a (socios menores de edad)</span></label>
					<label class="asde-choice"><input type="radio" name="tipo" value="<?php echo esc_attr( Solicitudes::SOCIO ); ?>" <?php checked( $valores['tipo'], Solicitudes::SOCIO ); ?>><span>Soy el socio/a (desde los 14 años)</span></label>
				</fieldset>
				<fieldset>
					<legend>Datos del socio/a</legend>
					<p>
						<label for="asde-sol-doc">Nº de documento (DNI o NIE)</label>
						<input type="text" id="asde-sol-doc" name="documento" value="<?php echo esc_attr( $valores['documento'] ); ?>" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false">
						<span class="asde-help">El del socio/a. Si es menor y no tiene DNI, déjalo en blanco.</span>
					</p>
					<p>
						<label for="asde-sol-nac">Fecha de nacimiento del socio/a</label>
						<input type="date" id="asde-sol-nac" name="nacimiento" value="<?php echo esc_attr( $valores['nacimiento'] ); ?>" required>
					</p>
					<?php if ( $con_nombre ) : ?>
						<p class="asde-destacado">
							<label for="asde-sol-menor">Nombre y apellidos del socio/a</label>
							<input type="text" id="asde-sol-menor" name="nombre_menor" value="<?php echo esc_attr( $valores['nombre_menor'] ); ?>" maxlength="120" required <?php echo S::PEDIR_NOMBRE === $resultado ? 'autofocus' : ''; ?>>
						</p>
					<?php endif; ?>
				</fieldset>
				<fieldset>
					<legend>Tus datos</legend>
					<p>
						<label for="asde-sol-nombre">Tu nombre y apellidos</label>
						<input type="text" id="asde-sol-nombre" name="nombre" value="<?php echo esc_attr( $valores['nombre'] ); ?>" maxlength="120" autocomplete="name" required>
					</p>
					<p>
						<label for="asde-sol-email">Tu email</label>
						<input type="email" id="asde-sol-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
						<span class="asde-help">Aquí te llegará la invitación, y con él podrás entrar. Si el grupo tiene otro, al aprobar la solicitud se actualizará en la ficha.</span>
					</p>
				</fieldset>
				<p class="asde-help">Usamos estos datos solo para comprobar la solicitud con la ficha del grupo. Se guardan cifrados y se borran 30 días después de resolverla.</p>
				<p><button type="submit" class="button">Enviar solicitud</button></p>
			</form>
			<p class="asde-muted">¿Ya tienes cuenta? <a href="<?php echo esc_url( $url_login ); ?>">Entra aquí</a>.</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
