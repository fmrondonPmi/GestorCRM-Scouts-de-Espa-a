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
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', strtotime( $f ) ) : '';
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Mis pagos' ); ?></h2>
	<p><?php asde_e( 'Aquí ves los pagos que ha registrado Tesorería' ); ?><?php echo $cuotas ? asde_t( ' y cómo va la cuota del grupo de este año' ) : ''; ?><?php asde_e( '. Si echas en falta alguno, habla con Tesorería.' ); ?></p>
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
			<h3 class="asde-card__title"><?php asde_e( 'Cuota del grupo · ronda' ); ?> <?php echo esc_html( $ronda ); ?></h3>
			<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $calc['estado'] ); ?>"><?php echo esc_html( asde_t( Calculadora::ETIQUETAS[ $calc['estado'] ] ) ); ?></span>
		</header>
		<?php if ( Calculadora::EXENTO === $calc['estado'] ) : ?>
			<p><?php asde_e( 'Este año no hay cuota que pagar.' ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><?php asde_e( 'Familia' ); ?> <?php echo esc_html( $c['familia']['numero'] ); ?><?php echo $nombres ? esc_html( ' · ' . implode( ', ', $nombres ) ) : ''; ?></p>
			<ul class="asde-resumen">
				<li><strong><?php echo esc_html( Calculadora::euros( $calc['a_pagar'] ) ); ?></strong> <?php asde_e( 'a pagar' ); ?></li>
				<li><strong><?php echo esc_html( Calculadora::euros( $calc['pagado'] ) ); ?></strong> <?php asde_e( 'pagado' ); ?></li>
				<li><strong><?php echo esc_html( Calculadora::euros( max( 0, $calc['pendiente'] ) ) ); ?></strong> <?php asde_e( 'pendiente' ); ?></li>
			</ul>
			<?php if ( $calc['plazos'] && $calc['a_pagar'] > 0 ) : ?>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php asde_e( 'Plazo' ); ?></th><th scope="col"><?php asde_e( 'Importe' ); ?></th><th scope="col"><?php asde_e( 'Estado' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $calc['plazos'] as $pl ) : ?>
							<tr>
								<td data-label="<?php echo asde_a( 'Plazo' ); ?>"><?php echo esc_html( sprintf( asde_t( 'Hasta el %s' ), $fecha( $pl['fecha'] ) ) ); ?></td>
								<td data-label="<?php echo asde_a( 'Importe' ); ?>"><?php echo esc_html( Calculadora::euros( $pl['importe'] ) ); ?></td>
								<td data-label="<?php echo asde_a( 'Estado' ); ?>"><?php echo $pl['cubierto'] ? '✓ ' . esc_html( asde_t( 'Pagado' ) ) : ( $pl['vencido'] ? '<span class="asde-help asde-help--warning">' . esc_html( asde_t( 'Vencido' ) ) . '</span>' : esc_html( asde_t( 'Pendiente' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="asde-help"><?php asde_e( 'Si pagas por transferencia, indica la referencia' ); ?> <strong><?php echo esc_html( $c['familia']['numero'] ); ?></strong>.</p>
		<?php endif; ?>
	</section>
	<?php $s = $sepa['familias'][ $c['familia']['id'] ] ?? null; ?>
	<?php if ( $s ) : ?>
		<?php
		echo TemplateLoader::render( // phpcs:ignore WordPress.Security.EscapeOutput
			'pagos/sepa',
			array(
				'familia'  => $c['familia'],
				'mandato'  => $s['mandato'],
				'puede'    => $s['puede'],
				'proximos' => $s['proximos'],
				'valores'  => $s['valores'],
				'lista'    => $sepa['lista'],
				'acreedor' => $sepa['acreedor'],
				'texto'    => $sepa['texto'],
				'url'      => $url,
			)
		);
		?>
	<?php endif; ?>
<?php endforeach; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Pagos registrados' ); ?></h3>
	<?php if ( ! $pagos ) : ?>
		<p><?php asde_e( 'Todavía no hay pagos registrados.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
					<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
					<th scope="col"><?php asde_e( 'Forma de pago' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pagos as $p ) : ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Fecha' ); ?>"><?php echo esc_html( $fecha( $p['fecha'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Concepto' ); ?>"><?php echo esc_html( $p['concepto'] ); ?><?php echo '' !== $p['ref'] ? '<br><span class="asde-muted">' . esc_html( sprintf( asde_t( 'Referencia: %s' ), $p['ref'] ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo asde_a( 'Forma de pago' ); ?>"><?php echo esc_html( $p['metodo'] ); ?></td>
						<td data-label="<?php echo asde_a( 'Importe' ); ?>"><?php echo esc_html( Calculadora::euros( $p['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $pendientes ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Pendiente de pago' ); ?></h3>
		<ul>
			<?php foreach ( $pendientes as $p ) : ?>
				<li><?php echo esc_html( $p['concepto'] ); ?> — <strong><?php echo esc_html( $p['importe'] ); ?></strong><?php echo ! empty( $p['ref'] ) ? '<br><span class="asde-muted">' . esc_html( sprintf( asde_t( 'Referencia para la transferencia o el Bizum: %s' ), $p['ref'] ) ) . '</span>' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="asde-muted"><?php asde_e( 'Los pedidos de uniforme se ven en' ); ?> <a href="<?php echo esc_url( $url_uniforme ); ?>"><?php asde_e( 'Uniforme' ); ?></a>.</p>
		<?php if ( ! empty( $url_eventos ) ) : ?>
			<p class="asde-muted"><?php asde_e( 'Las inscripciones a los eventos se ven en' ); ?> <a href="<?php echo esc_url( $url_eventos ); ?>"><?php asde_e( 'Mis eventos' ); ?></a>.</p>
		<?php endif; ?>
	</section>
<?php endif; ?>
