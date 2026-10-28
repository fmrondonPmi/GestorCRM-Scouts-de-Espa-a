<?php
/**
 * Alta de usuarios · ficha de un socio del CRM.
 *
 * @var AsdeBalears\AreaPrivada\Domain\Persona   $p
 * @var array{numero:?string, estado:string}     $numero      Nº de socio local y su estado
 * @var array<string, ?string>                   $eleg        rol => motivo de no elegibilidad (null = elegible)
 * @var ?string                                  $motivo_rep  motivo por el que no puede tener cuenta familiar
 * @var ?WP_User                                 $vinculado   usuario web de la propia persona
 * @var ?WP_User                                 $cuenta_familiar
 * @var WP_User[]                                $cuentas_familiares Las cuentas de tutor/a de la familia (T y T2)
 * @var array<string, string>                    $emails_otro campo => email de la ficha que aún no es de ninguna cuenta familiar
 * @var string                                   $usuario_familiar Usuario de la cuenta familiar (existente o a crear)
 * @var array<string, string>                    $emails_persona campo => email
 * @var array<string, string>                    $emails_familia campo => email
 * @var int                                      $email_compartido
 * @var AsdeBalears\AreaPrivada\Domain\Persona[] $familia     posibles hermanos/as
 * @var int                                      $edad_minima
 * @var string                                   $url_volver
 * @var string                                   $url_usuarios
 * @var string                                   $url_personas
 * @var array|null                               $solicitud   Solicitud de acceso pendiente de esta persona (v0.10)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\Numeros;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

$edad        = $p->edad();
$alguno      = in_array( null, $eleg, true );
$secc_actual = $vinculado ? Usuarios::secciones( $vinculado->ID ) : array();
// Con el nº de Scouts de España como usuario, un problema del nº territorial solo se avisa: no impide el acceso (doc 27).
$bloqueado   = ! Identidad::es_asde() && in_array( $numero['estado'], array( Numeracion::DUPLICADO, Numeracion::INVALIDO ), true );
$tiene_rol   = static fn( string $rol ) => $vinculado && in_array( $rol, (array) $vinculado->roles, true );
$familia_tes = FamiliasTable::de_socio( $p->num_socio_asde );

// Roles sugeridos por el cargo que figura en el CRM (las responsabilidades requieren Scouter).
$sugeridos = array();
if ( isset( AltaService::ROL_POR_CARGO[ $p->cargo ] ) ) {
	$sugeridos[] = AltaService::ROL_POR_CARGO[ $p->cargo ];
	if ( in_array( AltaService::ROL_POR_CARGO[ $p->cargo ], AltaService::ROLES_RESPONSABILIDAD, true ) ) {
		$sugeridos[] = Roles::SCOUTER;
	}
}
// Alta con el enlace para scouters (v0.16, doc 31): vienen marcados los roles y las secciones que pidió.
if ( $solicitud && \AsdeBalears\AreaPrivada\Usuarios\Solicitudes::POR_ENLACE === $solicitud['via'] ) {
	$sugeridos = array_merge( $sugeridos, array_values( array_intersect( AltaService::ROLES_PERSONA, (array) ( $solicitud['datos']['roles_pedidos'] ?? array() ) ) ) );
	$pedidas   = array_values( array_intersect( array_merge( Secciones::EDUCATIVAS, array( Secciones::SIN_SECCION ) ), (array) ( $solicitud['datos']['secciones_pedidas'] ?? array() ) ) );
	if ( $pedidas ) {
		$secc_actual = array_values( array_unique( array_merge( $secc_actual, $pedidas ) ) );
	}
}
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_volver ); ?>"><?php esc_html_e( '← Back to the search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card" data-asde-plegable="dar-acceso-persona">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?></h2>
		<?php
		foreach ( $p->secciones as $s ) {
			echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
		}
		?>
	</header>
	<dl class="asde-datos">
		<div>
			<dt><?php echo Identidad::es_asde() ? esc_html( __( 'Scouts de España member no. (username)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Local member no. (username)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></dt>
			<dd>
				<?php echo wp_kses( Numeros::html( $p, $numero ), asde_html_permitido() ); ?>
				<?php // El aviso del problema ya lo da Numeros::html (⚠); aquí solo lo que se hace con él. ?>
				<?php if ( Numeracion::FALTA === $numero['estado'] && ! Identidad::es_asde() ) : ?>
					<br><span class="asde-help"><?php esc_html_e( 'It will be generated when access is given.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo null === $edad ? '—' : esc_html( sprintf( '%d años (%s)', $edad, wp_date( 'd/m/Y', strtotime( $p->fecha_nacimiento . ' 12:00' ) ) ) ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo $p->email ? esc_html( $p->email ) : '—'; ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo $p->movil ? esc_html( $p->movil ) : '—'; ?></dd>
		</div>
		<?php if ( $p->cargo ) : ?>
			<div>
				<dt><?php esc_html_e( 'Role in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
				<?php /* v0.25.1: un cargo que no está en la lista se enseña tal cual (antes, error 500). */ ?>
				<dd><?php echo esc_html( asde_t( Catalogo::ENUMS['cargoeducativo_c'][ $p->cargo ] ?? $p->cargo ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php foreach ( $p->tutores as $n => $t ) : ?>
			<div>
				<dt><?php esc_html_e( 'Guardian', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $n; ?></dt>
				<dd>
					<?php echo esc_html( trim( $t['nombre'] . ' ' . $t['apellidos'] ) ?: '—' ); ?>
					<?php if ( $t['email'] ) : ?>
						<br><span class="asde-muted"><?php echo esc_html( $t['email'] ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<p class="asde-muted"><?php esc_html_e( 'CRM data, read-only: not stored on the website. If something is wrong, correct it in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( $bloqueado ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php esc_html_e( 'The local member number on this record is', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( mb_strtolower( asde_t( Numeracion::ETIQUETAS[ $numero['estado'] ] ) ) ); ?></strong>
			<?php esc_html_e( 'and cannot be used as a username. Correct it in the CRM ("Local/territorial member no." field); you can see all the incidents in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'incidencias', $url_personas ) ); ?>"><?php esc_html_e( 'People', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Current web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $vinculado ) : ?>
		<p>
			<?php esc_html_e( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $vinculado->user_login ); ?></strong>
			<?php foreach ( Usuarios::roles_asde( $vinculado ) as $rol ) : ?>
				<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $rol ) ); ?></span>
			<?php endforeach; ?>
			<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Usuarios::estado( $vinculado ) ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $vinculado ) ) ); ?></span>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'Has no username of their own.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php if ( $familia_tes ) : ?>
		<p><?php esc_html_e( 'Treasury family:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $familia_tes['numero'] ); ?></strong></p>
	<?php endif; ?>
	<?php if ( $cuenta_familiar ) : ?>
		<p>
			<?php esc_html_e( 'Family account:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $cuenta_familiar->user_login ); ?></strong>
			<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Usuarios::estado( $cuenta_familiar ) ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $cuenta_familiar ) ) ); ?></span>
			<?php $en_cuenta = in_array( $p->num_socio_asde, Usuarios::representados( $cuenta_familiar->ID ), true ); ?>
			<?php echo $en_cuenta ? '' : '<span class="asde-muted">' . esc_html( __( '(of a sibling; does not yet include this person)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?>
		</p>
	<?php endif; ?>
	<p class="asde-muted"><a href="<?php echo esc_url( $url_usuarios ); ?>"><?php esc_html_e( 'See all the users of the private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Give access to this person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $alguno ) : ?>
		<p><?php esc_html_e( 'They cannot be given their own access:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<ul>
			<?php foreach ( array_unique( array_filter( $eleg ) ) as $motivo ) : ?>
				<li><?php echo esc_html( $motivo ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $bloqueado ) : ?>
		<p><?php esc_html_e( 'Their member number must be corrected in the CRM first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form" data-asde-alta-persona>
			<?php wp_nonce_field( 'asde_alta_persona_' . $p->num_socio_asde ); ?>
			<input type="hidden" name="asde_action" value="alta_persona">
			<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">

			<fieldset>
				<legend><?php esc_html_e( 'Role or roles', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<?php foreach ( $eleg as $rol => $motivo ) : ?>
					<?php
					$tiene    = $tiene_rol( $rol );
					$sugerido = ! $tiene && null === $motivo && in_array( $rol, $sugeridos, true );
					?>
					<label class="asde-choice<?php echo null !== $motivo ? ' is-disabled' : ''; ?>">
						<input type="checkbox" name="roles[]" value="<?php echo esc_attr( $rol ); ?>" <?php disabled( null !== $motivo || $tiene ); ?> <?php checked( $tiene || $sugerido ); ?>>
						<span>
							<?php echo esc_html( Roles::label( $rol ) ); ?>
							<?php if ( $tiene ) : ?>
								<span class="asde-help"><?php esc_html_e( 'Already has this role.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php elseif ( null !== $motivo ) : ?>
								<span class="asde-help"><?php echo esc_html( $motivo ); ?></span>
							<?php elseif ( in_array( $rol, AltaService::ROLES_RESPONSABILIDAD, true ) ) : ?>
								<span class="asde-help"><?php esc_html_e( 'Also requires the Scouter role, or choose "Volunteer without a section".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $sugerido ? esc_html( __( ' Suggested by their role in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?></span>
							<?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<?php if ( null === $eleg[ Roles::SCOUTER ] ) : ?>
				<fieldset data-asde-secciones>
					<legend><?php esc_html_e( 'Sections they help in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<p class="asde-help"><?php esc_html_e( 'The CRM does not store which section each scouter helps in: state it here. In reports they will see the members of these sections.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
						<label class="asde-choice">
							<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $secc_actual, true ) ); ?>>
							<span><?php echo wp_kses( Secciones::badge( $slug ), asde_html_permitido() ); ?></span>
						</label>
					<?php endforeach; ?>
					<label class="asde-choice asde-choice--separada">
						<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( Secciones::SIN_SECCION ); ?>" data-asde-sin-seccion <?php checked( in_array( Secciones::SIN_SECCION, $secc_actual, true ) ); ?>>
						<span>
							<?php echo wp_kses( Secciones::badge( Secciones::SIN_SECCION ), asde_html_permitido() ); ?>
							<span class="asde-help"><?php esc_html_e( 'Helps without a section: they can only be given access as Treasury, Secretary\'s office, Coordination or Quartermaster (without the Scouter role).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						</span>
					</label>
				</fieldset>
			<?php endif; ?>

			<?php if ( $vinculado && $solicitud ) : ?>
				<?php // Ya tiene cuenta y hay una solicitud suya pendiente (v0.12.3): al guardar, la solicitud queda resuelta; su email no cambia. ?>
				<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
				<p class="asde-help"><?php esc_html_e( 'Has a', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'pending access request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> (<?php echo esc_html( $solicitud['datos']['email'] ); ?><?php esc_html_e( '). When the changes are saved the request is considered resolved; the email of their account does not change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $vinculado ) : ?>
				<fieldset>
					<legend><?php esc_html_e( 'Email for the invitation (also works for signing in)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<?php if ( $solicitud ) : ?>
						<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
						<label class="asde-choice">
							<input type="radio" name="campo_email" value="solicitud" checked>
							<span><?php echo esc_html( $solicitud['datos']['email'] ); ?> <span class="asde-help"><?php esc_html_e( 'the one on their access request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo in_array( $solicitud['datos']['email'], $emails_persona, true ) ? '' : esc_html( __( ': it will also be saved on the CRM record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span></span>
						</label>
					<?php endif; ?>
					<?php if ( ! $emails_persona && ! $solicitud ) : ?>
						<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The record has no email in the CRM. Add it in the CRM and come back to this page.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<?php foreach ( $emails_persona as $campo => $email ) : ?>
							<?php if ( $solicitud && $email === $solicitud['datos']['email'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<label class="asde-choice">
								<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( ! $solicitud ); ?>>
								<span><?php echo esc_html( $email ); ?> <span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php esc_html_e( 'in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></span>
							</label>
						<?php endforeach; ?>
						<?php if ( $email_compartido > 1 ) : ?>
							<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ This email address appears in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $email_compartido; ?> <?php esc_html_e( 'CRM records: it may be the family\'s. For a personal account it is best if the CRM shows the person\'s own email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php endif; ?>
						<p class="asde-help"><?php
						if ( Identidad::es_asde() ) {
							echo wp_kses( sprintf( wp_kses_post( /* translators: %s: text */ __( 'The username will be their Scouts de España member number (<strong>%s</strong>); they will also be able to sign in with the chosen email, which is stored encrypted on the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( $p->num_socio_asde ) ), asde_html_permitido() );
						} elseif ( $numero['numero'] ) {
							echo wp_kses( sprintf( wp_kses_post( /* translators: %s: text */ __( 'The username will be their member number (<strong>%s</strong>); they will also be able to sign in with the chosen email, which is stored encrypted on the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( $numero['numero'] ) ), asde_html_permitido() );
						} else {
							echo wp_kses( wp_kses_post( __( 'The username will be their member number, which will be generated automatically; they will also be able to sign in with the chosen email, which is stored encrypted on the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), asde_html_permitido() );
						}
						?></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<p><button type="submit" class="button" <?php disabled( ! $vinculado && ! $emails_persona && ! $solicitud ); ?>><?php echo $vinculado ? esc_html( __( 'Save changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Give access and send invitation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button></p>
		</form>
		<?php if ( $vinculado && $solicitud ) : ?>
			<?php // Si ya tiene el acceso que pide, no hay nada que cambiar: basta cerrar la solicitud. ?>
			<form method="post" class="asde-actions">
				<?php wp_nonce_field( 'asde_cerrar_solicitud_' . $p->num_socio_asde ); ?>
				<input type="hidden" name="asde_action" value="cerrar_solicitud">
				<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
				<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Already has the access requested: close the request without changing anything', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Family account (guardians)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( null !== $motivo_rep ) : ?>
		<p><?php echo esc_html( $motivo_rep ); ?></p>
	<?php elseif ( $bloqueado && ! $cuenta_familiar ) : ?>
		<p><?php esc_html_e( 'Their member number must be corrected in the CRM first (the family account uses that number).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<p>
			<?php esc_html_e( 'The family signs in with the username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $usuario_familiar ?: __( 'the minor\'s member number + T', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong>
			<?php echo $cuenta_familiar ? esc_html( __( '(existing account: the participants ticked will be added to it).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( '(the minor\'s member number + T) or with the chosen email. If they have siblings in the group, one account works for all of them; the other guardian can have their own (number + T2).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		</p>
		<form method="post" class="asde-form" data-asde-alta-tutor>
			<?php wp_nonce_field( 'asde_alta_tutor_' . $p->num_socio_asde ); ?>
			<input type="hidden" name="asde_action" value="alta_tutor">
			<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">

			<fieldset>
				<legend><?php esc_html_e( 'Participants of the account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<label class="asde-choice">
					<input type="checkbox" checked disabled>
					<span><?php echo esc_html( $p->nombre_completo() ); ?> <?php echo wp_kses( $p->seccion_educativa() ? Secciones::badge( $p->seccion_educativa() ) : '', asde_html_permitido() ); ?></span>
				</label>
				<?php if ( $familia ) : ?>
					<p class="asde-help"><?php esc_html_e( 'Possible siblings (they share an email in the CRM). Tick them if they are from the same family:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php $ya_en_cuenta = $cuenta_familiar ? Usuarios::representados( $cuenta_familiar->ID ) : array(); ?>
					<?php foreach ( $familia as $f ) : ?>
						<?php $fijo = in_array( $f->num_socio_asde, $ya_en_cuenta, true ); ?>
						<label class="asde-choice">
							<?php if ( $fijo ) : ?>
								<input type="hidden" name="hermanos[]" value="<?php echo esc_attr( $f->num_socio_asde ); ?>">
							<?php endif; ?>
							<input type="checkbox" name="<?php echo $fijo ? '' : 'hermanos[]'; ?>" value="<?php echo esc_attr( $f->num_socio_asde ); ?>" <?php checked( $fijo ); ?> <?php disabled( $fijo ); ?>>
							<span>
								<?php echo esc_html( $f->nombre_completo() ); ?>
								<?php echo wp_kses( $f->seccion_educativa() ? Secciones::badge( $f->seccion_educativa() ) : '', asde_html_permitido() ); ?>
								<?php if ( null !== $f->edad() ) : ?>
									<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f->edad() ) ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</fieldset>

			<?php if ( ! $cuenta_familiar ) : ?>
				<fieldset>
					<legend><?php esc_html_e( 'Family email (for the invitation, for signing in and for recovering the password)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<?php if ( ! $emails_familia ) : ?>
						<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The record has no email at all in the CRM (neither their own nor guardians\'). Add it in the CRM and come back to this page.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<?php $i = 0; ?>
						<?php foreach ( $emails_familia as $campo => $email ) : ?>
							<label class="asde-choice">
								<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( 0 === $i++ ); ?>>
								<span>
									<?php echo esc_html( $email ); ?>
									<span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php esc_html_e( 'in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<?php
									$n = (int) substr( $campo, 5, 1 );
									echo ( str_starts_with( $campo, 'tutor' ) && ! empty( $p->tutores[ $n ]['nombre'] ) ) ? ' · ' . esc_html( trim( $p->tutores[ $n ]['nombre'] . ' ' . $p->tutores[ $n ]['apellidos'] ) ) : '';
									?>
									</span>
								</span>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</fieldset>
			<?php else : ?>
				<input type="hidden" name="campo_email" value="<?php echo esc_attr( Usuarios::email_origen( $cuenta_familiar->ID )['campo'] ?? 'email1' ); ?>">
			<?php endif; ?>

			<p><button type="submit" class="button" <?php disabled( ! $cuenta_familiar && ! $emails_familia ); ?>><?php echo $cuenta_familiar ? esc_html( __( 'Add to the family account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Create family account and send invitation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button></p>
		</form>

		<?php if ( $cuenta_familiar ) : ?>
			<hr>
			<h4><?php esc_html_e( 'Another guardian with their own account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<?php if ( count( $cuentas_familiares ) >= Usuarios::MAX_CUENTAS_FAMILIARES ) : ?>
				<p><?php esc_html_e( 'This family already has both guardian accounts:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( implode( ' ' . esc_html( __( 'and', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' ', array_map( static fn( $c ) => '<strong>' . esc_html( $c->user_login ) . '</strong>', $cuentas_familiares ) ), asde_html_permitido() ); ?><?php esc_html_e( '. Each one signs in with their own email and sees the same participants.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'If the other guardian (mother, father…) wants to sign in with their own email and their own password, a second account is created for them (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><strong><?php echo esc_html( \AsdeBalears\AreaPrivada\Domain\NumeroSocio::usuario_tutor( \AsdeBalears\AreaPrivada\Domain\Identidad::convertir( (string) \AsdeBalears\AreaPrivada\Domain\NumeroSocio::socio_de_usuario_tutor( $cuenta_familiar->user_login ) ), 2 ) ); ?></strong><?php esc_html_e( ') who sees the same participants and shares the Treasury family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</p>
				<?php if ( ! $emails_otro ) : ?>
					<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The record has no other email in the CRM (the first account\'s is already there). Add the other guardian\'s in the CRM and come back to this page, or let them request it from "Request access".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php else : ?>
					<form method="post" class="asde-form">
						<?php wp_nonce_field( 'asde_alta_tutor_' . $p->num_socio_asde ); ?>
						<input type="hidden" name="asde_action" value="alta_tutor">
						<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
						<input type="hidden" name="otro_tutor" value="1">
						<fieldset>
							<legend><?php esc_html_e( 'Email of the other guardian', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
							<?php $i = 0; ?>
							<?php foreach ( $emails_otro as $campo => $email ) : ?>
								<label class="asde-choice">
									<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( 0 === $i++ ); ?>>
									<span><?php echo esc_html( $email ); ?> <span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php esc_html_e( 'in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p><button type="submit" class="button"><?php esc_html_e( 'Create the other guardian\'s account and send invitation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</section>
