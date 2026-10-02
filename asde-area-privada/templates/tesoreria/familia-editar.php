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
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>">← Volver a familias</a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Familia <?php echo esc_html( $familia['numero'] ); ?></h2>
		<a class="button asde-button-secundario" href="<?php echo esc_url( $url_pago ); ?>">Ver pagos</a>
	</header>
	<?php if ( $cuenta ) : ?>
		<p class="asde-muted">Cuenta familiar del área privada: <strong><?php echo esc_html( $cuenta->user_login ); ?></strong> (los accesos se gestionan en «Personas» y «Usuarios»).</p>
	<?php endif; ?>

	<?php if ( ! $familia['miembros'] ) : ?>
		<p>La familia no tiene socios.</p>
	<?php else : ?>
		<table class="asde-table">
			<tbody>
				<?php foreach ( $familia['miembros'] as $n ) : ?>
					<?php $p = $personas[ $n ] ?? null; ?>
					<tr>
						<td data-label="Socio">
							<?php echo esc_html( $p ? $p->nombre_completo() : 'Nº Scouts de España ' . $n ); ?><?php echo $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php
							if ( $p ) {
								foreach ( $p->secciones as $s ) {
									echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							?>
							<?php echo ( ! $p || ! $p->tiene_membresia_activa() ) ? '<br><span class="asde-help asde-help--warning">Sin membresía activa: no cuenta para la cuota</span>' : ''; ?>
						</td>
						<td data-label="">
							<form method="post" onsubmit="return confirm('¿Quitar a este socio de la familia?');">
								<?php wp_nonce_field( 'asde_familias' ); ?>
								<input type="hidden" name="asde_action" value="quitar">
								<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
								<input type="hidden" name="socio" value="<?php echo esc_attr( $n ); ?>">
								<button type="submit" class="asde-btn-link asde-btn-danger">Quitar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3>Añadir un socio</h3>
	<?php if ( ! $sin_fam ) : ?>
		<p>No hay socios activos sin familia.</p>
	<?php else : ?>
		<form method="post" class="asde-filter">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="anadir">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<label for="asde-socio-anadir" class="screen-reader-text">Socio</label>
			<select id="asde-socio-anadir" name="socio" required class="asde-combobox" data-min="3" data-placeholder="Busca por nombre o apellidos (a partir de 3 letras)…">
				<option value="">Elige un socio sin familia…</option>
				<?php foreach ( $sin_fam as $p ) : ?>
					<option value="<?php echo esc_attr( $p->num_socio_asde ); ?>"><?php echo esc_html( $p->apellidos . ', ' . $p->nombre . ( $p->secciones ? ' · ' . Secciones::nombre( $p->secciones[0] ) : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button">Añadir</button>
		</form>
	<?php endif; ?>
</section>

<?php if ( ! $familia['miembros'] ) : ?>
	<section class="asde-card">
		<form method="post" onsubmit="return confirm('¿Borrar la familia <?php echo esc_js( $familia['numero'] ); ?>?');">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="borrar">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<button type="submit" class="asde-btn-link asde-btn-danger">Borrar esta familia</button>
			<span class="asde-help">Solo si no tiene pagos registrados.</span>
		</form>
	</section>
<?php endif; ?>
