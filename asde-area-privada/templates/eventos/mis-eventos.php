<?php
/**
 * Mis eventos: los eventos de la persona y de los menores que representa.
 *
 * @var array[]                             $lista        [ evento, personas => [ nº => [ persona, fila|null, convocada ] ] ]
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var array                               $cfg          Configuración de eventos
 * @var array<int, array>                   $cobros       inscripción => Cobros::de()
 * @var array<int, string>                  $refs         inscripción => referencia de pago
 * @var array<int, int>                     $puestos      inscripción => puesto en la lista de espera
 * @var string                              $autorizacion Texto de la autorización
 * @var array|null                          $valores      Lo escrito en un formulario que no se pudo guardar
 * @var string                              $url
 * @var callable                            $url_ficha    fn( nº ) → su ficha
 * @var string                              $url_pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( int $t ): string => wp_date( 'd/m/Y', $t );
$utc    = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$campos = static function ( array $e, string $accion, string $extra = '' ): string {
	return wp_nonce_field( 'asde_mis_eventos_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. $extra;
};
$boton  = static function ( array $e, string $accion, string $texto, string $extra, string $clase = 'button', string $confirmar = '' ) use ( $campos ): string {
	return '<form method="post" class="asde-inline-form"' . ( '' !== $confirmar ? ' data-asde-confirmar="' . esc_attr( $confirmar ) . '"' : '' ) . '>'
		. $campos( $e, $accion, $extra )
		. '<button type="submit" class="' . esc_attr( $clase ) . '">' . esc_html( $texto ) . '</button></form>';
};
$oculto = static fn( string $k, string $v ): string => '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
/** El texto de la autorización, con a quién y a qué evento se autoriza. */
$texto_autorizacion = static function ( array $e, string $nombre ) use ( $autorizacion ): string {
	return '<div class="asde-autorizacion">'
		. '<p><strong>' . esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Authorisation for %1$s to take part in "%2$s" (%3$s).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, $e['nombre'], EventosTable::cuando( $e ) ) ) . '</strong></p>'
		. wp_kses_post( wpautop( esc_html( $autorizacion ) ) )
		. '</div>';
};
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php echo wp_kses_post( $ambito->representados ? __( 'The group\'s outings, camps and summer camps you are invited to. Sign up from here: when you sign up a minor you sign their <strong>participation authorisation</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'The group\'s outings, camps and summer camps you are invited to. Sign up from here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
	<p class="asde-muted"><?php esc_html_e( 'What must be paid and what has been paid for each event you can also see in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_pagos ); ?>"><?php esc_html_e( 'My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
</section>

<?php if ( ! $lista ) : ?>
	<section class="asde-card">
		<p><?php esc_html_e( 'There is currently no open event for you or your family. When the group publishes one, you will receive an email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>
<?php endif; ?>

<?php foreach ( $lista as $item ) : ?>
	<?php
	$e         = $item['evento'];
	$plazo     = Inscripciones::plazo( $e );
	$cancelado = EventosTable::CANCELADO === $e['estado'];
	if ( $cancelado ) {
		$badge = array( 'cancelado', __( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	} elseif ( 'abierta' === $plazo ) {
		$badge = array( 'publicado', sprintf( /* translators: %s: text */ __( 'Sign-up open until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( Inscripciones::cierra( $e ) ) ) );
	} elseif ( 'antes' === $plazo ) {
		$badge = array( 'validado', sprintf( /* translators: %s: text */ __( 'Sign-up opens on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( Inscripciones::abre( $e ) ) ) );
	} else {
		$badge = array( 'borrador', __( 'Sign-up closed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}
	?>
	<section class="asde-card asde-mis-evento" id="evento-<?php echo (int) $e['id']; ?>">
		<header class="asde-card__header asde-card__header--split">
			<h3 class="asde-card__title"><?php echo esc_html( $e['nombre'] ); ?></h3>
			<span class="asde-badge asde-badge--evento-<?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
		</header>
		<p>
			<strong><?php echo esc_html( EventosTable::cuando( $e ) ); ?></strong>
			<?php if ( '' !== $e['lugar'] ) : ?>
				· <?php echo esc_html( $e['lugar'] ); ?>
			<?php endif; ?>
			<?php if ( '' !== $e['mapa'] ) : ?>
				· <a href="<?php echo esc_url( $e['mapa'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on the map', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php endif; ?>
			<br><span class="asde-muted"><?php echo esc_html( EventosTable::numero( $e ) . ' · ' . Config::nombre_tipo( $cfg, $e['tipo'] ) ); ?></span>
		</p>
		<?php if ( $cancelado ) : ?>
			<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Cancelled. Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $e['cancelado_motivo'] ) ); ?> <?php esc_html_e( 'If you had already paid, Treasury will contact you about the refund.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<?php if ( ! $cancelado && ( '' !== $e['salida'] || '' !== $e['llegada'] || '' !== trim( $e['descripcion'] ) || '' !== trim( $e['material'] ) ) ) : ?>
			<details class="asde-mis-evento__info">
				<summary><?php esc_html_e( 'Practical information', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
				<dl class="asde-datos">
					<?php if ( '' !== $e['salida'] ) : ?>
						<div><dt><?php esc_html_e( 'Outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $e['salida'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== $e['llegada'] ) : ?>
						<div><dt><?php esc_html_e( 'Arrival', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $e['llegada'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== trim( $e['descripcion'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $e['descripcion'] ) ), asde_html_permitido() ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== trim( $e['material'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Personal equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $e['material'] ) ), asde_html_permitido() ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</details>
		<?php endif; ?>

		<ul class="asde-mis-evento__personas">
			<?php foreach ( $item['personas'] as $num => $x ) : ?>
				<?php
				$p        = $x['persona'];
				$f        = $x['fila'];
				$familia  = isset( $ambito->representados[ $num ] );
				$adulta   = Inscripciones::adulta_en( $e, $p );
				$estado   = $f ? Inscripciones::estado( $f ) : ( 'convocatoria' === $e['modalidad'] && 'abierta' === $plazo ? 'sin_respuesta' : '' );
				$seccion  = Inscripciones::seccion_de( $e, $p );
				$precio   = $f ? $f['precio'] : Inscripciones::precio( $e, (string) $seccion );
				$cobro    = $f ? ( $cobros[ $f['id'] ] ?? null ) : null;
				$activa   = $f && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true );
				$puede    = ! $cancelado && 'abierta' === $plazo && $x['convocada'] && ( ! $f || ! $activa ) && Inscripciones::puede_inscribirse_sola( $ambito, $e, $p );
				$clave    = $e['id'] . '|' . $num;
				$v        = $valores && $clave === $valores['clave'] ? $valores['datos'] : array();
				$extra    = $oculto( 'socio', (string) $num );
				?>
				<li class="asde-mis-evento__persona">
					<p class="asde-mis-evento__quien">
						<strong><?php echo esc_html( $familia ? $p->nombre_completo() : sprintf( /* translators: %s: text */ __( '%s (you)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo() ) ); ?></strong>
						<?php echo wp_kses( $seccion ? Secciones::badge( $seccion ) : '', asde_html_permitido() ); ?>
						<?php if ( '' !== $estado ) : ?>
							<span class="asde-badge asde-badge--inscripcion-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( ( InscripcionesTable::estados()[ $estado ] ?? $estado ) ); ?></span>
						<?php endif; ?>
					</p>

					<?php if ( $e['con_coste'] && ! $cancelado && ( $activa || $puede ) ) : ?>
						<p class="asde-muted">
							<?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Price: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $cobro ? $cobro['total'] : $precio ) ) ); ?>
							<?php if ( $cobro && $cobro['pagado'] > 0 ) : ?>
								· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'paid: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $cobro['pagado'] ) ) ); ?>
							<?php endif; ?>
							<?php if ( $cobro && $cobro['devuelto'] > 0 ) : ?>
								· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'returned: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $cobro['devuelto'] ) ) ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( $f && 'plaza' === $estado ) : ?>
						<div class="asde-notice asde-notice--info">
							<?php echo esc_html( sprintf( /* translators: %s: text */ __( 'A place has been freed up. Confirm it before %s; otherwise it will go to the next person on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $utc( $f['oferta_hasta'] ) ) ); ?>
							<?php echo wp_kses( $boton( $e, 'inscripcion_plaza', esc_html( __( 'Confirm the place', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $oculto( 'fila', (string) $f['id'] ), 'button button-primary' ), asde_html_permitido() ); ?>
						</div>
					<?php elseif ( $f && InscripcionesTable::ESPERA === $f['estado'] ) : ?>
						<p><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'There were no places left: they are on the waiting list (position %d). If one is freed up, we will let you know by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) ( $puestos[ $f['id'] ] ?? 0 ) ) ); ?></p>
					<?php elseif ( $f && InscripcionesTable::CONFIRMADA === $f['estado'] ) : ?>
						<p><?php esc_html_e( 'All set: the sign-up is confirmed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php elseif ( $f && InscripcionesTable::PENDIENTE === $f['estado'] && ! $cancelado ) : ?>
						<p><?php esc_html_e( 'To confirm the sign-up the following is missing:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<ul class="asde-falta">
							<?php if ( '' === $f['autorizacion'] ) : ?>
								<li>
									<?php if ( $familia ) : ?>
										<?php esc_html_e( 'sign the participation authorisation (or hand it in on paper to the team).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'have your family sign the participation authorisation from their account (or hand it in on paper to the team).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<?php endif; ?>
								</li>
							<?php endif; ?>
							<?php if ( $cobro && $cobro['pendiente'] > 0 ) : ?>
								<li><?php echo wp_kses_post( sprintf( /* translators: 1: text, 2: text */ __( 'pay <strong>%1$s</strong>, with the reference <strong>%2$s</strong> if by transfer or Bizum.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( Calculadora::euros( $cobro['pendiente'] ) ), esc_html( $refs[ $f['id'] ] ?? '' ) ) ); ?></li>
							<?php endif; ?>
						</ul>
						<?php if ( $cobro && $cobro['pendiente'] > 0 && '' !== trim( $cfg['texto_pago'] ) ) : ?>
							<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
						<?php endif; ?>
					<?php elseif ( $f && InscripcionesTable::NO_PARTICIPA === $f['estado'] ) : ?>
						<p><?php esc_html_e( 'You answered that they are not going. If they change their mind, they can still sign up.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php elseif ( $f && InscripcionesTable::BAJA === $f['estado'] && ! $cancelado ) : ?>
						<p><?php esc_html_e( 'Has been withdrawn from this event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php elseif ( ! $f && ! $cancelado ) : ?>
						<?php if ( 'antes' === $plazo ) : ?>
							<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Sign-up opens on %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( Inscripciones::abre( $e ) ) ) ); ?></p>
						<?php elseif ( 'cerrada' === $plazo ) : ?>
							<p><?php esc_html_e( 'Sign-up is closed. If you still want to sign up, talk to the section team.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php elseif ( ! Inscripciones::puede_inscribirse_sola( $ambito, $e, $p ) ) : ?>
							<p><?php esc_html_e( 'Your family has to sign you up from their account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $f && $familia && '' === $f['autorizacion'] && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::ESPERA ), true ) && ! $cancelado ) : ?>
						<details class="asde-inscribir">
							<summary class="button button-primary"><?php esc_html_e( 'Sign the authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
							<form method="post" class="asde-form asde-form--inscribir">
								<?php echo wp_kses( $campos( $e, 'inscripcion_firmar', $oculto( 'fila', (string) $f['id'] ) ), asde_html_permitido() ); ?>
								<?php echo wp_kses( $texto_autorizacion( $e, $p->nombre_completo() ), asde_html_permitido() ); ?>
								<label class="asde-check"><input type="checkbox" name="acepto" value="1" required> <?php esc_html_e( 'I have read the authorisation and I sign it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
								<p class="asde-help"><?php esc_html_e( 'It records who signs it, when and which version of the text.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
								<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Sign', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
							</form>
						</details>
					<?php endif; ?>

					<?php if ( $puede ) : ?>
						<details class="asde-inscribir"<?php echo $v ? ' open' : ''; ?>>
							<summary class="button button-primary"><?php echo esc_html( $familia ? sprintf( /* translators: %s: text */ __( 'Sign up %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre ) : ( $adulta ? __( 'Sign me up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Pre-register me', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?></summary>
							<form method="post" class="asde-form asde-form--inscribir">
								<?php echo wp_kses( $campos( $e, 'inscripcion_crear', $extra ), asde_html_permitido() ); ?>
								<fieldset class="asde-fieldset">
									<legend><?php esc_html_e( 'Transport', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
									<?php foreach ( array( 'transporte_ida' => __( 'Outward', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'transporte_vuelta' => __( 'Return', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) as $k => $etiqueta ) : ?>
										<label><?php echo esc_html( $etiqueta ); ?>
											<select name="<?php echo esc_attr( $k ); ?>">
												<?php foreach ( ( InscripcionesTable::transportes() ) as $t => $txt ) : ?>
													<option value="<?php echo esc_attr( $t ); ?>"<?php selected( (string) ( $v[ $k ] ?? 'grupo' ), $t ); ?>><?php echo esc_html( $txt ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<?php if ( ! $adulta ) : ?>
									<label><?php esc_html_e( 'People authorised to pick them up (name and ID number)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
										<textarea name="recoge" rows="2" maxlength="1000"><?php echo esc_textarea( (string) ( $v['recoge'] ?? '' ) ); ?></textarea>
									</label>
								<?php endif; ?>
								<label><?php esc_html_e( 'Notes for the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<textarea name="observaciones" rows="2" maxlength="1000"><?php echo esc_textarea( (string) ( $v['observaciones'] ?? '' ) ); ?></textarea>
								</label>
								<p class="asde-help"><?php esc_html_e( 'Only the event team sees this and it is deleted when the event is closed. Health and food are not written here: they go on the record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
								<label class="asde-check"><input type="checkbox" name="salud" value="1" required<?php checked( ! empty( $v['salud'] ) ); ?>> <?php echo esc_html( $familia ? sprintf( /* translators: %s: text */ __( 'I have checked %s\'s health and food record and it is up to date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre ) : __( 'I have checked my health and food record and it is up to date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></label>
								<p class="asde-help"><a href="<?php echo esc_url( $url_ficha( (string) $num ) ); ?>"><?php esc_html_e( 'View the record and propose changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
								<?php if ( $familia && ! $adulta ) : ?>
									<?php echo wp_kses( $texto_autorizacion( $e, $p->nombre_completo() ), asde_html_permitido() ); ?>
									<label class="asde-check"><input type="checkbox" name="acepto" value="1" required> <?php esc_html_e( 'I have read the authorisation and I sign it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<p class="asde-help"><?php esc_html_e( 'When signing up you sign the authorisation: who signs it, when and which version of the text is recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
								<?php elseif ( ! $adulta ) : ?>
									<p class="asde-help"><?php esc_html_e( 'This is a pre-registration: your family will have to sign the authorisation from their account (or hand it in on paper).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
								<?php endif; ?>
								<p><button type="submit" class="button button-primary"><?php echo esc_html( $familia ? __( 'Sign up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : ( $adulta ? __( 'Sign me up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Pre-register me', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?></button></p>
							</form>
						</details>
						<?php if ( 'convocatoria' === $e['modalidad'] && ( ! $f || InscripcionesTable::NO_PARTICIPA !== $f['estado'] ) ) : ?>
							<?php echo wp_kses( $boton( $e, 'inscripcion_no', esc_html( $familia ? sprintf( /* translators: %s: text */ __( '%s is not going', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre ) : __( 'I\'m not going', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $extra, 'asde-btn-link' ), asde_html_permitido() ); ?>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $activa && 'abierta' === $plazo && ! $cancelado ) : ?>
						<p class="asde-mis-evento__baja">
							<?php echo wp_kses( $boton( $e, 'inscripcion_baja', esc_html( InscripcionesTable::ESPERA === $f['estado'] ? __( 'Leave the waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $oculto( 'fila', (string) $f['id'] ), 'asde-btn-link', esc_attr( __( 'Withdraw this sign-up? Whatever has already been paid will be reviewed by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endforeach; ?>
