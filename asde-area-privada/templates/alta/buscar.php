<?php
/**
 * Alta de usuarios · búsqueda de socios activos en el CRM.
 *
 * @var string                                   $q
 * @var AsdeBalears\AreaPrivada\Domain\Persona[] $resultados
 * @var array<string, array{numero:?string, estado:string}> $numeros nº Scouts de España => nº local
 * @var array<string, WP_User>                   $usuarios nº Scouts de España => usuario web
 * @var string                                   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Give access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'Search for the member in the group\'s CRM (only members with an active membership appear). From their record you can give access to them or to their family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-search" role="search">
		<label for="asde-q" class="screen-reader-text"><?php esc_html_e( 'Search member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'First name and surname, member number, email or ID number', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" minlength="3" required>
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
</section>

<?php if ( '' !== $q && mb_strlen( $q ) < 3 ) : ?>
	<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'Enter at least 3 characters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
<?php elseif ( '' !== $q ) : ?>
	<section class="asde-card" aria-live="polite">
		<?php if ( ! $resultados ) : ?>
			<p><?php esc_html_e( 'There is no member with an active membership matching "', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( $q ); ?>».</p>
		<?php else : ?>
			<p class="asde-muted">
				<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d member found', '%d members found', count( $resultados ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $resultados ) ) ); ?>
				<?php echo count( $resultados ) >= 30 ? esc_html( __( ' (the first 30 are shown: narrow down your search)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>
			</p>
			<table class="asde-table" data-asde-paginar>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultados as $p ) : ?>
						<?php
						$u = $usuarios[ $p->num_socio_asde ] ?? null;
						$n = $numeros[ $p->num_socio_asde ] ?? array( 'numero' => null, 'estado' => 'falta' );
						?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $p->nombre_completo() ); ?></strong></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( \AsdeBalears\AreaPrivada\Socios\Numeros::html( $p, $n ), asde_html_permitido() ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php
								foreach ( $p->secciones as $s ) {
									echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
								}
								?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo null === $p->edad() ? '—' : esc_html( sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->edad() ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( $u ) : ?>
									<?php foreach ( Usuarios::roles_asde( $u ) as $rol ) : ?>
										<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $rol ) ); ?></span>
									<?php endforeach; ?>
									<span class="asde-muted"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $u ) ) ); ?></span>
								<?php else : ?>
									<span class="asde-muted"><?php esc_html_e( 'No username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
								<?php if ( Usuarios::tutores_de( $p->num_socio_asde ) ) : ?>
									<br><span class="asde-muted"><?php esc_html_e( 'With a family account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="">
								<a class="button" href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url ) ); ?>" data-asde-modal><?php echo $u ? esc_html( __( 'Manage access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Give access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endif; ?>
