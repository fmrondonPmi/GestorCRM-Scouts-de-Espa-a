<?php
/**
 * Editar los socios de una familia.
 *
 * @var array{id:int, numero:string, miembros:string[]}       $familia
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]              $sin_fam
 * @var ?WP_User                                              $cuenta
 * @var string                                                $url
 * @var string                                                $url_pago
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Volver a familias' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Familia' ); ?> <?php echo esc_html( $familia['numero'] ); ?></h2>
		<a class="button asde-button-secundario" href="<?php echo esc_url( $url_pago ); ?>"><?php asde_e( 'Ver pagos' ); ?></a>
	</header>
	<?php if ( $cuenta ) : ?>
		<p class="asde-muted"><?php asde_e( 'Cuenta familiar del área privada:' ); ?> <strong><?php echo esc_html( $cuenta->user_login ); ?></strong> <?php asde_e( '(los accesos se gestionan en «Personas» y «Usuarios»).' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $familia['miembros'] ) : ?>
		<p><?php asde_e( 'La familia no tiene socios.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<tbody>
				<?php foreach ( $familia['miembros'] as $n ) : ?>
					<?php $p = $personas[ $n ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Socio' ) ); ?>">
							<?php echo esc_html( $p ? $p->nombre_completo() : asde_t( 'Nº Scouts de España ' ) . $n ); ?><?php echo $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php
							if ( $p ) {
								foreach ( $p->secciones as $s ) {
									echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							?>
							<?php echo ( ! $p || ! $p->tiene_membresia_activa() ) ? '<br><span class="asde-help asde-help--warning">' . esc_html( asde_t( 'Sin membresía activa: no cuenta para la cuota' ) ) . '</span>' : ''; ?>
						</td>
						<td data-label="">
							<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Quitar a este socio de la familia?' ) ); ?>">
								<?php wp_nonce_field( 'asde_familias' ); ?>
								<input type="hidden" name="asde_action" value="quitar">
								<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
								<input type="hidden" name="socio" value="<?php echo esc_attr( $n ); ?>">
								<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Quitar' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Añadir un socio' ); ?></h3>
	<?php if ( ! $sin_fam ) : ?>
		<p><?php asde_e( 'No hay socios activos sin familia.' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-filter">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="anadir">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<label for="asde-socio-anadir" class="screen-reader-text"><?php asde_e( 'Socio' ); ?></label>
			<select id="asde-socio-anadir" name="socio" required class="asde-combobox" data-min="3" data-placeholder="<?php echo esc_attr( asde_a( 'Busca por nombre o apellidos (a partir de 3 letras)…' ) ); ?>">
				<option value=""><?php asde_e( 'Elige un socio sin familia…' ); ?></option>
				<?php foreach ( $sin_fam as $p ) : ?>
					<option value="<?php echo esc_attr( $p->num_socio_asde ); ?>"><?php echo esc_html( $p->apellidos . ', ' . $p->nombre . ( $p->secciones ? ' · ' . Secciones::nombre( $p->secciones[0] ) : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php asde_e( 'Añadir' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<?php if ( ! $familia['miembros'] ) : ?>
	<section class="asde-card">
		<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Borrar la familia %s?', $familia['numero'] ) ); ?>">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="borrar">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Borrar esta familia' ); ?></button>
			<span class="asde-help"><?php asde_e( 'Solo si no tiene pagos registrados.' ); ?></span>
		</form>
	</section>
<?php endif; ?>
