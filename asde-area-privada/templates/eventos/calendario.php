<?php
/**
 * Evento · calendario de tareas (v0.40, doc 49): lo que hay que hacer y para cuándo, con el semáforo de cada
 * tarea. Cualquier scouter con acceso al evento las marca como hechas (P-187); quien lo gestiona aplica la
 * plantilla, cambia fechas y responsables, y añade o quita tareas.
 *
 * @var array                                                 $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                   $ambito
 * @var array[]                                               $equipo
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $nombres
 * @var array[]                                               $tareas
 * @var array<int, string>                                    $estado       hecha | vencida | semana | pronto | sin_fecha
 * @var array<int, bool>                                      $auto         Tareas con comprobación automática que se puede medir: id => se cumple
 * @var array<int, int|null>                                  $dias         Días que faltan (negativo: ya pasó)
 * @var bool                                                  $puede_marcar
 * @var bool                                                  $puede_editar
 * @var array<string, string>                                 $plantillas   id => nombre
 * @var string                                                $propuesta    Plantilla que propone el tipo de evento
 * @var bool                                                  $viernes      Las fechas de fin de semana se adelantan al viernes
 * @var string                                                $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\Plantillas;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;

defined( 'ABSPATH' ) || exit;

$campos = static function ( string $accion ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="calendario">';
};
$fecha  = static fn( ?string $f ): string => $f ? wp_date( 'D d/m/Y', strtotime( $f . ' 12:00:00 UTC' ) ) : '';
$corta  = static fn( ?string $f ): string => $f ? wp_date( 'd/m', strtotime( $f . ' 12:00:00 UTC' ) ) : '';
$cuenta = array(
	'hecha'   => 0,
	'vencida' => 0,
	'semana'  => 0,
);
foreach ( $tareas as $t ) {
	if ( isset( $cuenta[ $estado[ $t['id'] ] ] ) ) {
		++$cuenta[ $estado[ $t['id'] ] ];
	}
}
$etiquetas = array(
	'hecha'     => asde_t( 'Hecha' ),
	'vencida'   => asde_t( 'Vencida' ),
	'semana'    => asde_t( 'Esta semana' ),
	'pronto'    => asde_t( 'Más adelante' ),
	'sin_fecha' => asde_t( 'Sin fecha' ),
);
$enlaces   = array(
	'resumen'       => asde_t( 'Resumen' ),
	'equipo'        => asde_t( 'Equipo' ),
	'inscripciones' => asde_t( 'Inscripciones' ),
	'presupuesto'   => asde_t( 'Presupuesto' ),
);
?>
<section class="asde-card asde-tareas">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Calendario de tareas' ); ?></h3>
		<?php if ( $tareas ) : ?>
			<button type="button" class="button asde-button-secundario asde-solo-pantalla" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button>
		<?php endif; ?>
	</header>

	<?php if ( ! $tareas ) : ?>
		<p><?php asde_e( 'Este evento todavía no tiene tareas.' ); ?></p>
		<?php if ( $puede_editar && $plantillas ) : ?>
			<form method="post" class="asde-form">
				<?php echo $campos( 'tarea_aplicar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p>
					<label for="asde-tarea-plantilla"><?php asde_e( 'Plantilla de tareas' ); ?></label>
					<select id="asde-tarea-plantilla" name="plantilla">
						<?php foreach ( $plantillas as $id => $nombre ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $propuesta, (string) $id ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="asde-help"><?php asde_e( 'Las fechas límite se calculan a partir de las del evento (por ejemplo, 60 días antes del inicio). Si cambias las fechas del evento, se recalculan las tareas que no estén hechas ni tengan fecha propia.' ); ?></p>
				<p><button type="submit" class="button"><?php asde_e( 'Aplicar la plantilla' ); ?></button></p>
			</form>
		<?php elseif ( $puede_editar ) : ?>
			<p class="asde-muted"><?php asde_e( 'No hay plantillas con tareas. Se crean en Eventos → Configuración → Calendario de tareas, o puedes añadir las tareas una a una.' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<p class="asde-tareas__resumen">
			<strong><?php echo (int) $cuenta['hecha']; ?></strong> / <?php echo count( $tareas ); ?> <?php asde_e( 'hechas' ); ?>
			<?php if ( $cuenta['vencida'] ) : ?>
				· <span class="asde-badge asde-badge--tarea-vencida"><?php echo esc_html( sprintf( asde_n( '%d vencida', '%d vencidas', $cuenta['vencida'] ), $cuenta['vencida'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( $cuenta['semana'] ) : ?>
				· <span class="asde-badge asde-badge--tarea-semana"><?php echo esc_html( sprintf( asde_n( '%d esta semana', '%d esta semana', $cuenta['semana'] ), $cuenta['semana'] ) ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( $viernes ) : ?>
			<p class="asde-help"><?php asde_e( 'Las fechas que caen en sábado o domingo se adelantan al viernes.' ); ?></p>
		<?php endif; ?>

		<ul class="asde-tareas__lista">
			<?php foreach ( $tareas as $t ) : ?>
				<?php
				$st      = $estado[ $t['id'] ];
				$d       = $dias[ $t['id'] ];
				$f       = TareasTable::fecha( $t );
				$resp    = '' !== $t['responsable'] ? ( isset( $nombres[ $t['responsable'] ] ) ? $nombres[ $t['responsable'] ]->nombre_completo() : $t['responsable'] ) : '';
				$cuando  = '';
				if ( 'hecha' === $st ) {
					$cuando = sprintf( asde_t( 'Hecha el %s' ), wp_date( 'd/m/Y', strtotime( $t['hecha_en'] . ' UTC' ) ) );
				} elseif ( null !== $d ) {
					$cuando = $d < 0
						? sprintf( asde_n( 'Venció hace %d día', 'Venció hace %d días', -$d ), -$d )
						: ( 0 === $d ? asde_t( 'Vence hoy' ) : sprintf( asde_n( 'Queda %d día', 'Quedan %d días', $d ), $d ) );
				}
				?>
				<li class="asde-tarea asde-tarea--<?php echo esc_attr( $st ); ?>">
					<div class="asde-tarea__marca">
						<?php if ( $puede_marcar && ! isset( $auto[ $t['id'] ] ) ) : ?>
							<form method="post">
								<?php echo $campos( 'hecha' === $st ? 'tarea_deshecha' : 'tarea_hecha' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
								<button type="submit" class="asde-tarea__boton" aria-label="<?php echo esc_attr( 'hecha' === $st ? asde_a( 'Marcar como pendiente' ) : asde_a( 'Marcar como hecha' ) ); ?>" title="<?php echo esc_attr( 'hecha' === $st ? asde_a( 'Marcar como pendiente' ) : asde_a( 'Marcar como hecha' ) ); ?>"><?php echo 'hecha' === $st ? '✓' : ''; ?></button>
							</form>
						<?php else : ?>
							<span class="asde-tarea__boton" aria-hidden="true"><?php echo 'hecha' === $st ? '✓' : ''; ?></span>
						<?php endif; ?>
					</div>
					<div class="asde-tarea__cuerpo">
						<strong class="asde-tarea__titulo"><?php echo esc_html( Plantillas::texto( $t['titulo'] ) ); ?></strong>
						<?php echo '' !== $t['detalle'] ? '<span class="asde-muted">' . esc_html( Plantillas::texto( $t['detalle'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="asde-tarea__meta">
							<span class="asde-badge asde-badge--tarea-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $etiquetas[ $st ] ); ?></span>
							<?php if ( isset( $auto[ $t['id'] ] ) ) : ?>
								<span class="asde-badge asde-badge--tarea-auto" title="<?php echo esc_attr( asde_a( 'Se marca sola cuando se cumple lo que mide la aplicación' ) ); ?>"><?php asde_e( 'Automática' ); ?></span>
							<?php endif; ?>
							<?php echo esc_html( $fecha( $f ) ); ?>
							<?php if ( '' !== $cuando ) : ?>
								<span class="asde-muted">· <?php echo esc_html( $cuando ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $t['fecha_aj'] && $t['fecha_aj'] !== $t['fecha_calc'] && null === $t['fecha_propia'] ) : ?>
								<span class="asde-muted">· <?php echo esc_html( sprintf( asde_t( 'adelantada desde el %s' ), $corta( $t['fecha_calc'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $t['fecha_propia'] && ! $t['propia'] ) : ?>
								<span class="asde-muted">· <?php asde_e( 'fecha propia' ); ?></span>
							<?php endif; ?>
							<?php echo '' !== $resp ? '<span>· ' . esc_html( sprintf( asde_t( 'Responsable: %s' ), $resp ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( '' !== $t['enlace'] && isset( $enlaces[ $t['enlace'] ] ) ) : ?>
								· <a class="asde-solo-pantalla" href="<?php echo esc_url( add_query_arg( 'pestana', $t['enlace'], $url ) ); ?>"><?php echo esc_html( $enlaces[ $t['enlace'] ] ); ?></a>
							<?php endif; ?>
						</span>
						<?php echo '' !== $t['nota'] ? '<span class="asde-tarea__nota">' . esc_html( $t['nota'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>

						<?php if ( $puede_marcar && isset( $auto[ $t['id'] ] ) && ! $auto[ $t['id'] ] && null === $t['hecha_en'] ) : ?>
							<details class="asde-tarea__editar asde-solo-pantalla">
								<summary><?php asde_e( 'No procede' ); ?></summary>
								<form method="post" class="asde-form asde-form--compacta">
									<?php echo $campos( 'tarea_hecha' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<p class="asde-form__fila">
										<label><?php asde_e( 'Observación' ); ?> <input type="text" name="nota" value="<?php echo esc_attr( $t['nota'] ); ?>" maxlength="255" required></label>
										<button type="submit" class="button"><?php asde_e( 'Cerrar la tarea' ); ?></button>
									</p>
									<p class="asde-help"><?php asde_e( 'Esta tarea se marca sola cuando se cumple. Si no procede, ciérrala con una observación.' ); ?></p>
								</form>
							</details>
						<?php endif; ?>
						<?php if ( $puede_editar ) : ?>
							<details class="asde-tarea__editar asde-solo-pantalla">
								<summary><?php asde_e( 'Cambiar' ); ?></summary>
								<form method="post" class="asde-form asde-form--compacta">
									<?php echo $campos( 'tarea_guardar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<p class="asde-form__fila">
										<label><?php asde_e( 'Responsable' ); ?>
											<select name="responsable">
												<option value=""><?php asde_e( 'Sin asignar' ); ?></option>
												<?php foreach ( $equipo as $m ) : ?>
													<option value="<?php echo esc_attr( $m['socio'] ); ?>" <?php selected( $t['responsable'], $m['socio'] ); ?>><?php echo esc_html( isset( $nombres[ $m['socio'] ] ) ? $nombres[ $m['socio'] ]->nombre_completo() : $m['socio'] ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label><?php asde_e( 'Fecha propia' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( (string) ( $t['fecha_propia'] ?? '' ) ); ?>"></label>
										<label><?php asde_e( 'Observaciones' ); ?> <input type="text" name="nota" value="<?php echo esc_attr( $t['nota'] ); ?>" maxlength="255"></label>
									</p>
									<p class="asde-help"><?php asde_e( 'Con fecha propia, la tarea no se mueve si cambian las fechas del evento. Vacía, vuelve a la calculada.' ); ?></p>
									<p><button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button></p>
								</form>
								<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Quitar esta tarea?' ) ); ?>">
									<?php echo $campos( 'tarea_quitar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Quitar la tarea' ); ?></button>
								</form>
							</details>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( $puede_editar ) : ?>
	<section class="asde-card asde-solo-pantalla">
		<h3 class="asde-card__title"><?php asde_e( 'Añadir una tarea' ); ?></h3>
		<form method="post" class="asde-form">
			<?php echo $campos( 'tarea_anadir' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Tarea' ); ?> <input type="text" name="titulo" maxlength="200" required></label>
				<label><?php asde_e( 'Fecha límite' ); ?> <input type="date" name="fecha" required></label>
			</p>
			<p>
				<label for="asde-tarea-detalle"><?php asde_e( 'Detalle (opcional)' ); ?></label>
				<input type="text" id="asde-tarea-detalle" name="detalle" maxlength="400">
			</p>
			<p><button type="submit" class="button"><?php asde_e( 'Añadir' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>
