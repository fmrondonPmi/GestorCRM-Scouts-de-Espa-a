<?php
/**
 * Editor de informes: columnas + filtros.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe $informe
 * @var array<string, array>                     $permitidas Columnas que puede usar este usuario
 * @var bool                                     $global     Visión global (todo el grupo)
 * @var string[]                                 $mis_secc   Secciones del scouter
 * @var bool                                     $salud      Puede ver datos de salud
 * @var string                                   $url
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\Informe;

defined( 'ABSPATH' ) || exit;

$f         = $informe->filtros;
$secciones = $global ? array_keys( Secciones::all() ) : $mis_secc;
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>">← Volver a informes</a></p>

<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-informe-editor" data-asde-informe>
	<?php wp_nonce_field( 'asde_informe_guardar' ); ?>
	<input type="hidden" name="asde_action" value="guardar">
	<input type="hidden" name="id" value="<?php echo (int) $informe->id; ?>">

	<section class="asde-card">
		<h2><?php echo $informe->id ? 'Editar informe' : 'Nuevo informe'; ?></h2>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-nombre">Nombre</label>
				<input type="text" id="asde-inf-nombre" name="nombre" value="<?php echo esc_attr( $informe->nombre ); ?>" maxlength="120" placeholder="Ej.: Autorizaciones campamento de verano">
			</p>
			<p>
				<label for="asde-inf-desc">Descripción (opcional)</label>
				<input type="text" id="asde-inf-desc" name="descripcion" value="<?php echo esc_attr( $informe->descripcion ); ?>" maxlength="255">
			</p>
		</div>
	</section>

	<section class="asde-card">
		<h3>1 · ¿Qué datos quieres ver?</h3>
		<p class="asde-help">Marca las columnas. Aparecerán en este orden.</p>
		<div class="asde-columnas">
			<?php foreach ( Catalogo::GRUPOS as $grupo => $titulo ) : ?>
				<?php $cols = array_filter( $permitidas, static fn( $c ) => $grupo === $c[1] ); ?>
				<?php if ( ! $cols ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<fieldset class="asde-columnas__grupo<?php echo 'salud' === $grupo ? ' asde-columnas__grupo--salud' : ''; ?>">
					<legend><?php echo esc_html( $titulo ); ?></legend>
					<p class="asde-columnas__todas">
						<button type="button" class="asde-btn-link" data-asde-marcar="todas">Todas</button> ·
						<button type="button" class="asde-btn-link" data-asde-marcar="ninguna">Ninguna</button>
					</p>
					<?php foreach ( $cols as $clave => $c ) : ?>
						<label class="asde-choice">
							<input type="checkbox" name="cols[]" value="<?php echo esc_attr( $clave ); ?>" <?php checked( in_array( $clave, $informe->columnas, true ) ); ?>>
							<span><?php echo esc_html( $c[0] ); ?></span>
						</label>
					<?php endforeach; ?>
					<?php if ( 'salud' === $grupo ) : ?>
						<p class="asde-help asde-help--warning">Datos de salud: categoría especial (RGPD). Úsalos solo para la actividad y no los compartas fuera del equipo.</p>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="asde-card">
		<h3>2 · ¿De quién?</h3>
		<fieldset>
			<legend>Secciones</legend>
			<?php if ( ! $global ) : ?>
				<p class="asde-help">Como scouter ves los socios de tus secciones.</p>
			<?php endif; ?>
			<?php foreach ( $secciones as $slug ) : ?>
				<label class="asde-choice asde-choice--inline">
					<input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $f['secciones'], true ) ); ?>>
					<span><?php echo Secciones::badge( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</label>
			<?php endforeach; ?>
			<p class="asde-help">Si no marcas ninguna: <?php echo $global ? 'todo el grupo' : 'todas tus secciones'; ?>.</p>
		</fieldset>
		<div class="asde-grid-2">
			<p>
				<label for="asde-inf-emin">Edad desde</label>
				<input type="number" id="asde-inf-emin" name="edad_min" min="0" max="120" value="<?php echo null === $f['edad_min'] ? '' : (int) $f['edad_min']; ?>">
			</p>
			<p>
				<label for="asde-inf-emax">Edad hasta</label>
				<input type="number" id="asde-inf-emax" name="edad_max" min="0" max="120" value="<?php echo null === $f['edad_max'] ? '' : (int) $f['edad_max']; ?>">
			</p>
		</div>
		<?php if ( $salud ) : ?>
			<label class="asde-choice">
				<input type="checkbox" name="salud" value="1" <?php checked( $f['salud'] ); ?>>
				<span>Solo socios con datos de salud o alimentación relevantes <span class="asde-help">(alergias, intolerancias, régimen, medicación, tratamiento o enfermedades)</span></span>
			</label>
		<?php endif; ?>
		<p>
			<label for="asde-inf-orden">Ordenar por</label>
			<select id="asde-inf-orden" name="orden">
				<?php foreach ( Informe::ORDENES as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['orden'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	</section>

	<section class="asde-card">
		<h3>3 · Ver o guardar</h3>
		<label class="asde-choice">
			<input type="checkbox" name="compartido" value="1" <?php checked( $informe->compartido ); ?>>
			<span>Compartir con el resto del equipo <span class="asde-help">(cada persona verá solo los datos a los que tiene acceso)</span></span>
		</label>
		<p class="asde-actions asde-actions--botones">
			<button type="submit" class="button" formmethod="get" formaction="<?php echo esc_url( $url ); ?>" name="ver" value="1">Ver informe</button>
			<button type="submit" class="button asde-button-secundario">Guardar informe</button>
		</p>
	</section>
</form>
