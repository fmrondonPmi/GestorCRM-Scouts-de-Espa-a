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
 * @var array                 $validacion   Validacion::evaluar() (en vivo, no se guarda)
 * @var string                $nivel         conflicto | riesgo | incompleto | sin_conflictos (con lo confirmado)
 * @var array<string, array>  $confirmaciones «plato|alérgeno» => confirmación
 * @var bool                  $ve_quienes    Quien consulta ve quiénes están afectados
 * @var bool                  $puede_hoja    Puede generar la hoja de cocina
 * @var array|null            $entrega       Última hoja entregada: cuando, como, desfasada
 * @var string                $url_hoja
 * @var array<int, string>    $confirmantes  Quién confirmó cada aviso
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\MenuTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

// La hoja de cocina para imprimir (B3): sustituye al menú.
if ( 'hoja' === ( $modo ?? 'menu' ) ) {
	echo \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'eventos/menu-hoja', array( 'e' => $e, 'hoja' => $hoja, 'url_menu' => $url_menu ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado en su plantilla.
	return;
}

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

<?php
$nombres_plato = array();
foreach ( $por_dia as $comidas ) {
	foreach ( $comidas as $platos_c ) {
		foreach ( $platos_c as $pl ) {
			$nombres_plato[ $pl['plato']['id'] ] = $nombre_receta( $pl['receta'] );
		}
	}
}
$nivel_clase = array(
	'conflicto'      => 'asde-badge--pago-atrasado',
	'riesgo'         => 'asde-badge--pago-parcial',
	'incompleto'     => 'asde-badge--pago-exento',
	'sin_conflictos' => 'asde-badge--pago-pagado',
);
$nivel_texto = array(
	'conflicto'      => asde_t( 'Conflicto' ),
	'riesgo'         => asde_t( 'Posible riesgo' ),
	'incompleto'     => asde_t( 'Datos incompletos' ),
	'sin_conflictos' => asde_t( 'Sin conflictos' ),
);
$hay_aviso = static fn( string $k ): bool => isset( $confirmaciones[ $k ] );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Alérgenos del menú' ); ?></h3>
		<span class="asde-badge <?php echo esc_attr( $nivel_clase[ $nivel ] ?? '' ); ?>"><?php echo esc_html( $nivel_texto[ $nivel ] ?? '' ); ?></span>
	</header>
	<?php if ( 'sin_menu' === $validacion['estado'] ) : ?>
		<p><?php asde_e( 'Añade platos al menú para poder revisar los alérgenos.' ); ?></p>
	<?php elseif ( 'sin_asistentes' === $validacion['estado'] ) : ?>
		<p><?php asde_e( 'Todavía no hay inscritos ni equipo en el evento: no hay nadie con quien comparar el menú.' ); ?></p>
	<?php elseif ( 'crm' === $validacion['estado'] ) : ?>
		<div class="asde-notice asde-notice--warning"><?php asde_e( 'No se ha podido leer las fichas del CRM ahora mismo: no se puede decir que el menú esté libre de alérgenos. Inténtalo de nuevo.' ); ?></div>
	<?php else : ?>
		<p class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Comprobado en vivo con las fichas de %1$d personas (inscritos y equipo), a fecha de %2$s. No se guarda nada de lo que dicen las fichas.' ), (int) $validacion['asistentes'], $validacion['momento'] ) ); ?></p>
		<?php if ( 'sin_conflictos' === $nivel ) : ?>
			<p><?php asde_e( 'Ninguno de los alérgenos del menú afecta a nadie según las fichas.' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $validacion['platos'] as $plato_id => $p ) : ?>
			<?php if ( ! $p['conflictos'] && ! $p['riesgos'] && ! $p['dieta'] ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<h4><?php echo esc_html( $nombres_plato[ $plato_id ] ?? '' ); ?></h4>
			<ul class="asde-menu__avisos">
				<?php foreach ( array( 'conflictos' => 'conflicto', 'riesgos' => 'riesgo' ) as $clave => $tipo ) : ?>
					<?php foreach ( $p[ $clave ] as $cod => $d ) : ?>
						<?php $k = $plato_id . '|' . $cod; ?>
						<li>
							<span class="asde-badge <?php echo esc_attr( $nivel_clase[ $tipo ] ); ?>"><?php echo esc_html( $nivel_texto[ $tipo ] ); ?></span>
							<strong><?php echo esc_html( Alergenos::nombre( $cod ) ); ?></strong>:
							<?php echo esc_html( sprintf( asde_n( '%d persona', '%d personas', (int) $d['n'] ), (int) $d['n'] ) ); ?>
							<?php if ( ! empty( $d['ingredientes'] ) ) : ?>
								<span class="asde-muted">· <?php echo esc_html( sprintf( asde_t( 'por %s' ), implode( ', ', $d['ingredientes'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $ve_quienes && ! empty( $d['nombres'] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( implode( ', ', $d['nombres'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( $hay_aviso( $k ) ) : ?>
								<br><span class="asde-badge asde-badge--pago-pagado"><?php asde_e( 'Confirmado' ); ?></span>
								<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( '%1$s · por %2$s el %3$s' ), asde_t( \AsdeBalears\AreaPrivada\Menus\AvisosTable::ALTERNATIVAS[ $confirmaciones[ $k ]['alternativa'] ] ?? '' ), $confirmantes[ $k ] ?? '', wp_date( 'd/m/Y', strtotime( $confirmaciones[ $k ]['creado'] . ' UTC' ) ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $puede_editar ) : ?>
								<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline asde-solo-pantalla">
									<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
									<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
									<input type="hidden" name="pestana" value="menu">
									<input type="hidden" name="plato" value="<?php echo (int) $plato_id; ?>">
									<input type="hidden" name="alergeno" value="<?php echo esc_attr( $cod ); ?>">
									<?php if ( $hay_aviso( $k ) ) : ?>
										<button type="submit" class="asde-btn-link" name="asde_action" value="menu_desconfirmar"><?php asde_e( 'Quitar la confirmación' ); ?></button>
									<?php else : ?>
										<select name="alternativa" aria-label="<?php echo esc_attr( asde_a( 'Qué se hace' ) ); ?>">
											<?php foreach ( \AsdeBalears\AreaPrivada\Menus\AvisosTable::ALTERNATIVAS as $ak => $an ) : ?>
												<option value="<?php echo esc_attr( $ak ); ?>"><?php echo esc_html( asde_t( $an ) ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button asde-button-secundario" name="asde_action" value="menu_confirmar"><?php asde_e( 'Confirmar' ); ?></button>
									<?php endif; ?>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php foreach ( $p['dieta'] as $dieta => $d ) : ?>
					<li>
						<span class="asde-badge <?php echo esc_attr( $nivel_clase['riesgo'] ); ?>"><?php echo esc_html( $nivel_texto['riesgo'] ); ?></span>
						<strong><?php echo esc_html( 'vegana' === $dieta ? asde_t( 'Dieta vegana' ) : asde_t( 'Dieta vegetariana' ) ); ?></strong>:
						<?php echo esc_html( sprintf( asde_n( '%d persona', '%d personas', (int) $d['n'] ), (int) $d['n'] ) ); ?>
						<span class="asde-muted">· <?php asde_e( 'el plato lleva ingredientes de origen animal' ); ?></span>
						<?php if ( $ve_quienes && ! empty( $d['nombres'] ) ) : ?>
							<br><span class="asde-muted"><?php echo esc_html( implode( ', ', $d['nombres'] ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<?php if ( $validacion['revisar'] > 0 ) : ?>
			<div class="asde-notice asde-notice--warning">
				<?php echo esc_html( sprintf( asde_n( '%d ficha tiene algo escrito que no se ha podido entender: revísala a mano.', '%d fichas tienen algo escrito que no se ha podido entender: revísalas a mano.', (int) $validacion['revisar'] ), (int) $validacion['revisar'] ) ); ?>
				<?php if ( $ve_quienes ) : ?>
					<?php foreach ( $validacion['textos_revisar'] as $nombre => $texto ) : ?>
						<br><strong><?php echo esc_html( $nombre ); ?></strong>: <?php echo esc_html( $texto ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $validacion['incompletos'] > 0 ) : ?>
			<div class="asde-notice asde-notice--info">
				<?php echo esc_html( sprintf( asde_n( '%d persona no tiene la ficha de salud y alimentación revisada o rellenada: no se puede asegurar nada de ella.', '%d personas no tienen la ficha de salud y alimentación revisada o rellenada: no se puede asegurar nada de ellas.', (int) $validacion['incompletos'] ), (int) $validacion['incompletos'] ) ); ?>
				<?php if ( $ve_quienes && $validacion['nombres_incompletos'] ) : ?>
					<br><?php echo esc_html( implode( ', ', $validacion['nombres_incompletos'] ) ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $ve_quienes ) : ?>
			<p class="asde-help"><?php asde_e( 'Aquí solo se ven los alérgenos y cuántas personas afectan, sin nombres. Los nombres los ven quienes ya ven la salud del evento y salen en la hoja de cocina.' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $puede_hoja && 'ok' === $validacion['estado'] ) : ?>
		<div class="asde-solo-pantalla">
			<h4><?php asde_e( 'Hoja de cocina' ); ?></h4>
			<p class="asde-muted"><?php asde_e( 'Para quien cocina: el nombre de cada persona afectada y su alérgeno, comida por comida. Se genera al momento y no se guarda; por email solo llega al equipo de apoyo con cuenta.' ); ?></p>
			<?php if ( $entrega && $entrega['desfasada'] ) : ?>
				<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( asde_t( 'La última hoja se entregó el %s y ha cambiado algo desde entonces (inscritos, fichas o menú): vuelve a generarla.' ), wp_date( 'd/m/Y H:i', strtotime( $entrega['cuando'] . ' UTC' ) ) ) ); ?></div>
			<?php elseif ( $entrega ) : ?>
				<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Última hoja entregada el %s, al día.' ), wp_date( 'd/m/Y H:i', strtotime( $entrega['cuando'] . ' UTC' ) ) ) ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline">
				<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
				<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
				<input type="hidden" name="pestana" value="menu">
				<a class="button" href="<?php echo esc_url( $url_hoja ); ?>"><?php asde_e( 'Ver e imprimir la hoja' ); ?></a>
				<button type="submit" class="button asde-button-secundario" name="asde_action" value="menu_hoja_enviar"><?php asde_e( 'Enviar al equipo de cocina' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
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
