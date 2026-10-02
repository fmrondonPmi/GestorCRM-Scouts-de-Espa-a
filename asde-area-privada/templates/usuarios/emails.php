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
 * @var string                                 $url_usuarios
 * @var string                                 $mailpit URL de Mailpit (solo en local)
 */
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'fecha'   => 'Fecha',
	'usuario' => 'Usuario',
	'tipo'    => 'Tipo',
	'estado'  => 'Estado',
);
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Registro de emails</h2>
		<a href="<?php echo esc_url( $url_usuarios ); ?>">← Usuarios</a>
	</header>
	<p>Cada invitación, recuperación de contraseña o aviso de solicitud rechazada que envía el área privada, con su resultado. <strong>Enviado</strong> quiere decir que el servidor de correo lo aceptó; si no llega, que revisen la carpeta de spam o que la Secretaría compruebe el email en el CRM.</p>

	<?php if ( $mailpit ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong>Entorno local:</strong> los emails no salen a internet (así no llegan a familias reales). Se recogen en Mailpit: <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<?php if ( $usuario ) : ?>
			<input type="hidden" name="usuario" value="<?php echo (int) $usuario->ID; ?>">
		<?php endif; ?>
		<label for="asde-estado-email">Mostrar</label>
		<select id="asde-estado-email" name="estado" onchange="this.form.submit()">
			<option value="">Todos</option>
			<?php foreach ( RegistroEmails::ESTADOS as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="button">Filtrar</button></noscript>
		<?php if ( $usuario ) : ?>
			<span class="asde-muted">Solo del usuario <strong><?php echo esc_html( $usuario->user_login ); ?></strong> · <a href="<?php echo esc_url( $url ); ?>">ver todos</a></span>
		<?php endif; ?>
	</form>

	<?php if ( ! $filas ) : ?>
		<p>No hay emails registrados<?php echo $estado || $usuario ? ' con este filtro' : ''; ?>.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col">Enviado a</th>
					<th scope="col"><span class="screen-reader-text">Acciones</span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $f['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="Usuario">
							<strong><?php echo '' !== $f['login'] ? esc_html( $f['login'] ) : '—'; ?></strong>
							<?php if ( isset( $nombres[ (int) $f['user_id'] ] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $nombres[ (int) $f['user_id'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="Tipo"><?php echo esc_html( RegistroEmails::TIPOS[ $f['tipo'] ] ?? $f['tipo'] ); ?></td>
						<td data-label="Estado">
							<span class="asde-badge asde-badge--email-<?php echo esc_attr( $f['estado'] ); ?>"><?php echo esc_html( RegistroEmails::ESTADOS[ $f['estado'] ] ?? $f['estado'] ); ?></span>
							<?php if ( '' !== $f['error'] ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( $f['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="Enviado a"><?php echo '' !== $f['destino'] ? esc_html( $f['destino'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( 'ver', (int) $f['id'], $url ) ); ?>">Ver email</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
