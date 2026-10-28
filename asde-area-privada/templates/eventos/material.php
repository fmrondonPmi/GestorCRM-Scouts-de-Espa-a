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
		<h3 class="asde-card__title"><?php esc_html_e( 'Event equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla"><button type="button" class="button asde-button-secundario" data-asde-imprimir-pagina><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'What the event takes from the group\'s inventory. Reserving it does not deduct it: it warns you if another event with overlapping dates takes the same and there is not enough.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php if ( $resumen['lineas'] ) : ?>
		<p>
			<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d item of equipment', '%d items of equipment', $resumen['lineas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resumen['lineas'] ) ); ?>
			<?php echo wp_kses( $resumen['sin_sacar'] ? ' · ' . esc_html( sprintf( /* translators: %d: number */ _n( '%d not yet taken out', '%d not yet taken out', $resumen['sin_sacar'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resumen['sin_sacar'] ) ) : '', asde_html_permitido() ); ?>
			<?php echo wp_kses( $resumen['por_devolver'] ? ' · ' . esc_html( sprintf( /* translators: %d: number */ _n( '%d to refund', '%d to refund', $resumen['por_devolver'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resumen['por_devolver'] ) ) : '', asde_html_permitido() ); ?>
			<?php echo wp_kses( $resumen['con_faltas'] ? ' · <strong>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d with absences', '%d with absences', $resumen['con_faltas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resumen['con_faltas'] ) ) . '</strong>' : '', asde_html_permitido() ); ?>
		</p>
	<?php endif; ?>

	<?php if ( ! $lineas ) : ?>
		<p><?php esc_html_e( 'Nothing has been reserved for this event yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reserved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Refund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( $l['descripcion'] ); ?>
							<?php echo wp_kses( $l['baja'] ? ' <span class="asde-badge asde-badge--pago-atrasado">' . esc_html( __( 'No longer in the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Reserved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $puede_editar && null === $l['salida_en'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla">
									<?php echo wp_kses( $campos( $l['id'] ), asde_html_permitido() ); ?>
									<input type="number" name="cantidad" min="1" max="99999" value="<?php echo (int) $l['cantidad']; ?>" size="5" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Quantity of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['descripcion'] ) ); ?>">
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_cantidad"><?php esc_html_e( 'Change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_quitar"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
								<span class="asde-solo-impresion"><?php echo (int) $l['cantidad']; ?></span>
							<?php else : ?>
								<?php echo (int) $l['cantidad']; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( null === $l['devuelta_en'] ) : ?>
								<?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'There are %1$d · other events %2$d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['existencias'], $l['otros'] ) ); ?>
								<?php if ( $l['faltan'] > 0 ) : ?>
									<br><span class="asde-badge asde-badge--pago-atrasado"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d unit missing', '%d units missing', $l['faltan'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['faltan'] ) ); ?></span>
								<?php else : ?>
									<br><span class="asde-badge asde-badge--pago-pagado"><?php esc_html_e( 'Reaches', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( null !== $l['salida_en'] ) : ?>
								<?php echo esc_html( $corta( $l['salida_en'] ) ); ?>
								<?php $quien = $usuario( $l['salida_por'] ); ?>
								<?php echo wp_kses( '' !== $quien ? '<span class="asde-muted">· ' . esc_html( $quien ) . '</span>' : '', asde_html_permitido() ); ?>
							<?php elseif ( $puede_editar ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla">
									<?php echo wp_kses( $campos( $l['id'] ), asde_html_permitido() ); ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="material_salida"><?php esc_html_e( 'Mark as taken out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Refund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( null !== $l['devuelta_en'] ) : ?>
								<?php echo esc_html( sprintf( /* translators: 1: number, 2: number, 3: text */ __( '%1$d of %2$d on %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $l['devueltas'], $l['cantidad'], $corta( $l['devuelta_en'] ) ) ); ?>
								<?php echo wp_kses( '' !== $l['incidencia'] ? '<br><span class="asde-muted">' . esc_html( $l['incidencia'] ) . '</span>' : '', asde_html_permitido() ); ?>
								<?php if ( $l['sin_devolver'] > 0 ) : ?>
									<br><span class="asde-badge asde-badge--pago-parcial"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d unit missing', '%d units missing', $l['sin_devolver'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['sin_devolver'] ) ); ?></span>
									<?php if ( $l['descontado'] ) : ?>
										<span class="asde-muted"><?php esc_html_e( 'Deducted from the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
									<?php elseif ( $puede_editar ) : ?>
										<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-inline asde-solo-pantalla" data-asde-confirmar="<?php echo esc_attr( esc_attr__( 'Deduct from the inventory what has not come back? This is final.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
											<?php echo wp_kses( $campos( $l['id'] ), asde_html_permitido() ); ?>
											<button type="submit" class="asde-btn-link" name="asde_action" value="material_descontar"><?php esc_html_e( 'Deduct from the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $puede_editar && null !== $l['salida_en'] && ! $l['descontado'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-solo-pantalla">
									<?php echo wp_kses( $campos( $l['id'] ), asde_html_permitido() ); ?>
									<label class="screen-reader-text" for="asde-md-<?php echo (int) $l['id']; ?>"><?php esc_html_e( 'Units returned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<input type="number" id="asde-md-<?php echo (int) $l['id']; ?>" name="devueltas" min="0" max="<?php echo (int) $l['cantidad']; ?>" value="<?php echo (int) ( $l['devueltas'] ?? $l['cantidad'] ); ?>" size="5">
									<input type="text" name="incidencia" maxlength="200" value="<?php echo esc_attr( $l['incidencia'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Incident (missing, broken…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Incident', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<button type="submit" class="button asde-button-secundario" name="asde_action" value="material_devolver"><?php echo esc_html( null === $l['devuelta_en'] ? __( 'Record refund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Fix', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button>
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
				<?php echo wp_kses( $campos(), asde_html_permitido() ); ?>
				<p><button type="submit" class="button" name="asde_action" value="material_salida"><?php esc_html_e( 'Mark everything as taken out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $puede_editar ) : ?>
	<section class="asde-card asde-solo-pantalla" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php esc_html_e( 'Reserve equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $reservable ) : ?>
			<p><?php esc_html_e( 'There is no equipment in stock in the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_inventario ); ?>"><?php esc_html_e( 'Go to the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $url_material ); ?>" class="asde-form">
				<?php echo wp_kses( $campos(), asde_html_permitido() ); ?>
				<input type="hidden" name="asde_action" value="material_anadir">
				<p class="asde-form__fila asde-form__fila--una-linea">
					<label for="asde-ma-material"><?php esc_html_e( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <select id="asde-ma-material" name="material" required>
						<option value="">—</option>
						<?php foreach ( $reservable as $m ) : ?>
							<option value="<?php echo (int) $m['id']; ?>"><?php echo esc_html( $m['descripcion'] . ' (' . (int) $m['cantidad'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select></label>
					<label for="asde-ma-cantidad"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" id="asde-ma-cantidad" name="cantidad" min="1" max="99999" value="1" required></label>
					<button type="submit" class="button"><?php esc_html_e( 'Reserve', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
		<?php if ( $habitual ) : ?>
			<form method="post" action="<?php echo esc_url( $url_material ); ?>">
				<?php echo wp_kses( $campos(), asde_html_permitido() ); ?>
				<p class="asde-help"><?php esc_html_e( 'Or load the usual equipment for this type of event in one go (what is already reserved is added to).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button asde-button-secundario" name="asde_action" value="material_habitual"><?php esc_html_e( 'Load the usual equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php else : ?>
			<p class="asde-help"><?php esc_html_e( 'If you want usual equipment for this type of event, write it in the event settings.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_config ); ?>"><?php esc_html_e( 'Go to the settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
		<?php endif; ?>
		<p class="asde-help"><?php esc_html_e( 'If something does not come back, record the return with what is missing; afterwards you can deduct those units from the inventory. To pay for a replacement, record it in the event\'s accounts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>
<?php endif; ?>
