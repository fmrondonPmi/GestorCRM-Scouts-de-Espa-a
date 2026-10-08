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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Solicitudes' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Solicitud de acceso' ); ?></h2>
		<span class="asde-badge asde-badge--estado-<?php echo esc_attr( $pendiente ? 'invitado' : ( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ) ); ?>"><?php echo esc_html( asde_t( Solicitudes::ESTADOS[ $s['estado'] ] ?? $s['estado'] ) ); ?></span>
	</header>
	<p class="asde-muted">
		<?php asde_e( 'Recibida el' ); ?> <?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?>.
		<?php echo $tutor ? esc_html( asde_t( 'La pide su familia (madre, padre o tutor/a).' ) ) : esc_html( asde_t( 'La pide el propio socio/a.' ) ); ?>
		<?php if ( $revisor ) : ?>
			<?php echo esc_html( sprintf( asde_t( '%s por %s el %s.' ), Solicitudes::APROBADA === $s['estado'] ? asde_t( 'Aprobada' ) : asde_t( 'Rechazada' ), $revisor->display_name, wp_date( 'd/m/Y', strtotime( $s['revisada_en'] . ' UTC' ) ) ) ); ?>
			<?php echo '' !== $s['motivo'] ? esc_html( sprintf( asde_t( 'Motivo: %s' ), $s['motivo'] ) ) : ''; ?>
		<?php endif; ?>
	</p>

	<?php if ( ! $p ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'La ficha de esta solicitud ya no está en el CRM del grupo (o no tiene membresía activa).' ); ?></div>
	<?php else : ?>
		<table class="asde-table asde-comparar">
			<thead><tr><th scope="col"><?php asde_e( 'Dato' ); ?></th><th scope="col"><?php asde_e( 'Escrito en la solicitud' ); ?></th><th scope="col"><?php asde_e( 'En el CRM' ); ?></th></tr></thead>
			<tbody>
				<tr>
					<th scope="row"><?php asde_e( 'Socio/a' ); ?></th>
					<td data-label="<?php echo esc_attr( asde_a( 'Escrito' ) ); ?>">
						<?php
						$escrito = Solicitudes::POR_NOMBRE === $s['via'] ? $d['nombre_menor'] : ( $tutor ? '' : $d['nombre'] );
						echo '' !== $escrito ? $marca( $c['nombre_socio'] ) . esc_html( $escrito ) : '<span class="asde-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
						?>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'CRM' ) ); ?>">
						<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php
						foreach ( $p->secciones as $sec ) {
							echo Secciones::badge( $sec ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
						<br><span class="asde-help"><?php echo null === $p->edad() ? '' : sprintf( esc_html( asde_t( '%d años' ) ), (int) $p->edad() ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php asde_e( 'Comprobación' ); ?></th>
					<td data-label="<?php echo esc_attr( asde_a( 'Escrito' ) ); ?>" colspan="2">
						<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
							<?php asde_e( 'Alta con el enlace para scouters: el DNI o NIE es el de esta ficha de Scouter o Voluntariado.' ); ?>
						<?php elseif ( Solicitudes::POR_NOMBRE === $s['via'] ) : ?>
							<?php echo $marca( (bool) $c['nacimiento'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php asde_e( 'Fecha de nacimiento y nombre y apellidos (menor de 14 años sin documento en el CRM).' ); ?>
						<?php else : ?>
							<?php echo $marca( (bool) $c['nacimiento'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php asde_e( 'El nº de documento y la fecha de nacimiento coinciden con la ficha.' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
					<tr>
						<th scope="row"><?php asde_e( 'Pide' ); ?></th>
						<td data-label="<?php echo esc_attr( asde_a( 'Escrito' ) ); ?>" colspan="2">
							<?php echo esc_html( implode( ', ', array_map( array( \AsdeBalears\AreaPrivada\Install\Roles::class, 'label' ), (array) ( $d['roles_pedidos'] ?? array() ) ) ) ?: '—' ); ?>
							<?php foreach ( (array) ( $d['secciones_pedidas'] ?? array() ) as $sec ) : ?>
								<?php echo Secciones::badge( (string) $sec ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endforeach; ?>
							<?php if ( ! empty( $d['cuenta_creada'] ) ) : ?>
								<br><span class="asde-help"><?php asde_e( 'Su cuenta de scouter ya está creada (le llegó la activación al email de su ficha). Revisa sus secciones y confirma las responsabilidades que pide, o cierra la solicitud si no hay nada que cambiar.' ); ?></span>
							<?php elseif ( ! empty( $d['error_alta'] ) ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( sprintf( asde_t( 'No se pudo crear la cuenta al momento: %s' ), (string) $d['error_alta'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php asde_e( 'Quién pide' ); ?></th>
					<td data-label="<?php echo esc_attr( asde_a( 'Escrito' ) ); ?>"><?php echo ( $tutor ? $marca( $c['tutor'] > 0 ) : '' ) . esc_html( $d['nombre'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'CRM' ) ); ?>">
						<?php if ( $tutor ) : ?>
							<?php foreach ( array( 1, 2 ) as $n ) : ?>
								<?php $t = $p->tutores[ $n ] ?? null; ?>
								<?php if ( $t && '' !== trim( $t['nombre'] . $t['apellidos'] ) ) : ?>
									<?php asde_e( 'Tutor/a' ); ?> <?php echo (int) $n; ?>: <?php echo esc_html( trim( $t['nombre'] . ' ' . $t['apellidos'] ) ); ?><?php echo $n === $c['tutor'] ? ' <strong>(coincide)</strong>' : ''; ?><br>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( ! $c['tutor'] ) : ?>
								<span class="asde-help asde-help--warning"><?php asde_e( 'No coincide con ningún tutor/a de la ficha: confirma con la familia antes de aprobar.' ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="asde-muted"><?php asde_e( 'La propia persona' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php asde_e( 'Email' ); ?></th>
					<td data-label="<?php echo esc_attr( asde_a( 'Escrito' ) ); ?>"><?php echo ( null !== $c['email_en'] ? $marca( true ) : '' ) . esc_html( $d['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'CRM' ) ); ?>">
						<?php if ( null !== $c['email_en'] ) : ?>
							<?php asde_e( 'Ya está en la ficha:' ); ?> <?php echo esc_html( Usuarios::etiqueta_campo_email( $c['email_en'] ) ); ?>.
						<?php else : ?>
							<span class="asde-help asde-help--warning"><?php asde_e( 'No está en la ficha.' ); ?></span>
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
			<h3><?php asde_e( 'Dar acceso' ); ?></h3>
			<p><?php asde_e( 'Es scouter o voluntario/a: su acceso lo da Secretaría o Coordinación, eligiendo sus roles y secciones. La invitación irá al email de la solicitud.' ); ?></p>
			<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( $url_alta ); ?>" data-asde-modal><?php asde_e( 'Elegir roles y dar acceso' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<h3><?php asde_e( 'Aprobar' ); ?></h3>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="aprobar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<ul class="asde-lista-pasos">
					<?php if ( null === $c['email_en'] ) : ?>
						<li>
							<?php if ( $tutor ) : ?>
								<label for="asde-sol-campo"><?php asde_e( 'Se guardará el email en la ficha del CRM como' ); ?></label>
								<select id="asde-sol-campo" name="campo">
									<?php foreach ( SolicitudService::CAMPOS[ $s['tipo'] ] as $campo ) : ?>
										<option value="<?php echo esc_attr( $campo ); ?>" <?php selected( ( $propuesta['campo'] ?? '' ) ?: $c['campo'], $campo ); ?>><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) . ( isset( $c['emails'][ $campo ] ) ? ' (ahora: ' . $c['emails'][ $campo ] . ')' : asde_t( ' (ahora vacío)' ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<?php asde_e( 'Se guardará el email en la ficha del CRM (' ); ?><?php echo esc_html( Usuarios::etiqueta_campo_email( 'email1' ) ); ?><?php echo isset( $c['emails']['email1'] ) ? ', ahora: ' . esc_html( $c['emails']['email1'] ) : ''; ?>).
							<?php endif; ?>
							<?php if ( ! $escritura ) : ?>
								<br><span class="asde-help asde-help--warning"><?php asde_e( 'El usuario del CRM del grupo no puede editar fichas: tendrás que anotarlo a mano en el CRM.' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endif; ?>
					<?php if ( $tutor ) : ?>
						<?php if ( $familiares ) : ?>
							<li>
								<fieldset>
										<legend><?php asde_e( '¿Quién pide el acceso?' ); ?></legend>
									<p class="asde-muted"><?php asde_e( 'Esta familia ya tiene' ); ?> <?php echo 1 === count( $familiares ) ? esc_html( asde_t( "una cuenta" ) ) : esc_html( asde_t( "dos cuentas" ) ); ?>: <?php echo implode( ' y ', array_map( static fn( $f ) => '<strong>' . esc_html( $f->user_login ) . '</strong>' . ( '' !== ( $emails_familiares[ $f->user_login ] ?? '' ) ? ' (' . esc_html( $emails_familiares[ $f->user_login ] ) . ')' : '' ), $familiares ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>.</p>
										<?php if ( $propuesta['puede_nueva'] ) : ?>
											<label class="asde-choice">
												<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_NUEVA ); ?>" <?php checked( SolicitudService::MODO_NUEVA, $propuesta['modo'] ); ?>>
												<span><strong><?php asde_e( 'Otro tutor/a' ); ?></strong><?php asde_e( ': se le crea su propia cuenta (' ); ?><?php echo esc_html( NumeroSocio::usuario_tutor( \AsdeBalears\AreaPrivada\Domain\Identidad::convertir( (string) NumeroSocio::socio_de_usuario_tutor( $familiares[0]->user_login ) ), 2 ) ); ?><?php asde_e( '), con su email y su contraseña. Ve a los mismos participantes y la otra cuenta no cambia.' ); ?></span>
											</label>
										<?php endif; ?>
										<label class="asde-choice">
											<input type="radio" name="modo" value="<?php echo esc_attr( SolicitudService::MODO_SUSTITUIR ); ?>" <?php checked( SolicitudService::MODO_SUSTITUIR, $propuesta['modo'] ); ?>>
											<span>
												<strong><?php asde_e( 'La misma persona con otro email' ); ?></strong><?php asde_e( ': sustituir el email de la cuenta' ); ?>
												<?php if ( count( $familiares ) > 1 ) : ?>
													<select name="cuenta" aria-label="<?php echo esc_attr( asde_a( 'Cuenta cuyo email se sustituye' ) ); ?>">
														<?php foreach ( $familiares as $f ) : ?>
															<option value="<?php echo esc_attr( $f->user_login ); ?>" <?php selected( $propuesta['cuenta'], $f->user_login ); ?>><?php echo esc_html( $f->user_login ); ?></option>
														<?php endforeach; ?>
													</select>
												<?php else : ?>
													<strong><?php echo esc_html( $familiares[0]->user_login ); ?></strong><input type="hidden" name="cuenta" value="<?php echo esc_attr( $familiares[0]->user_login ); ?>">
												<?php endif; ?>
												<?php asde_e( 'por el de la solicitud. Esa persona entrará con el nuevo email y elegirá una contraseña nueva: la anterior dejará de valer.' ); ?>
											</span>
										</label>
									</fieldset>
															</li>
						<?php else : ?>
							<li><?php asde_e( 'Se creará la cuenta familiar (nº de socio/a del menor + T) y le llegará la invitación con la bienvenida. Si el otro tutor/a quiere la suya, podrá pedirla igual desde el mismo enlace.' ); ?></li>
						<?php endif; ?>
						<?php if ( $hermanos ) : ?>
							<li>
								<fieldset>
									<legend><?php asde_e( 'Hermanos/as en el grupo (comparten email con la ficha)' ); ?></legend>
									<?php foreach ( $hermanos as $h ) : ?>
										<label class="asde-choice"><input type="checkbox" name="hermanos[]" value="<?php echo esc_attr( $h->num_socio_asde ); ?>"><span><?php echo esc_html( $h->nombre_completo() ); ?></span><?php foreach ( $h->secciones as $sec ) { echo Secciones::badge( $sec ); } // phpcs:ignore ?></label>
									<?php endforeach; ?>
								</fieldset>
							</li>
						<?php endif; ?>
					<?php elseif ( $propia ) : ?>
						<li><?php asde_e( 'Ya tiene la cuenta' ); ?> <strong><?php echo esc_html( $propia->user_login ); ?></strong> (<?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $propia ) ) ); ?><?php asde_e( '): pasará a entrar con este email y le llegará un enlace para elegir contraseña.' ); ?></li>
					<?php else : ?>
						<li><?php asde_e( 'Se creará su cuenta de participante (su nº de socio/a) y le llegará la invitación con la bienvenida.' ); ?></li>
					<?php endif; ?>
				</ul>
				<p><button type="submit" class="button"><?php asde_e( 'Aprobar y enviar la invitación' ); ?></button></p>
			</form>
		<?php endif; ?>

		<details class="asde-rechazar">
			<summary><?php asde_e( 'Rechazar la solicitud' ); ?></summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_solicitud_' . $s['id'] ); ?>
				<input type="hidden" name="asde_action" value="rechazar_solicitud">
				<input type="hidden" name="solicitud" value="<?php echo (int) $s['id']; ?>">
				<p>
					<label for="asde-sol-motivo"><?php asde_e( 'Motivo (solo para el grupo)' ); ?></label>
					<input type="text" id="asde-sol-motivo" name="motivo" maxlength="250">
				</p>
				<p>
					<label class="asde-choice">
						<input type="checkbox" name="avisar" value="1" checked>
						<span><?php
							echo '' !== $contacto
								? sprintf( wp_kses_post( asde_h( 'Avisar por email a quien la pidió (%1$s). El texto es genérico: no lleva el motivo y le dice que escriba a %2$s.' ) ), esc_html( Contacto::enmascarar( (string) $d['email'] ) ), esc_html( $contacto ) ) // phpcs:ignore WordPress.Security.EscapeOutput
								: sprintf( wp_kses_post( asde_h( 'Avisar por email a quien la pidió (%s). El texto es genérico: no lleva el motivo.' ) ), esc_html( Contacto::enmascarar( (string) $d['email'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							?></span>
					</label>
					<?php if ( '' === $contacto ) : ?>
						<span class="asde-help asde-help--warning"><?php asde_e( 'El grupo no tiene email de contacto: el aviso le dirá que hable con la Secretaría. Se configura en wp-admin → Grupos Scouts.' ); ?></span>
					<?php endif; ?>
				</p>
				<p><button type="submit" class="button asde-button-secundario"><?php asde_e( 'Rechazar' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>
