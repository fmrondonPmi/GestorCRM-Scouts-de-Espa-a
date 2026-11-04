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
	<h2><?php esc_html_e( 'Group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'The group\'s details are written once, here: they are used in direct debits, in the group\'s documents and to form the member no. Everyone sees them; the Coordination team changes them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( $faltan && $editable ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Still to fill in: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( '; ', $faltan ) ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $editable ) : ?>
		<table class="asde-table asde-table--resumen" data-asde-sin-paginar="tabla de datos fijos / resumen sin bucle">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Group name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['nombre'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Group no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $d['numero'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Tax name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['denominacion'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Tax ID', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['cif'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['sede'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Postcode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['cp'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Town', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['poblacion'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Province', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['provincia_nombre'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Contact email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( $valor( $d['email_contacto'] ) ); ?></td></tr>
			</tbody>
		</table>
	<?php else : ?>
		<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
			<?php wp_nonce_field( 'asde_grupo_datos' ); ?>
			<input type="hidden" name="asde_action" value="guardar_datos_grupo">
			<div class="asde-grid-3">
				<p><label for="asde-g-nombre"><?php esc_html_e( 'Group name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-nombre" name="nombre" maxlength="120" required value="<?php echo esc_attr( $d['nombre'] ); ?>"></p>
				<p><label for="asde-g-numero"><?php esc_html_e( 'Group no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-numero" value="<?php echo esc_attr( $d['numero'] ); ?>" readonly aria-describedby="asde-g-numero-ayuda"><span class="asde-help" id="asde-g-numero-ayuda"><?php esc_html_e( 'It is set by whoever administers the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
				<p><label for="asde-g-denominacion"><?php esc_html_e( 'Tax name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-denominacion" name="denominacion" maxlength="120" value="<?php echo esc_attr( $d['denominacion'] ); ?>"></p>
				<p><label for="asde-g-cif"><?php esc_html_e( 'Tax ID', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-cif" name="cif" maxlength="12" value="<?php echo esc_attr( $d['cif'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'G07123456', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></p>
				<p><label for="asde-g-sede"><?php esc_html_e( 'Address (street and number)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-sede" name="sede" maxlength="160" value="<?php echo esc_attr( $d['sede'] ); ?>"></p>
				<p><label for="asde-g-cp"><?php esc_html_e( 'Postcode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-cp" name="cp" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" value="<?php echo esc_attr( $d['cp'] ); ?>"></p>
				<p><label for="asde-g-poblacion"><?php esc_html_e( 'Town', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-g-poblacion" name="poblacion" maxlength="80" value="<?php echo esc_attr( $d['poblacion'] ); ?>"></p>
				<p>
					<label for="asde-g-provincia"><?php esc_html_e( 'Province', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-g-provincia" name="provincia">
						<option value=""><?php esc_html_e( '— Choose the province —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( Provincias::todas() as $codigo => $nombre ) : ?>
							<option value="<?php echo esc_attr( $codigo ); ?>" <?php selected( $d['provincia'], $codigo ); ?>><?php echo esc_html( $codigo . ' · ' . $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-g-email"><?php esc_html_e( 'Contact email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="email" id="asde-g-email" name="email_contacto" maxlength="120" value="<?php echo esc_attr( $d['email_contacto'] ); ?>"></p>
			</div>
			<?php if ( '' !== $d['provincia'] ) : ?>
				<p class="asde-help">
					<label class="asde-choice"><input type="checkbox" name="confirmar_provincia" value="1"><span><?php esc_html_e( 'If I change the province, I understand that the CRM member numbers with the previous province will no longer be recognised as the group\'s (they will have to be corrected in the CRM). Users already created keep signing in with their usual username or their email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
				</p>
			<?php endif; ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save the group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="grupo-numero-socio" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Group member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p><?php esc_html_e( 'It is the union of three parts: the two digits of the province (with its leading 0 if it has one), the group no. and an incremental 4-digit number.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( '' !== $ejemplo ) : ?>
		<p>
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Province %1$s · group %2$s · incremental 0001 → %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $d['provincia'], $d['numero'], $ejemplo ) ); ?>
		</p>
		<p class="asde-help"><?php esc_html_e( 'Numbers that already existed without the leading 0 (e.g. 76840390) remain valid as they are; new ones are generated with the full province.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'This group does not have a province yet: without it member numbers cannot be recognised or generated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
</section>
