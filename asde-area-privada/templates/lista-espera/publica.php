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
		<summary><?php asde_e( '¿Ya estás en la lista? Recibe tus enlaces' ); ?></summary>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_enlaces' ); ?>
			<input type="hidden" name="asde_action" value="espera_enlaces">
			<input type="hidden" name="volver" value="<?php echo esc_attr( $volver ); ?>">
			<p>
				<label for="asde-espera-enl"><?php asde_e( 'El email con el que te apuntaste' ); ?></label>
				<input type="email" id="asde-espera-enl" name="email_enlaces" autocomplete="email" autocapitalize="none" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button"><?php asde_e( 'Enviarme mis enlaces' ); ?></button></p>
		</form>
	</details>
	<?php
};
?>
<section class="asde-card asde-card--narrow asde-espera">
	<h2><?php asde_e( 'Lista de espera' ); ?></h2>

	<?php if ( $errores ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
	<?php endif; ?>

	<?php if ( 'sin_enlace' === $modo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este enlace no es válido o ya no está en uso. Pide el enlace actual a tu grupo scout.' ); ?></div>
		<?php $ya_estas( 'sin_enlace' ); ?>

	<?php elseif ( 'invalido' === $modo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( asde_t( 'Este enlace no es válido o la inscripción ya no está en la lista (se borra al darse de alta en el grupo, al pedirlo o a los %d meses).' ), Inscripciones::MESES ) ); ?></div>
		<?php $ya_estas( 'invalido' ); ?>

	<?php elseif ( 'enviado' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php asde_e( 'Gracias.' ); ?></strong>
			<?php echo esc_html( '' !== $destino ? sprintf( asde_t( 'Te hemos enviado un email a %s para confirmar la inscripción. Hasta que no pulses su enlace, no entra en la lista.' ), $destino ) : asde_t( 'Te hemos enviado un email para confirmar la inscripción. Hasta que no pulses su enlace, no entra en la lista.' ) ); ?>
		</div>
		<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Si no te llega, mira en el correo no deseado. El enlace vale %d días.' ), Inscripciones::DIAS_CONFIRMAR ) ); ?></p>
		<p><a href="<?php echo esc_url( add_query_arg( array( 'grupo' => rawurlencode( (string) $grupo['id'] ), 'clave' => rawurlencode( $clave ) ) ) ); ?>"><?php asde_e( 'Apuntar a otra persona' ); ?></a></p>

	<?php elseif ( 'enlaces' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( sprintf( asde_t( 'Si %s está en alguna lista de espera, te hemos enviado un email con tus enlaces.' ), $destino ) ); ?></div>

	<?php elseif ( 'confirmar' === $modo ) : ?>
		<p><?php echo wp_kses_post( sprintf( asde_h( 'Confirma la inscripción de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong>.' ), esc_html( $nombre ), esc_html( $nombre_grupo ) ) ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_confirmar' ); ?>
			<input type="hidden" name="asde_action" value="espera_confirmar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<p><button type="submit" class="button button-primary"><?php asde_e( 'Confirmar la inscripción' ); ?></button></p>
		</form>

	<?php elseif ( 'confirmada' === $modo || 'ya_confirmada' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php asde_e( 'Inscripción confirmada.' ); ?></strong>
			<?php echo esc_html( sprintf( asde_t( '%1$s ya está en la lista de espera del %2$s. Te hemos enviado un email con el enlace para ver tus datos.' ), $nombre, $nombre_grupo ) ); ?>
		</div>

	<?php elseif ( 'acceso' === $modo ) : ?>
		<p><?php asde_e( 'Para ver tus datos de la lista de espera, escribe el email con el que te apuntaste.' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_entrar' ); ?>
			<input type="hidden" name="asde_action" value="espera_entrar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<p>
				<label for="asde-espera-email"><?php asde_e( 'Tu email' ); ?></label>
				<input type="email" id="asde-espera-email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button button-primary"><?php asde_e( 'Ver mis datos' ); ?></button></p>
		</form>

	<?php elseif ( 'dentro' === $modo ) : ?>
		<?php if ( 'mantenidos' === $aviso ) : ?>
			<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( sprintf( asde_t( 'Hecho: guardamos tus datos hasta el %s.' ), $fecha( $fila['caduca'] ) ) ); ?></div>
		<?php endif; ?>
		<p><?php echo wp_kses_post( sprintf( asde_h( 'Inscripción en la lista de espera del <strong>%s</strong>.' ), esc_html( $nombre_grupo ) ) ); ?></p>
		<dl class="asde-datos asde-espera-datos">
			<dt><?php asde_e( 'Nombre' ); ?></dt><dd><?php echo esc_html( $nombre ); ?></dd>
			<dt><?php asde_e( 'Fecha de nacimiento' ); ?></dt><dd><?php echo esc_html( $dma( $fila['datos']['nacimiento'] ) ); ?></dd>
			<?php if ( '' !== $fila['datos']['documento'] ) : ?>
				<dt><?php asde_e( 'Documento' ); ?></dt><dd><?php echo esc_html( $fila['datos']['documento'] ); ?></dd>
			<?php endif; ?>
			<?php if ( '' !== $fila['datos']['contacto_nombre'] ) : ?>
				<dt><?php asde_e( 'Persona de contacto' ); ?></dt><dd><?php echo esc_html( trim( $fila['datos']['contacto_nombre'] . ' ' . $fila['datos']['contacto_apellidos'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php asde_e( 'Teléfono' ); ?></dt><dd><?php echo esc_html( $fila['datos']['telefono'] ); ?></dd>
			<dt><?php asde_e( 'Email' ); ?></dt><dd><?php echo esc_html( $fila['datos']['email'] ); ?></dd>
			<?php if ( '' !== $fila['datos']['observaciones'] ) : ?>
				<dt><?php asde_e( 'Observaciones' ); ?></dt><dd><?php echo nl2br( esc_html( $fila['datos']['observaciones'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<?php endif; ?>
			<dt><?php asde_e( 'En la lista desde' ); ?></dt><dd><?php echo esc_html( $fecha( $fila['confirmada_en'] ?: $fila['creada'] ) ); ?></dd>
			<?php if ( $fila['caduca'] ) : ?>
				<dt><?php asde_e( 'Guardamos tus datos hasta' ); ?></dt><dd><?php echo esc_html( $fecha( $fila['caduca'] ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<p class="asde-help"><?php asde_e( 'Para corregir algún dato, escribe a tu grupo o borra la inscripción y vuelve a apuntarte con el enlace del grupo.' ); ?></p>
		<?php if ( Inscripciones::SIN_CONFIRMAR !== $fila['estado'] ) : ?>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_espera_mantener' ); ?>
				<input type="hidden" name="asde_action" value="espera_mantener">
				<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
				<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
				<p><button type="submit" class="button button-primary"><?php echo esc_html( sprintf( asde_t( 'Mantener mis datos %d meses más' ), Inscripciones::MESES ) ); ?></button></p>
			</form>
		<?php endif; ?>
		<form method="post" class="asde-form asde-espera-borrar">
			<?php wp_nonce_field( 'asde_espera_borrar' ); ?>
			<input type="hidden" name="asde_action" value="espera_borrar">
			<input type="hidden" name="id" value="<?php echo (int) $fila['id']; ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
			<label class="asde-choice"><input type="checkbox" name="seguro" value="1"><span><?php asde_e( 'Quiero borrar mis datos y salir de la lista de espera. No se puede deshacer.' ); ?></span></label>
			<p><button type="submit" class="button asde-button-secundario"><?php asde_e( 'Borrar mis datos' ); ?></button></p>
		</form>

	<?php elseif ( 'borrada' === $modo ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php asde_e( 'Tus datos se han borrado y ya no estás en la lista de espera.' ); ?></div>

	<?php else : ?>
		<p><?php echo esc_html( sprintf( asde_t( 'Apúntate a la lista de espera del %s. Te avisaremos cuando haya plaza.' ), Grupos::etiqueta( $grupo ) ) ); ?></p>
		<form method="post" class="asde-form asde-espera-form" novalidate>
			<?php wp_nonce_field( 'asde_espera_inscribir' ); ?>
			<input type="hidden" name="asde_action" value="espera_inscribir">
			<input type="hidden" name="grupo" value="<?php echo esc_attr( (string) $grupo['id'] ); ?>">
			<input type="hidden" name="clave" value="<?php echo esc_attr( $clave ); ?>">
			<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
			<p class="asde-trampa" aria-hidden="true"><label for="asde-espera-web"><?php asde_e( 'No escribas nada aquí' ); ?></label><input type="text" id="asde-espera-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
			<fieldset>
				<legend><?php asde_e( 'Quién quiere entrar en el grupo' ); ?></legend>
				<p><label for="asde-espera-nombre"><?php asde_e( 'Nombre' ); ?></label><input type="text" id="asde-espera-nombre" name="nombre" value="<?php echo esc_attr( $v( 'nombre' ) ); ?>" maxlength="60" required></p>
				<p><label for="asde-espera-apellidos"><?php asde_e( 'Apellidos' ); ?></label><input type="text" id="asde-espera-apellidos" name="apellidos" value="<?php echo esc_attr( $v( 'apellidos' ) ); ?>" maxlength="100" required></p>
				<p><label for="asde-espera-nac"><?php asde_e( 'Fecha de nacimiento' ); ?></label><input type="date" id="asde-espera-nac" name="nacimiento" value="<?php echo esc_attr( $v( 'nacimiento' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></p>
				<p>
					<label for="asde-espera-doc"><?php asde_e( 'DNI o NIE' ); ?></label>
					<input type="text" id="asde-espera-doc" name="documento" value="<?php echo esc_attr( $v( 'documento' ) ); ?>" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false">
					<span class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Obligatorio a partir de los %d años.' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
				</p>
			</fieldset>
			<fieldset>
				<legend><?php asde_e( 'Contacto' ); ?></legend>
				<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Si es menor de %d años, los datos de la madre, el padre o el tutor/a.' ), Inscripciones::EDAD_ADULTA ) ); ?></p>
				<p><label for="asde-espera-cnombre"><?php asde_e( 'Nombre de la persona de contacto' ); ?></label><input type="text" id="asde-espera-cnombre" name="contacto_nombre" value="<?php echo esc_attr( $v( 'contacto_nombre' ) ); ?>" maxlength="60"></p>
				<p><label for="asde-espera-capellidos"><?php asde_e( 'Apellidos de la persona de contacto' ); ?></label><input type="text" id="asde-espera-capellidos" name="contacto_apellidos" value="<?php echo esc_attr( $v( 'contacto_apellidos' ) ); ?>" maxlength="100"></p>
				<p><label for="asde-espera-tel"><?php asde_e( 'Teléfono' ); ?></label><input type="tel" id="asde-espera-tel" name="telefono" value="<?php echo esc_attr( $v( 'telefono' ) ); ?>" maxlength="20" autocomplete="tel" required></p>
				<p>
					<label for="asde-espera-email-n"><?php asde_e( 'Email' ); ?></label>
					<input type="email" id="asde-espera-email-n" name="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
					<span class="asde-help"><?php asde_e( 'Te enviaremos un email para confirmarlo. Es también tu clave para ver tus datos más adelante.' ); ?></span>
				</p>
				<p><label for="asde-espera-obs"><?php asde_e( 'Observaciones (opcional)' ); ?></label><textarea id="asde-espera-obs" name="observaciones" rows="3" maxlength="<?php echo (int) Formulario::MAX_OBSERVACIONES; ?>"><?php echo esc_textarea( $v( 'observaciones' ) ); ?></textarea></p>
			</fieldset>
			<label class="asde-choice"><input type="checkbox" name="acepto" value="1" <?php checked( '1', $v( 'acepto' ) ); ?> required><span><?php echo esc_html( sprintf( asde_t( 'Acepto que el %1$s guarde estos datos, cifrados, para gestionar la lista de espera durante %2$d meses (o hasta que me dé de alta o los borre). Solo los ve el equipo del grupo.' ), Grupos::etiqueta( $grupo ), Inscripciones::MESES ) ); ?></span></label>
			<p><button type="submit" class="button button-primary"><?php asde_e( 'Apuntarme a la lista de espera' ); ?></button></p>
		</form>
		<?php $ya_estas( 'formulario' ); ?>
	<?php endif; ?>
</section>
