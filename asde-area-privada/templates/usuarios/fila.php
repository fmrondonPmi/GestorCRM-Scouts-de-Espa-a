<?php
/**
 * Una fila del listado de usuarios. Los atributos data-* son los datos con los que
 * el navegador filtra y ordena en memoria (assets/js/usuarios.js).
 *
 * @var WP_User                                   $u
 * @var array<string, array{nombre:string, activa:bool}> $personas nº Scouts de España => nombre y membresía (caché del CRM)
 * @var array|null                                $e        último email de este usuario (RegistroEmails)
 * @var string                                    $url_alta
 * @var string                                    $url_emails
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeros;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

$estado  = Usuarios::estado( $u );
$socio   = Usuarios::socio( $u->ID );
$persona = $socio ? ( $personas[ $socio ] ?? null ) : null;
$reps    = Usuarios::representados( $u->ID );
$alta_en = (string) get_user_meta( $u->ID, Usuarios::META_ALTA_EN, true );
$expira  = Invitacion::expira( $u->ID );
$nombres = array();
foreach ( $reps as $n ) {
	$nombres[] = isset( $personas[ $n ] ) ? $personas[ $n ]['nombre'] : $n;
}
$roles_u = Usuarios::roles_asde( $u );
$secc_u  = Usuarios::secciones( $u->ID );

// Lo que se ve en la columna «Persona» (también es lo que busca el filtro).
if ( $persona ) {
	$txt_persona = $persona['nombre'];
} elseif ( $reps ) {
	$txt_persona = sprintf( /* translators: %s: text */ __( 'Family of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Contacto::lista( $nombres ) );
} elseif ( $socio ) {
	$txt_persona = __( 'Scouts de España no. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $socio;
} else {
	$txt_persona = $u->display_name;
}
$txt_roles = array_map( array( Roles::class, 'label' ), $roles_u );
if ( $u->has_cap( 'manage_options' ) ) {
	$txt_roles[] = __( 'WP administrator', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
}
?>
<tr data-asde-fila="<?php echo (int) $u->ID; ?>"
	data-usuario="<?php echo esc_attr( $u->user_login ); ?>"
	data-persona="<?php echo esc_attr( $txt_persona ); ?>"
	data-roles="|<?php echo esc_attr( implode( '|', $roles_u ) ); ?>|"
	data-roles-texto="<?php echo esc_attr( implode( ', ', $txt_roles ) ); ?>"
	data-ambito="|<?php echo esc_attr( implode( '|', $secc_u ) ); ?>|"
	data-estado="<?php echo esc_attr( $estado ); ?>">
	<td data-label="<?php echo esc_attr( esc_attr__( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<strong><?php echo esc_html( $u->user_login ); ?></strong>
		<?php $email_cuenta = EmailCuenta::leer( $u->ID ); ?>
		<?php if ( $email_cuenta ) : ?>
			<br><span class="asde-help" title="<?php echo esc_attr( esc_attr__( 'Account email (stored encrypted)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Contacto::enmascarar( $email_cuenta ) ); ?></span>
		<?php endif; ?>
	</td>
	<td data-label="<?php echo esc_attr( esc_attr__( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<?php if ( $persona ) : ?>
			<?php echo esc_html( $persona['nombre'] ); ?><?php echo wp_kses( Numeros::compacto_asde( $socio ), asde_html_permitido() ); ?>
			<?php echo $persona['activa'] ? '' : '<br><span class="asde-help asde-help--warning">' . esc_html( __( 'No active membership: cannot sign in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?>
		<?php elseif ( $reps ) : ?>
			<?php esc_html_e( 'Family of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( Contacto::lista( $nombres ) ); ?><?php echo wp_kses( NumeroSocio::orden_tutor( $u->user_login ) > 1 ? ' <span class="asde-help">' . esc_html( __( '(account of the 2nd guardian)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
		<?php elseif ( $socio ) : ?>
			<span class="asde-muted"><?php esc_html_e( 'Scouts de España no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $socio ); ?> <?php esc_html_e( '(not found in the CRM)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php else : ?>
			<span class="asde-muted"><?php echo esc_html( $u->display_name ); ?></span>
		<?php endif; ?>
	</td>
	<td data-label="<?php echo esc_attr( esc_attr__( 'Roles', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<?php foreach ( $roles_u as $r ) : ?>
			<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $r ) ); ?></span>
		<?php endforeach; ?>
		<?php echo $u->has_cap( 'manage_options' ) ? '<span class="asde-muted">Administrador/a WP</span>' : ''; ?>
	</td>
	<td data-label="<?php echo esc_attr( esc_attr__( 'Scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<?php
		foreach ( $secc_u as $s ) {
			echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
		}
		?>
		<?php if ( $reps ) : ?>
			<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d participant', '%d participants', count( $reps ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $reps ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $socio ) : ?>
			<br><a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_alta ) ); ?>" data-asde-modal><?php esc_html_e( 'Manage access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<?php endif; ?>
	</td>
	<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<span class="asde-badge asde-badge--estado-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( $estado ) ); ?></span>
		<?php if ( Usuarios::INVITADO === $estado && $expira ) : ?>
			<br><span class="asde-muted"><?php echo $expira < time() ? esc_html( __( 'Invitation expired', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( sprintf( /* translators: %s: text */ __( 'Expires on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', $expira ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $alta_en ) : ?>
			<br><span class="asde-muted"><?php esc_html_e( 'Registration:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $alta_en . ' UTC' ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $e ) : ?>
			<br><span class="asde-muted"><?php esc_html_e( 'Latest email:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<span class="asde-badge asde-badge--email-<?php echo esc_attr( $e['estado'] ); ?>"><?php echo esc_html( ( RegistroEmails::estados()[ $e['estado'] ] ?? $e['estado'] ) ); ?></span>
			<span class="asde-muted"><?php echo esc_html( wp_date( 'd/m H:i', strtotime( $e['creado'] . ' UTC' ) ) ); ?></span>
			<a href="<?php echo esc_url( add_query_arg( 'ver', (int) $e['id'], $url_emails ) ); ?>"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<?php endif; ?>
	</td>
	<td data-label="">
		<div class="asde-actions">
			<?php if ( Usuarios::INVITADO === $estado ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'asde_reenviar_' . $u->ID ); ?>
					<input type="hidden" name="asde_action" value="reenviar">
					<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
					<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Resend invitation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( $roles_u && ! $u->has_cap( 'manage_options' ) && get_current_user_id() !== $u->ID ) : ?>
				<form method="post" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Deactivate the access of user %s? All their private area roles will be removed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $u->user_login ) ); ?>">
					<?php wp_nonce_field( 'asde_desactivar_' . $u->ID ); ?>
					<input type="hidden" name="asde_action" value="desactivar">
					<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Deactivate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</td>
</tr>
