<?php
/**
 * Mi sección (doc 65): las personas de la sección con su etapa, la última revisión y sus aspectos.
 *
 * @var array[]  $filas     p (Persona), r (última revisión o null), anio, tarde
 * @var string   $seccion   Sección que se ve
 * @var string[] $secciones Las que puede ver
 * @var int      $etapa     Filtro de etapa (0 = todas)
 * @var string   $solo      Filtro: '' todas, 'revisar' o 'propuesta'
 * @var string   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Seguimiento\Catalogo;

defined( 'ABSPATH' ) || exit;

$etapas = Catalogo::etapas();
$lista  = array_values(
	array_filter(
		$filas,
		static fn( array $f ): bool => ( ! $etapa || ( $f['r']['etapa'] ?? 1 ) === $etapa )
			&& ( 'revisar' !== $solo || $f['tarde'] )
			&& ( 'propuesta' !== $solo || ! empty( $f['r']['propuesta'] ) )
	)
);
$enlace = static fn( array $args ): string => esc_url( add_query_arg( $args + array( 'seccion' => $seccion ), $url ) );
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'My section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( Secciones::badge( $seccion ), asde_html_permitido() ); ?></h2>
	<p class="asde-help"><?php esc_html_e( 'The programme asks for a review at least every quarter. The stage describes where each person is; it is not a prize and people are not compared.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<p>
		<a href="<?php echo $enlace( array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $enlace ?>"><?php echo esc_html( sprintf( /* translators: %d: number of people */ _n( '%d person', '%d people', count( $filas ), 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $filas ) ) ); ?></a> ·
		<a href="<?php echo $enlace( array( 'solo' => 'revisar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $enlace ?>"><?php echo esc_html( sprintf( /* translators: %d: number of people */ __( '%d to review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( array_filter( array_column( $filas, 'tarde' ) ) ) ) ); ?></a> ·
		<a href="<?php echo $enlace( array( 'solo' => 'propuesta' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $enlace ?>"><?php echo esc_html( sprintf( /* translators: %d: number of people */ __( '%d stage changes proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( array_filter( array_column( array_column( $filas, 'r' ), 'propuesta' ) ) ) ) ); ?></a>
	</p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<?php if ( count( $secciones ) > 1 ) : ?>
			<label for="asde-seg-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-seg-seccion" name="seccion" data-asde-autoenviar>
				<?php foreach ( $secciones as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="hidden" name="seccion" value="<?php echo esc_attr( $seccion ); ?>">
		<?php endif; ?>
		<label for="asde-seg-etapa"><?php esc_html_e( 'Stage', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-seg-etapa" name="etapa" data-asde-autoenviar>
			<option value="0"><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $etapas as $n => $nombre ) : ?>
				<option value="<?php echo (int) $n; ?>" <?php selected( $etapa, $n ); ?>><?php echo esc_html( $nombre ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'Nobody matches this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-seguimiento-tabla" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Year in the section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stage', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Aspects', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo $enlace( array( 'persona' => $f['p']->num_socio_asde ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $enlace ?>" data-asde-modal><?php echo esc_html( $f['p']->nombre_completo() ); ?></a></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Year in the section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo $f['anio'] ? esc_html( sprintf( /* translators: 1: year, 2: total years */ __( '%1$d of %2$d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['anio']['n'], $f['anio']['de'] ) . ( $f['anio']['fuera'] ? ' *' : '' ) ) : '—'; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Stage', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo $f['r'] ? esc_html( $etapas[ $f['r']['etapa'] ] ?? '' ) : '—'; ?>
							<?php if ( ! empty( $f['r']['propuesta'] ) ) : ?>
								<span class="asde-badge asde-badge--estado-invitado"><?php esc_html_e( 'Change proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Last review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="<?php echo $f['tarde'] ? 'asde-seguimiento-tarde' : ''; ?>">
							<?php echo $f['r'] ? esc_html( wp_date( 'd/m/Y', strtotime( $f['r']['creada'] . ' UTC' ) ) ) : esc_html__( 'Never', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Aspects', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-seguimiento-puntos">
								<?php foreach ( $f['r']['estados'] ?? array() as $e ) : ?>
									<i class="asde-seguimiento-punto asde-seguimiento-punto--<?php echo (int) $e; ?>"></i>
								<?php endforeach; ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-help"><?php esc_html_e( '* Outside the usual age range of the section (it can be adjusted to each person\'s maturity).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>
