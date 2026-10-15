<?php
/**
 * Evento · equipo dirigente, con lo que dice la ficha de cada persona (formación y
 * certificado de delitos sexuales, leídos en vivo y sin guardar) y la ratio.
 *
 * @var array                                                   $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                     $ambito
 * @var array[]                                                 $equipo
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>   $nombres
 * @var array                                                   $check      Normativa::comprobar_equipo
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>   $candidatos Scouters y voluntariado que aún no están
 * @var string                                                  $url
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;

defined( 'ABSPATH' ) || exit;

$editable = Servicio::puede_editar( $e, $ambito );
$campos   = static function ( string $accion ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="equipo">';
};
$titulos  = array(
	'director' => asde_t( 'Director/a' ),
	'monitor'  => asde_t( 'Monitor/a' ),
	''         => '—',
);
$certs    = array(
	'ok'        => array( 'ok', asde_t( 'Al día' ) ),
	'caducado'  => array( 'aviso', asde_t( 'Caducado al acabar' ) ),
	'sin_fecha' => array( 'aviso', asde_t( 'Sin fecha' ) ),
	'no'        => array( 'aviso', asde_t( 'No consta' ) ),
	''          => array( 'info', '—' ),
);
$r        = $check['ratio'];
?>
<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Equipo dirigente' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'Un director/a titulado y los monitores que pida la ratio, todos mayores de edad y con el certificado de delitos sexuales de menos de 12 meses. La formación y el certificado se leen de la ficha del CRM.' ); ?></p>
	<?php if ( ! $check['leido'] ) : ?>
		<p class="asde-notice asde-notice--warning"><?php asde_e( 'No se ha podido leer en el CRM la formación y el certificado del equipo: compruébalos más tarde.' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $equipo ) : ?>
		<p><?php asde_e( 'Todavía no hay nadie en el equipo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--equipo">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Persona' ); ?></th>
					<th scope="col"><?php asde_e( 'Función' ); ?></th>
					<th scope="col"><?php asde_e( 'Titulación' ); ?></th>
					<th scope="col"><?php asde_e( 'Certificado' ); ?></th>
					<?php if ( $editable ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Quitar' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $equipo as $m ) : ?>
					<?php
					$p    = $nombres[ $m['socio'] ] ?? null;
					$dato = $check['personas'][ $m['socio'] ] ?? array(
						'menor'       => false,
						'titulo'      => '',
						'certificado' => '',
					);
					$cert = $certs[ $dato['certificado'] ] ?? $certs[''];
					?>
					<tr>
						<td data-label="<?php echo asde_a( 'Persona' ); ?>">
							<?php echo esc_html( $p ? $p->nombre_completo() : sprintf( asde_t( 'Nº %s' ), $m['socio'] ) ); ?>
							<?php
							foreach ( $m['secciones'] as $s ) {
								echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
							<?php if ( $dato['menor'] ) : ?>
								<br><span class="asde-comprobacion--aviso"><?php asde_e( 'Menor de edad' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo asde_a( 'Función' ); ?>">
							<?php if ( $editable ) : ?>
								<form method="post" class="asde-inline-form">
									<?php echo $campos( 'evento_equipo_papel' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="fila" value="<?php echo (int) $m['id']; ?>">
									<label class="screen-reader-text" for="asde-papel-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Función' ); ?></label>
									<select id="asde-papel-<?php echo (int) $m['id']; ?>" name="papel">
										<?php foreach ( asde_ta( InscripcionesTable::PAPELES ) as $papel => $etiqueta ) : ?>
											<option value="<?php echo esc_attr( $papel ); ?>" <?php selected( $m['papel'], $papel ); ?>><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Cambiar' ); ?></button>
								</form>
							<?php else : ?>
								<?php echo esc_html( asde_t( InscripcionesTable::PAPELES[ $m['papel'] ] ?? $m['papel'] ) ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo asde_a( 'Titulación' ); ?>"><?php echo esc_html( $check['leido'] ? $titulos[ $dato['titulo'] ] : '—' ); ?></td>
						<td data-label="<?php echo asde_a( 'Certificado' ); ?>"><span class="asde-comprobacion--<?php echo esc_attr( $cert[0] ); ?>"><?php echo esc_html( $cert[1] ); ?></span></td>
						<?php if ( $editable ) : ?>
							<td data-label="<?php echo asde_a( 'Quitar' ); ?>">
								<form method="post" class="asde-inline-form" onsubmit="return confirm('<?php echo esc_js( asde_t( '¿Quitar a esta persona del equipo?' ) ); ?>');">
									<?php echo $campos( 'evento_equipo_quitar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="fila" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Quitar' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="asde-ratio">
		<?php echo esc_html( sprintf( asde_t( 'Participantes: %d (las plazas o, si no hay, los previstos).' ), $r['participantes'] ) ); ?>
		<?php echo esc_html( sprintf( asde_t( 'Hacen falta %1$d monitores además del director/a (%2$d de apoyo por dependencia) y hay %3$d.' ), $r['necesarios'], $r['apoyo'], $r['monitores'] ) ); ?>
	</p>
	<?php if ( $check['avisos'] ) : ?>
		<ul class="asde-comprobacion">
			<?php foreach ( $check['avisos'] as $aviso ) : ?>
				<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $aviso ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Añadir al equipo' ); ?></h3>
		<?php if ( ! $candidatos ) : ?>
			<p class="asde-muted"><?php asde_e( 'No quedan scouters ni voluntariado del grupo por añadir.' ); ?></p>
		<?php else : ?>
			<form method="post" class="asde-form asde-form--compacta">
				<?php echo $campos( 'evento_equipo_anadir' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Persona' ); ?>
						<select name="socio" required>
							<option value=""><?php asde_e( '— Elige —' ); ?></option>
							<?php foreach ( $candidatos as $n => $p ) : ?>
								<option value="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $p->nombre_completo() . ' · ' . implode( ', ', array_map( array( Secciones::class, 'nombre' ), $p->secciones ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php asde_e( 'Función' ); ?>
						<select name="papel">
							<?php foreach ( asde_ta( InscripcionesTable::PAPELES ) as $papel => $etiqueta ) : ?>
								<option value="<?php echo esc_attr( $papel ); ?>" <?php selected( 'monitor', $papel ); ?>><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button"><?php asde_e( 'Añadir' ); ?></button>
				</p>
				<p class="asde-help"><?php asde_e( 'Quien está en el equipo puede gestionar el evento. El equipo no paga: su coste se reparte en el precio de los participantes.' ); ?></p>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
