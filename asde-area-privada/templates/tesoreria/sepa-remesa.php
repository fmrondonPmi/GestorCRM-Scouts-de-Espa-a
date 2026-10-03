<?php
/**
 * Tesorería · una remesa SEPA: fichero, aviso a las familias, envío al banco y cobros.
 *
 * @var array   $r         Remesa (Sepa\Remesas::obtener)
 * @var array[] $lineas    Sus adeudos
 * @var array   $familias  id => familia
 * @var array   $personas  nº Scouts de España => Persona
 * @var string  $url       Domiciliaciones de la ronda
 * @var string  $url_fam   Control de pagos
 */
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fmt_fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$fmt_dt    = static fn( ?string $f ) => $f ? get_date_from_gmt( $f, 'd/m/Y H:i' ) : '';
$nombres   = static function ( int $id ) use ( $familias, $personas ): string {
	$f = $familias[ $id ] ?? null;
	return $f ? implode( ', ', array_map( static fn( $num ) => isset( $personas[ $num ] ) ? $personas[ $num ]->nombre : $num, $f['miembros'] ) ) : '';
};
$estado_l  = array(
	Remesas::PENDIENTE => asde_t( 'Pendiente' ),
	Remesas::COBRADO   => asde_t( 'Cobrado' ),
	Remesas::DEVUELTO  => asde_t( 'Devuelto' ),
);
$pendientes = array_filter( $lineas, static fn( $l ) => Remesas::PENDIENTE === $l['estado'] );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Domiciliaciones' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Remesa' ); ?> <?php echo esc_html( $r['numero'] ); ?></h2>
		<span class="asde-badge"><?php echo esc_html( asde_t( Remesas::ESTADOS[ $r['estado'] ] ?? $r['estado'] ) ); ?></span>
	</header>
	<ul class="asde-resumen">
		<li><?php asde_e( 'Ronda' ); ?> <strong><?php echo esc_html( $r['ronda'] ); ?></strong> · <?php echo esc_html( $r['plazo'] ); ?></li>
		<li><?php asde_e( 'Fecha de cobro' ); ?> <strong><?php echo esc_html( $fmt_fecha( $r['fecha_cobro'] ) ); ?></strong></li>
		<li><strong><?php echo esc_html( Remesas::adeudos( (int) $r['adeudos'] ) ); ?></strong> · <strong><?php echo esc_html( Calculadora::euros( $r['total'] ) ); ?></strong></li>
	</ul>
	<p class="asde-muted">
		<?php asde_e( 'Creada el' ); ?> <?php echo esc_html( $fmt_dt( $r['creada'] ) ); ?>.
		<?php echo esc_html( $r['avisada_en'] ? sprintf( asde_t( 'Familias avisadas el %s. ' ), $fmt_dt( $r['avisada_en'] ) ) : asde_t( 'Todavía no se ha avisado a las familias. ' ) ); ?>
		<?php echo $r['enviada_en'] ? esc_html( sprintf( asde_t( 'Enviada al banco el %s. ' ), $fmt_dt( $r['enviada_en'] ) ) ) : ''; ?>
		<?php echo $r['descargas'] ? esc_html( sprintf( asde_n( 'Descargada %d vez.', 'Descargada %d veces.', (int) $r['descargas'] ), (int) $r['descargas'] ) ) : ''; ?>
	</p>

	<?php if ( Remesas::ANULADA === $r['estado'] ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Remesa anulada. Sus datos se han borrado.' ); ?></div>
	<?php elseif ( ! $lineas ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Los datos de los adeudos de esta remesa ya no se conservan (se borran 14 meses después de la fecha de cobro).' ); ?></div>
	<?php else : ?>
		<div class="asde-actions">
			<form method="post" data-asde-sin-carga>
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_descargar">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<button type="submit" class="button"><?php asde_e( 'Descargar el fichero XML' ); ?></button>
			</form>
			<form method="post" onsubmit="return confirm(<?php echo asde_js( 'Se enviará un email a las cuentas de cada familia con la fecha y el importe del cargo. ¿Continuar?' ); ?>);">
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_avisar">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<button type="submit" class="button asde-button-secundario"><?php echo $r['avisada_en'] ? asde_t( 'Volver a avisar a las familias' ) : asde_t( 'Avisar a las familias por email' ); ?></button>
			</form>
			<?php if ( Remesas::GENERADA === $r['estado'] ) : ?>
				<form method="post" onsubmit="return confirm(<?php echo asde_js( '¿Confirmas que has subido el fichero al banco?' ); ?>);">
					<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_enviada">
					<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="button asde-button-secundario"><?php asde_e( 'Marcar como enviada al banco' ); ?></button>
				</form>
				<form method="post" onsubmit="return confirm(<?php echo asde_js( '¿Anular la remesa? Se borrarán sus datos.' ); ?>);">
					<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_anular">
					<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
					<input type="hidden" name="ronda" value="<?php echo esc_attr( $r['ronda'] ); ?>">
					<button type="submit" class="asde-btn-link"><?php asde_e( 'Anular' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( Remesas::GENERADA === $r['estado'] ) : ?>
			<p class="asde-help"><?php asde_e( 'El fichero lleva las cuentas de las familias sin cifrar: guárdalo solo el tiempo necesario y bórralo del ordenador después de subirlo al banco.' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $lineas ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Adeudos' ); ?></h3>
		<?php $puede_cobros = Remesas::ENVIADA === $r['estado'] && $pendientes; ?>
		<?php if ( $puede_cobros ) : ?>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_cobros">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<input type="hidden" name="ronda" value="<?php echo esc_attr( $r['ronda'] ); ?>">
		<?php endif; ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Familia' ); ?></th>
					<th scope="col"><?php asde_e( 'Socios' ); ?></th>
					<th scope="col"><?php asde_e( 'Titular · cuenta' ); ?></th>
					<th scope="col"><?php asde_e( 'Secuencia' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<?php if ( $puede_cobros ) : ?><th scope="col"><?php asde_e( 'Cobrado' ); ?></th><th scope="col"><?php asde_e( 'Devuelto' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Familia' ); ?>"><a href="<?php echo esc_url( add_query_arg( array( 'familia' => (int) $l['familia'], 'ronda' => $r['ronda'] ), $url_fam ) ); ?>"><?php echo esc_html( $l['familia_n'] ); ?></a></td>
						<td data-label="<?php echo asde_a( 'Socios' ); ?>"><?php echo esc_html( $nombres( (int) $l['familia'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Titular' ); ?>"><?php echo esc_html( $l['titular'] . ' · ' . ( $l['iban_mask'] ?? '' ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Secuencia' ); ?>"><?php echo esc_html( $l['secuencia'] ); ?></td>
						<td data-label="<?php echo asde_a( 'Importe' ); ?>"><?php echo esc_html( Calculadora::euros( $l['centimos'] / 100 ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Estado' ); ?>"><?php echo esc_html( $estado_l[ $l['estado'] ] ?? $l['estado'] ); ?></td>
						<?php if ( $puede_cobros ) : ?>
							<?php if ( Remesas::PENDIENTE === $l['estado'] ) : ?>
								<td data-label="<?php echo asde_a( 'Cobrado' ); ?>"><input type="checkbox" name="cobrada[]" value="<?php echo (int) $l['familia']; ?>" checked aria-label="Cobrado a <?php echo esc_attr( $l['familia_n'] ); ?>"></td>
								<td data-label="<?php echo asde_a( 'Devuelto' ); ?>"><input type="checkbox" name="devuelta[]" value="<?php echo (int) $l['familia']; ?>" aria-label="Devuelto a <?php echo esc_attr( $l['familia_n'] ); ?>"></td>
							<?php else : ?>
								<td></td><td></td>
							<?php endif; ?>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $puede_cobros ) : ?>
				<p>
					<label for="asde-sepa-cobro-fecha"><?php asde_e( 'Fecha en que el banco cobró' ); ?></label>
					<input type="date" id="asde-sepa-cobro-fecha" name="fecha" value="<?php echo esc_attr( min( $r['fecha_cobro'], current_time( 'Y-m-d' ) ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					<span class="asde-help"><?php asde_e( 'Marca «Cobrado» en los que ya han salido de la cuenta (pasan a ser pagos de la familia) y «Devuelto» en los que el banco ha devuelto; los que dejes sin marcar seguirán pendientes.' ); ?></span>
				</p>
				<p><button type="submit" class="button"><?php asde_e( 'Registrar los cobros' ); ?></button></p>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
