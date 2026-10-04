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
 * @var string                                             $enlace_scouters Enlace de alta de scouters ('' si no le toca verlo; v0.16, doc 31)
 */
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

$publico = add_query_arg( 'grupo', Grupo::numero(), $enlace );
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Solicitudes de acceso' ); ?></h2>
		<nav class="asde-subnav" aria-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
			<ul class="asde-subnav__tabs">
				<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $resueltas ? '' : ' class="is-active" aria-current="page"'; ?>><?php asde_e( 'Pendientes' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltas', $url ) ); ?>"<?php echo $resueltas ? ' class="is-active" aria-current="page"' : ''; ?>><?php asde_e( 'Resueltas' ); ?></a></li>
			</ul>
		</nav>
	</header>
	<p><?php asde_e( 'Las familias y los socios piden el acceso con este enlace. Al comprobar su documento y su fecha de nacimiento con la ficha del CRM, la solicitud aparece aquí para que la apruebes.' ); ?></p>
	<?php if ( '' === ( $contacto ?? '' ) ) : ?>
			<p class="asde-help asde-help--warning"><?php asde_e( '⚠️ El grupo no tiene' ); ?> <strong><?php asde_e( 'email de contacto' ); ?></strong><?php asde_e( '. Es el que ve quien no puede comprobar sus datos y el que lleva el aviso de una solicitud rechazada. Un administrador lo pone en wp-admin → Grupos Scouts → (el grupo) → «Email de contacto del grupo».' ); ?></p>
		<?php endif; ?>
		<p class="asde-copiar">
		<code class="asde-copiar__texto" id="asde-enlace-solicitud"><?php echo esc_html( $publico ); ?></code>
		<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-solicitud"><?php asde_e( 'Copiar el enlace' ); ?></button>
	</p>
</section>

<?php if ( '' !== ( $enlace_scouters ?? '' ) ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Enlace para scouters' ); ?></h3>
		<p><?php asde_e( 'Pásalo a los scouters y al voluntariado del grupo. Se dan de alta con su DNI o NIE, eligen sus secciones y la activación les llega al email de su ficha. Las responsabilidades (Secretaría, Coordinación, Tesorería) que pidan, las altas sin sección y las de quien no tiene email en la ficha aparecen aquí para que las revises.' ); ?></p>
		<p class="asde-copiar">
			<code class="asde-copiar__texto" id="asde-enlace-scouters"><?php echo esc_html( $enlace_scouters ); ?></code>
			<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-scouters"><?php asde_e( 'Copiar el enlace' ); ?></button>
		</p>
		<form method="post" onsubmit="return confirm(<?php echo esc_attr( asde_js( '¿Generar un enlace nuevo? El que ya has pasado dejará de servir.' ) ); ?>);">
			<?php wp_nonce_field( 'asde_enlace_scouters' ); ?>
			<input type="hidden" name="asde_action" value="renovar_enlace_scouters">
			<button type="submit" class="asde-btn-link"><?php asde_e( 'Generar enlace nuevo' ); ?></button>
			<span class="asde-help"><?php asde_e( 'Si el enlace ha llegado a quien no debía.' ); ?></span>
		</form>
	</section>
<?php endif; ?>

<section class="asde-card">
	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php echo $resueltas ? esc_html( asde_t( 'Todavía no hay solicitudes resueltas.' ) ) : esc_html( asde_t( 'No hay solicitudes pendientes.' ) ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
					<th scope="col"><?php asde_e( 'Socio/a' ); ?></th>
					<th scope="col"><?php asde_e( 'Quién pide' ); ?></th>
					<th scope="col"><?php asde_e( 'Email' ); ?></th>
					<th scope="col"><?php echo $resueltas ? esc_html( asde_t( 'Estado' ) ) : '<span class="screen-reader-text">' . esc_html( asde_t( 'Acciones' ) ) . '</span>'; ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $s ) : ?>
					<?php $p = $personas[ $s['socio'] ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Socio/a' ) ); ?>">
							<?php if ( $p ) : ?>
								<?php echo esc_html( $p->nombre_completo() ); ?><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php
								foreach ( $p->secciones as $sec ) {
									echo Secciones::badge( $sec ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
							<?php else : ?>
								<span class="asde-muted"><?php asde_e( 'Ficha no encontrada (' ); ?><?php echo esc_html( $s['socio'] ); ?>)</span>
							<?php endif; ?>
							<br><span class="asde-help"><?php echo Solicitudes::POR_ENLACE === $s['via'] ? esc_html( asde_t( 'Alta con el enlace para scouters (comprobada con el DNI)' ) ) : ( Solicitudes::POR_NOMBRE === $s['via'] ? esc_html( asde_t( 'Comprobado con la fecha de nacimiento y el nombre (sin documento)' ) ) : esc_html( asde_t( 'Comprobado con el documento y la fecha de nacimiento' ) ) ); ?></span>
								<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
									<br><span class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Pide: %s' ), implode( ', ', array_map( array( Roles::class, 'label' ), (array) ( $s['datos']['roles_pedidos'] ?? array() ) ) ) ?: '—' ) ); ?><?php echo ! empty( $s['datos']['cuenta_creada'] ) ? ' · ' . esc_html( asde_t( 'cuenta ya creada' ) ) : ''; ?></span>
								<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Quién pide' ) ); ?>"><?php echo esc_html( '' !== $s['datos']['nombre'] ? $s['datos']['nombre'] : '—' ); ?><br><span class="asde-help"><?php echo Solicitudes::TUTOR === $s['tipo'] ? esc_html( asde_t( 'Su familia (madre, padre o tutor/a)' ) ) : esc_html( asde_t( 'El propio socio/a' ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Email' ) ); ?>"><?php echo esc_html( '' !== $s['datos']['email'] ? $s['datos']['email'] : '—' ); ?></td>
						<td data-label="">
							<?php if ( $resueltas ) : ?>
								<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ); ?>"><?php echo esc_html( asde_t( Solicitudes::ESTADOS[ $s['estado'] ] ?? $s['estado'] ) ); ?></span>
								<br><a href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal><?php asde_e( 'Ver' ); ?></a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal><?php asde_e( 'Revisar' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
