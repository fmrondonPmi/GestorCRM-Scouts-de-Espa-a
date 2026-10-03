<?php
/**
 * Resultado de un informe.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe $informe
 * @var array{columnas: array<string,string>, filas: list<array<string,string>>, secciones: string[], avisos: string[], salud: bool} $res
 * @var string                                   $url
 * @var string                                   $url_csv
 * @var string                                   $url_edit
 * @var bool                                     $global
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$f = $informe->filtros;
?>
<p class="asde-back asde-no-print"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Volver a informes' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $informe->titulo() ); ?></h2>
		<p class="asde-actions asde-no-print">
			<a class="button" href="<?php echo esc_url( $url_csv ); ?>"><?php asde_e( 'Descargar CSV' ); ?></a>
			<button type="button" class="button asde-button-secundario" onclick="window.print()"><?php asde_e( 'Imprimir' ); ?></button>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_edit ); ?>"><?php echo $informe->id ? asde_t( 'Editar' ) : asde_t( 'Editar / guardar' ); ?></a>
		</p>
	</header>
	<p class="asde-muted">
		<?php echo esc_html( sprintf( asde_n( '%d socio', '%d socios', count( $res['filas'] ) ), count( $res['filas'] ) ) ); ?>
		<?php asde_e( 'con membresía activa ·' ); ?>
		<?php
		if ( $res['secciones'] ) {
			echo esc_html( implode( ', ', array_map( array( Secciones::class, 'nombre' ), $res['secciones'] ) ) );
		} else {
			echo asde_t( 'todo el grupo' );
		}
		?>
		<?php echo null !== $f['edad_min'] ? esc_html( ' · ' . sprintf( asde_t( 'desde %d años' ), $f['edad_min'] ) ) : ''; ?>
		<?php echo null !== $f['edad_max'] ? esc_html( ' · ' . sprintf( asde_t( 'hasta %d años' ), $f['edad_max'] ) ) : ''; ?>
		<?php echo $f['salud'] ? asde_t( ' · solo con datos de salud relevantes' ) : ''; ?>
		· <?php echo esc_html( wp_date( 'd/m/Y H:i' ) ); ?>
	</p>
	<?php foreach ( $res['avisos'] as $aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( $aviso ); ?></div>
	<?php endforeach; ?>
	<?php if ( $res['salud'] ) : ?>
		<div class="asde-notice asde-notice--info asde-no-print" role="status"><?php asde_e( 'Este informe incluye' ); ?> <strong><?php asde_e( 'datos de salud' ); ?></strong><?php asde_e( ': úsalos solo para la actividad, no los reenvíes y destruye las copias impresas al terminar. Queda registrado quién lo consulta.' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $res['filas'] ) : ?>
		<p><?php asde_e( 'No hay socios que cumplan estos filtros.' ); ?></p>
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
