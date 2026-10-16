<?php
/**
 * Evento · inscripciones (v0.35): plazas, participantes, lista de espera, sin respuesta,
 * bajas, inscribir a alguien y, para Tesorería, los cobros.
 *
 * @var array                                               $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                 $ambito
 * @var bool                                                $gestiona     Gestiona el evento
 * @var array[]                                             $filas        Participantes (todos los estados)
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array<int, array>                                   $cobros       inscripción => Cobros::de()
 * @var array<int, string>                                  $refs         inscripción => referencia de pago (Tesorería)
 * @var array<int, array>                                   $datos        inscripción => quién recoge y observaciones (equipo)
 * @var array<int, array[]>                                 $movs         inscripción => movimientos
 * @var array                                               $ocupadas     Inscripciones::ocupadas()
 * @var array[]                                             $espera       Lista de espera, por orden
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $sin_respuesta
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $convocados  Convocados que se pueden inscribir
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $otros       Del grupo, fuera de lo convocado (excepción)
 * @var array<int, array>                                   $familias     Familias con algo pendiente (Tesorería)
 * @var bool                                                $economia     Es Tesorería
 * @var bool                                                $abiertas     Las cuentas no están cerradas (v0.36)
 * @var bool                                                $gestionable  Puede inscribir y dar de baja ahora
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$campos = static function ( string $accion, string $extra = '' ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="inscripciones">'
		. $extra;
};
$fila_h = static fn( array $f ): string => '<input type="hidden" name="fila" value="' . (int) $f['id'] . '">';
$nombre = static fn( array $f ): string => isset( $personas[ $f['socio'] ] ) ? $personas[ $f['socio'] ]->nombre_completo() : $f['socio'];
$cuando = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y', (int) strtotime( $f . ' UTC' ) ) : '';
$hoy    = current_time( 'Y-m-d' );
$activas = array_values( array_filter( $filas, static fn( $f ) => in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) );
usort(
	$activas,
	static fn( $x, $y ) => array_search( $x['seccion'], $e['secciones'], true ) <=> array_search( $y['seccion'], $e['secciones'], true ) ?: strcoll( remove_accents( $nombre( $x ) ), remove_accents( $nombre( $y ) ) )
);
$fuera  = array_values( array_filter( $filas, static fn( $f ) => in_array( $f['estado'], array( InscripcionesTable::BAJA, InscripcionesTable::NO_PARTICIPA ), true ) ) );
$cuenta = array_count_values( array_column( $filas, 'estado' ) );
$plazo  = Inscripciones::plazo( $e );
$selector = static function ( string $name, array $lista, bool $vacio ) use ( $e ): string {
	$h = '<select name="' . esc_attr( $name ) . '">' . ( $vacio ? '<option value="">' . esc_html( asde_t( '— Elige —' ) ) . '</option>' : '' );
	foreach ( $lista as $num => $p ) {
		$s  = Inscripciones::seccion_de( $e, $p ) ?? $p->seccion_educativa() ?? ( $p->secciones[0] ?? '' );
		$h .= '<option value="' . esc_attr( (string) $num ) . '">' . esc_html( $p->nombre_completo() . ( '' !== $s ? ' · ' . Secciones::nombre( $s ) : '' ) ) . '</option>';
	}
	return $h . '</select>';
};
$formas = static function (): string {
	$h = '<select name="forma">';
	foreach ( array_intersect_key( asde_ta( MovimientosTable::FORMAS ), array_flip( MovimientosTable::FORMAS_MANUALES ) ) as $k => $t ) {
		$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $t ) . '</option>';
	}
	return $h . '</select>';
};
// v0.36: devolver a la familia lo pagado (hasta lo pagado y con motivo, P-160). Lo hace Tesorería.
$devolver = static function ( array $f, array $c ) use ( $campos, $fila_h, $formas, $hoy ): string {
	return '<details class="asde-accion"><summary>' . esc_html( asde_t( 'Devolver a la familia' ) ) . '</summary>'
		. '<form method="post" class="asde-form asde-form--compacta">'
		. $campos( 'evento_devolucion', $fila_h( $f ) )
		. '<label>' . esc_html( asde_t( 'Importe (€)' ) ) . ' <input type="text" name="importe" inputmode="decimal" value="' . esc_attr( number_format( $c['neto'], 2, ',', '' ) ) . '" required></label>'
		. '<label>' . esc_html( asde_t( 'Forma de pago' ) ) . ' ' . $formas() . '</label>'
		. '<label>' . esc_html( asde_t( 'Fecha' ) ) . ' <input type="date" name="fecha" value="' . esc_attr( $hoy ) . '" max="' . esc_attr( $hoy ) . '" required></label>'
		. '<label>' . esc_html( asde_t( 'Motivo' ) ) . ' <input type="text" name="motivo" maxlength="255" required></label>'
		. '<button type="submit" class="button">' . esc_html( asde_t( 'Anotar la devolución' ) ) . '</button>'
		. '</form></details>';
};
?>
<?php if ( EventosTable::BORRADOR === $e['estado'] ) : ?>
	<section class="asde-card">
		<p><?php asde_e( 'Cuando publiques el evento, las familias de las secciones convocadas podrán inscribirse desde «Mis eventos» y les llegará un email.' ); ?></p>
	</section>
	<?php return; ?>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Inscripción' ); ?></h3>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Modalidad' ); ?></dt><dd><?php echo esc_html( asde_t( EventosTable::MODALIDADES[ $e['modalidad'] ] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Plazo' ); ?></dt><dd>
			<?php
			if ( 'abierta' === $plazo ) {
				echo esc_html( sprintf( asde_t( 'Abierto hasta el %s' ), wp_date( 'd/m/Y H:i', Inscripciones::cierra( $e ) ) ) );
			} elseif ( 'antes' === $plazo ) {
				echo esc_html( sprintf( asde_t( 'Se abre el %s' ), wp_date( 'd/m/Y', Inscripciones::abre( $e ) ) ) );
			} else {
				asde_e( 'Cerrado: solo inscribe el equipo del evento' );
			}
			?>
		</dd></div>
		<div><dt><?php asde_e( 'Plazas' ); ?></dt><dd>
			<?php foreach ( $e['secciones'] as $s ) : ?>
				<?php $max = (int) ( $e['plazas'][ $s ]['maximas'] ?? 0 ); ?>
				<?php echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $max ? sprintf( asde_t( '%1$d de %2$d plazas ocupadas' ), $ocupadas['seccion'][ $s ] ?? 0, $max ) : sprintf( asde_t( '%d plazas ocupadas' ), $ocupadas['seccion'][ $s ] ?? 0 ) ); ?><br>
			<?php endforeach; ?>
			<?php if ( $e['plazas_max'] ) : ?>
				<?php echo esc_html( sprintf( asde_t( 'En total: %1$d de %2$d' ), $ocupadas['total'], $e['plazas_max'] ) ); ?>
			<?php endif; ?>
		</dd></div>
		<div><dt><?php asde_e( 'Estado' ); ?></dt><dd>
			<?php
			echo esc_html(
				sprintf(
					asde_t( '%1$d confirmadas, %2$d pendientes, %3$d en la lista de espera y %4$d bajas.' ),
					$cuenta[ InscripcionesTable::CONFIRMADA ] ?? 0,
					$cuenta[ InscripcionesTable::PENDIENTE ] ?? 0,
					$cuenta[ InscripcionesTable::ESPERA ] ?? 0,
					$cuenta[ InscripcionesTable::BAJA ] ?? 0
				)
			);
			?>
		</dd></div>
	</dl>
</section>

<section class="asde-card">
	<h3 class="asde-card__title"><?php echo esc_html( sprintf( asde_t( 'Participantes (%d)' ), count( $activas ) ) ); ?></h3>
	<?php if ( ! $activas ) : ?>
		<p><?php asde_e( 'Todavía no hay nadie inscrito.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inscripciones">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Nombre' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<?php if ( $e['con_coste'] ) : ?>
						<th scope="col"><?php asde_e( 'Pago' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php asde_e( 'Acciones' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $activas as $f ) : ?>
					<?php
					$c     = $cobros[ $f['id'] ];
					$falta = Inscripciones::falta( $f, $c );
					?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>">
							<strong><?php echo esc_html( $nombre( $f ) ); ?></strong> <?php echo Secciones::badge( $f['seccion'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( '' !== $f['motivo'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Excepción: %s' ), $f['motivo'] ) ); ?></span>
							<?php endif; ?>
							<br><span class="asde-muted">
								<?php
								$ida    = asde_t( InscripcionesTable::TRANSPORTES[ $f['transporte_ida'] ] ?? '—' );
								$vuelta = asde_t( InscripcionesTable::TRANSPORTES[ $f['transporte_vuelta'] ] ?? '—' );
								echo esc_html( sprintf( asde_t( 'Ida: %1$s · Vuelta: %2$s' ), $ida, $vuelta ) );
								?>
							</span>
							<?php if ( isset( $datos[ $f['id'] ] ) ) : ?>
								<details class="asde-inscripcion__datos">
									<summary><?php asde_e( 'Recogida y observaciones' ); ?></summary>
									<?php if ( '' !== $datos[ $f['id'] ]['recoge'] ) : ?>
										<p><strong><?php asde_e( 'Recogen:' ); ?></strong> <?php echo nl2br( esc_html( $datos[ $f['id'] ]['recoge'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
									<?php endif; ?>
									<?php if ( '' !== $datos[ $f['id'] ]['observaciones'] ) : ?>
										<p><strong><?php asde_e( 'Observaciones:' ); ?></strong> <?php echo nl2br( esc_html( $datos[ $f['id'] ]['observaciones'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
									<?php endif; ?>
								</details>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<span class="asde-badge asde-badge--inscripcion-<?php echo esc_attr( $f['estado'] ); ?>"><?php echo esc_html( asde_t( InscripcionesTable::ESTADOS[ $f['estado'] ] ) ); ?></span>
							<?php if ( $falta ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( in_array( 'autorizacion', $falta, true ) && in_array( 'pago', $falta, true ) ? asde_t( 'Falta la autorización y el pago' ) : ( in_array( 'pago', $falta, true ) ? asde_t( 'Falta el pago' ) : asde_t( 'Falta la autorización' ) ) ); ?></span>
							<?php endif; ?>
							<br><?php echo esc_html( asde_t( InscripcionesTable::AUTORIZACIONES[ $f['autorizacion'] ] ?? '' ) ); ?>
							<?php if ( in_array( $f['autorizacion'], array( 'web', 'papel' ), true ) ) : ?>
								<span class="asde-muted"><?php echo esc_html( $cuando( $f['autorizacion_en'] ) ); ?><?php echo 'web' === $f['autorizacion'] ? esc_html( ' · ' . $f['autorizacion_version'] ) : ''; ?></span>
							<?php elseif ( '' === $f['autorizacion'] && $gestionable ) : ?>
								<form method="post" class="asde-inline-form"><?php echo $campos( 'evento_papel', $fila_h( $f ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="asde-btn-link"><?php asde_e( 'Marcar la de papel como recibida' ); ?></button></form>
							<?php endif; ?>
						</td>
						<?php if ( $e['con_coste'] ) : ?>
							<td data-label="<?php echo esc_attr( asde_a( 'Pago' ) ); ?>">
								<?php echo esc_html( sprintf( asde_t( '%1$s de %2$s' ), Calculadora::euros( $c['neto'] ), Calculadora::euros( $c['total'] ) ) ); ?>
								<?php if ( $c['devuelto'] > 0 ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Devuelto: %s' ), Calculadora::euros( $c['devuelto'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( 0.0 !== $c['ajustes'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Precio %1$s, ajustes %2$s' ), Calculadora::euros( $c['precio'] ), Calculadora::euros( $c['ajustes'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $economia && isset( $refs[ $f['id'] ] ) ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Referencia: %s' ), $refs[ $f['id'] ] ) ); ?></span>
								<?php endif; ?>
								<?php if ( $economia && ! empty( $movs[ $f['id'] ] ) ) : ?>
									<ul class="asde-movimientos">
										<?php foreach ( $movs[ $f['id'] ] as $m ) : ?>
											<li class="<?php echo null !== $m['anulado_en'] ? 'asde-anulado' : ''; ?>">
												<?php
												if ( MovimientosTable::PAGO === $m['tipo'] ) {
													$texto = sprintf( asde_t( '%1$s · pago de %2$s (%3$s)' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), asde_t( MovimientosTable::FORMAS[ $m['forma'] ] ?? $m['forma'] ) );
												} elseif ( MovimientosTable::DEVOLUCION === $m['tipo'] ) {
													$texto = sprintf( asde_t( '%1$s · devolución de %2$s (%3$s): %4$s' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), asde_t( MovimientosTable::FORMAS[ $m['forma'] ] ?? $m['forma'] ), $m['nota'] );
												} else {
													$texto = sprintf( asde_t( '%1$s · ajuste de %2$s: %3$s' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), $m['nota'] );
												}
												echo esc_html( $texto );
												?>
												<?php if ( null !== $m['anulado_en'] ) : ?>
													<?php asde_e( '(anulado)' ); ?>
												<?php elseif ( $abiertas ) : ?>
													<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Anular este movimiento? Si es un pago de la familia, se anula entero.' ) ); ?>"><?php echo $campos( 'evento_anular', '<input type="hidden" name="movimiento" value="' . (int) $m['id'] . '">' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="asde-btn-link"><?php asde_e( 'Anular' ); ?></button></form>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
							<?php if ( $economia && $abiertas && $e['con_coste'] && $c['pendiente'] > 0 ) : ?>
								<details class="asde-accion">
									<summary><?php asde_e( 'Registrar un pago' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo $campos( 'evento_pago', $fila_h( $f ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" value="<?php echo esc_attr( number_format( $c['pendiente'], 2, ',', '' ) ); ?>" required></label>
										<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
										<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
										<label><?php asde_e( 'Referencia' ); ?> <input type="text" name="referencia" maxlength="120" value="<?php echo esc_attr( $refs[ $f['id'] ] ?? '' ); ?>"></label>
										<label><?php asde_e( 'Nota' ); ?> <input type="text" name="nota" maxlength="255"></label>
										<button type="submit" class="button button-primary"><?php asde_e( 'Registrar el pago' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
							<?php if ( $economia && $abiertas && $e['con_coste'] && $c['neto'] > 0 ) : ?>
								<?php echo $devolver( $f, $c ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( $economia && $abiertas && $e['con_coste'] ) : ?>
								<details class="asde-accion">
									<summary><?php asde_e( 'Beca o ajuste' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo $campos( 'evento_ajuste', $fila_h( $f ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<label class="asde-check"><input type="radio" name="sentido" value="rebaja" checked> <?php asde_e( 'Rebaja o beca' ); ?></label>
										<label class="asde-check"><input type="radio" name="sentido" value="recargo"> <?php asde_e( 'Recargo' ); ?></label>
										<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" required></label>
										<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
										<button type="submit" class="button"><?php asde_e( 'Guardar el ajuste' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
							<?php if ( $gestionable ) : ?>
								<details class="asde-accion">
									<summary><?php asde_e( 'Dar de baja' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo $campos( 'evento_baja', $fila_h( $f ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
										<button type="submit" class="button"><?php asde_e( 'Dar de baja' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $espera ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php echo esc_html( sprintf( asde_t( 'Lista de espera (%d)' ), count( $espera ) ) ); ?></h3>
		<ol class="asde-lista-espera">
			<?php foreach ( $espera as $f ) : ?>
				<li>
					<?php echo esc_html( $nombre( $f ) ); ?> <?php echo Secciones::badge( $f['seccion'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( Inscripciones::con_oferta( $f ) ) : ?>
						<span class="asde-badge asde-badge--inscripcion-plaza"><?php echo esc_html( sprintf( asde_t( 'Plaza ofrecida hasta el %s' ), wp_date( 'd/m/Y H:i', (int) strtotime( $f['oferta_hasta'] . ' UTC' ) ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( $gestionable ) : ?>
						<details class="asde-accion">
							<summary><?php asde_e( 'Sacar de la lista' ); ?></summary>
							<form method="post" class="asde-form asde-form--compacta">
								<?php echo $campos( 'evento_baja', $fila_h( $f ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
								<button type="submit" class="button"><?php asde_e( 'Sacar de la lista' ); ?></button>
							</form>
						</details>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Cuando se libera una plaza, se ofrece por orden: tienen %d días para confirmarla.' ), Inscripciones::dias_oferta() ) ); ?></p>
	</section>
<?php endif; ?>

<?php if ( $sin_respuesta ) : ?>
	<section class="asde-card">
		<details>
			<summary><strong><?php echo esc_html( sprintf( 'convocatoria' === $e['modalidad'] ? asde_t( 'Sin respuesta (%d)' ) : asde_t( 'Convocados sin inscribir (%d)' ), count( $sin_respuesta ) ) ); ?></strong></summary>
			<ul class="asde-lista-nombres">
				<?php foreach ( $sin_respuesta as $p ) : ?>
					<?php $s = Inscripciones::seccion_de( $e, $p ); ?>
					<li><?php echo esc_html( $p->nombre_completo() ); ?> <?php echo $s ? Secciones::badge( $s ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
	</section>
<?php endif; ?>

<?php if ( $fuera ) : ?>
	<section class="asde-card">
		<details>
			<summary><strong><?php echo esc_html( sprintf( asde_t( 'Bajas y «no va» (%d)' ), count( $fuera ) ) ); ?></strong></summary>
			<ul class="asde-lista-nombres">
				<?php foreach ( $fuera as $f ) : ?>
					<li>
						<?php echo esc_html( $nombre( $f ) ); ?> · <?php echo esc_html( asde_t( InscripcionesTable::ESTADOS[ $f['estado'] ] ) ); ?>
						<?php if ( InscripcionesTable::BAJA === $f['estado'] ) : ?>
							<span class="asde-muted"><?php echo esc_html( $cuando( $f['baja_en'] ) . ( '' !== $f['baja_motivo'] ? ' · ' . asde_t( $f['baja_motivo'] ) : '' ) ); ?></span>
						<?php endif; ?>
						<?php if ( $e['con_coste'] && ( $cobros[ $f['id'] ]['devuelto'] ?? 0 ) > 0 ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Devuelto: %s' ), Calculadora::euros( $cobros[ $f['id'] ]['devuelto'] ) ) ); ?></span>
						<?php endif; ?>
						<?php if ( $e['con_coste'] && ( $cobros[ $f['id'] ]['neto'] ?? 0 ) > 0 ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Había pagado %s: la devolución la decide Tesorería.' ), Calculadora::euros( $cobros[ $f['id'] ]['neto'] ) ) ); ?></span>
							<?php if ( $economia && $abiertas ) : ?>
								<?php echo $devolver( $f, $cobros[ $f['id'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	</section>
<?php endif; ?>

<?php if ( $gestionable && ( $convocados || $otros ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Inscribir a alguien' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'En nombre de su familia: si es menor, la autorización llega en papel y la marcas aquí cuando la tengas.' ); ?></p>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo $campos( 'evento_inscribir' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( $convocados ) : ?>
				<label><?php asde_e( 'De las secciones convocadas' ); ?> <?php echo $selector( 'socio', $convocados, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
			<?php endif; ?>
			<?php if ( $otros ) : ?>
				<details>
					<summary><?php asde_e( 'Otra persona del grupo (excepción, con motivo)' ); ?></summary>
					<label><?php asde_e( 'Persona' ); ?> <?php echo $selector( 'otro', $otros, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
					<label><?php asde_e( 'Motivo de la excepción' ); ?> <input type="text" name="motivo" maxlength="255"></label>
				</details>
			<?php endif; ?>
			<?php foreach ( array( 'transporte_ida' => asde_t( 'Ida' ), 'transporte_vuelta' => asde_t( 'Vuelta' ) ) as $k => $etiqueta ) : ?>
				<label><?php echo esc_html( $etiqueta ); ?>
					<select name="<?php echo esc_attr( $k ); ?>">
						<?php foreach ( asde_ta( InscripcionesTable::TRANSPORTES ) as $t => $txt ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $txt ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endforeach; ?>
			<button type="submit" class="button button-primary"><?php asde_e( 'Inscribir' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( $economia && $familias ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Pago de una familia' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Un solo pago para los hermanos: se reparte entre sus inscripciones con algo pendiente, por orden de inscripción.' ); ?></p>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo $campos( 'evento_pago_familia' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<label><?php asde_e( 'Familia' ); ?>
				<select name="familia">
					<?php foreach ( $familias as $id => $x ) : ?>
						<option value="<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( asde_t( '%1$s · %2$d inscripciones · pendiente %3$s' ), $x['familia']['numero'], count( $x['filas'] ), Calculadora::euros( $x['pendiente'] ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" required></label>
			<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
			<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
			<label><?php asde_e( 'Referencia' ); ?> <input type="text" name="referencia" maxlength="120"></label>
			<label><?php asde_e( 'Nota' ); ?> <input type="text" name="nota" maxlength="255"></label>
			<button type="submit" class="button button-primary"><?php asde_e( 'Registrar el pago' ); ?></button>
		</form>
	</section>
<?php endif; ?>
