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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>">← Solicitudes</a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Solicitud de acceso</h2>
		<span class="asde-badge asde-badge--estado-<?php echo esc_attr( $pendiente ? 'invitado' : ( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ) ); ?>"><?php echo esc_html( Solicitudes::ESTADOS[ $s['estado'] ] ?? $s['estado'] ); ?></span>
	</header>
	<p class="asde-muted">
		Recibida el <?php echo esc_html( wp_date( 'd/m/Y \a \l\a\s H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?>.
		<?php echo $tutor ? 'La pide su familia (madre, padre o tutor/a).' : 'La pide el propio socio/a.'; ?>
		<?php if ( $revisor ) : ?>
			<?php echo esc_html( sprintf( '%s por %s el %s.', Solicitudes::APROBADA === $s['estado'] ? 'Aprobada' : 'Rechazada', $revisor->display_name, wp_date( 'd/m/Y', strtotime( $s['revisada_en'] . ' UTC' ) ) ) ); ?>
			<?php echo '' !== $s['motivo'] ? esc_html( 'Motivo: ' . $s['motivo'] ) : ''; ?>
		<?php endif; ?>
	</p>

	<?php if ( ! $p ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">La ficha de esta solicitud ya no está en el CRM del grupo (o no tiene membresía activa).</div>
	<?php else : ?>
		<table class="asde-table asde-comparar">
			<thead><tr><th scope="col">Dato</th><th scope="col">Escrito en la solicitud</th><th scope="col">En el CRM</th></tr></thead>
			<tbody>
				<tr>
					<th scope="row">Socio/a</th>
					<td data-label="Escrito">
						<?php
						$escrito = Solicitudes::POR_NOMBRE === $s['via'] ? $d['nombre_menor'] : ( $tutor ? '' : $d['nombre'] );
						echo '' !== $escrito ? $marca( $c['nombre_socio'] ) . esc_html( $escrito ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
						?>
					</td>
					<td data-label="CRM">
						<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php
						foreach ( $p->secciones as $sec ) {
							echo Secciones::badge( $sec ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
						<br><span class="asde-help"><?php echo null === $p->edad() ? '' : (int) $p->edad() . ' años'; ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row">Comprobación</th>
					<td data-label="Escrito" colspan="2">
						<?php if ( Solicitudes::POR_NOMBRE === $s['via'] ) : ?>
							<?php echo $marca( (bool) $c['nacimiento'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Fecha de nacimiento y nombre y apellidos (menor de 14 años sin documento en el CRM).
						<?php else : ?>
							<?php echo $marca( (bool) $c['nacimiento'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> El nº de documento y la fecha de nacimiento coinciden con la ficha.
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Quién pide</th>
					<td data-label="Escrito"><?php echo ( $tutor ? $marca( $c['tutor'] > 0 ) : '' ) . esc_html( $d['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="CRM">
						<?php if ( $tutor ) : ?>
							<?php foreach ( array( 1, 2 ) as $n ) : ?>
								<?php $t = $p->tutores[ $n ] ?? null; ?>
								<?php if ( $t && '' !== trim( $t['nombre'] . $t['apellidos'] ) ) : ?>
									Tutor/a <?php echo (int) $n; ?>: <?php echo esc_html( trim( $t['nombre'] . ' ' . $t['apellidos'] ) ); ?><?php echo $n === $c['tutor'] ? ' <strong>(coincide)</strong>' : ''; ?><br>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( ! $c['tutor'] ) : ?>
								<span class="asde-help asde-help--warning">No coincide con ningún tutor/a de la ficha: confirma con la familia antes de aprobar.</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="asde-muted">La propia persona</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Email</th>
					<td data-label="Escrito"><?php echo ( null !== $c['email_en'] ? $marca( true ) : '' ) . esc_html( $d['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="CRM">
						<?php if ( null !== $c['email_en'] ) : ?>
							Ya está en la ficha: <?php echo esc_html( Usuarios::etiqueta_campo_email( $c['email_en'] ) ); ?>.
						<?php else : ?>
							<span class="asde-help asde-help--warning">No está en la ficha.</span>
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
			<h3>Dar acceso</h3>
			<p>Es scouter o voluntario/a: su acceso lo da Secretaría o Coordinación, eligiendo sus roles y secciones. La invitación irá al email de la solicitud.</p>
			<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( $url_alta ); ?>" data-asde-modal>Elegir roles y dar acceso</a></p>
			<?php endif; ?>
		<?php else : ?>
			<h3>Aprobar</h3>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="aprobar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<ul class="asde-lista-pasos">
					<?php if ( null === $c['email_en'] ) : ?>
						<li>
							<?php if ( $tutor ) : ?>
								<label for="asde-sol-campo">Se guardará el email en la ficha del CRM como</label>
								<select id="asde-sol-campo" name="campo">
									<?php foreach ( SolicitudService::CAMPOS[ $s['tipo'] ] as $campo ) : ?>
										<option value="<?php echo esc_attr( $campo ); ?>" <?php selected( ( $propuesta['campo'] ?? '' ) ?: $c['campo'], $campo ); ?>><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) . ( isset( $c['emails'][ $campo ] ) ? ' (ahora: ' . $c['emails'][ $campo ] . ')' : ' (ahora vacío)' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								Se guardará el email en la ficha del CRM (<?php echo esc_html( Usuarios::etiqueta_campo_email( 'email1' ) ); ?><?php echo isset( $c['emails']['email1'] ) ? ', ahora: ' . esc_html( $c['emails']['email1'] ) : ''; ?>).
							<?php endif; ?>
							<?php if ( ! $escritura ) : ?>
								<br><span class="asde-help asde-help--warning">El usuario del CRM del grupo no puede editar fichas: tendrás que anotarlo a mano en el CRM.</span>
							<?php endif; ?>
						</li>
					<?php endif; ?>
					<?php if ( $tutor ) : ?>
						<?php if ( $familiares ) : ?>
							<li>
								<fieldset>
										<legend>¿Quién pide el acceso?</legend>
									<p class="asde-muted">Esta familia ya tiene <?php echo 1 === count( $familiares ) ? "una cuenta" : "dos cuentas"; ?>: <?php echo implode( ' y ', array_map( static fn( $f ) => '<strong>' . esc_html( $f->user_login ) . '</strong>' . ( '' !== ( $emails_familiares[ $f->user_login ] ?? '' ) ? ' (' . esc_html( $emails_familiares[ $f->user_login ] ) . ')' : '' ), $familiares ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>.</p>
										<?php if ( $propuesta['puede_nueva'] ) : ?>
											<label class="asde-choice">
												<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_NUEVA ); ?>" <?php checked( SolicitudService::MODO_NUEVA, $propuesta['modo'] ); ?>>
												<span><strong>Otro tutor/a</strong>: se le crea su propia cuenta (<?php echo esc_html( NumeroSocio::usuario_tutor( \AsdeBalears\AreaPrivada\Domain\Identidad::convertir( (string) NumeroSocio::socio_de_usuario_tutor( $familiares[0]->user_login ) ), 2 ) ); ?>), con su email y su contraseña. Ve a los mismos participantes y la otra cuenta no cambia.</span>
											</label>
										<?php endif; ?>
										<label class="asde-choice">
											<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_SUSTITUIR ); ?>" <?php checked( SolicitudService::MODO_SUSTITUIR, $propuesta['modo'] ); ?>>
											<span>
												<strong>La misma persona con otro email</strong>: sustituir el email de la cuenta
												<?php if ( count( $familiares ) > 1 ) : ?>
													<select name="cuenta" aria-label="Cuenta cuyo email se sustituye">
														<?php foreach ( $familiares as $f ) : ?>
															<option value="<?php echo esc_attr( $f->user_login ); ?>" <?php selected( $propuesta['cuenta'], $f->user_login ); ?>><?php echo esc_html( $f->user_login ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<strong><?php echo esc_html( $familiares[0]->user_login ); ?></strong><input type="hidden" name="cuenta" value="<?php echo esc_attr( $familiares[0]->user_login ); ?>">
												<?php endif; ?>
												por el de la solicitud. Esa persona entrará con el nuevo email y elegirá una contraseña nueva: la anterior dejará de valer.
											</span>
										</label>
									</fieldset>
															</li>
						<?php else : ?>
							<li>Se creará la cuenta familiar (nº de socio/a del menor + T) y le llegará la invitación con la bienvenida. Si el otro tutor/a quiere la suya, podrá pedirla igual desde el mismo enlace.</li>
						<?php endif; ?>
						<?php if ( $hermanos ) : ?>
							<li>
								<fieldset>
									<legend>Hermanos/as en el grupo (comparten email con la ficha)</legend>
									<?php foreach ( $hermanos as $h ) : ?>
										<label class="asde-choice"><input type="checkbox" name="hermanos[]" value="<?php echo esc_attr( $h->num_socio_asde ); ?>"><span><?php echo esc_html( $h->nombre_completo() ); ?></span><?php foreach ( $h->secciones as $sec ) { echo Secciones::badge( $sec ); } // phpcs:ignore ?></label>
									<?php endforeach; ?>
								</fieldset>
							</li>
						<?php endif; ?>
					<?php elseif ( $propia ) : ?>
						<li>Ya tiene la cuenta <strong><?php echo esc_html( $propia->user_login ); ?></strong> (<?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $propia ) ) ); ?>): pasará a entrar con este email y le llegará un enlace para elegir contraseña.</li>
					<?php else : ?>
						<li>Se creará su cuenta de participante (su nº de socio/a) y le llegará la invitación con la bienvenida.</li>
					<?php endif; ?>
				</ul>
				<p><button type="submit" class="button">Aprobar y enviar la invitación</button></p>
			</form>
		<?php endif; ?>

		<details class="asde-rechazar">
			<summary>Rechazar la solicitud</summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="rechazar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<p>
					<label for="asde-sol-motivo">Motivo (solo para el grupo)</label>
					<input type="text" id="asde-sol-motivo" name="motivo" maxlength="250">
				</p>
				<p>
					<label class="asde-choice">
						<input type="checkbox" name="avisar" value="1" checked>
						<span>Avisar por email a quien la pidió (<?php echo esc_html( Contacto::enmascarar( (string) $d['email'] ) ); ?>). El texto es genérico: no lleva el motivo<?php echo '' !== $contacto ? ' y le dice que escriba a ' . esc_html( $contacto ) : ''; ?>.</span>
					</label>
					<?php if ( '' === $contacto ) : ?>
						<span class="asde-help asde-help--warning">El grupo no tiene email de contacto: el aviso le dirá que hable con la Secretaría. Se configura en wp-admin → Grupos Scouts.</span>
					<?php endif; ?>
				</p>
				<p><button type="submit" class="button asde-button-secundario">Rechazar</button></p>
			</form>
		</details>
	</section>
<?php endif; ?>
