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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_volver ); ?>"><?php asde_e( '← Volver a la búsqueda' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?></h2>
		<?php
		foreach ( $p->secciones as $s ) {
			echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML escapado en badge().
		}
		?>
	</header>
	<dl class="asde-datos">
		<div>
			<dt><?php echo Identidad::es_asde() ? esc_html( asde_t( 'Nº socio/a Scouts de España (usuario)' ) ) : esc_html( asde_t( 'Nº socio/a local (usuario)' ) ); ?></dt>
			<dd>
				<?php echo Numeros::html( $p, $numero ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php // El aviso del problema ya lo da Numeros::html (⚠); aquí solo lo que se hace con él. ?>
				<?php if ( Numeracion::FALTA === $numero['estado'] && ! Identidad::es_asde() ) : ?>
					<br><span class="asde-help"><?php asde_e( 'Se generará al darle acceso.' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php asde_e( 'Edad' ); ?></dt>
			<dd><?php echo null === $edad ? '—' : esc_html( sprintf( '%d años (%s)', $edad, wp_date( 'd/m/Y', strtotime( $p->fecha_nacimiento . ' 12:00' ) ) ) ); ?></dd>
		</div>
		<div>
			<dt><?php asde_e( 'Email' ); ?></dt>
			<dd><?php echo $p->email ? esc_html( $p->email ) : '—'; ?></dd>
		</div>
		<div>
			<dt><?php asde_e( 'Móvil' ); ?></dt>
			<dd><?php echo $p->movil ? esc_html( $p->movil ) : '—'; ?></dd>
		</div>
		<?php if ( $p->cargo ) : ?>
			<div>
				<dt><?php asde_e( 'Cargo en el CRM' ); ?></dt>
				<?php /* v0.25.1: un cargo que no está en la lista se enseña tal cual (antes, error 500). */ ?>
				<dd><?php echo esc_html( asde_t( Catalogo::ENUMS['cargoeducativo_c'][ $p->cargo ] ?? $p->cargo ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php foreach ( $p->tutores as $n => $t ) : ?>
			<div>
				<dt><?php asde_e( 'Tutor/a' ); ?> <?php echo (int) $n; ?></dt>
				<dd>
					<?php echo esc_html( trim( $t['nombre'] . ' ' . $t['apellidos'] ) ?: '—' ); ?>
					<?php if ( $t['email'] ) : ?>
						<br><span class="asde-muted"><?php echo esc_html( $t['email'] ); ?></span>
					<?php endif; ?>
				</dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<p class="asde-muted"><?php asde_e( 'Datos del CRM, solo lectura: no se guardan en la web. Si algo es incorrecto, corrígelo en el CRM.' ); ?></p>
	<?php if ( $bloqueado ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php asde_e( 'El nº de socio/a local de esta ficha está' ); ?> <strong><?php echo esc_html( mb_strtolower( asde_t( Numeracion::ETIQUETAS[ $numero['estado'] ] ) ) ); ?></strong>
			<?php asde_e( 'y no se puede usar como usuario. Corrígelo en el CRM (campo «Nº de socio/a local/territorial»); puedes ver todas las incidencias en' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'incidencias', $url_personas ) ); ?>"><?php asde_e( 'Personas' ); ?></a>.
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Acceso web actual' ); ?></h3>
	<?php if ( $vinculado ) : ?>
		<p>
			<?php asde_e( 'Usuario' ); ?> <strong><?php echo esc_html( $vinculado->user_login ); ?></strong>
			<?php foreach ( Usuarios::roles_asde( $vinculado ) as $rol ) : ?>
				<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $rol ) ); ?></span>
			<?php endforeach; ?>
			<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Usuarios::estado( $vinculado ) ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $vinculado ) ) ); ?></span>
		</p>
	<?php else : ?>
		<p><?php asde_e( 'No tiene usuario propio.' ); ?></p>
	<?php endif; ?>
	<?php if ( $familia_tes ) : ?>
		<p><?php asde_e( 'Familia de Tesorería:' ); ?> <strong><?php echo esc_html( $familia_tes['numero'] ); ?></strong></p>
	<?php endif; ?>
	<?php if ( $cuenta_familiar ) : ?>
		<p>
			<?php asde_e( 'Cuenta familiar:' ); ?> <strong><?php echo esc_html( $cuenta_familiar->user_login ); ?></strong>
			<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Usuarios::estado( $cuenta_familiar ) ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $cuenta_familiar ) ) ); ?></span>
			<?php $en_cuenta = in_array( $p->num_socio_asde, Usuarios::representados( $cuenta_familiar->ID ), true ); ?>
			<?php echo $en_cuenta ? '' : '<span class="asde-muted">' . esc_html( asde_t( '(de un hermano/a; aún no incluye a esta persona)' ) ) . '</span>'; ?>
		</p>
	<?php endif; ?>
	<p class="asde-muted"><a href="<?php echo esc_url( $url_usuarios ); ?>"><?php asde_e( 'Ver todos los usuarios del área privada' ); ?></a></p>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Dar acceso a esta persona' ); ?></h3>
	<?php if ( ! $alguno ) : ?>
		<p><?php asde_e( 'No se le puede dar acceso propio:' ); ?></p>
		<ul>
			<?php foreach ( array_unique( array_filter( $eleg ) ) as $motivo ) : ?>
				<li><?php echo esc_html( $motivo ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $bloqueado ) : ?>
		<p><?php asde_e( 'Primero hay que corregir su nº de socio/a en el CRM.' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form" data-asde-alta-persona>
			<?php wp_nonce_field( 'asde_alta_persona_' . $p->num_socio_asde ); ?>
			<input type="hidden" name="asde_action" value="alta_persona">
			<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">

			<fieldset>
				<legend><?php asde_e( 'Rol o roles' ); ?></legend>
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
								<span class="asde-help"><?php asde_e( 'Ya tiene este rol.' ); ?></span>
							<?php elseif ( null !== $motivo ) : ?>
								<span class="asde-help"><?php echo esc_html( $motivo ); ?></span>
							<?php elseif ( in_array( $rol, AltaService::ROLES_RESPONSABILIDAD, true ) ) : ?>
								<span class="asde-help"><?php asde_e( 'Requiere también el rol de Scouter, o elegir «Voluntario sin sección».' ); ?><?php echo $sugerido ? esc_html( asde_t( ' Sugerido por su cargo en el CRM.' ) ) : ''; ?></span>
							<?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<?php if ( null === $eleg[ Roles::SCOUTER ] ) : ?>
				<fieldset data-asde-secciones>
					<legend><?php asde_e( 'Secciones en las que colabora' ); ?></legend>
					<p class="asde-help"><?php asde_e( 'El CRM no guarda en qué sección colabora cada scouter: indícalo aquí. En los informes verá a los socios de estas secciones.' ); ?></p>
					<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
						<label class="asde-choice">
							<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $secc_actual, true ) ); ?>>
							<span><?php echo Secciones::badge( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</label>
					<?php endforeach; ?>
					<label class="asde-choice asde-choice--separada">
						<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( Secciones::SIN_SECCION ); ?>" data-asde-sin-seccion <?php checked( in_array( Secciones::SIN_SECCION, $secc_actual, true ) ); ?>>
						<span>
							<?php echo Secciones::badge( Secciones::SIN_SECCION ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="asde-help"><?php asde_e( 'Colabora sin sección: solo se le puede dar acceso como Tesorería, Secretaría o Coordinación (sin el rol de Scouter).' ); ?></span>
						</span>
					</label>
				</fieldset>
			<?php endif; ?>

			<?php if ( $vinculado && $solicitud ) : ?>
				<?php // Ya tiene cuenta y hay una solicitud suya pendiente (v0.12.3): al guardar, la solicitud queda resuelta; su email no cambia. ?>
				<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
				<p class="asde-help"><?php asde_e( 'Tiene una' ); ?> <strong><?php asde_e( 'solicitud de acceso pendiente' ); ?></strong> (<?php echo esc_html( $solicitud['datos']['email'] ); ?><?php asde_e( '). Al guardar los cambios la solicitud se da por resuelta; el email de su cuenta no cambia.' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $vinculado ) : ?>
				<fieldset>
					<legend><?php asde_e( 'Email para la invitación (también sirve para entrar)' ); ?></legend>
					<?php if ( $solicitud ) : ?>
						<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
						<label class="asde-choice">
							<input type="radio" name="campo_email" value="solicitud" checked>
							<span><?php echo esc_html( $solicitud['datos']['email'] ); ?> <span class="asde-help"><?php asde_e( 'el de su solicitud de acceso' ); ?><?php echo in_array( $solicitud['datos']['email'], $emails_persona, true ) ? '' : esc_html( asde_t( ': se guardará también en la ficha del CRM' ) ); ?></span></span>
						</label>
					<?php endif; ?>
					<?php if ( ! $emails_persona && ! $solicitud ) : ?>
						<p class="asde-help asde-help--warning"><?php asde_e( '⚠️ La ficha no tiene email en el CRM. Añádelo en el CRM y vuelve a esta página.' ); ?></p>
					<?php else : ?>
						<?php foreach ( $emails_persona as $campo => $email ) : ?>
							<?php if ( $solicitud && $email === $solicitud['datos']['email'] ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<label class="asde-choice">
								<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( ! $solicitud ); ?>>
								<span><?php echo esc_html( $email ); ?> <span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php asde_e( 'en el CRM' ); ?></span></span>
							</label>
						<?php endforeach; ?>
						<?php if ( $email_compartido > 1 ) : ?>
							<p class="asde-help asde-help--warning"><?php asde_e( '⚠️ Este email aparece en' ); ?> <?php echo (int) $email_compartido; ?> <?php asde_e( 'fichas del CRM: puede ser el de la familia. Para una cuenta personal conviene que en el CRM figure el email propio de la persona.' ); ?></p>
						<?php endif; ?>
						<p class="asde-help"><?php
						if ( Identidad::es_asde() ) {
							echo sprintf( wp_kses_post( asde_h( 'El usuario será su nº de socio/a de Scouts de España (<strong>%s</strong>); también podrá entrar con el email elegido, que se guarda cifrado en la web.' ) ), esc_html( $p->num_socio_asde ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						} elseif ( $numero['numero'] ) {
							echo sprintf( wp_kses_post( asde_h( 'El usuario será su nº de socio/a (<strong>%s</strong>); también podrá entrar con el email elegido, que se guarda cifrado en la web.' ) ), esc_html( $numero['numero'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							echo wp_kses_post( asde_h( 'El usuario será su nº de socio/a, que se generará automáticamente; también podrá entrar con el email elegido, que se guarda cifrado en la web.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<p><button type="submit" class="button" <?php disabled( ! $vinculado && ! $emails_persona && ! $solicitud ); ?>><?php echo $vinculado ? esc_html( asde_t( 'Guardar cambios' ) ) : esc_html( asde_t( 'Dar acceso y enviar invitación' ) ); ?></button></p>
		</form>
		<?php if ( $vinculado && $solicitud ) : ?>
			<?php // Si ya tiene el acceso que pide, no hay nada que cambiar: basta cerrar la solicitud. ?>
			<form method="post" class="asde-actions">
				<?php wp_nonce_field( 'asde_cerrar_solicitud_' . $p->num_socio_asde ); ?>
				<input type="hidden" name="asde_action" value="cerrar_solicitud">
				<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
				<input type="hidden" name="solicitud" value="<?php echo (int) $solicitud['id']; ?>">
				<button type="submit" class="button"><?php asde_e( 'Ya tiene el acceso que pide: cerrar la solicitud sin cambiar nada' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Cuenta familiar (tutores)' ); ?></h3>
	<?php if ( null !== $motivo_rep ) : ?>
		<p><?php echo esc_html( $motivo_rep ); ?></p>
	<?php elseif ( $bloqueado && ! $cuenta_familiar ) : ?>
		<p><?php asde_e( 'Primero hay que corregir su nº de socio/a en el CRM (la cuenta familiar usa ese número).' ); ?></p>
	<?php else : ?>
		<p>
			<?php asde_e( 'La familia entra con el usuario' ); ?> <strong><?php echo esc_html( $usuario_familiar ?: asde_t( 'nº de socio/a del menor + T' ) ); ?></strong>
			<?php echo $cuenta_familiar ? esc_html( asde_t( '(cuenta ya existente: se le añadirán los participantes marcados).' ) ) : esc_html( asde_t( '(nº de socio/a del menor + T) o con el email elegido. Si tiene hermanos en el grupo, una sola cuenta sirve para todos; el otro tutor/a puede tener la suya (nº + T2).' ) ); ?>
		</p>
		<form method="post" class="asde-form" data-asde-alta-tutor>
			<?php wp_nonce_field( 'asde_alta_tutor_' . $p->num_socio_asde ); ?>
			<input type="hidden" name="asde_action" value="alta_tutor">
			<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">

			<fieldset>
				<legend><?php asde_e( 'Participantes de la cuenta' ); ?></legend>
				<label class="asde-choice">
					<input type="checkbox" checked disabled>
					<span><?php echo esc_html( $p->nombre_completo() ); ?> <?php echo $p->seccion_educativa() ? Secciones::badge( $p->seccion_educativa() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</label>
				<?php if ( $familia ) : ?>
					<p class="asde-help"><?php asde_e( 'Posibles hermanos/as (comparten un email en el CRM). Márcalos si son de la misma familia:' ); ?></p>
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
								<?php echo $f->seccion_educativa() ? Secciones::badge( $f->seccion_educativa() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( null !== $f->edad() ) : ?>
									<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( '%d años' ), $f->edad() ) ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</fieldset>

			<?php if ( ! $cuenta_familiar ) : ?>
				<fieldset>
					<legend><?php asde_e( 'Email de la familia (para la invitación, para entrar y para recuperar la contraseña)' ); ?></legend>
					<?php if ( ! $emails_familia ) : ?>
						<p class="asde-help asde-help--warning"><?php asde_e( '⚠️ La ficha no tiene ningún email en el CRM (ni propio ni de tutores). Añádelo en el CRM y vuelve a esta página.' ); ?></p>
					<?php else : ?>
						<?php $i = 0; ?>
						<?php foreach ( $emails_familia as $campo => $email ) : ?>
							<label class="asde-choice">
								<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( 0 === $i++ ); ?>>
								<span>
									<?php echo esc_html( $email ); ?>
									<span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php asde_e( 'en el CRM' ); ?>
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

			<p><button type="submit" class="button" <?php disabled( ! $cuenta_familiar && ! $emails_familia ); ?>><?php echo $cuenta_familiar ? esc_html( asde_t( 'Añadir a la cuenta familiar' ) ) : esc_html( asde_t( 'Crear cuenta familiar y enviar invitación' ) ); ?></button></p>
		</form>

		<?php if ( $cuenta_familiar ) : ?>
			<hr>
			<h4><?php asde_e( 'Otro tutor/a con su propia cuenta' ); ?></h4>
			<?php if ( count( $cuentas_familiares ) >= Usuarios::MAX_CUENTAS_FAMILIARES ) : ?>
				<p><?php asde_e( 'Esta familia ya tiene las dos cuentas de tutor/a:' ); ?> <?php echo implode( ' ' . esc_html( asde_t( 'y' ) ) . ' ', array_map( static fn( $c ) => '<strong>' . esc_html( $c->user_login ) . '</strong>', $cuentas_familiares ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php asde_e( '. Cada una entra con su propio email y ve a los mismos participantes.' ); ?></p>
			<?php else : ?>
				<p>
					<?php asde_e( 'Si el otro tutor/a (madre, padre…) quiere entrar con su propio email y su propia contraseña, se le crea una segunda cuenta (' ); ?><strong><?php echo esc_html( \AsdeBalears\AreaPrivada\Domain\NumeroSocio::usuario_tutor( \AsdeBalears\AreaPrivada\Domain\Identidad::convertir( (string) \AsdeBalears\AreaPrivada\Domain\NumeroSocio::socio_de_usuario_tutor( $cuenta_familiar->user_login ) ), 2 ) ); ?></strong><?php asde_e( ') que ve a los mismos participantes y comparte la familia de Tesorería.' ); ?>
				</p>
				<?php if ( ! $emails_otro ) : ?>
					<p class="asde-help asde-help--warning"><?php asde_e( '⚠️ La ficha no tiene ningún otro email en el CRM (ya está el de la primera cuenta). Añade el del otro tutor/a en el CRM y vuelve a esta página, o deja que lo pida desde «Solicitar acceso».' ); ?></p>
				<?php else : ?>
					<form method="post" class="asde-form">
						<?php wp_nonce_field( 'asde_alta_tutor_' . $p->num_socio_asde ); ?>
						<input type="hidden" name="asde_action" value="alta_tutor">
						<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
						<input type="hidden" name="otro_tutor" value="1">
						<fieldset>
							<legend><?php asde_e( 'Email del otro tutor/a' ); ?></legend>
							<?php $i = 0; ?>
							<?php foreach ( $emails_otro as $campo => $email ) : ?>
								<label class="asde-choice">
									<input type="radio" name="campo_email" value="<?php echo esc_attr( $campo ); ?>" <?php checked( 0 === $i++ ); ?>>
									<span><?php echo esc_html( $email ); ?> <span class="asde-help"><?php echo esc_html( Usuarios::etiqueta_campo_email( $campo ) ); ?> <?php asde_e( 'en el CRM' ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p><button type="submit" class="button"><?php asde_e( 'Crear la cuenta del otro tutor/a y enviar invitación' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</section>
