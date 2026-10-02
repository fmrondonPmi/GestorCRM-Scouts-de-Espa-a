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
	$txt_persona = 'Familia de ' . Contacto::lista( $nombres );
} elseif ( $socio ) {
	$txt_persona = 'Nº Scouts de España ' . $socio;
} else {
	$txt_persona = $u->display_name;
}
$txt_roles = array_map( array( Roles::class, 'label' ), $roles_u );
if ( $u->has_cap( 'manage_options' ) ) {
	$txt_roles[] = 'Administrador/a WP';
}
?>
<tr data-asde-fila="<?php echo (int) $u->ID; ?>"
	data-usuario="<?php echo esc_attr( $u->user_login ); ?>"
	data-persona="<?php echo esc_attr( $txt_persona ); ?>"
	data-roles="|<?php echo esc_attr( implode( '|', $roles_u ) ); ?>|"
	data-roles-texto="<?php echo esc_attr( implode( ', ', $txt_roles ) ); ?>"
	data-ambito="|<?php echo esc_attr( implode( '|', $secc_u ) ); ?>|"
	data-estado="<?php echo esc_attr( $estado ); ?>">
	<td data-label="Usuario">
		<strong><?php echo esc_html( $u->user_login ); ?></strong>
		<?php $email_cuenta = EmailCuenta::leer( $u->ID ); ?>
		<?php if ( $email_cuenta ) : ?>
			<br><span class="asde-help" title="Email de la cuenta (guardado cifrado)"><?php echo esc_html( Contacto::enmascarar( $email_cuenta ) ); ?></span>
		<?php endif; ?>
	</td>
	<td data-label="Persona">
		<?php if ( $persona ) : ?>
			<?php echo esc_html( $persona['nombre'] ); ?><?php echo Numeros::compacto_asde( $socio ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $persona['activa'] ? '' : '<br><span class="asde-help asde-help--warning">Sin membresía activa: no puede entrar</span>'; ?>
		<?php elseif ( $reps ) : ?>
			Familia de <?php echo esc_html( Contacto::lista( $nombres ) ); ?><?php echo NumeroSocio::orden_tutor( $u->user_login ) > 1 ? ' <span class="asde-help">(cuenta del 2.º tutor/a)</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php elseif ( $socio ) : ?>
			<span class="asde-muted">Nº Scouts de España <?php echo esc_html( $socio ); ?> (no encontrado en el CRM)</span>
		<?php else : ?>
			<span class="asde-muted"><?php echo esc_html( $u->display_name ); ?></span>
		<?php endif; ?>
	</td>
	<td data-label="Roles">
		<?php foreach ( $roles_u as $r ) : ?>
			<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $r ) ); ?></span>
		<?php endforeach; ?>
		<?php echo $u->has_cap( 'manage_options' ) ? '<span class="asde-muted">Administrador/a WP</span>' : ''; ?>
	</td>
	<td data-label="Ámbito">
		<?php
		foreach ( $secc_u as $s ) {
			echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
		<?php if ( $reps ) : ?>
			<span class="asde-muted"><?php echo esc_html( sprintf( _n( '%d participante', '%d participantes', count( $reps ), 'asde-area-privada' ), count( $reps ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $socio ) : ?>
			<br><a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_alta ) ); ?>" data-asde-modal>Gestionar acceso</a>
		<?php endif; ?>
	</td>
	<td data-label="Estado">
		<span class="asde-badge asde-badge--estado-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( Usuarios::etiqueta_estado( $estado ) ); ?></span>
		<?php if ( Usuarios::INVITADO === $estado && $expira ) : ?>
			<br><span class="asde-muted"><?php echo $expira < time() ? 'Invitación caducada' : esc_html( 'Caduca el ' . wp_date( 'd/m/Y', $expira ) ); ?></span>
		<?php endif; ?>
		<?php if ( $alta_en ) : ?>
			<br><span class="asde-muted">Alta: <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $alta_en . ' UTC' ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $e ) : ?>
			<br><span class="asde-muted">Último email:</span>
			<span class="asde-badge asde-badge--email-<?php echo esc_attr( $e['estado'] ); ?>"><?php echo esc_html( RegistroEmails::ESTADOS[ $e['estado'] ] ?? $e['estado'] ); ?></span>
			<span class="asde-muted"><?php echo esc_html( wp_date( 'd/m H:i', strtotime( $e['creado'] . ' UTC' ) ) ); ?></span>
			<a href="<?php echo esc_url( add_query_arg( 'ver', (int) $e['id'], $url_emails ) ); ?>">Ver</a>
		<?php endif; ?>
	</td>
	<td data-label="">
		<div class="asde-actions">
			<?php if ( Usuarios::INVITADO === $estado ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'asde_reenviar_' . $u->ID ); ?>
					<input type="hidden" name="asde_action" value="reenviar">
					<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
					<button type="submit" class="asde-btn-link">Reenviar invitación</button>
				</form>
			<?php endif; ?>
			<?php if ( $roles_u && ! $u->has_cap( 'manage_options' ) && get_current_user_id() !== $u->ID ) : ?>
				<form method="post" onsubmit="return confirm('¿Desactivar el acceso del usuario <?php echo esc_js( $u->user_login ); ?>? Se le quitarán todos los roles del área privada.');">
					<?php wp_nonce_field( 'asde_desactivar_' . $u->ID ); ?>
					<input type="hidden" name="asde_action" value="desactivar">
					<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger">Desactivar</button>
				</form>
			<?php endif; ?>
		</div>
	</td>
</tr>
