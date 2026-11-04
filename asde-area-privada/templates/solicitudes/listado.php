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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

$publico = add_query_arg( 'grupo', Grupo::numero(), $enlace );
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Access requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<nav class="asde-subnav" aria-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<ul class="asde-subnav__tabs">
				<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $resueltas ? '' : ' class="is-active" aria-current="page"'; ?>><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltas', $url ) ); ?>"<?php echo $resueltas ? ' class="is-active" aria-current="page"' : ''; ?>><?php esc_html_e( 'Resolved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></li>
			</ul>
		</nav>
	</header>
	<p><?php esc_html_e( 'Families and members request access with this link. When their document and date of birth are checked against the CRM record, the request appears here for you to approve.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( '' === ( $contacto ?? '' ) ) : ?>
			<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The group has no', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'contact email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. It is the one seen by those who cannot have their details checked and the one carried by a rejected request\'s notice. An administrator sets it in wp-admin → Scout Groups → (the group) → "Group contact email".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
		<p class="asde-copiar">
		<code class="asde-copiar__texto" id="asde-enlace-solicitud"><?php echo esc_html( $publico ); ?></code>
		<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-solicitud"><?php esc_html_e( 'Copy the link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</p>
</section>

<?php if ( '' !== ( $enlace_scouters ?? '' ) ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Scouters\' link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p><?php esc_html_e( 'Pass it on to the group\'s scouters and volunteers. They register with their ID or foreigner ID number, choose their sections and the activation arrives at the email on their record. The responsibilities (Secretary\'s office, Coordination, Treasury, Quartermaster) they ask for, registrations without a section and those of people with no email on their record appear here for you to review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-copiar">
			<code class="asde-copiar__texto" id="asde-enlace-scouters"><?php echo esc_html( $enlace_scouters ); ?></code>
			<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-scouters"><?php esc_html_e( 'Copy the link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>
		<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Generate a new link? The one you already passed on will stop working.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php wp_nonce_field( 'asde_enlace_scouters' ); ?>
			<input type="hidden" name="asde_action" value="renovar_enlace_scouters">
			<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Generate a new link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<span class="asde-help"><?php esc_html_e( 'If the link has reached someone it should not have.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</form>
	</section>
<?php endif; ?>

<section class="asde-card">
	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php echo $resueltas ? esc_html( __( 'There are no resolved requests yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There are no pending requests.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php echo $resueltas ? esc_html( __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : '<span class="screen-reader-text">' . esc_html( __( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $s ) : ?>
					<?php $p = $personas[ $s['socio'] ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $s['creada'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $p ) : ?>
								<?php echo esc_html( $p->nombre_completo() ); ?><?php echo wp_kses( \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ), asde_html_permitido() ); ?>
								<?php
								foreach ( $p->secciones as $sec ) {
									echo wp_kses( Secciones::badge( $sec ), asde_html_permitido() );
								}
								?>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'Record not found (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( $s['socio'] ); ?>)</span>
							<?php endif; ?>
							<br><span class="asde-help"><?php echo Solicitudes::POR_ENLACE === $s['via'] ? esc_html( __( 'Registration with the scouters\' link (checked with the ID number)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ( Solicitudes::POR_NOMBRE === $s['via'] ? esc_html( __( 'Checked with the date of birth and the name (no document)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Checked with the document and the date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?></span>
								<?php if ( Solicitudes::POR_ENLACE === $s['via'] ) : ?>
									<br><span class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Requests: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_map( array( Roles::class, 'label' ), (array) ( $s['datos']['roles_pedidos'] ?? array() ) ) ) ?: '—' ) ); ?><?php echo ! empty( $s['datos']['cuenta_creada'] ) ? ' · ' . esc_html( __( 'account already created', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?></span>
								<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Who requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( '' !== $s['datos']['nombre'] ? $s['datos']['nombre'] : '—' ); ?><br><span class="asde-help"><?php echo Solicitudes::TUTOR === $s['tipo'] ? esc_html( __( 'Their family (mother, father or guardian)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'The member themselves', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( '' !== $s['datos']['email'] ? $s['datos']['email'] : '—' ); ?></td>
						<td data-label="">
							<?php if ( $resueltas ) : ?>
								<span class="asde-badge asde-badge--estado-<?php echo esc_attr( Solicitudes::APROBADA === $s['estado'] ? 'activo' : 'desactivado' ); ?>"><?php echo esc_html( ( Solicitudes::estados()[ $s['estado'] ] ?? $s['estado'] ) ); ?></span>
								<br><a href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'solicitud', $s['id'], $url ) ); ?>" data-asde-modal><?php esc_html_e( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
