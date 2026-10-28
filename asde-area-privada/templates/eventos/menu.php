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
	echo wp_kses( \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'eventos/menu-hoja', array( 'e' => $e, 'hoja' => $hoja, 'url_menu' => $url_menu ) ), asde_html_permitido() );
	return;
}

$nombre_receta = static fn( array $r ): string => IngredientesTable::COMUN === $r['grupo'] ? asde_t( $r['nombre'] ) : $r['nombre'];
$fecha         = static fn( string $d ): string => wp_date( 'l d/m/Y', strtotime( $d . ' 12:00 UTC' ) );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Event menu', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla"><button type="button" class="button asde-button-secundario" data-asde-imprimir-pagina><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></span>
	</header>
	<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d diner counts by default (the sign-ups and the team); each dish can have its own.', '%d diners count by default (the sign-ups and the team); each dish can have its own.', max( 1, $comensales ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), max( 1, $comensales ) ) ); ?>
		<?php esc_html_e( 'The allergens are those of the recipes: nobody in particular is checked here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( ! $recetas && $puede_editar ) : ?>
		<div class="asde-notice asde-notice--warning"><?php esc_html_e( 'There are no recipes yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_recetas ); ?>"><?php esc_html_e( 'Go to the recipe book', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></div>
	<?php endif; ?>

	<?php foreach ( $dias as $d ) : ?>
		<h4><?php echo esc_html( $fecha( $d ) ); ?><?php echo wp_kses( isset( $coste_dias[ $d ] ) ? ' <span class="asde-muted">· ' . esc_html( Calculadora::euros( $coste_dias[ $d ] ) ) . '</span>' : '', asde_html_permitido() ); ?></h4>
		<table class="asde-table">
			<tbody>
				<?php foreach ( MenuTable::COMIDAS as $c => $nombre_c ) : ?>
					<?php $platos = $por_dia[ $d ][ $c ] ?? array(); ?>
					<tr>
						<th scope="row"><?php echo esc_html( asde_t( $nombre_c ) ); ?></th>
						<td>
							<?php if ( ! $platos ) : ?>
								<span class="asde-muted"><?php esc_html_e( 'No dishes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $platos as $p ) : ?>
								<div class="asde-menu__plato">
									<strong><?php echo esc_html( $nombre_receta( $p['receta'] ) ); ?></strong>
									<span class="asde-muted">· <?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d diner', '%d diners', $p['comensales'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p['comensales'] ) ); ?></span>
									<?php if ( $p['alergenos']['contiene'] ) : ?>
										<span>· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Contains: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Alergenos::texto( $p['alergenos']['contiene'] ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( $p['alergenos']['trazas'] ) : ?>
										<span class="asde-muted">· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'May contain: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Alergenos::texto( $p['alergenos']['trazas'] ) ) ); ?></span>
									<?php endif; ?>
									<?php if ( $p['alergenos']['sin_revisar'] ) : ?>
										<span class="asde-badge asde-badge--pago-parcial"><?php esc_html_e( 'Ingredients not yet reviewed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
									<?php endif; ?>
									<?php if ( $puede_editar ) : ?>
										<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline asde-solo-pantalla">
											<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
											<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
											<input type="hidden" name="pestana" value="menu">
											<input type="hidden" name="plato" value="<?php echo (int) $p['plato']['id']; ?>">
											<input type="number" name="comensales" min="1" max="5000" size="5" value="<?php echo null === $p['plato']['comensales'] ? '' : (int) $p['plato']['comensales']; ?>" placeholder="<?php echo esc_attr( esc_attr__( 'default', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Diners for this dish', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
											<button type="submit" class="asde-btn-link" name="asde_action" value="menu_comensales"><?php esc_html_e( 'Change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
											<button type="submit" class="asde-btn-link" name="asde_action" value="menu_quitar"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
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
									<select name="receta" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Recipe for %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_t( $nombre_c ) ) ); ?>">
										<option value="0">— <?php esc_html_e( 'Add a dish', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> —</option>
										<?php foreach ( $recetas as $r ) : ?>
											<option value="<?php echo (int) $r['id']; ?>"><?php echo esc_html( $nombre_receta( $r ) ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Add', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
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
	'conflicto'      => __( 'Conflict', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'riesgo'         => __( 'Possible risk', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'incompleto'     => __( 'Incomplete details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'sin_conflictos' => __( 'No conflicts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$hay_aviso = static fn( string $k ): bool => isset( $confirmaciones[ $k ] );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Menu allergens', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-badge <?php echo esc_attr( $nivel_clase[ $nivel ] ?? '' ); ?>"><?php echo esc_html( $nivel_texto[ $nivel ] ?? '' ); ?></span>
	</header>
	<?php if ( 'sin_menu' === $validacion['estado'] ) : ?>
		<p><?php esc_html_e( 'Add dishes to the menu so the allergens can be checked.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( 'sin_asistentes' === $validacion['estado'] ) : ?>
		<p><?php esc_html_e( 'There are no sign-ups or team members in the event yet: there is nobody to compare the menu with.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( 'crm' === $validacion['estado'] ) : ?>
		<div class="asde-notice asde-notice--warning"><?php esc_html_e( 'The CRM records cannot be read right now: it cannot be said that the menu is free of allergens. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php else : ?>
		<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: number, 2: text */ __( 'Checked live against the records of %1$d people (sign-ups and team), as of %2$s. Nothing from the records is stored.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $validacion['asistentes'], $validacion['momento'] ) ); ?></p>
		<?php if ( 'sin_conflictos' === $nivel ) : ?>
			<p><?php esc_html_e( 'None of the menu\'s allergens affects anyone according to the records.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
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
							<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person', '%d people', (int) $d['n'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $d['n'] ) ); ?>
							<?php if ( ! empty( $d['ingredientes'] ) ) : ?>
								<span class="asde-muted">· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'by %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $d['ingredientes'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $ve_quienes && ! empty( $d['nombres'] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( implode( ', ', $d['nombres'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( $hay_aviso( $k ) ) : ?>
								<br><span class="asde-badge asde-badge--pago-pagado"><?php esc_html_e( 'Confirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s · for %2$s on %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_t( \AsdeBalears\AreaPrivada\Menus\AvisosTable::ALTERNATIVAS[ $confirmaciones[ $k ]['alternativa'] ] ?? '' ), $confirmantes[ $k ] ?? '', wp_date( 'd/m/Y', strtotime( $confirmaciones[ $k ]['creado'] . ' UTC' ) ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $puede_editar ) : ?>
								<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline asde-solo-pantalla">
									<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
									<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
									<input type="hidden" name="pestana" value="menu">
									<input type="hidden" name="plato" value="<?php echo (int) $plato_id; ?>">
									<input type="hidden" name="alergeno" value="<?php echo esc_attr( $cod ); ?>">
									<?php if ( $hay_aviso( $k ) ) : ?>
										<button type="submit" class="asde-btn-link" name="asde_action" value="menu_desconfirmar"><?php esc_html_e( 'Remove the confirmation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									<?php else : ?>
										<select name="alternativa" aria-label="<?php echo esc_attr( esc_attr__( 'What is done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
											<?php foreach ( \AsdeBalears\AreaPrivada\Menus\AvisosTable::ALTERNATIVAS as $ak => $an ) : ?>
												<option value="<?php echo esc_attr( $ak ); ?>"><?php echo esc_html( asde_t( $an ) ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button asde-button-secundario" name="asde_action" value="menu_confirmar"><?php esc_html_e( 'Confirm', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									<?php endif; ?>
								</form>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php foreach ( $p['dieta'] as $dieta => $d ) : ?>
					<li>
						<span class="asde-badge <?php echo esc_attr( $nivel_clase['riesgo'] ); ?>"><?php echo esc_html( $nivel_texto['riesgo'] ); ?></span>
						<strong><?php echo esc_html( 'vegana' === $dieta ? __( 'Vegan diet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Vegetarian diet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong>:
						<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person', '%d people', (int) $d['n'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $d['n'] ) ); ?>
						<span class="asde-muted">· <?php esc_html_e( 'the dish contains ingredients of animal origin', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						<?php if ( $ve_quienes && ! empty( $d['nombres'] ) ) : ?>
							<br><span class="asde-muted"><?php echo esc_html( implode( ', ', $d['nombres'] ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<?php if ( $validacion['revisar'] > 0 ) : ?>
			<div class="asde-notice asde-notice--warning">
				<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d record has something written that could not be understood: check it by hand.', '%d records have something written that could not be understood: check them by hand.', (int) $validacion['revisar'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $validacion['revisar'] ) ); ?>
				<?php if ( $ve_quienes ) : ?>
					<?php foreach ( $validacion['textos_revisar'] as $nombre => $texto ) : ?>
						<br><strong><?php echo esc_html( $nombre ); ?></strong>: <?php echo esc_html( $texto ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $validacion['incompletos'] > 0 ) : ?>
			<div class="asde-notice asde-notice--info">
				<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person does not have the health and food record checked or filled in: nothing can be guaranteed about them.', '%d people do not have the health and food record checked or filled in: nothing can be guaranteed about them.', (int) $validacion['incompletos'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $validacion['incompletos'] ) ); ?>
				<?php if ( $ve_quienes && $validacion['nombres_incompletos'] ) : ?>
					<br><?php echo esc_html( implode( ', ', $validacion['nombres_incompletos'] ) ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $ve_quienes ) : ?>
			<p class="asde-help"><?php esc_html_e( 'Only the allergens and how many people they affect are shown here, without names. The names are seen by those who already see the event\'s health data and appear on the kitchen sheet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $puede_hoja && 'ok' === $validacion['estado'] ) : ?>
		<div class="asde-solo-pantalla">
			<h4><?php esc_html_e( 'Kitchen sheet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<p class="asde-muted"><?php esc_html_e( 'For whoever cooks: the name of each affected person and their allergen, meal by meal. It is generated on the spot and not stored; by email it only reaches the support team with an account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php if ( $entrega && $entrega['desfasada'] ) : ?>
				<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'The last sheet was delivered on %s and something has changed since then (sign-ups, records or menu): generate it again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y H:i', strtotime( $entrega['cuando'] . ' UTC' ) ) ) ); ?></div>
			<?php elseif ( $entrega ) : ?>
				<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Last sheet delivered on %s, up to date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y H:i', strtotime( $entrega['cuando'] . ' UTC' ) ) ) ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $url_menu ); ?>" class="asde-inline">
				<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
				<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
				<input type="hidden" name="pestana" value="menu">
				<a class="button" href="<?php echo esc_url( $url_hoja ); ?>"><?php esc_html_e( 'View and print the sheet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<button type="submit" class="button asde-button-secundario" name="asde_action" value="menu_hoja_enviar"><?php esc_html_e( 'Send to the kitchen team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla">
			<a class="no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php esc_html_e( 'Download CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea asde-solo-pantalla">
		<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
		<input type="hidden" name="pestana" value="menu">
		<label for="asde-mn-dia"><?php esc_html_e( 'For', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-mn-dia" name="dia" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'The whole event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $dias as $d ) : ?>
				<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $dia, $d ); ?>><?php echo esc_html( $fecha( $d ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
	<?php if ( ! $compra['filas'] ) : ?>
		<p><?php esc_html_e( 'There is nothing to buy yet: add dishes to the menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col" class="asde-num"><?php esc_html_e( 'Cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $compra['filas'] as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['nombre'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['texto'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo wp_kses( null === $f['coste'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $f['coste'] ) ), asde_html_permitido() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row" colspan="2"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td class="asde-num"><?php echo esc_html( Calculadora::euros( $compra['total'] ) ); ?></td></tr></tfoot>
		</table>
		<?php if ( $compra['sin_coste'] ) : ?>
			<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'No cost recorded (they do not add up): %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $compra['sin_coste'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="asde-card asde-solo-pantalla" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php esc_html_e( 'Menu cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $coste_total <= 0 ) : ?>
		<p><?php esc_html_e( 'The menu has no cost yet. Record the cost of the ingredients in the recipe book and add dishes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<p><strong><?php echo esc_html( Calculadora::euros( $coste_total ) ); ?></strong>
			<?php if ( $comensales > 0 ) : ?>
				· <?php echo esc_html( sprintf( /* translators: %s: text */ __( '%s per diner', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( round( $coste_total / $comensales, 2 ) ) ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $puede_partida ) : ?>
			<form method="post" action="<?php echo esc_url( $url_menu ); ?>">
				<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
				<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
				<input type="hidden" name="pestana" value="menu">
				<p class="asde-help"><?php esc_html_e( 'It is added as a "Food" expense item in the budget. It never changes by itself: if the menu changes, remove the item and propose another.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button" name="asde_action" value="menu_partida"><?php esc_html_e( 'Propose as a Food item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
