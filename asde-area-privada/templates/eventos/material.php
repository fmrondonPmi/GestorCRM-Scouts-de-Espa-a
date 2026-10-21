<?php
/**
 * Evento · material (v0.42, doc 43): lo que se lleva del inventario del grupo, con el aviso si otro evento que coincide en fechas lo
 * reserva, cuándo sale y cómo vuelve. Sin datos personales.
 *
 * @var array                $e
 * @var array[]              $lineas       Material::lineas()
 * @var array[]              $reservable   El material del inventario que se puede reservar
 * @var bool                 $habitual     Este tipo de evento tiene material habitual en la configuración
 * @var bool                 $puede_editar
 * @var array                $resumen      lineas, sin_sacar, por_devolver, con_faltas
 * @var string               $url_material
 * @var string               $url_config
 * @var string               $url_inventario
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$corta   = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' UTC' ) );
$usuario = static fn( int $id ): string => $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '';
$campos  = static function ( int $linea = 0 ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false ) . '<input type="hidden" name="evento" value="' . (int) $e['id'] . '"><input type="hidden" name="pestana" value="material">' . ( $linea ? '<input type="hidden" name="linea" value="' . $linea . '">' : '' );
};
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Material del evento' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla"><button type="button" class="button asde-button-secundario" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button></span>
	</header>
	<p class="asde-muted"><?php asde_e( 'Lo que el evento se lleva del inventario del grupo. Reservarlo no lo descuenta: avisa si otro evento que coincide en fechas se lleva lo mismo y no alcanza.' ); ?></p>

	<?php if ( $resumen['lineas'] ) : ?>
		<p>
			<?php echo esc_html( sprintf( asde_n( '%d material', '%d materiales', $resumen['lineas'] ), $resumen['lineas'] ) ); ?>
			<?php echo $resumen['sin_sacar'] ? ' · ' . esc_html( sprintf( asde_n( '%d sin sacar', '%d sin sacar', $resumen['sin_sacar'] ), $resumen['sin_sacar'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $resumen['por_devolver'] ? ' · ' . esc_html( sprintf( asde_n( '%d por devolver', '%d por devolver', $resumen['por_devolver'] ), $resumen['por_devolver'] ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $resumen['con_faltas'] ? ' · <strong>' . esc_html( sprintf( asde_n( '%d con faltas', '%d con faltas', $resumen['con_faltas'] ), $resumen['con_faltas'] ) ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
	<?php endif; ?>

	<?php if ( ! $lineas ) : ?>
		<p><?php asde_e( 'Todavía no se ha reservado nada para este evento.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Material' ); ?></th>
					<th scope="col"><?php asde_e( 'Reservado' ); ?></th>
					<th scope="col"><?php asde_e( 'Inventario' ); ?></th>
					<th scope="col"><?php asde_e( 'Salida' ); ?></th>
					<th scope="col"><?php asde_e( 'Devolución' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Material' ) ); ?>">
							<?php echo esc_html( $l['descripcion'] ); ?>
							<?php echo $l['baja'] ? ' <span class="asde-badge asde-badge--pago-atrasado">' . esc_html( asde_t( 'Ya no está en el inventario' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Reservado' ) ); ?>">
							<?php if ( $puede_editar && null === $l['salida_en'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla">
									<?php echo $campos( $l['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="number" name="cantidad" min="1" max="99999" value="<?php echo (int) $l['cantidad']; ?>" size="5" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Cantidad de %s' ), $l['descripcion'] ) ); ?>">
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_cantidad"><?php asde_e( 'Cambiar' ); ?></button>
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_quitar"><?php asde_e( 'Quitar' ); ?></button>
								</form>
								<span class="asde-solo-impresion"><?php echo (int) $l['cantidad']; ?></span>
							<?php else : ?>
								<?php echo (int) $l['cantidad']; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Inventario' ) ); ?>">
							<?php if ( null === $l['devuelta_en'] ) : ?>
								<?php echo esc_html( sprintf( asde_t( 'Hay %1$d · otros eventos %2$d' ), $l['existencias'], $l['otros'] ) ); ?>
								<?php if ( $l['faltan'] > 0 ) : ?>
									<br><span class="asde-badge asde-badge--pago-atrasado"><?php echo esc_html( sprintf( asde_n( 'Falta %d unidad', 'Faltan %d unidades', $l['faltan'] ), $l['faltan'] ) ); ?></span>
								<?php else : ?>
									<br><span class="asde-badge asde-badge--pago-pagado"><?php asde_e( 'Alcanza' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Salida' ) ); ?>">
							<?php if ( null !== $l['salida_en'] ) : ?>
								<?php echo esc_html( $corta( $l['salida_en'] ) ); ?>
								<?php $quien = $usuario( $l['salida_por'] ); ?>
								<?php echo '' !== $quien ? '<span class="asde-muted">· ' . esc_html( $quien ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php elseif ( $puede_editar ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla">
									<?php echo $campos( $l['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_salida"><?php asde_e( 'Marcar salida' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Devolución' ) ); ?>">
							<?php if ( null !== $l['devuelta_en'] ) : ?>
								<?php echo esc_html( sprintf( asde_t( '%1$d de %2$d el %3$s' ), (int) $l['devueltas'], $l['cantidad'], $corta( $l['devuelta_en'] ) ) ); ?>
								<?php echo '' !== $l['incidencia'] ? '<br><span class="asde-muted">' . esc_html( $l['incidencia'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( $l['sin_devolver'] > 0 ) : ?>
									<br><span class="asde-badge asde-badge--pago-parcial"><?php echo esc_html( sprintf( asde_n( 'Falta %d unidad', 'Faltan %d unidades', $l['sin_devolver'] ), $l['sin_devolver'] ) ); ?></span>
									<?php if ( $l['descontado'] ) : ?>
										<span class="asde-muted"><?php asde_e( 'Descontado del inventario' ); ?></span>
									<?php elseif ( $puede_editar ) : ?>
										<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla" data-asde-confirmar="<?php echo esc_attr( asde_a( '¿Descontar del inventario lo que no ha vuelto? Es definitivo.' ) ); ?>">
											<?php echo $campos( $l['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<button type="submit" class="asde-btn-link" name="asde_action" value="material_descontar"><?php asde_e( 'Descontar del inventario' ); ?></button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $puede_editar && null !== $l['salida_en'] && ! $l['descontado'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-solo-pantalla">
									<?php echo $campos( $l['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<label class="screen-reader-text" for="asde-md-<?php echo (int) $l['id']; ?>"><?php asde_e( 'Unidades devueltas' ); ?></label>
									<input type="number" id="asde-md-<?php echo (int) $l['id']; ?>" name="devueltas" min="0" max="<?php echo (int) $l['cantidad']; ?>" value="<?php echo (int) ( $l['devueltas'] ?? $l['cantidad'] ); ?>" size="5">
									<input type="text" name="incidencia" maxlength="200" value="<?php echo esc_attr( $l['incidencia'] ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Incidencia (falta, roto…)' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Incidencia' ) ); ?>">
									<button type="submit" class="button asde-button-secundario" name="asde_action" value="material_devolver"><?php echo esc_html( null === $l['devuelta_en'] ? asde_t( 'Anotar devolución' ) : asde_t( 'Corregir' ) ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( null === $l['devuelta_en'] && null === $l['salida_en'] ) : ?>
								<span class="asde-muted asde-solo-impresion">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $puede_editar && $resumen['sin_sacar'] ) : ?>
			<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-solo-pantalla">
				<?php echo $campos(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p><button type="submit" class="button" name="asde_action" value="material_salida"><?php asde_e( 'Marcar la salida de todo' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $puede_editar ) : ?>
	<section class="asde-card asde-solo-pantalla" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php asde_e( 'Reservar material' ); ?></h3>
		<?php if ( ! $reservable ) : ?>
			<p><?php asde_e( 'No hay material con existencias en el inventario.' ); ?> <a href="<?php echo esc_url( $url_inventario ); ?>"><?php asde_e( 'Ir al inventario' ); ?></a></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-form">
				<?php echo $campos(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="asde_action" value="material_anadir">
				<p class="asde-form__fila asde-form__fila--una-linea">
					<label for="asde-ma-material"><?php asde_e( 'Material' ); ?> <select id="asde-ma-material" name="material" required>
						<option value="">—</option>
						<?php foreach ( $reservable as $m ) : ?>
							<option value="<?php echo (int) $m['id']; ?>"><?php echo esc_html( $m['descripcion'] . ' (' . (int) $m['cantidad'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select></label>
					<label for="asde-ma-cantidad"><?php asde_e( 'Cantidad' ); ?> <input type="number" id="asde-ma-cantidad" name="cantidad" min="1" max="99999" value="1" required></label>
					<button type="submit" class="button"><?php asde_e( 'Reservar' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
		<?php if ( $habitual ) : ?>
			<form method="post" action="<?php echo esc_url( $url_material ); ?>">
				<?php echo $campos(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="asde-help"><?php asde_e( 'O carga de una vez el material habitual de este tipo de evento (lo que ya esté reservado se suma).' ); ?></p>
				<p><button type="submit" class="button asde-button-secundario" name="asde_action" value="material_habitual"><?php asde_e( 'Cargar el material habitual' ); ?></button></p>
			</form>
		<?php else : ?>
			<p class="asde-help"><?php asde_e( 'Si quieres un material habitual para este tipo de evento, escríbelo en la configuración de eventos.' ); ?> <a href="<?php echo esc_url( $url_config ); ?>"><?php asde_e( 'Ir a la configuración' ); ?></a></p>
		<?php endif; ?>
		<p class="asde-help"><?php asde_e( 'Si algo no vuelve, anota la devolución con lo que falta; después puedes descontar esas unidades del inventario. Para pagar una reposición, apúntala en las cuentas del evento.' ); ?></p>
	</section>
<?php endif; ?>
