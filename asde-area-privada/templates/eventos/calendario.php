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
	'hecha'     => __( 'Done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'vencida'   => __( 'Overdue', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'semana'    => __( 'This week', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'pronto'    => __( 'Later', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'sin_fecha' => __( 'No date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$enlaces   = array(
	'resumen'       => __( 'Summary', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'equipo'        => __( 'Team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'inscripciones' => __( 'Sign-ups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'menu'          => __( 'Menu', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'material'      => __( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'presupuesto'   => __( 'Budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card asde-tareas">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Task calendar', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( $tareas ) : ?>
			<button type="button" class="button asde-button-secundario asde-solo-pantalla" data-asde-imprimir-pagina><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<?php endif; ?>
	</header>

	<?php if ( ! $tareas ) : ?>
		<p><?php esc_html_e( 'This event has no tasks yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php if ( $puede_editar && $plantillas ) : ?>
			<form method="post" class="asde-form">
				<?php echo wp_kses( $campos( 'tarea_aplicar' ), asde_html_permitido() ); ?>
				<p>
					<label for="asde-tarea-plantilla"><?php esc_html_e( 'Task template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-tarea-plantilla" name="plantilla">
						<?php foreach ( $plantillas as $id => $nombre ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $propuesta, (string) $id ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="asde-help"><?php esc_html_e( 'Deadlines are calculated from the event\'s dates (for example, 60 days before the start). If you change the event dates, tasks that are not done and have no date of their own are recalculated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Apply the template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php elseif ( $puede_editar ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'There are no templates with tasks. They are created in Events → Settings → Task calendar, or you can add the tasks one by one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<p class="asde-tareas__resumen">
			<strong><?php echo (int) $cuenta['hecha']; ?></strong> / <?php echo count( $tareas ); ?> <?php esc_html_e( 'done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			<?php if ( $cuenta['vencida'] ) : ?>
				· <span class="asde-badge asde-badge--tarea-vencida"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d overdue', '%d overdue', $cuenta['vencida'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta['vencida'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( $cuenta['semana'] ) : ?>
				· <span class="asde-badge asde-badge--tarea-semana"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d this week', '%d this week', $cuenta['semana'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta['semana'] ) ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( $viernes ) : ?>
			<p class="asde-help"><?php esc_html_e( 'Dates that fall on a Saturday or Sunday are moved forward to the Friday.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
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
					$cuando = sprintf( /* translators: %s: text */ __( 'Done on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $t['hecha_en'] . ' UTC' ) ) );
				} elseif ( null !== $d ) {
					$cuando = $d < 0
						? sprintf( /* translators: %d: number */ _n( 'Was due %d day ago', 'Was due %d days ago', -$d , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), -$d )
						: ( 0 === $d ? __( 'Due today', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ _n( '%d day left', '%d days left', $d , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $d ) );
				}
				?>
				<li class="asde-tarea asde-tarea--<?php echo esc_attr( $st ); ?>">
					<div class="asde-tarea__marca">
						<?php if ( $puede_marcar && ! isset( $auto[ $t['id'] ] ) ) : ?>
							<form method="post">
								<?php echo wp_kses( $campos( 'hecha' === $st ? 'tarea_deshecha' : 'tarea_hecha' ), asde_html_permitido() ); ?>
								<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
								<button type="submit" class="asde-tarea__boton" aria-label="<?php echo esc_attr( 'hecha' === $st ? esc_attr__( 'Mark as pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_attr__( 'Mark as done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" title="<?php echo esc_attr( 'hecha' === $st ? esc_attr__( 'Mark as pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : esc_attr__( 'Mark as done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo 'hecha' === $st ? '✓' : ''; ?></button>
							</form>
						<?php else : ?>
							<span class="asde-tarea__boton" aria-hidden="true"><?php echo 'hecha' === $st ? '✓' : ''; ?></span>
						<?php endif; ?>
					</div>
					<div class="asde-tarea__cuerpo">
						<strong class="asde-tarea__titulo"><?php echo esc_html( Plantillas::texto( $t['titulo'] ) ); ?></strong>
						<?php echo wp_kses( '' !== $t['detalle'] ? '<span class="asde-muted">' . esc_html( Plantillas::texto( $t['detalle'] ) ) . '</span>' : '', asde_html_permitido() ); ?>
						<span class="asde-tarea__meta">
							<span class="asde-badge asde-badge--tarea-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $etiquetas[ $st ] ); ?></span>
							<?php if ( isset( $auto[ $t['id'] ] ) ) : ?>
								<span class="asde-badge asde-badge--tarea-auto" title="<?php echo esc_attr( esc_attr__( 'It is ticked off by itself when what the application measures is met', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Automatic', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
							<?php echo esc_html( $fecha( $f ) ); ?>
							<?php if ( '' !== $cuando ) : ?>
								<span class="asde-muted">· <?php echo esc_html( $cuando ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $t['fecha_aj'] && $t['fecha_aj'] !== $t['fecha_calc'] && null === $t['fecha_propia'] ) : ?>
								<span class="asde-muted">· <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'brought forward from %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $corta( $t['fecha_calc'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $t['fecha_propia'] && ! $t['propia'] ) : ?>
								<span class="asde-muted">· <?php esc_html_e( 'own date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
							<?php echo wp_kses( '' !== $resp ? '<span>· ' . esc_html( sprintf( /* translators: %s: text */ __( 'Person in charge: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resp ) ) . '</span>' : '', asde_html_permitido() ); ?>
							<?php if ( '' !== $t['enlace'] && isset( $enlaces[ $t['enlace'] ] ) ) : ?>
								· <a class="asde-solo-pantalla" href="<?php echo esc_url( add_query_arg( 'pestana', $t['enlace'], $url ) ); ?>"><?php echo esc_html( $enlaces[ $t['enlace'] ] ); ?></a>
							<?php endif; ?>
						</span>
						<?php echo wp_kses( '' !== $t['nota'] ? '<span class="asde-tarea__nota">' . esc_html( $t['nota'] ) . '</span>' : '', asde_html_permitido() ); ?>

						<?php if ( $puede_marcar && isset( $auto[ $t['id'] ] ) && ! $auto[ $t['id'] ] && null === $t['hecha_en'] ) : ?>
							<details class="asde-tarea__editar asde-solo-pantalla">
								<summary><?php esc_html_e( 'Not applicable', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
								<form method="post" class="asde-form asde-form--compacta">
									<?php echo wp_kses( $campos( 'tarea_hecha' ), asde_html_permitido() ); ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<p class="asde-form__fila">
										<label><?php esc_html_e( 'Note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" value="<?php echo esc_attr( $t['nota'] ); ?>" maxlength="255" required></label>
										<button type="submit" class="button"><?php esc_html_e( 'Close the task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</p>
									<p class="asde-help"><?php esc_html_e( 'This task is ticked off by itself when it is done. If it does not apply, close it with a note.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
								</form>
							</details>
						<?php endif; ?>
						<?php if ( $puede_editar ) : ?>
							<details class="asde-tarea__editar asde-solo-pantalla">
								<summary><?php esc_html_e( 'Change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
								<form method="post" class="asde-form asde-form--compacta">
									<?php echo wp_kses( $campos( 'tarea_guardar' ), asde_html_permitido() ); ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<p class="asde-form__fila">
										<label><?php esc_html_e( 'Person in charge', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
											<select name="responsable">
												<option value=""><?php esc_html_e( 'Unassigned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
												<?php foreach ( $equipo as $m ) : ?>
													<option value="<?php echo esc_attr( $m['socio'] ); ?>" <?php selected( $t['responsable'], $m['socio'] ); ?>><?php echo esc_html( isset( $nombres[ $m['socio'] ] ) ? $nombres[ $m['socio'] ]->nombre_completo() : $m['socio'] ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
										<label><?php esc_html_e( 'Own date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( (string) ( $t['fecha_propia'] ?? '' ) ); ?>"></label>
										<label><?php esc_html_e( 'Notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" value="<?php echo esc_attr( $t['nota'] ); ?>" maxlength="255"></label>
									</p>
									<p class="asde-help"><?php esc_html_e( 'With its own date, the task does not move if the event dates change. Empty, it goes back to the calculated one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
									<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
								</form>
								<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Remove this task?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php echo wp_kses( $campos( 'tarea_quitar' ), asde_html_permitido() ); ?>
									<input type="hidden" name="tarea" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Remove the task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
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
		<h3 class="asde-card__title"><?php esc_html_e( 'Add a task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" class="asde-form">
			<?php echo wp_kses( $campos( 'tarea_anadir' ), asde_html_permitido() ); ?>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="titulo" maxlength="200" required></label>
				<label><?php esc_html_e( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" required></label>
			</p>
			<p>
				<label for="asde-tarea-detalle"><?php esc_html_e( 'Detail (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-tarea-detalle" name="detalle" maxlength="400">
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Add', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>
