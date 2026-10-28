<?php
/**
 * Editor de informes: columnas + filtros.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe $informe
 * @var array<string, array>                     $permitidas Columnas que puede usar este usuario
 * @var bool                                     $global     Visión global (todo el grupo)
 * @var string[]                                 $mis_secc   Secciones del scouter
 * @var bool                                     $salud      Puede ver datos de salud
 * @var bool                                     $pagos      Puede ver la cuota y los pagos (Tesorería, v0.25)
 * @var string[]                                 $rondas     Rondas de Tesorería (la más reciente primero)
 * @var string                                   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\Informe;

defined( 'ABSPATH' ) || exit;

$f         = $informe->filtros;
$secciones = $global ? array_keys( Secciones::all() ) : $mis_secc;
$pagos     = $pagos ?? false;
$rondas    = $rondas ?? array();
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Back to reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-informe-editor" data-asde-informe>
	<?php wp_nonce_field( 'asde_informe_guardar' ); ?>
	<input type="hidden" name="asde_action" value="guardar">
	<input type="hidden" name="id" value="<?php echo (int) $informe->id; ?>">

	<section class="asde-card">
		<h2><?php echo $informe->id ? esc_html( __( 'Edit report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'New report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h2>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inf-nombre" name="nombre" value="<?php echo esc_attr( $informe->nombre ); ?>" maxlength="120" placeholder="<?php echo esc_attr( esc_attr__( 'E.g.: Summer camp authorisations', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			</p>
			<p>
				<label for="asde-inf-desc"><?php esc_html_e( 'Description (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inf-desc" name="descripcion" value="<?php echo esc_attr( $informe->descripcion ); ?>" maxlength="255">
			</p>
		</div>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( '1 · What data do you want to see?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Tick the columns. They will appear in this order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div class="asde-columnas">
			<?php foreach ( ( Catalogo::grupos() ) as $grupo => $titulo ) : ?>
				<?php $cols = array_filter( $permitidas, static fn( $c ) => $grupo === $c[1] ); ?>
				<?php if ( ! $cols ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<fieldset class="asde-columnas__grupo<?php echo 'salud' === $grupo ? ' asde-columnas__grupo--salud' : ''; ?>">
					<legend><?php echo esc_html( $titulo ); ?></legend>
					<p class="asde-columnas__todas">
						<button type="button" class="asde-btn-link" data-asde-marcar="todas"><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button> ·
						<button type="button" class="asde-btn-link" data-asde-marcar="ninguna"><?php esc_html_e( 'None', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
					<?php foreach ( $cols as $clave => $c ) : ?>
						<label class="asde-choice">
							<input type="checkbox" name="cols[]" value="<?php echo esc_attr( $clave ); ?>" <?php checked( in_array( $clave, $informe->columnas, true ) ); ?>>
							<span><?php echo esc_html( $c[0] ); ?></span>
						</label>
					<?php endforeach; ?>
					<?php if ( 'salud' === $grupo ) : ?>
						<p class="asde-help asde-help--warning"><?php esc_html_e( 'Health data: special category (GDPR). Use it only for the activity and do not share it outside the team.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php elseif ( 'pagos' === $grupo ) : ?>
						<p class="asde-help"><?php esc_html_e( 'From Treasury: the amounts are those of the family (with their siblings), in the round you choose below.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( '2 · Of whom?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<fieldset>
			<legend><?php esc_html_e( 'Sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php if ( ! $global ) : ?>
				<p class="asde-help"><?php esc_html_e( 'As a scouter you see the members of your sections.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $secciones as $slug ) : ?>
				<label class="asde-choice asde-choice--inline">
					<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $f['secciones'], true ) ); ?>>
					<span><?php echo wp_kses( Secciones::badge( $slug ), asde_html_permitido() ); ?></span>
				</label>
			<?php endforeach; ?>
			<p class="asde-help"><?php esc_html_e( 'If you tick none:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo $global ? esc_html( __( 'the whole group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'all your sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>.</p>
		</fieldset>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-emin"><?php esc_html_e( 'Age from', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="number" id="asde-inf-emin" name="edad_min" min="0" max="120" value="<?php echo null === $f['edad_min'] ? '' : (int) $f['edad_min']; ?>">
			</p>
			<p>
				<label for="asde-inf-emax"><?php esc_html_e( 'Age to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="number" id="asde-inf-emax" name="edad_max" min="0" max="120" value="<?php echo null === $f['edad_max'] ? '' : (int) $f['edad_max']; ?>">
			</p>
		</div>
		<?php if ( $salud ) : ?>
			<label class="asde-choice">
				<input type="checkbox" name="salud" value="1" <?php checked( $f['salud'] ); ?>>
				<span><?php esc_html_e( 'Only members with relevant health or food data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-help"><?php esc_html_e( '(allergies, intolerances, diet, medication, treatment or illnesses)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></span>
			</label>
		<?php endif; ?>
		<?php if ( $pagos ) : ?>
			<div class="asde-grid-2">
				<p>
					<label for="asde-inf-pago"><?php esc_html_e( 'According to what has been paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-inf-pago" name="pago">
						<?php foreach ( ( Catalogo::filtros_pago() ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['pago'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="asde-inf-ronda"><?php esc_html_e( 'Fee round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-inf-ronda" name="ronda">
						<?php /* translators: %s: ronda (2026-2027) */ ?>
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'The current one (%s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Config::ronda_actual() ) ); ?></option>
						<?php foreach ( $rondas as $r ) : ?>
							<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $f['ronda'], $r ); ?>><?php echo esc_html( $r ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
		<?php endif; ?>
		<p>
			<label for="asde-inf-orden"><?php esc_html_e( 'Sort by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-inf-orden" name="orden">
				<?php foreach ( ( Informe::ordenes() ) as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['orden'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php /* v0.29: en tabla o una ficha por persona (al imprimir, una por página). */ ?>
		<p>
			<label for="asde-inf-vista"><?php esc_html_e( 'Presentation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-inf-vista" name="vista">
				<?php foreach ( ( Informe::vistas() ) as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['vista'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( '3 · View or save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<label class="asde-choice">
			<input type="checkbox" name="compartido" value="1" <?php checked( $informe->compartido ); ?>>
			<span><?php esc_html_e( 'Share with the rest of the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-help"><?php esc_html_e( '(each person will see only the data they have access to)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></span>
		</label>
		<p class="asde-actions asde-actions--botones">
			<button type="submit" class="button" formmethod="get" formaction="<?php echo esc_url( $url ); ?>" name="ver" value="1"><?php esc_html_e( 'View report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Save report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>
	</section>
</form>
