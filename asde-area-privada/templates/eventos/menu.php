<?php
/**
 * Evento · menú (v0.41, doc 49, B2): qué se come cada día, la lista de la compra y lo que cuesta. Sin datos de personas: los
 * alérgenos que se enseñan son los de las recetas.
 *
 * @var array                 $e
 * @var string[]              $dias          Los días del evento (Y-m-d)
 * @var array                 $por_dia       día => comida => platos
 * @var array[]               $recetas       Las que se pueden elegir
 * @var int                   $comensales    Inscritos activos + equipo
 * @var array                 $compra        filas, total, sin_coste
 * @var float                 $coste_total
 * @var array<string, float>  $coste_dias
 * @var string                $dia           Día de la lista de la compra ('' = todo el evento)
 * @var bool                  $puede_editar
 * @var bool                  $puede_partida
 * @var string                $url_menu
 * @var string                $url_csv
 * @var string                $url_recetas
 * @var string                $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\MenuTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$nombre_receta = static fn( array $r ): string => IngredientesTable::COMUN === $r['grupo'] ? asde_t( $r['nombre'] ) : $r['nombre'];
$fecha         = static fn( string $d ): string => wp_date( 'l d/m/Y', strtotime( $d . ' 12:00 UTC' ) );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Menú del evento' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla"><button type="button" class="button asde-button-secundario" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button></span>
	</header>
	<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( 'Cuentan %d comensal por defecto (los inscritos y el equipo); cada plato puede llevar los suyos.', 'Cuentan %d comensales por defecto (los inscritos y el equipo); cada plato puede llevar los suyos.', max( 1, $comensales ) ), max( 1, $comensales ) ) ); ?>
		<?php asde_e( 'Los alérgenos son los de las recetas: aquí no se mira a nadie en concreto.' ); ?></p>
	<?php if ( ! $recetas && $puede_editar ) : ?>
		<div class="asde-notice asde-notice--warning"><?php asde_e( 'Todavía no hay recetas.' ); ?> <a href="<?php echo esc_url( $url_recetas ); ?>"><?php asde_e( 'Ve al recetario' ); ?></a></div>
	<?php endif; ?>

	<?php foreach ( $dias as $d ) : ?>
		<h4><?php echo esc_html( $fecha( $d ) ); ?><?php echo isset( $coste_dias[ $d ] ) ? ' <span class="asde-muted">· ' . esc_html( Calculadora::euros( $coste_dias[ $d ] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></h4>
		<table class="asde-table">
			<tbody>
				<?php foreach ( MenuTable::COMIDAS as $c => $nombre_c ) : ?>
					<?php $platos = $por_dia[ $d ][ $c ] ?? array(); ?>
					<tr>
						<th scope="row"><?php echo esc_html( asde_t( $nombre_c ) ); ?></th>
						<td>
							<?php if ( ! $platos ) : ?>
								<span class="asde-muted"><?php asde_e( 'Sin platos' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $platos as $p ) : ?>
								<div class="asde-menu__plato">
									<strong><?php echo esc_html( $nombre_receta( $p['receta'] ) ); ?></strong>
									<span class="asde-muted">· <?php echo esc_html( sprintf( asde_n( '%d comensal', '%d comensales', $p['comensales'] ), $p['comensales'] ) ); ?></span>
									<?php if ( $p['alergenos']['contiene'] ) : ?>
										<span>· <?php echo esc_html( sprintf( asde_t( 'Contiene: %s' ), Alergenos::texto( $p['alergenos']['contiene'] ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( $p['alergenos']['trazas'] ) : ?>
										<span class="asde-muted">· <?php echo esc_html( sprintf( asde_t( 'Puede contener: %s' ), Alergenos::texto( $p['alergenos']['trazas'] ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( $p['alergenos']['sin_revisar'] ) : ?>
										<span class="asde-badge asde-badge--pago-parcial"><?php asde_e( 'Ingredientes sin revisar' ); ?></span>
									<?php endif; ?>
									<?php if ( $puede_editar ) : ?>
										<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline asde-solo-pantalla">
											<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
											<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
											<input type="hidden" name="pestana" value="menu">
											<input type="hidden" name="plato" value="<?php echo (int) $p['plato']['id']; ?>">
											<input type="number" name="comensales" min="1" max="5000" size="5" value="<?php echo null === $p['plato']['comensales'] ? '' : (int) $p['plato']['comensales']; ?>" placeholder="<?php echo esc_attr( asde_a( 'por defecto' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Comensales de este plato' ) ); ?>">
											<button type="submit" class="asde-btn-link" name="asde_action" value="menu_comensales"><?php asde_e( 'Cambiar' ); ?></button>
											<button type="submit" class="asde-btn-link" name="asde_action" value="menu_quitar"><?php asde_e( 'Quitar' ); ?></button>
										</form>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
							<?php if ( $puede_editar && $recetas ) : ?>
								<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline asde-solo-pantalla">
									<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
									<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
									<input type="hidden" name="pestana" value="menu">
									<input type="hidden" name="asde_action" value="menu_anadir">
									<input type="hidden" name="dia" value="<?php echo esc_attr( $d ); ?>">
									<input type="hidden" name="comida" value="<?php echo esc_attr( $c ); ?>">
									<select name="receta" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Receta para %s' ), asde_t( $nombre_c ) ) ); ?>">
										<option value="0">— <?php asde_e( 'Añadir un plato' ); ?> —</option>
										<?php foreach ( $recetas as $r ) : ?>
											<option value="<?php echo (int) $r['id']; ?>"><?php echo esc_html( $nombre_receta( $r ) ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button asde-button-secundario"><?php asde_e( 'Añadir' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
</section>

<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Lista de la compra' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla">
			<a class="no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php asde_e( 'Descargar CSV' ); ?></a>
		</span>
	</header>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-solo-pantalla">
		<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
		<input type="hidden" name="pestana" value="menu">
		<label for="asde-mn-dia"><?php asde_e( 'Para' ); ?></label>
		<select id="asde-mn-dia" name="dia" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todo el evento' ); ?></option>
			<?php foreach ( $dias as $d ) : ?>
				<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $dia, $d ); ?>><?php echo esc_html( $fecha( $d ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>
	<?php if ( ! $compra['filas'] ) : ?>
		<p><?php asde_e( 'Todavía no hay nada que comprar: añade platos al menú.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Ingrediente' ); ?></th><th scope="col"><?php asde_e( 'Cantidad' ); ?></th><th scope="col" class="asde-num"><?php asde_e( 'Coste' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $compra['filas'] as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Ingrediente' ) ); ?>"><?php echo esc_html( $f['nombre'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cantidad' ) ); ?>"><?php echo esc_html( $f['texto'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Coste' ) ); ?>" class="asde-num"><?php echo null === $f['coste'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $f['coste'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row" colspan="2"><?php asde_e( 'Total' ); ?></th><td class="asde-num"><?php echo esc_html( Calculadora::euros( $compra['total'] ) ); ?></td></tr></tfoot>
		</table>
		<?php if ( $compra['sin_coste'] ) : ?>
			<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Sin coste anotado (no suman): %s.' ), implode( ', ', $compra['sin_coste'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card asde-solo-pantalla" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php asde_e( 'Coste del menú' ); ?></h3>
	<?php if ( $coste_total <= 0 ) : ?>
		<p><?php asde_e( 'El menú todavía no tiene coste. Anota el coste de los ingredientes en el recetario y añade platos.' ); ?></p>
	<?php else : ?>
		<p><strong><?php echo esc_html( Calculadora::euros( $coste_total ) ); ?></strong>
			<?php if ( $comensales > 0 ) : ?>
				· <?php echo esc_html( sprintf( asde_t( '%s por comensal' ), Calculadora::euros( round( $coste_total / $comensales, 2 ) ) ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $puede_partida ) : ?>
			<form method="post" action="<?php echo esc_url( $url_menu ); ?>">
				<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
				<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
				<input type="hidden" name="pestana" value="menu">
				<p class="asde-help"><?php asde_e( 'Se añade como una partida de gasto «Alimentación» en el presupuesto. Nunca se cambia sola: si el menú cambia, quita la partida y propón otra.' ); ?></p>
				<p><button type="submit" class="button" name="asde_action" value="menu_partida"><?php asde_e( 'Proponer como partida de Alimentación' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
