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
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Volver a informes' ); ?></a></p>

<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-informe-editor" data-asde-informe>
	<?php wp_nonce_field( 'asde_informe_guardar' ); ?>
	<input type="hidden" name="asde_action" value="guardar">
	<input type="hidden" name="id" value="<?php echo (int) $informe->id; ?>">

	<section class="asde-card">
		<h2><?php echo $informe->id ? esc_html( asde_t( 'Editar informe' ) ) : esc_html( asde_t( 'Nuevo informe' ) ); ?></h2>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-nombre"><?php asde_e( 'Nombre' ); ?></label>
				<input type="text" id="asde-inf-nombre" name="nombre" value="<?php echo esc_attr( $informe->nombre ); ?>" maxlength="120" placeholder="<?php echo esc_attr( asde_a( 'Ej.: Autorizaciones campamento de verano' ) ); ?>">
			</p>
			<p>
				<label for="asde-inf-desc"><?php asde_e( 'Descripción (opcional)' ); ?></label>
				<input type="text" id="asde-inf-desc" name="descripcion" value="<?php echo esc_attr( $informe->descripcion ); ?>" maxlength="255">
			</p>
		</div>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( '1 · ¿Qué datos quieres ver?' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Marca las columnas. Aparecerán en este orden.' ); ?></p>
		<div class="asde-columnas">
			<?php foreach ( asde_ta( Catalogo::GRUPOS ) as $grupo => $titulo ) : ?>
				<?php $cols = array_filter( $permitidas, static fn( $c ) => $grupo === $c[1] ); ?>
				<?php if ( ! $cols ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<fieldset class="asde-columnas__grupo<?php echo 'salud' === $grupo ? ' asde-columnas__grupo--salud' : ''; ?>">
					<legend><?php echo esc_html( $titulo ); ?></legend>
					<p class="asde-columnas__todas">
						<button type="button" class="asde-btn-link" data-asde-marcar="todas"><?php asde_e( 'Todas' ); ?></button> ·
						<button type="button" class="asde-btn-link" data-asde-marcar="ninguna"><?php asde_e( 'Ninguna' ); ?></button>
					</p>
					<?php foreach ( $cols as $clave => $c ) : ?>
						<label class="asde-choice">
							<input type="checkbox" name="cols[]" value="<?php echo esc_attr( $clave ); ?>" <?php checked( in_array( $clave, $informe->columnas, true ) ); ?>>
							<span><?php echo esc_html( $c[0] ); ?></span>
						</label>
					<?php endforeach; ?>
					<?php if ( 'salud' === $grupo ) : ?>
						<p class="asde-help asde-help--warning"><?php asde_e( 'Datos de salud: categoría especial (RGPD). Úsalos solo para la actividad y no los compartas fuera del equipo.' ); ?></p>
					<?php elseif ( 'pagos' === $grupo ) : ?>
						<p class="asde-help"><?php asde_e( 'De Tesorería: los importes son los de la familia (con sus hermanos), en la ronda que elijas abajo.' ); ?></p>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( '2 · ¿De quién?' ); ?></h3>
		<fieldset>
			<legend><?php asde_e( 'Secciones' ); ?></legend>
			<?php if ( ! $global ) : ?>
				<p class="asde-help"><?php asde_e( 'Como scouter ves los socios de tus secciones.' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $secciones as $slug ) : ?>
				<label class="asde-choice asde-choice--inline">
					<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $f['secciones'], true ) ); ?>>
					<span><?php echo Secciones::badge( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</label>
			<?php endforeach; ?>
			<p class="asde-help"><?php asde_e( 'Si no marcas ninguna:' ); ?> <?php echo $global ? esc_html( asde_t( 'todo el grupo' ) ) : esc_html( asde_t( 'todas tus secciones' ) ); ?>.</p>
		</fieldset>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-emin"><?php asde_e( 'Edad desde' ); ?></label>
				<input type="number" id="asde-inf-emin" name="edad_min" min="0" max="120" value="<?php echo null === $f['edad_min'] ? '' : (int) $f['edad_min']; ?>">
			</p>
			<p>
				<label for="asde-inf-emax"><?php asde_e( 'Edad hasta' ); ?></label>
				<input type="number" id="asde-inf-emax" name="edad_max" min="0" max="120" value="<?php echo null === $f['edad_max'] ? '' : (int) $f['edad_max']; ?>">
			</p>
		</div>
		<?php if ( $salud ) : ?>
			<label class="asde-choice">
				<input type="checkbox" name="salud" value="1" <?php checked( $f['salud'] ); ?>>
				<span><?php asde_e( 'Solo socios con datos de salud o alimentación relevantes' ); ?> <span class="asde-help"><?php asde_e( '(alergias, intolerancias, régimen, medicación, tratamiento o enfermedades)' ); ?></span></span>
			</label>
		<?php endif; ?>
		<?php if ( $pagos ) : ?>
			<div class="asde-grid-2">
				<p>
					<label for="asde-inf-pago"><?php asde_e( 'Según lo pagado' ); ?></label>
					<select id="asde-inf-pago" name="pago">
						<?php foreach ( asde_ta( Catalogo::FILTROS_PAGO ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['pago'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="asde-inf-ronda"><?php asde_e( 'Ronda de la cuota' ); ?></label>
					<select id="asde-inf-ronda" name="ronda">
						<?php /* translators: %s: ronda (2026-2027) */ ?>
						<option value=""><?php echo esc_html( sprintf( asde_t( 'La actual (%s)' ), Config::ronda_actual() ) ); ?></option>
						<?php foreach ( $rondas as $r ) : ?>
							<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $f['ronda'], $r ); ?>><?php echo esc_html( $r ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
		<?php endif; ?>
		<p>
			<label for="asde-inf-orden"><?php asde_e( 'Ordenar por' ); ?></label>
			<select id="asde-inf-orden" name="orden">
				<?php foreach ( asde_ta( Informe::ORDENES ) as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['orden'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( '3 · Ver o guardar' ); ?></h3>
		<label class="asde-choice">
			<input type="checkbox" name="compartido" value="1" <?php checked( $informe->compartido ); ?>>
			<span><?php asde_e( 'Compartir con el resto del equipo' ); ?> <span class="asde-help"><?php asde_e( '(cada persona verá solo los datos a los que tiene acceso)' ); ?></span></span>
		</label>
		<p class="asde-actions asde-actions--botones">
			<button type="submit" class="button" formmethod="get" formaction="<?php echo esc_url( $url ); ?>" name="ver" value="1"><?php asde_e( 'Ver informe' ); ?></button>
			<button type="submit" class="button asde-button-secundario"><?php asde_e( 'Guardar informe' ); ?></button>
		</p>
	</section>
</form>
