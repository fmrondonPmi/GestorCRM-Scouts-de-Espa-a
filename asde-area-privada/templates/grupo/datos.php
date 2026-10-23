<?php
/**
 * Datos del grupo (v0.56, doc 58): los ve todo el mundo; los cambia Coordinación.
 *
 * @var array    $d        DatosGrupo::de()
 * @var bool     $editable
 * @var string[] $faltan   Lo que falta por rellenar
 * @var string   $ejemplo  Ejemplo de nº de socio/a del grupo
 * @var string   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Provincias;

defined( 'ABSPATH' ) || exit;

$valor = static fn( string $v ): string => '' !== $v ? $v : '–';
?>
<section class="asde-card" data-asde-plegable="grupo-datos" data-asde-inicio="abierto">
	<h2><?php asde_e( 'Datos del grupo' ); ?></h2>
	<p><?php asde_e( 'Los datos del grupo se escriben una sola vez, aquí: se usan en las domiciliaciones, en los documentos del grupo y para formar el nº de socio/a. Los ve todo el mundo; los cambia el equipo de Coordinación.' ); ?></p>
	<?php if ( $faltan && $editable ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( asde_t( 'Falta por rellenar: %s.' ), implode( '; ', $faltan ) ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $editable ) : ?>
		<table class="asde-table asde-table--resumen">
			<tbody>
				<tr><th scope="row"><?php asde_e( 'Nombre del grupo' ); ?></th><td><?php echo esc_html( $valor( $d['nombre'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Nº de grupo' ); ?></th><td><?php echo esc_html( $d['numero'] ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Denominación fiscal' ); ?></th><td><?php echo esc_html( $valor( $d['denominacion'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'CIF' ); ?></th><td><?php echo esc_html( $valor( $d['cif'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Dirección' ); ?></th><td><?php echo esc_html( $valor( $d['sede'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Código postal' ); ?></th><td><?php echo esc_html( $valor( $d['cp'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Población' ); ?></th><td><?php echo esc_html( $valor( $d['poblacion'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Provincia' ); ?></th><td><?php echo esc_html( $valor( $d['provincia_nombre'] ) ); ?></td></tr>
				<tr><th scope="row"><?php asde_e( 'Email de contacto' ); ?></th><td><?php echo esc_html( $valor( $d['email_contacto'] ) ); ?></td></tr>
			</tbody>
		</table>
	<?php else : ?>
		<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
			<?php wp_nonce_field( 'asde_grupo_datos' ); ?>
			<input type="hidden" name="asde_action" value="guardar_datos_grupo">
			<div class="asde-grid-3">
				<p><label for="asde-g-nombre"><?php asde_e( 'Nombre del grupo' ); ?></label><input type="text" id="asde-g-nombre" name="nombre" maxlength="120" required value="<?php echo esc_attr( $d['nombre'] ); ?>"></p>
				<p><label for="asde-g-numero"><?php asde_e( 'Nº de grupo' ); ?></label><input type="text" id="asde-g-numero" value="<?php echo esc_attr( $d['numero'] ); ?>" readonly aria-describedby="asde-g-numero-ayuda"><span class="asde-help" id="asde-g-numero-ayuda"><?php asde_e( 'Lo fija quien administra la web.' ); ?></span></p>
				<p><label for="asde-g-denominacion"><?php asde_e( 'Denominación fiscal' ); ?></label><input type="text" id="asde-g-denominacion" name="denominacion" maxlength="120" value="<?php echo esc_attr( $d['denominacion'] ); ?>"></p>
				<p><label for="asde-g-cif"><?php asde_e( 'CIF' ); ?></label><input type="text" id="asde-g-cif" name="cif" maxlength="12" value="<?php echo esc_attr( $d['cif'] ); ?>" placeholder="<?php echo esc_attr( asde_a( 'G07123456' ) ); ?>"></p>
				<p><label for="asde-g-sede"><?php asde_e( 'Dirección (calle y número)' ); ?></label><input type="text" id="asde-g-sede" name="sede" maxlength="160" value="<?php echo esc_attr( $d['sede'] ); ?>"></p>
				<p><label for="asde-g-cp"><?php asde_e( 'Código postal' ); ?></label><input type="text" id="asde-g-cp" name="cp" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" value="<?php echo esc_attr( $d['cp'] ); ?>"></p>
				<p><label for="asde-g-poblacion"><?php asde_e( 'Población' ); ?></label><input type="text" id="asde-g-poblacion" name="poblacion" maxlength="80" value="<?php echo esc_attr( $d['poblacion'] ); ?>"></p>
				<p>
					<label for="asde-g-provincia"><?php asde_e( 'Provincia' ); ?></label>
					<select id="asde-g-provincia" name="provincia">
						<option value=""><?php asde_e( '— Elige la provincia —' ); ?></option>
						<?php foreach ( Provincias::todas() as $codigo => $nombre ) : ?>
							<option value="<?php echo esc_attr( $codigo ); ?>" <?php selected( $d['provincia'], $codigo ); ?>><?php echo esc_html( $codigo . ' · ' . $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-g-email"><?php asde_e( 'Email de contacto' ); ?></label><input type="email" id="asde-g-email" name="email_contacto" maxlength="120" value="<?php echo esc_attr( $d['email_contacto'] ); ?>"></p>
			</div>
			<?php if ( '' !== $d['provincia'] ) : ?>
				<p class="asde-help">
					<label class="asde-choice"><input type="checkbox" name="confirmar_provincia" value="1"><span><?php asde_e( 'Si cambio la provincia, entiendo que los números de socio/a del CRM con la provincia anterior dejarán de reconocerse como del grupo (habrá que corregirlos en el CRM). Los usuarios ya creados siguen entrando con su usuario de siempre o con su email.' ); ?></span></label>
				</p>
			<?php endif; ?>
			<p><button type="submit" class="button"><?php asde_e( 'Guardar los datos del grupo' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="grupo-numero-socio" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Nº de socio/a del grupo' ); ?></h3>
	<p><?php asde_e( 'Es la unión de tres partes: los dos dígitos de la provincia (con su 0 inicial si lo lleva), el nº de grupo y un número incremental de 4 cifras.' ); ?></p>
	<?php if ( '' !== $ejemplo ) : ?>
		<p>
			<?php echo esc_html( sprintf( asde_t( 'Provincia %1$s · grupo %2$s · incremental 0001 → %3$s' ), $d['provincia'], $d['numero'], $ejemplo ) ); ?>
		</p>
		<p class="asde-help"><?php asde_e( 'Los números que ya existían sin el 0 inicial (p. ej. 76840390) siguen siendo válidos tal cual; los nuevos se generan con la provincia completa.' ); ?></p>
	<?php else : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Este grupo aún no tiene provincia: sin ella no se pueden reconocer ni generar números de socio/a.' ); ?></div>
	<?php endif; ?>
</section>
