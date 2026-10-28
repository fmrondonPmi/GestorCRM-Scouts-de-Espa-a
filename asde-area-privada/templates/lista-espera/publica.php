<?php
/**
 * «Inscripción en la lista de espera» (pública, v0.32, doc 39): el formulario del enlace del
 * grupo, la confirmación del email y la página personal de cada inscripción.
 *
 * @var string      $modo         formulario|enviado|sin_enlace|confirmar|ya_confirmada|confirmada|invalido|acceso|dentro|borrada|enlaces
 * @var string[]    $errores
 * @var array|null  $fila         Inscripción (confirmar, acceso, dentro)
 * @var array|null  $grupo        Grupo del enlace (formulario)
 * @var array       $valores      Lo escrito (para no repetirlo)
 * @var string      $clave
 * @var string      $sello
 * @var string      $token        Token del enlace personal
 * @var string      $email        Email ya comprobado (dentro)
 * @var string      $aviso        mantenidos|''
 * @var string      $destino      Email enmascarado al que se ha escrito
 * @var string      $nombre_grupo Grupo de la inscripción
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\ListaEspera\CamposExtra;
use AsdeBalears\AreaPrivada\ListaEspera\Formulario;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;

defined( 'ABSPATH' ) || exit;

$fecha  = static function ( ?string $utc ): string {
	$t = $utc ? strtotime( $utc . ' UTC' ) : false;
	return $t ? wp_date( 'd/m/Y', $t ) : '';
};
$dma    = static function ( string $f ): string {
	$d = DateTime::createFromFormat( '!Y-m-d', $f );
	return $d ? $d->format( 'd/m/Y' ) : $f;
};
$v      = static fn( string $c ): string => (string) ( $valores[ $c ] ?? '' );
$nombre = $fila ? trim( $fila['datos']['nombre'] . ' ' . $fila['datos']['apellidos'] ) : '';
// «¿Ya estás en la lista?»: reenvía los enlaces personales al email escrito.
$ya_estas = static function ( string $volver ): void {
	?>
	<details class="asde-espera-enlaces">
		<summary><?php esc_html_e( 'Already on the list? Get your links', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_enlaces' ); ?>
			<input type="hidden" name="asde_action" value="espera_enlaces">
			<input type="hidden" name="volver" value="<?php echo esc_attr( $volver ); ?>">
			<p>
				<label for="asde-espera-enl"><?php esc_html_e( 'The email you signed up with', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="email" id="asde-espera-enl" name="email_enlaces" autocomplete="email" autocapitalize="none" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Send me my links', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</details>
	<?php
};
?>
<section class="asde-card asde-card--narrow asde-espera">
	<h2><?php esc_html_e( 'Waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>

	<?php if ( $errores ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
	<?php endif; ?>

	<?php if ( 'sin_enlace' === $modo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'This link is not valid or is no longer in use. Ask your Scout group for the current link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php $ya_estas( 'sin_enlace' ); ?>

	<?php elseif ( 'invalido' === $modo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'This link is not valid or the sign-up is no longer on the list (it is deleted when you register in the group, on request or after %d months).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::MESES ) ); ?></div>
		<?php $ya_estas( 'invalido' ); ?>

	<?php elseif ( 'enviado' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php esc_html_e( 'Thank you.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
			<?php echo esc_html( '' !== $destino ? sprintf( /* translators: %s: text */ __( 'We have sent you an email at %s to confirm the sign-up. Until you press its link, it does not go on the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $destino ) : __( 'We have sent you an email to confirm the sign-up. Until you press its link, it does not go on the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		</div>
		<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'If it doesn\'t arrive, check your spam folder. The link is valid for %d days.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::DIAS_CONFIRMAR ) ); ?></p>
		<p><a href="<?php echo esc_url( add_query_arg( array( 'grupo' => rawurlencode( (string) $grupo['id'] ), 'clave' => rawurlencode( $clave ) ) ) ); ?>"><?php esc_html_e( 'Sign up another person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

	<?php elseif ( 'enlaces' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'If %s is on any waiting list, we have sent you an email with your links.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $destino ) ); ?></div>

	<?php elseif ( 'confirmar' === $modo ) : ?>
		<p><?php echo wp_kses_post( sprintf( /* translators: 1: text, 2: text */ __( 'Confirm the sign-up of <strong>%1$s</strong> on the waiting list of <strong>%2$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre ), esc_html( $nombre_grupo ) ) ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_confirmar' ); ?>
			<input type="hidden" name="asde_action" value="espera_confirmar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm the sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>

	<?php elseif ( 'confirmada' === $modo || 'ya_confirmada' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php esc_html_e( 'Sign-up confirmed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( '%1$s is already on the waiting list of %2$s. We have sent you an email with the link to see your details.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, $nombre_grupo ) ); ?>
		</div>

	<?php elseif ( 'acceso' === $modo ) : ?>
		<p><?php esc_html_e( 'To see your waiting list data, enter the email address you signed up with.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_entrar' ); ?>
			<input type="hidden" name="asde_action" value="espera_entrar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<p>
				<label for="asde-espera-email"><?php esc_html_e( 'Your email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="email" id="asde-espera-email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'View my details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>

	<?php elseif ( 'dentro' === $modo ) : ?>
		<?php if ( 'mantenidos' === $aviso ) : ?>
			<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Done: we keep your data until %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $fila['caduca'] ) ) ); ?></div>
		<?php endif; ?>
		<p><?php echo wp_kses_post( sprintf( /* translators: %s: text */ __( 'Sign-up on the waiting list of <strong>%s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre_grupo ) ) ); ?></p>
		<dl class="asde-datos asde-espera-datos">
			<dt><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $nombre ); ?></dd>
			<dt><?php esc_html_e( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $dma( $fila['datos']['nacimiento'] ) ); ?></dd>
			<?php if ( '' !== $fila['datos']['documento'] ) : ?>
				<dt><?php esc_html_e( 'Document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fila['datos']['documento'] ); ?></dd>
			<?php endif; ?>
			<?php if ( '' !== $fila['datos']['contacto_nombre'] ) : ?>
				<dt><?php esc_html_e( 'Contact person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( trim( $fila['datos']['contacto_nombre'] . ' ' . $fila['datos']['contacto_apellidos'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fila['datos']['telefono'] ); ?></dd>
			<dt><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fila['datos']['email'] ); ?></dd>
			<?php if ( '' !== $fila['datos']['observaciones'] ) : ?>
				<dt><?php esc_html_e( 'Notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $fila['datos']['observaciones'] ) ), asde_html_permitido() ); ?></dd>
			<?php endif; ?>
			<?php foreach ( $fila['extra'] as $x ) : ?>
				<dt><?php echo esc_html( $x['etiqueta'] ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $x['valor'] ) ), asde_html_permitido() ); ?></dd>
			<?php endforeach; ?>
			<dt><?php esc_html_e( 'On the list since', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fecha( $fila['confirmada_en'] ?: $fila['creada'] ) ); ?></dd>
			<?php if ( $fila['caduca'] ) : ?>
				<dt><?php esc_html_e( 'We keep your data until', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fecha( $fila['caduca'] ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<p class="asde-help"><?php esc_html_e( 'To correct any data, write to your group or delete the sign-up and sign up again with the group\'s link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php if ( Inscripciones::SIN_CONFIRMAR !== $fila['estado'] ) : ?>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_espera_mantener' ); ?>
				<input type="hidden" name="asde_action" value="espera_mantener">
				<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
				<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
				<p><button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Keep my data for %d more months', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::MESES ) ); ?></button></p>
			</form>
		<?php endif; ?>
		<form method="post" class="asde-form asde-espera-borrar">
			<?php wp_nonce_field( 'asde_espera_borrar' ); ?>
			<input type="hidden" name="asde_action" value="espera_borrar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
			<label class="asde-choice"><input type="checkbox" name="seguro" value="1"><span><?php esc_html_e( 'I want to delete my data and leave the waiting list. This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p><button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Delete my data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>

	<?php elseif ( 'borrada' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php esc_html_e( 'Your data has been deleted and you are no longer on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>

	<?php else : ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Join the waiting list of %s. We will let you know when a place is available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Grupos::etiqueta( $grupo ) ) ); ?></p>
		<form method="post" class="asde-form asde-espera-form" novalidate>
			<?php wp_nonce_field( 'asde_espera_inscribir' ); ?>
			<input type="hidden" name="asde_action" value="espera_inscribir">
			<input type="hidden" name="grupo" value="<?php echo esc_attr( (string) $grupo['id'] ); ?>">
			<input type="hidden" name="clave" value="<?php echo esc_attr( $clave ); ?>">
			<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
			<p class="asde-trampa" aria-hidden="true"><label for="asde-espera-web"><?php esc_html_e( 'Do not write anything here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
			<fieldset>
				<legend><?php esc_html_e( 'Who wants to join the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<p><label for="asde-espera-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-nombre" name="nombre" value="<?php echo esc_attr( $v( 'nombre' ) ); ?>" maxlength="60" required></p>
				<p><label for="asde-espera-apellidos"><?php esc_html_e( 'Surnames', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-apellidos" name="apellidos" value="<?php echo esc_attr( $v( 'apellidos' ) ); ?>" maxlength="100" required></p>
				<p><label for="asde-espera-nac"><?php esc_html_e( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="date" id="asde-espera-nac" name="nacimiento" value="<?php echo esc_attr( $v( 'nacimiento' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></p>
				<p>
					<label for="asde-espera-doc"><?php esc_html_e( 'ID or foreigner ID', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-espera-doc" name="documento" value="<?php echo esc_attr( $v( 'documento' ) ); ?>" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false">
					<span class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Required from age %d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Contact', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'If they are under %d years old, the details of the mother, father or guardian.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::EDAD_ADULTA ) ); ?></p>
				<p><label for="asde-espera-cnombre"><?php esc_html_e( 'Contact person\'s first name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-cnombre" name="contacto_nombre" value="<?php echo esc_attr( $v( 'contacto_nombre' ) ); ?>" maxlength="60"></p>
				<p><label for="asde-espera-capellidos"><?php esc_html_e( 'Surname of the contact person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-capellidos" name="contacto_apellidos" value="<?php echo esc_attr( $v( 'contacto_apellidos' ) ); ?>" maxlength="100"></p>
				<p><label for="asde-espera-tel"><?php esc_html_e( 'Phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="tel" id="asde-espera-tel" name="telefono" value="<?php echo esc_attr( $v( 'telefono' ) ); ?>" maxlength="20" autocomplete="tel" required></p>
				<p>
					<label for="asde-espera-email-n"><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="email" id="asde-espera-email-n" name="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
					<span class="asde-help"><?php esc_html_e( 'We will send you an email to confirm it. It is also your key to see your data later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
				<p><label for="asde-espera-obs"><?php esc_html_e( 'Notes (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><textarea id="asde-espera-obs" name="observaciones" rows="3" maxlength="<?php echo (int) Formulario::MAX_OBSERVACIONES; ?>"><?php echo esc_textarea( $v( 'observaciones' ) ); ?></textarea></p>
			</fieldset>
			<?php if ( $campos ) : ?>
				<fieldset>
					<legend><?php esc_html_e( 'More data requested by the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<?php foreach ( $campos as $c ) : ?>
						<?php
						$cid  = 'asde-espera-x-' . $c['id'];
						$name = 'extra_' . $c['id'];
						$req  = $c['obligatorio'] ? ' required' : '';
						$opc  = $c['obligatorio'] ? '' : ' ' . __( '(optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						?>
						<?php if ( CamposExtra::CASILLA === $c['tipo'] ) : ?>
							<label class="asde-choice"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( '1', $v( $name ) ); ?><?php echo esc_attr( $req ); ?>><span><?php echo esc_html( $c['etiqueta'] . $opc ); ?></span></label>
						<?php elseif ( CamposExtra::PARRAFO === $c['tipo'] ) : ?>
							<p><label for="<?php echo esc_attr( $cid ); ?>"><?php echo esc_html( $c['etiqueta'] . $opc ); ?></label><textarea id="<?php echo esc_attr( $cid ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" maxlength="<?php echo (int) CamposExtra::MAX_RESPUESTA; ?>"<?php echo esc_attr( $req ); ?>><?php echo esc_textarea( $v( $name ) ); ?></textarea></p>
						<?php else : ?>
							<p><label for="<?php echo esc_attr( $cid ); ?>"><?php echo esc_html( $c['etiqueta'] . $opc ); ?></label><input type="<?php echo CamposExtra::FECHA === $c['tipo'] ? 'date' : 'text'; ?>"<?php echo CamposExtra::NUMERO === $c['tipo'] ? ' inputmode="decimal"' : ''; ?> id="<?php echo esc_attr( $cid ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $v( $name ) ); ?>" maxlength="200"<?php echo esc_attr( $req ); ?>></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<label class="asde-choice"><input type="checkbox" name="acepto" value="1" <?php checked( '1', $v( 'acepto' ) ); ?> required><span><?php echo esc_html( sprintf( /* translators: 1: text, 2: number */ __( 'I accept that %1$s stores this data, encrypted, to manage the waiting list for %2$d months (or until I register or delete it). Only the group\'s team sees it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Grupos::etiqueta( $grupo ), Inscripciones::MESES ) ); ?></span></label>
			<label class="asde-choice"><input type="checkbox" name="declaro" value="1" <?php checked( '1', $v( 'declaro' ) ); ?> required><span><?php echo esc_html( $declaracion ); ?></span></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Join the waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<?php $ya_estas( 'formulario' ); ?>
	<?php endif; ?>
</section>
