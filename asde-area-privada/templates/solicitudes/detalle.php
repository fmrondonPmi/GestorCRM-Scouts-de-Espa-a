<?php
/**
 * Solicitud de acceso: lo escrito junto a lo del CRM, y aprobar o rechazar.
 *
 * @var array                                        $s
 * @var AsdeBalears\AreaPrivada\Domain\Persona|null  $p
 * @var array|null                                   $c         SolicitudService::comparacion()
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]     $hermanos
 * @var WP_User[]                                    $familiares Cuentas familiares que ya existen (T y T2)
 * @var array<string, string>                        $emails_familiares usuario => email enmascarado
 * @var array|null                                   $propuesta SolicitudService::propuesta() (familia con cuenta)
 * @var string                                       $contacto  Email de contacto del grupo ('' si no hay)
 * @var WP_User|null                                 $propia    Cuenta propia que ya existe
 * @var bool                                         $escritura El usuario del CRM puede editar fichas
 * @var WP_User|null                                 $revisor
 * @var string                                       $url
 * @var string                                       $url_alta
 * @var string                                       $url_ficha
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

$d         = $s['datos'];
$pendiente = Solicitudes::PENDIENTE === $s['estado'];
$tutor     = Solicitudes::TUTOR === $s['tipo'];
$marca     = static fn( ?bool $ok ): string => null === $ok ? '' : ( $ok ? '<span class="asde-ok" title="Coincide">✅</span> ' : '<span class="asde-ko" title="No coincide">⚠️</span> ' );
$adulto    = $s['adulto'] || ( $p && $p->es_adulto_voluntario() );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Access request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-badge asde-badge--estado-<?php echo esc_attr( $pendiente ? 'invitado' : ( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ) ); ?>"><?php echo esc_html( ( Solicitudes::estados()[ $s['estado'] ] ?? $s['estado'] ) ); ?></span>
	</header>
	<p class="asde-muted">
		<?php esc_html_e( 'Received on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?>.
		<?php echo $tutor ? esc_html( __( 'It is requested by their family (mother, father or guardian).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'It is requested by the member themselves.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		<?php if ( $revisor ) : ?>
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s by %2$s on %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Solicitudes::APROBADA === $s['estado'] ? __( 'Approved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Rejected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $revisor->display_name, wp_date( 'd/m/Y', strtotime( $s['revisada_en'] . ' UTC' ) ) ) ); ?>
			<?php echo '' !== $s['motivo'] ? esc_html( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_guardado( (string) $s['motivo'] ) ) ) : ''; ?>
		<?php endif; ?>
	</p>

	<?php if ( ! $p ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The record of this request is no longer in the group\'s CRM (or has no active membership).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php else : ?>
		<table class="asde-table asde-comparar" data-asde-sin-paginar="comparacion campo a campo de una ficha">
			<thead><tr><th scope="col"><?php esc_html_e( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Written on the request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'In the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Written', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php
						$escrito = Solicitudes::POR_NOMBRE === $s['via'] ? $d['nombre_menor'] : ( $tutor ? '' : $d['nombre'] );
						echo wp_kses( '' !== $escrito ? $marca( $c['nombre_socio'] ) . esc_html( $escrito ) : '<span class="asde-muted">—</span>', asde_html_permitido() );
						?>
					</td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a><?php echo wp_kses( \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ), asde_html_permitido() ); ?>
						<?php
						foreach ( $p->secciones as $sec ) {
							echo wp_kses( Secciones::badge( $sec ), asde_html_permitido() );
						}
						?>
						<br><span class="asde-help"><?php echo null === $p->edad() ? '' : sprintf( esc_html( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), (int) $p->edad() ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Check', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Written', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" colspan="2">
						<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
							<?php esc_html_e( 'Registration with the scouters\' link: the ID or foreigner ID number is that of this Scouter or Volunteer record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<?php elseif ( Solicitudes::POR_NOMBRE === $s['via'] ) : ?>
							<?php echo wp_kses( $marca( (bool) $c['nacimiento'] ), asde_html_permitido() ); ?> <?php esc_html_e( 'Date of birth and first name and surname (under 14 with no document in the CRM).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<?php else : ?>
							<?php echo wp_kses( $marca( (bool) $c['nacimiento'] ), asde_html_permitido() ); ?> <?php esc_html_e( 'The document number and date of birth match the record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Asks for', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Written', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" colspan="2">
							<?php echo esc_html( implode( ', ', array_map( array( \AsdeBalears\AreaPrivada\Install\Roles::class, 'label' ), (array) ( $d['roles_pedidos'] ?? array() ) ) ) ?: '—' ); ?>
							<?php foreach ( (array) ( $d['secciones_pedidas'] ?? array() ) as $sec ) : ?>
								<?php echo wp_kses( Secciones::badge( (string) $sec ), asde_html_permitido() ); ?>
							<?php endforeach; ?>
							<?php if ( ! empty( $d['cuenta_creada'] ) ) : ?>
								<br><span class="asde-help"><?php esc_html_e( 'Their scouter account is already created (the activation arrived at the email on their record). Check their sections and confirm the responsibilities they ask for, or close the request if there is nothing to change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php elseif ( ! empty( $d['error_alta'] ) ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'The account could not be created right away: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (string) $d['error_alta'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Who requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Written', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( ( $tutor ? $marca( $c['tutor'] > 0 ) : '' ) . esc_html( $d['nombre'] ), asde_html_permitido() ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php if ( $tutor ) : ?>
							<?php foreach ( array( 1, 2 ) as $n ) : ?>
								<?php $t = $p->tutores[ $n ] ?? null; ?>
								<?php if ( $t && '' !== trim( $t['nombre'] . $t['apellidos'] ) ) : ?>
									<?php esc_html_e( 'Guardian', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $n; ?>: <?php echo esc_html( trim( $t['nombre'] . ' ' . $t['apellidos'] ) ); ?><?php echo $n === $c['tutor'] ? ' <strong>(coincide)</strong>' : ''; ?><br>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( ! $c['tutor'] ) : ?>
								<span class="asde-help asde-help--warning"><?php esc_html_e( 'It does not match any guardian on the record: confirm with the family before approving.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="asde-muted"><?php esc_html_e( 'The person themselves', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Written', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( ( null !== $c['email_en'] ? $marca( true ) : '' ) . esc_html( $d['email'] ), asde_html_permitido() ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php if ( null !== $c['email_en'] ) : ?>
							<?php esc_html_e( 'Already on the record:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( Usuarios::etiqueta_campo_email( $c['email_en'] ) ); ?>.
						<?php else : ?>
							<span class="asde-help asde-help--warning"><?php esc_html_e( 'It is not on the record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php foreach ( SolicitudService::CAMPOS[ $s['tipo'] ] as $campo ) : ?>
								<br><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?>: <?php echo esc_html( $c['emails'][ $campo ] ?? '—' ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $pendiente && $p ) : ?>
	<section class="asde-card">
		<?php if ( $adulto ) : ?>
			<h3><?php esc_html_e( 'Give access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p><?php esc_html_e( 'They are a scouter or volunteer: their access is given by the Secretary\'s office or Coordination, choosing their roles and sections. The invitation will go to the email on the request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( $url_alta ); ?>" data-asde-modal><?php esc_html_e( 'Choose roles and give access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<h3><?php esc_html_e( 'Approve', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="aprobar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<ul class="asde-lista-pasos">
					<?php if ( null === $c['email_en'] ) : ?>
						<li>
							<?php if ( $tutor ) : ?>
								<label for="asde-sol-campo"><?php esc_html_e( 'The email will be saved on the CRM record as', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
								<select id="asde-sol-campo" name="campo">
									<?php foreach ( SolicitudService::CAMPOS[ $s['tipo'] ] as $campo ) : ?>
										<option value="<?php echo esc_attr( $campo ); ?>" <?php selected( ( $propuesta['campo'] ?? '' ) ?: $c['campo'], $campo ); ?>><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) . ( isset( $c['emails'][ $campo ] ) ? ' (ahora: ' . $c['emails'][ $campo ] . ')' : __( ' (now empty)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<?php esc_html_e( 'The email will be saved on the CRM record (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( Usuarios::etiqueta_campo_email( 'email1' ) ); ?><?php echo isset( $c['emails']['email1'] ) ? ', ahora: ' . esc_html( $c['emails']['email1'] ) : ''; ?>).
							<?php endif; ?>
							<?php if ( ! $escritura ) : ?>
								<br><span class="asde-help asde-help--warning"><?php esc_html_e( 'The group\'s CRM user cannot edit records: you will have to note it by hand in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endif; ?>
					<?php if ( $tutor ) : ?>
						<?php if ( $familiares ) : ?>
							<li>
								<fieldset>
										<legend><?php esc_html_e( 'Who is requesting access?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
									<p class="asde-muted"><?php esc_html_e( 'This family already has', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo 1 === count( $familiares ) ? esc_html( __( 'one account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'two accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>: <?php echo wp_kses( implode( ' y ', array_map( static fn( $f ) => '<strong>' . esc_html( $f->user_login ) . '</strong>' . ( '' !== ( $emails_familiares[ $f->user_login ] ?? '' ) ? ' (' . esc_html( $emails_familiares[ $f->user_login ] ) . ')' : '' ), $familiares ) ), asde_html_permitido() ); ?>.</p>
										<?php if ( $propuesta['puede_nueva'] ) : ?>
											<label class="asde-choice">
												<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_NUEVA ); ?>" <?php checked( SolicitudService::MODO_NUEVA, $propuesta['modo'] ); ?>>
												<span><strong><?php esc_html_e( 'Other guardian', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': their own account is created (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( NumeroSocio::usuario_tutor( \AsdeBalears\AreaPrivada\Domain\Identidad::convertir( (string) NumeroSocio::socio_de_usuario_tutor( $familiares[0]->user_login ) ), 2 ) ); ?><?php esc_html_e( '), with their email and their password. They see the same participants and the other account does not change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
											</label>
										<?php endif; ?>
										<label class="asde-choice">
											<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_SUSTITUIR ); ?>" <?php checked( SolicitudService::MODO_SUSTITUIR, $propuesta['modo'] ); ?>>
											<span>
												<strong><?php esc_html_e( 'The same person with another email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': replace the account\'s email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
												<?php if ( count( $familiares ) > 1 ) : ?>
													<select name="cuenta" aria-label="<?php echo esc_attr( esc_attr__( 'Account whose email is replaced', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
														<?php foreach ( $familiares as $f ) : ?>
															<option value="<?php echo esc_attr( $f->user_login ); ?>" <?php selected( $propuesta['cuenta'], $f->user_login ); ?>><?php echo esc_html( $f->user_login ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<strong><?php echo esc_html( $familiares[0]->user_login ); ?></strong><input type="hidden" name="cuenta" value="<?php echo esc_attr( $familiares[0]->user_login ); ?>">
												<?php endif; ?>
												<?php esc_html_e( 'with the one on the request. That person will sign in with the new email and will choose a new password: the previous one will stop working.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
											</span>
										</label>
									</fieldset>
															</li>
						<?php else : ?>
							<li><?php esc_html_e( 'The family account will be created (the minor\'s member no. + T) and they will receive the invitation with the welcome. If the other guardian wants their own, they can request it in the same way from the same link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
						<?php endif; ?>
						<?php if ( $hermanos ) : ?>
							<li>
								<fieldset>
									<legend><?php esc_html_e( 'Siblings in the group (they share an email with the record)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
									<?php foreach ( $hermanos as $h ) : ?>
										<label class="asde-choice"><input type="checkbox" name="hermanos[]" value="<?php echo esc_attr( $h->num_socio_asde ); ?>"><span><?php echo esc_html( $h->nombre_completo() ); ?></span><?php foreach ( $h->secciones as $sec ) { echo wp_kses( Secciones::badge( $sec ), asde_html_permitido() ); } // phpcs:ignore ?></label>
									<?php endforeach; ?>
								</fieldset>
							</li>
						<?php endif; ?>
					<?php elseif ( $propia ) : ?>
						<li><?php esc_html_e( 'Already has the account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $propia->user_login ); ?></strong> (<?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $propia ) ) ); ?><?php esc_html_e( '): they will sign in with this email and will receive a link to choose a password.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
					<?php else : ?>
						<li><?php esc_html_e( 'Their participant account will be created (their member no.) and they will receive the invitation with the welcome.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
					<?php endif; ?>
				</ul>
				<p><button type="submit" class="button"><?php esc_html_e( 'Approve and send the invitation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php endif; ?>

		<details class="asde-rechazar">
			<summary><?php esc_html_e( 'Reject the request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="rechazar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<p>
					<label for="asde-sol-motivo"><?php esc_html_e( 'Reason (for the group only)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-sol-motivo" name="motivo" maxlength="250">
				</p>
				<p>
					<label class="asde-choice">
						<input type="checkbox" name="avisar" value="1" checked>
						<span><?php
							echo wp_kses( '' !== $contacto
								? sprintf( wp_kses_post( /* translators: 1: text, 2: text */ __( 'Notify by email whoever requested it (%1$s). The text is generic: it does not include the reason and tells them to write to %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( Contacto::enmascarar( (string) $d['email'] ) ), esc_html( $contacto ) ) 
								: sprintf( wp_kses_post( /* translators: %s: text */ __( 'Notify by email whoever requested it (%s). The text is generic: it does not include the reason.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( Contacto::enmascarar( (string) $d['email'] ) ) ), asde_html_permitido() );
							?></span>
					</label>
					<?php if ( '' === $contacto ) : ?>
						<span class="asde-help asde-help--warning"><?php esc_html_e( 'The group has no contact email: the notice will tell them to talk to the Secretary\'s office. It is set in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					<?php endif; ?>
				</p>
				<p><button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Reject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>
