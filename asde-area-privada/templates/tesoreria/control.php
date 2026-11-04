<?php
/**
 * Tesorería · control de pagos de la ronda.
 *
 * @var string $ronda
 * @var array  $cfg
 * @var array  $lista     familia_id => ['familia' => …, 'calc' => …]
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array  $resumen
 * @var int    $sin_fam   Educandos activos sin familia (no se les cobra)
 * @var list<array{socio:string, fin:string, motivo:string, familia:int, numero:string, nombre:string}> $bajas Bajas recientes desde la web de personas de alguna familia (doc 34)
 * @var string $estado
 * @var string $q
 * @var string $url
 * @var string $url_fam
 * @var string $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$pct = $resumen['a_pagar'] > 0 ? round( 100 * $resumen['pagado'] / $resumen['a_pagar'] ) : 0;
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Payment control ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h2>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</header>
	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'Families with a fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo (int) $resumen['familias']; ?></strong></dd></div>
		<div><dt><?php esc_html_e( 'Children who pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo (int) $resumen['hijos']; ?></dd></div>
		<div><dt><?php esc_html_e( 'Total to collect', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo esc_html( Calculadora::euros( $resumen['a_pagar'] ) ); ?></strong></dd></div>
		<div><dt><?php esc_html_e( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( $resumen['pagado'] ) ); ?> <span class="asde-muted">(<?php echo (int) $pct; ?> %)</span></dd></div>
		<div><dt><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( $resumen['pendiente'] ) ); ?></dd></div>
	</dl>
	<div class="asde-progreso" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( '%d %% collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $pct ) ); ?>"><span style="width:<?php echo (int) min( 100, $pct ); ?>%"></span></div>
	<p class="asde-muted">
		<?php foreach ( $resumen['estados'] as $e => $n ) : ?>
			<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $e ); ?>"><?php echo esc_html( ( Calculadora::etiquetas()[ $e ] ) . ': ' . $n ); ?></span>
		<?php endforeach; ?>
	</p>
	<p class="asde-muted">
		<?php esc_html_e( 'Instalments:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		<?php foreach ( $cfg['plazos'] as $pl ) : ?>
			<?php echo esc_html( asde_guardado( (string) $pl['nombre'] ) . ' (' . sprintf( /* translators: 1: text, 2: text */ __( '%1$s %%, until %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), number_format_i18n( (float) $pl['porcentaje'] ), wp_date( 'd/m/Y', strtotime( $pl['fecha'] . ' 12:00' ) ) ) . ')' ); ?> ·
		<?php endforeach; ?>
		<?php esc_html_e( 'Reference for transfers: the family no. (e.g.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( ( reset( $lista )['familia']['numero'] ?? 'F684001' ) ); ?>).
	</p>
	<?php if ( $sin_fam ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php esc_html_e( 'There are', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo (int) $sin_fam; ?></strong> <?php esc_html_e( 'youth members with an active membership who are not in any family: no fee is being calculated for them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			<a href="<?php echo esc_url( $url_fam ); ?>"><?php esc_html_e( 'Assign them a family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.
		</div>
	<?php endif; ?>
	<?php if ( $bajas ) : ?>
		<div class="asde-notice asde-notice--info asde-bajas-recientes" role="status">
			<p><strong><?php esc_html_e( 'Recent withdrawals', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> · <?php esc_html_e( 'People in a family withdrawn in the CRM from the website in the last 30 days: they no longer count in their family\'s fee.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<ul>
				<?php foreach ( $bajas as $b ) : ?>
					<?php $fin = DateTime::createFromFormat( '!Y-m-d', $b['fin'] ); ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'familia' => $b['familia'], 'ronda' => $ronda ), $url ) ); ?>"><?php echo esc_html( $b['nombre'] ); ?></a>
						· <?php /* translators: %s: family number */ echo esc_html( sprintf( __( 'family no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['numero'] ) ); ?>
						<?php if ( $fin ) : ?>
							· <?php /* translators: %s: date */ echo esc_html( sprintf( __( 'withdrawn on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fin->format( 'd/m/Y' ) ) ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<?php /* v0.31: el selector, el cuadro de búsqueda y «Filtrar» en una línea; la lista se actualiza sola (parcial.js). */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="pagos-lista">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<label for="asde-estado"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-estado" name="estado" data-asde-autoenviar>
			<?php foreach ( ( TesoreriaView::filtros() ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-q-tes" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<span class="asde-filter__buscar">
			<input type="search" id="asde-q-tes" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Family no. or name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-buscar autocomplete="off">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</span>
	</form>

	<div data-asde-parcial="pagos-lista">
	<?php if ( ! $lista ) : ?>
		<p><?php esc_html_e( 'There are no families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo '' !== $estado || '' !== $q ? esc_html( __( ' with these filters', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>. <a href="<?php echo esc_url( $url_fam ); ?>"><?php esc_html_e( 'Manage families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $id => $fila ) : ?>
					<?php
					$f = $fila['familia'];
					$c = $fila['calc'];
					?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $f['numero'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php foreach ( $f['miembros'] as $n ) : ?>
								<?php $p = $personas[ $n ] ?? null; ?>
								<span class="asde-miembro<?php echo in_array( $n, $c['fuera'], true ) ? ' asde-miembro--fuera' : ''; ?>">
									<?php echo esc_html( $p ? $p->nombre_completo() : 'Nº ' . $n ); ?><?php echo wp_kses( $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : '', asde_html_permitido() ); ?>
									<?php echo wp_kses( $p && $p->secciones ? Secciones::badge( $p->secciones[0] ) : '', asde_html_permitido() ); ?>
								</span>
							<?php endforeach; ?>
						</td>
						<?php
						// v0.31: los hijos que pagan (y el ajuste), como tooltip del importe: la fila no se parte en dos líneas.
						/* translators: %d: number of children who pay a fee */
						$detalle = sprintf( /* translators: %d: number */ _n( '%d child', '%d children', count( $c['pagan'] ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $c['pagan'] ) ) . ( $c['ajustes'] ? ' · ' . sprintf( /* translators: %s: text */ __( 'adjustment %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $c['ajustes'] ) ) : '' );
						?>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-tooltip" title="<?php echo esc_attr( $detalle ); ?>" tabindex="0"><?php echo esc_html( Calculadora::euros( $c['a_pagar'] ) ); ?></span><span class="screen-reader-text"> (<?php echo esc_html( $detalle ); ?>)</span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $c['pagado'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( max( 0, $c['pendiente'] ) ) ); ?><?php echo $c['pendiente'] < 0 ? '<br><span class="asde-muted">' . esc_html( sprintf( /* translators: %s: text */ __( 'Credit balance %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( -$c['pendiente'] ) ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-badge asde-badge--pago-<?php echo esc_attr( $c['estado'] ); ?>"><?php echo esc_html( ( Calculadora::etiquetas()[ $c['estado'] ] ) ); ?></span></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( array( 'familia' => $id, 'ronda' => $ronda ), $url ) ); ?>" data-asde-recarga><?php esc_html_e( 'View / record payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	</div>
</section>
