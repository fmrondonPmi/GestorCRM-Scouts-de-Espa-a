<?php
/**
 * Solicitudes de acceso: lista.
 *
 * @var array[]                                            $lista
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas nº Scouts de España => ficha
 * @var bool                                               $resueltas
 * @var string                                             $url
 * @var string                                             $enlace    URL pública de «Solicitar acceso»
 * @var string                                             $contacto  Email de contacto del grupo ('' si no hay)
 */
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

$publico = add_query_arg( 'grupo', Grupo::numero(), $enlace );
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Solicitudes de acceso</h2>
		<nav class="asde-subnav" aria-label="Estado">
			<ul class="asde-subnav__tabs">
				<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $resueltas ? '' : ' class="is-active" aria-current="page"'; ?>>Pendientes</a></li>
				<li><a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltas', $url ) ); ?>"<?php echo $resueltas ? ' class="is-active" aria-current="page"' : ''; ?>>Resueltas</a></li>
			</ul>
		</nav>
	</header>
	<p>Las familias y los socios piden el acceso con este enlace. Al comprobar su documento y su fecha de nacimiento con la ficha del CRM, la solicitud aparece aquí para que la apruebes.</p>
	<?php if ( '' === ( $contacto ?? '' ) ) : ?>
			<p class="asde-help asde-help--warning">⚠️ El grupo no tiene <strong>email de contacto</strong>. Es el que ve quien no puede comprobar sus datos y el que lleva el aviso de una solicitud rechazada. Un administrador lo pone en wp-admin → Grupos Scouts → (el grupo) → «Email de contacto del grupo».</p>
		<?php endif; ?>
		<p class="asde-copiar">
		<code class="asde-copiar__texto" id="asde-enlace-solicitud"><?php echo esc_html( $publico ); ?></code>
		<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-solicitud">Copiar el enlace</button>
	</p>
</section>

<section class="asde-card">
	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php echo $resueltas ? 'Todavía no hay solicitudes resueltas.' : 'No hay solicitudes pendientes.'; ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col">Fecha</th>
					<th scope="col">Socio/a</th>
					<th scope="col">Quién pide</th>
					<th scope="col">Email</th>
					<th scope="col"><?php echo $resueltas ? 'Estado' : '<span class="screen-reader-text">Acciones</span>'; ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $s ) : ?>
					<?php $p = $personas[ $s['socio'] ] ?? null; ?>
					<tr>
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?></td>
						<td data-label="Socio/a">
							<?php if ( $p ) : ?>
								<?php echo esc_html( $p->nombre_completo() ); ?><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php
								foreach ( $p->secciones as $sec ) {
									echo Secciones::badge( $sec ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
							<?php else : ?>
								<span class="asde-muted">Ficha no encontrada (<?php echo esc_html( $s['socio'] ); ?>)</span>
							<?php endif; ?>
							<br><span class="asde-help"><?php echo Solicitudes::POR_NOMBRE === $s['via'] ? 'Comprobado con la fecha de nacimiento y el nombre (sin documento)' : 'Comprobado con el documento y la fecha de nacimiento'; ?></span>
						</td>
						<td data-label="Quién pide"><?php echo esc_html( '' !== $s['datos']['nombre'] ? $s['datos']['nombre'] : '—' ); ?><br><span class="asde-help"><?php echo Solicitudes::TUTOR === $s['tipo'] ? 'Su familia (madre, padre o tutor/a)' : 'El propio socio/a'; ?></span></td>
						<td data-label="Email"><?php echo esc_html( '' !== $s['datos']['email'] ? $s['datos']['email'] : '—' ); ?></td>
						<td data-label="">
							<?php if ( $resueltas ) : ?>
								<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ); ?>"><?php echo esc_html( Solicitudes::ESTADOS[ $s['estado'] ] ?? $s['estado'] ); ?></span>
								<br><a href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal>Ver</a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal>Revisar</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
