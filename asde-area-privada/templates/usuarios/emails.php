<?php
/**
 * Registro de emails del grupo.
 *
 * @var array[]                                $filas   Página que se muestra
 * @var int                                    $total
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var array<int, string>                     $nombres user_id => nombre (del CRM)
 * @var string                                 $estado  Filtro ('' = todos)
 * @var WP_User|null                           $usuario Filtro por usuario
 * @var string                                 $url
 * @var string                                 $mailpit URL de Mailpit (solo en local)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'fecha'   => asde_t( 'Fecha' ),
	'usuario' => asde_t( 'Usuario' ),
	'tipo'    => asde_t( 'Tipo' ),
	'estado'  => asde_t( 'Estado' ),
);
?>
<section class="asde-card">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php asde_e( 'Registro de emails' ); ?></h2>
	</header>
	<p><?php asde_e( 'Cada invitación, recuperación de contraseña o aviso de solicitud rechazada que envía el área privada, con su resultado.' ); ?> <strong><?php asde_e( 'Enviado' ); ?></strong> <?php asde_e( 'quiere decir que el servidor de correo lo aceptó; si no llega, que revisen la carpeta de spam o que la Secretaría compruebe el email en el CRM.' ); ?></p>

	<?php if ( $mailpit ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong><?php asde_e( 'Entorno local:' ); ?></strong> <?php asde_e( 'los emails no salen a internet (así no llegan a familias reales). Se recogen en Mailpit:' ); ?> <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<?php if ( $usuario ) : ?>
			<input type="hidden" name="usuario" value="<?php echo (int) $usuario->ID; ?>">
		<?php endif; ?>
		<label for="asde-estado-email"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-estado-email" name="estado" onchange="this.form.submit()">
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( asde_ta( RegistroEmails::ESTADOS ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button></noscript>
		<?php if ( $usuario ) : ?>
			<span class="asde-muted"><?php asde_e( 'Solo del usuario' ); ?> <strong><?php echo esc_html( $usuario->user_login ); ?></strong> · <a href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'ver todos' ); ?></a></span>
		<?php endif; ?>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php asde_e( 'No hay emails registrados' ); ?><?php echo $estado || $usuario ? esc_html( asde_t( ' con este filtro' ) ) : ''; ?>.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php asde_e( 'Enviado a' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $f['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Usuario' ) ); ?>">
							<strong><?php echo '' !== $f['login'] ? esc_html( $f['login'] ) : '—'; ?></strong>
							<?php if ( isset( $nombres[ (int) $f['user_id'] ] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $nombres[ (int) $f['user_id'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>"><?php echo esc_html( asde_t( RegistroEmails::TIPOS[ $f['tipo'] ] ?? $f['tipo'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<span class="asde-badge asde-badge--email-<?php echo esc_attr( $f['estado'] ); ?>"><?php echo esc_html( asde_t( RegistroEmails::ESTADOS[ $f['estado'] ] ?? $f['estado'] ) ); ?></span>
							<?php if ( '' !== $f['error'] ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( $f['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Enviado a' ) ); ?>"><?php echo '' !== $f['destino'] ? esc_html( $f['destino'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( 'ver', (int) $f['id'], $url ) ); ?>"><?php asde_e( 'Ver email' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
