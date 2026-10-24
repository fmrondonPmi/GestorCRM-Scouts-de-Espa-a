<?php
/**
 * Asociar un movimiento del banco con otro (v0.58, petición del usuario: «por ejemplo un gasto y un ingreso que es la
 * devolución del mismo»). Se abre en la ventana emergente desde el listado; sin JavaScript, en su página. Primero salen
 * los de signo contrario y el mismo importe; con el buscador, los que contienen el texto.
 *
 * @var bool         $hecho      Ya se han asociado (desde la ventana): solo el aviso y cerrar
 * @var array|null   $mov        El movimiento elegido
 * @var array[]      $candidatos Con los que se puede asociar (con 'igual': signo contrario y mismo importe)
 * @var string       $q
 * @var string       $url
 * @var string       $volver     Listado del que se viene
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha   = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$importe = static fn( float $v ) => ( $v > 0 ? '+' : '' ) . Calculadora::euros( $v );
$texto   = static fn( array $m ) => trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ) );
?>
<?php if ( $hecho ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php asde_e( 'Movimientos asociados' ); ?></h2>
		<p><?php asde_e( 'Cierra esta ventana para volver al listado: ya sale actualizado.' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
	</section>
<?php elseif ( ! $mov ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php asde_e( 'Asociar con otro movimiento' ); ?></h2>
		<p><?php asde_e( 'Ese movimiento no existe.' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
	</section>
<?php else : ?>
	<section class="asde-card asde-banco-asociar" data-asde-no-plegable>
		<h2 class="asde-card__title"><?php asde_e( 'Asociar con otro movimiento' ); ?></h2>
		<p>
			<strong><?php echo esc_html( $fecha( $mov['fecha'] ) . ' · ' . $importe( (float) $mov['importe'] ) ); ?></strong><br>
			<?php echo esc_html( $texto( $mov ) ); ?>
		</p>
		<?php if ( ! empty( $mov['asociado_id'] ) ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este movimiento ya está asociado con otro: quita antes esa asociación en el listado.' ); ?></div>
		<?php else : ?>
			<p class="asde-muted"><?php asde_e( 'Por ejemplo, un gasto y el ingreso que lo devuelve. Asociarlos no cambia su estado ni su tipo: sirve para saber que van juntos. Primero salen los de signo contrario y el mismo importe.' ); ?></p>
			<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-en-ventana>
				<input type="hidden" name="asociar" value="<?php echo (int) $mov['id']; ?>">
				<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
				<label for="asde-asociar-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
				<input type="search" id="asde-asociar-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Concepto, ordenante o referencia' ) ); ?>">
				<button type="submit" class="button"><?php asde_e( 'Buscar' ); ?></button>
			</form>
			<?php if ( ! $candidatos ) : ?>
				<p><?php asde_e( 'No hay ningún movimiento sin asociar con este filtro.' ); ?></p>
			<?php else : ?>
				<table class="asde-table">
					<thead>
						<tr>
							<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
							<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
							<th scope="col"><?php asde_e( 'Importe' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $candidatos as $c ) : ?>
							<tr<?php echo $c['igual'] ? ' class="asde-banco-asociar__igual"' : ''; ?>>
								<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $c['fecha'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
									<?php echo esc_html( $texto( $c ) ); ?>
									<br><span class="asde-muted"><?php echo esc_html( asde_t( BancoTable::ESTADOS[ $c['estado'] ] ?? $c['estado'] ) ); ?></span>
								</td>
								<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $c['importe'] > 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( $importe( (float) $c['importe'] ) ); ?></td>
								<td data-label="">
									<form method="post" action="<?php echo esc_url( $url ); ?>">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $mov['id'] ); ?>
										<input type="hidden" name="asde_action" value="asociar">
										<input type="hidden" name="mov" value="<?php echo (int) $mov['id']; ?>">
										<input type="hidden" name="con" value="<?php echo (int) $c['id']; ?>">
										<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
										<button type="submit" class="button"><?php asde_e( 'Asociar' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
	</section>
<?php endif; ?>
