<?php
/**
 * Reintegros (v0.47, doc 53): pedir uno, los míos y, para quien aprueba o paga, los pendientes y el historial.
 *
 * @var bool                $puede_pedir Si puede pedir reintegros (scouter o Coordinación)
 * @var array<int, string>  $eventos     Eventos a los que se puede asociar (id => nombre; vacío sin el módulo Eventos)
 * @var array[]             $mios        Los reintegros de esta persona
 * @var array[]             $todos       Por aprobar y por pagar (solo si aprueba o paga)
 * @var array[]             $historial   Pagados, rechazados y cancelados (solo si aprueba o paga)
 * @var string              $url
 * @var string              $hoy         Y-m-d
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Reintegros\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$nombre = static function ( array $r ) use ( $eventos ): string {
	return $r['evento_id'] ? (string) ( $eventos[ $r['evento_id'] ] ?? asde_t( 'Evento' ) ) : '';
};
/** Una fila de la tabla de reintegros, con lo que se puede hacer con ella. */
$fila = static function ( array $r, bool $con_quien ) use ( $url, $fecha, $nombre, $hoy ): void {
	$iban = Servicio::iban( $r );
	?>
	<tr>
		<td data-label="<?php echo esc_attr( asde_a( 'Reintegro' ) ); ?>">
			<strong><?php echo esc_html( ReintegrosTable::numero( $r ) ); ?></strong>
			<span class="asde-badge asde-badge--reintegro-<?php echo esc_attr( $r['estado'] ); ?>"><?php echo esc_html( asde_t( ReintegrosTable::ESTADOS[ $r['estado'] ] ?? '' ) ); ?></span>
			<?php echo $con_quien ? '<br><span class="asde-muted">' . esc_html( Servicio::nombre( $r['solicitante'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</td>
		<td data-label="<?php echo esc_attr( asde_a( 'Qué' ) ); ?>">
			<?php echo esc_html( $r['concepto'] ); ?><br>
			<span class="asde-muted"><?php echo esc_html( $fecha( $r['fecha'] ) ); ?><?php echo '' !== $nombre( $r ) ? ' · ' . esc_html( $nombre( $r ) ) : ''; ?></span>
			<?php if ( '' !== $r['motivo'] && ReintegrosTable::RECHAZADO === $r['estado'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Motivo: %s' ), $r['motivo'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( ReintegrosTable::PAGADO === $r['estado'] && $r['pago_fecha'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Pagado el %s' ), $fecha( $r['pago_fecha'] ) ) ); ?><?php echo '' !== $r['pago_ref'] ? ' · ' . esc_html( $r['pago_ref'] ) : ''; ?></span>
			<?php endif; ?>
		</td>
		<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>">
			<?php echo esc_html( Calculadora::euros( $r['importe'] ) ); ?><br>
			<span class="asde-muted"><?php echo esc_html( asde_t( ReintegrosTable::FORMAS[ $r['forma'] ] ?? '' ) ); ?></span>
			<?php echo '' !== $iban ? '<br><span class="asde-muted">' . esc_html( $iban ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</td>
		<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
			<?php if ( '' !== $r['justificante'] ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-sin-carga target="_blank">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_justificante">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="asde-btn-link"><?php asde_e( 'Ver justificante' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_aprobar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_aprobar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="button"><?php asde_e( 'Aprobar' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_rechazar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<p class="asde-form__fila">
						<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
						<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Rechazar' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_pagar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_pagar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<p class="asde-form__fila">
						<label><?php asde_e( 'Fecha del pago' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
						<label><?php asde_e( 'Referencia (opcional)' ); ?> <input type="text" name="referencia" maxlength="120"></label>
						<button type="submit" class="button"><?php asde_e( 'Marcar pagado' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_cancelar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Cancelar el reintegro %s?', ReintegrosTable::numero( $r ) ) ); ?>">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_cancelar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Cancelar' ); ?></button>
				</form>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
/** La tabla de una lista de reintegros. */
$tabla = static function ( array $lista, bool $con_quien ) use ( $fila ): void {
	?>
	<table class="asde-table">
		<thead>
			<tr>
				<th scope="col"><?php asde_e( 'Reintegro' ); ?></th>
				<th scope="col"><?php asde_e( 'Qué' ); ?></th>
				<th scope="col"><?php asde_e( 'Importe' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lista as $r ) : ?>
				<?php $fila( $r, $con_quien ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<?php if ( $puede_pedir ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-pedir" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php asde_e( 'Pedir un reintegro' ); ?></h2>
		<p class="asde-muted"><?php asde_e( 'Si has pagado algo del grupo de tu bolsillo, pide aquí que te lo devuelvan. Adjunta el tique o la factura. Lo aprueba Tesorería y lo paga Tesorería o Coordinación.' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>" data-asde-reintegro>
			<?php wp_nonce_field( 'asde_reintegro_pedir' ); ?>
			<input type="hidden" name="asde_action" value="reintegro_pedir">
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-rei-concepto"><?php asde_e( 'Qué pagaste' ); ?> <input type="text" id="asde-rei-concepto" name="concepto" maxlength="120" required></label>
				<label for="asde-rei-fecha"><?php asde_e( 'Fecha del gasto' ); ?> <input type="date" id="asde-rei-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
				<label for="asde-rei-importe"><?php asde_e( 'Importe (€)' ); ?> <input type="text" id="asde-rei-importe" name="importe" inputmode="decimal" size="8" required></label>
				<?php if ( $eventos ) : ?>
					<label for="asde-rei-evento"><?php asde_e( 'Evento (si es de uno)' ); ?>
						<select id="asde-rei-evento" name="evento">
							<option value=""><?php asde_e( 'Sin evento' ); ?></option>
							<?php foreach ( $eventos as $id => $nombre_evento ) : ?>
								<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $nombre_evento ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</p>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-rei-just"><?php asde_e( 'Tique o factura (imagen o PDF, hasta 5 MB)' ); ?> <input type="file" id="asde-rei-just" name="justificante" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
				<label for="asde-rei-forma"><?php asde_e( '¿Cómo prefieres cobrarlo?' ); ?>
					<select id="asde-rei-forma" name="forma">
						<?php foreach ( asde_ta( ReintegrosTable::FORMAS ) as $forma => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $forma ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label for="asde-rei-iban" data-asde-iban><?php asde_e( 'IBAN (solo por transferencia)' ); ?> <input type="text" id="asde-rei-iban" name="iban" maxlength="42" autocomplete="off"></label>
				<button type="submit" class="button"><?php asde_e( 'Pedir el reintegro' ); ?></button>
			</p>
			<p class="asde-help"><?php asde_e( 'Solo lo ven Tesorería y Coordinación, y se borra cuando el reintegro se paga, se rechaza o se cancela.' ); ?></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::ve_todos() ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-pendientes" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php asde_e( 'Por aprobar y por pagar' ); ?></h2>
		<?php if ( ! $todos ) : ?>
			<p class="asde-muted"><?php asde_e( 'No hay reintegros pendientes.' ); ?></p>
		<?php else : ?>
			<?php $tabla( $todos, true ); ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $puede_pedir ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-mios" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php asde_e( 'Mis reintegros' ); ?></h2>
		<?php if ( ! $mios ) : ?>
			<p class="asde-muted"><?php asde_e( 'Todavía no has pedido ningún reintegro.' ); ?></p>
		<?php else : ?>
			<?php $tabla( $mios, false ); ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( Servicio::ve_todos() && $historial ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-historial" data-asde-inicio="cerrado">
		<h2 class="asde-card__title"><?php asde_e( 'Historial' ); ?></h2>
		<?php $tabla( $historial, true ); ?>
	</section>
<?php endif; ?>
