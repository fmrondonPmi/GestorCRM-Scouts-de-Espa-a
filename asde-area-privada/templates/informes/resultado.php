<?php
/**
 * Resultado de un informe.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe $informe
 * @var array{columnas: array<string,string>, filas: list<array<string,string>>, secciones: string[], avisos: string[], salud: bool, ronda: ?string, pago: string} $res
 * @var string                                   $url
 * @var string                                   $url_excel
 * @var string                                   $url_print Versión para imprimir (v0.29)
 * @var string                                   $seccion   Sección elegida al verlo ('' = todas las que ve)
 * @var string[]                                 $secciones Las que puede elegir
 * @var array                                    $args_base Parámetros que reproducen el informe (sin la sección)
 * @var string                                   $url_edit
 * @var bool                                     $global
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Informes\Generador;

defined( 'ABSPATH' ) || exit;

$ronda = $res['ronda'] ?? null;
?>
<p class="asde-back asde-no-print"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Back to reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<?php /* v0.31: al cambiar la sección se actualiza solo el resultado (parcial.js). */ ?>
<div data-asde-parcial="informe-resultado">
<section class="asde-card" data-asde-plegable="informe-resultado">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $informe->titulo() ); ?></h2>
		<p class="asde-actions asde-no-print">
			<a class="button no-transition" href="<?php echo esc_url( $url_excel ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php /* v0.29: la versión para imprimir, en la ventana emergente (informes.js); sin JavaScript, en otra pestaña. */ ?>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_print ); ?>" target="_blank" rel="noopener" data-asde-imprimir="<?php echo esc_attr( $informe->titulo() ); ?>"><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_edit ); ?>"><?php echo $informe->id ? esc_html( __( 'Edit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Edit / save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a>
		</p>
	</header>
	<p class="asde-muted"><?php echo esc_html( Generador::resumen( $informe, $res ) ); ?></p>
	<?php if ( count( $secciones ) > 1 ) : ?>
		<?php /* v0.29: elegir la sección sin editar el informe (p. ej. las fichas reducidas de una sección). */ ?>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea asde-no-print">
			<?php foreach ( $args_base as $k => $v ) : ?>
				<?php foreach ( (array) $v as $uno ) : ?>
					<input type="hidden" name="<?php echo esc_attr( is_array( $v ) ? $k . '[]' : $k ); ?>" value="<?php echo esc_attr( (string) $uno ); ?>">
				<?php endforeach; ?>
			<?php endforeach; ?>
			<label for="asde-inf-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-inf-seccion" name="seccion" data-asde-autoenviar>
				<option value=""><?php echo $informe->filtros['secciones'] && '' === $seccion ? esc_html( __( 'Those of the report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></option>
				<?php foreach ( $secciones as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
		</form>
	<?php endif; ?>
	<?php if ( null !== $ronda ) : ?>
		<?php /* translators: %s: round (2026-2027) */ ?>
		<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Fee for round %s, from Treasury: the amounts are those of each member\'s family (with their siblings).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ronda ) ); ?></p>
	<?php endif; ?>
	<?php foreach ( $res['avisos'] as $aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( $aviso ); ?></div>
	<?php endforeach; ?>
	<?php if ( $res['salud'] ) : ?>
		<div class="asde-notice asde-notice--info asde-no-print" role="status"><?php esc_html_e( 'This report includes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'health data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': use them only for the activity, do not forward them and destroy printed copies when finished. Who consults it is recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $res['filas'] ) : ?>
		<p><?php esc_html_e( 'There are no members matching these filters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( $informe->en_fichas() ) : ?>
		<?php /* v0.29: una ficha por persona (al imprimir, una por página). */ ?>
		<div class="asde-informe-fichas">
			<?php foreach ( $res['filas'] as $fila ) : ?>
				<?php $ficha = Generador::ficha( $fila, $res['columnas'] ); ?>
				<article class="asde-informe-ficha">
					<h3><?php echo esc_html( $ficha['titulo'] ); ?></h3>
					<dl>
						<?php foreach ( $ficha['datos'] as $etiqueta => $valor ) : ?>
							<div><dt><?php echo esc_html( $etiqueta ); ?></dt><dd><?php echo '' !== $valor ? esc_html( $valor ) : '—'; ?></dd></div>
						<?php endforeach; ?>
					</dl>
				</article>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="asde-table-wrap">
			<table class="asde-table asde-table--informe">
				<thead>
					<tr>
						<?php foreach ( $res['columnas'] as $label ) : ?>
							<th scope="col"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $res['filas'] as $fila ) : ?>
						<tr>
							<?php foreach ( $fila as $valor ) : ?>
								<td><?php echo esc_html( $valor ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
</div>
