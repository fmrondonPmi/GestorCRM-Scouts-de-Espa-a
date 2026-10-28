<?php
/**
 * Mis pagos: lo que ha registrado Tesorería (solo lectura).
 *
 * @var string  $ronda      Ronda en curso
 * @var array[] $cuotas     familia_id => [familia, personas, calc]
 * @var array[] $pagos      [fecha, concepto, metodo, importe, ref], más recientes primero
 * @var array[] $pendientes Uniformes y eventos sin pagar: [concepto, importe, ref?]
 * @var string  $url_uniforme
 * @var string  $url_eventos  «Mis eventos» ('' si no hay nada de eventos)
 * @var array   $sepa       Domiciliación SEPA: lista, acreedor, texto y, por familia, mandato, puede, proximos, valores
 * @var string  $url        Esta misma página
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', strtotime( $f ) ) : '';
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'Here you see the payments Treasury has recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $cuotas ? esc_html( __( ' and how this year\'s group fee is going', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?><?php esc_html_e( '. If you miss any, talk to Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</section>

<?php foreach ( $cuotas as $c ) : ?>
	<?php
	$calc    = $c['calc'];
	$nombres = array();
	foreach ( $c['familia']['miembros'] as $n ) {
		if ( isset( $c['personas'][ $n ] ) && in_array( $n, $calc['pagan'], true ) ) {
			$nombres[] = $c['personas'][ $n ]->nombre;
		}
	}
	?>
	<section class="asde-card">
		<header class="asde-card__header asde-card__header--split">
			<h3 class="asde-card__title"><?php esc_html_e( 'Group fee · round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h3>
			<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $calc['estado'] ); ?>"><?php echo esc_html( ( Calculadora::etiquetas()[ $calc['estado'] ] ) ); ?></span>
		</header>
		<?php if ( Calculadora::EXENTO === $calc['estado'] ) : ?>
			<p><?php esc_html_e( 'There is no fee to pay this year.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $c['familia']['numero'] ); ?><?php echo $nombres ? esc_html( ' · ' . implode( ', ', $nombres ) ) : ''; ?></p>
			<ul class="asde-resumen">
				<li><strong><?php echo esc_html( Calculadora::euros( $calc['a_pagar'] ) ); ?></strong> <?php esc_html_e( 'to pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><strong><?php echo esc_html( Calculadora::euros( $calc['pagado'] ) ); ?></strong> <?php esc_html_e( 'paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><strong><?php echo esc_html( Calculadora::euros( max( 0, $calc['pendiente'] ) ) ); ?></strong> <?php esc_html_e( 'pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			</ul>
			<?php if ( $calc['plazos'] && $calc['a_pagar'] > 0 ) : ?>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $calc['plazos'] as $pl ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $pl['fecha'] ) ) ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $pl['importe'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $pl['cubierto'] ? '✓ ' . esc_html( __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ( $pl['vencido'] ? '<span class="asde-help asde-help--warning">' . esc_html( __( 'Overdue', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : esc_html( __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="asde-help"><?php esc_html_e( 'If you pay by transfer, state the reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $c['familia']['numero'] ); ?></strong>.</p>
		<?php endif; ?>
	</section>
	<?php $s = $sepa['familias'][ $c['familia']['id'] ] ?? null; ?>
	<?php if ( $s ) : ?>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla propia del plugin: cada valor se escapa dentro de ella.
		echo wp_kses(
			TemplateLoader::render(
			'pagos/sepa',
			array(
				'familia'  => $c['familia'],
				'mandato'  => $s['mandato'],
				'cuenta'   => $s['cuenta'],
				'puede'    => $s['puede'],
				'proximos' => $s['proximos'],
				'valores'  => $s['valores'],
				'lista'    => $sepa['lista'],
				'acreedor' => $sepa['acreedor'],
				'texto'    => $sepa['texto'],
				'url'      => $url,
			)
			),
			asde_html_permitido()
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php endif; ?>
<?php endforeach; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Recorded payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $pagos ) : ?>
		<p><?php esc_html_e( 'There are no recorded payments yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pagos as $p ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $p['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $p['concepto'] ); ?><?php echo '' !== $p['ref'] ? '<br><span class="asde-muted">' . esc_html( sprintf( /* translators: %s: text */ __( 'Reference: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p['ref'] ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $p['metodo'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $p['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $pendientes ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Pending payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<ul>
			<?php foreach ( $pendientes as $p ) : ?>
				<li><?php echo esc_html( $p['concepto'] ); ?> — <strong><?php echo esc_html( $p['importe'] ); ?></strong><?php echo ! empty( $p['ref'] ) ? '<br><span class="asde-muted">' . esc_html( sprintf( /* translators: %s: text */ __( 'Reference for the transfer or Bizum: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p['ref'] ) ) . '</span>' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="asde-muted"><?php esc_html_e( 'Uniform orders can be seen in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_uniforme ); ?>"><?php esc_html_e( 'Uniform', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
		<?php if ( ! empty( $url_eventos ) ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'Event sign-ups can be seen in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_eventos ); ?>"><?php esc_html_e( 'My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
		<?php endif; ?>
	</section>
<?php endif; ?>
