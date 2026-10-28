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
	$h = '<select name="' . esc_attr( $name ) . '">' . ( $vacio ? '<option value="">' . esc_html( __( '— Choose —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</option>' : '' );
	foreach ( $lista as $num => $p ) {
		$s  = Inscripciones::seccion_de( $e, $p ) ?? $p->seccion_educativa() ?? ( $p->secciones[0] ?? '' );
		$h .= '<option value="' . esc_attr( (string) $num ) . '">' . esc_html( $p->nombre_completo() . ( '' !== $s ? ' · ' . Secciones::nombre( $s ) : '' ) ) . '</option>';
	}
	return $h . '</select>';
};
$formas = static function (): string {
	$h = '<select name="forma">';
	foreach ( array_intersect_key( ( MovimientosTable::formas() ), array_flip( MovimientosTable::FORMAS_MANUALES ) ) as $k => $t ) {
		$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $t ) . '</option>';
	}
	return $h . '</select>';
};
// v0.36: devolver a la familia lo pagado (hasta lo pagado y con motivo, P-160). Lo hace Tesorería.
$devolver = static function ( array $f, array $c ) use ( $campos, $fila_h, $formas, $hoy ): string {
	return '<details class="asde-accion"><summary>' . esc_html( __( 'Refund to the family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</summary>'
		. '<form method="post" class="asde-form asde-form--compacta">'
		. $campos( 'evento_devolucion', $fila_h( $f ) )
		. '<label>' . esc_html( __( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' <input type="text" name="importe" inputmode="decimal" value="' . esc_attr( number_format( $c['neto'], 2, ',', '' ) ) . '" required></label>'
		. '<label>' . esc_html( __( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' ' . $formas() . '</label>'
		. '<label>' . esc_html( __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' <input type="date" name="fecha" value="' . esc_attr( $hoy ) . '" max="' . esc_attr( $hoy ) . '" required></label>'
		. '<label>' . esc_html( __( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' <input type="text" name="motivo" maxlength="255" required></label>'
		. '<button type="submit" class="button">' . esc_html( __( 'Record the refund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</button>'
		. '</form></details>';
};
?>
<?php if ( EventosTable::BORRADOR === $e['estado'] ) : ?>
	<section class="asde-card">
		<p><?php esc_html_e( 'When you publish the event, the families of the invited sections will be able to sign up from "My events" and will receive an email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>
	<?php return; ?>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'Mode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( ( EventosTable::modalidades()[ $e['modalidad'] ] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Deadline', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php
			if ( 'abierta' === $plazo ) {
				echo esc_html( sprintf( /* translators: %s: text */ __( 'Open until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y H:i', Inscripciones::cierra( $e ) ) ) );
			} elseif ( 'antes' === $plazo ) {
				echo esc_html( sprintf( /* translators: %s: text */ __( 'Opens on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', Inscripciones::abre( $e ) ) ) );
			} else {
				esc_html_e( 'Closed: only the event team signs people up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			?>
		</dd></div>
		<div><dt><?php esc_html_e( 'Places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php foreach ( $e['secciones'] as $s ) : ?>
				<?php $max = (int) ( $e['plazas'][ $s ]['maximas'] ?? 0 ); ?>
				<?php echo wp_kses( Secciones::badge( $s ), asde_html_permitido() ); ?> <?php echo esc_html( $max ? sprintf( /* translators: 1: number, 2: number */ __( '%1$d of %2$d places taken', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ocupadas['seccion'][ $s ] ?? 0, $max ) : sprintf( /* translators: %d: number */ __( '%d places taken', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ocupadas['seccion'][ $s ] ?? 0 ) ); ?><br>
			<?php endforeach; ?>
			<?php if ( $e['plazas_max'] ) : ?>
				<?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'In total: %1$d of %2$d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ocupadas['total'], $e['plazas_max'] ) ); ?>
			<?php endif; ?>
		</dd></div>
		<div><dt><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number, 2: number, 3: number, 4: number */ __( '%1$d confirmed, %2$d pending, %3$d on the waiting list and %4$d withdrawals.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
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
	<h3 class="asde-card__title"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Participants (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $activas ) ) ); ?></h3>
	<?php if ( ! $activas ) : ?>
		<p><?php esc_html_e( 'Nobody has signed up yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inscripciones">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<?php if ( $e['con_coste'] ) : ?>
						<th scope="col"><?php esc_html_e( 'Payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $activas as $f ) : ?>
					<?php
					$c     = $cobros[ $f['id'] ];
					$falta = Inscripciones::falta( $f, $c );
					?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<strong><?php echo esc_html( $nombre( $f ) ); ?></strong> <?php echo wp_kses( Secciones::badge( $f['seccion'] ), asde_html_permitido() ); ?>
							<?php if ( '' !== $f['motivo'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Exception: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['motivo'] ) ); ?></span>
							<?php endif; ?>
							<br><span class="asde-muted">
								<?php
								$ida    = ( InscripcionesTable::transportes()[ $f['transporte_ida'] ] ?? '—' );
								$vuelta = ( InscripcionesTable::transportes()[ $f['transporte_vuelta'] ] ?? '—' );
								echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Outward: %1$s · Return: %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ida, $vuelta ) );
								?>
							</span>
							<?php if ( isset( $datos[ $f['id'] ] ) ) : ?>
								<details class="asde-inscripcion__datos">
									<summary><?php esc_html_e( 'Pick-up and notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<?php if ( '' !== $datos[ $f['id'] ]['recoge'] ) : ?>
										<p><strong><?php esc_html_e( 'Picked up by:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo wp_kses( nl2br( esc_html( $datos[ $f['id'] ]['recoge'] ) ), asde_html_permitido() ); ?></p>
									<?php endif; ?>
									<?php if ( '' !== $datos[ $f['id'] ]['observaciones'] ) : ?>
										<p><strong><?php esc_html_e( 'Notes:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo wp_kses( nl2br( esc_html( $datos[ $f['id'] ]['observaciones'] ) ), asde_html_permitido() ); ?></p>
									<?php endif; ?>
								</details>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-badge asde-badge--inscripcion-<?php echo esc_attr( $f['estado'] ); ?>"><?php echo esc_html( ( InscripcionesTable::estados()[ $f['estado'] ] ) ); ?></span>
							<?php if ( $falta ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( in_array( 'autorizacion', $falta, true ) && in_array( 'pago', $falta, true ) ? __( 'Authorisation and payment missing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : ( in_array( 'pago', $falta, true ) ? __( 'Payment missing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Authorisation missing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?></span>
							<?php endif; ?>
							<br><?php echo esc_html( ( InscripcionesTable::autorizaciones()[ $f['autorizacion'] ] ?? '' ) ); ?>
							<?php if ( in_array( $f['autorizacion'], array( 'web', 'papel' ), true ) ) : ?>
								<span class="asde-muted"><?php echo esc_html( $cuando( $f['autorizacion_en'] ) ); ?><?php echo 'web' === $f['autorizacion'] ? esc_html( ' · ' . $f['autorizacion_version'] ) : ''; ?></span>
							<?php elseif ( '' === $f['autorizacion'] && $gestionable ) : ?>
								<form method="post" class="asde-inline-form"><?php echo wp_kses( $campos( 'evento_papel', $fila_h( $f ) ), asde_html_permitido() ); ?><button type="submit" class="asde-btn-link"><?php esc_html_e( 'Mark the paper one as received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
							<?php endif; ?>
						</td>
						<?php if ( $e['con_coste'] ) : ?>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( '%1$s of %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $c['neto'] ), Calculadora::euros( $c['total'] ) ) ); ?>
								<?php if ( $c['devuelto'] > 0 ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Refunded: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $c['devuelto'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( 0.0 !== $c['ajustes'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Price %1$s, adjustments %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $c['precio'] ), Calculadora::euros( $c['ajustes'] ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( $economia && isset( $refs[ $f['id'] ] ) ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Reference: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $refs[ $f['id'] ] ) ); ?></span>
								<?php endif; ?>
								<?php if ( $economia && ! empty( $movs[ $f['id'] ] ) ) : ?>
									<ul class="asde-movimientos">
										<?php foreach ( $movs[ $f['id'] ] as $m ) : ?>
											<li class="<?php echo null !== $m['anulado_en'] ? 'asde-anulado' : ''; ?>">
												<?php
												if ( MovimientosTable::PAGO === $m['tipo'] ) {
													$texto = sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s · payment of %2$s (%3$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), ( MovimientosTable::formas()[ $m['forma'] ] ?? $m['forma'] ) );
												} elseif ( MovimientosTable::DEVOLUCION === $m['tipo'] ) {
													$texto = sprintf( /* translators: 1: text, 2: text, 3: text, 4: text */ __( '%1$s · refund of %2$s (%3$s): %4$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), ( MovimientosTable::formas()[ $m['forma'] ] ?? $m['forma'] ), $m['nota'] );
												} else {
													$texto = sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s · adjustment of %2$s: %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', (int) strtotime( $m['fecha'] ) ), Calculadora::euros( $m['importe'] ), $m['nota'] );
												}
												echo esc_html( $texto );
												?>
												<?php if ( null !== $m['anulado_en'] ) : ?>
													<?php esc_html_e( '(cancelled)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
												<?php elseif ( $abiertas ) : ?>
													<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( __( 'Cancel this transaction? If it is a payment from the family, it is cancelled in full.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $campos( 'evento_anular', '<input type="hidden" name="movimiento" value="' . (int) $m['id'] . '">' ), asde_html_permitido() ); ?><button type="submit" class="asde-btn-link"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $economia && $abiertas && $e['con_coste'] && $c['pendiente'] > 0 ) : ?>
								<details class="asde-accion">
									<summary><?php esc_html_e( 'Record a payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo wp_kses( $campos( 'evento_pago', $fila_h( $f ) ), asde_html_permitido() ); ?>
										<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" value="<?php echo esc_attr( number_format( $c['pendiente'], 2, ',', '' ) ); ?>" required></label>
										<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
										<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
										<label><?php esc_html_e( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia" maxlength="120" value="<?php echo esc_attr( $refs[ $f['id'] ] ?? '' ); ?>"></label>
										<label><?php esc_html_e( 'Note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" maxlength="255"></label>
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Record the payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
							<?php if ( $economia && $abiertas && $e['con_coste'] && $c['neto'] > 0 ) : ?>
								<?php echo wp_kses( $devolver( $f, $c ), asde_html_permitido() ); ?>
							<?php endif; ?>
							<?php if ( $economia && $abiertas && $e['con_coste'] ) : ?>
								<details class="asde-accion">
									<summary><?php esc_html_e( 'Scholarship or adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo wp_kses( $campos( 'evento_ajuste', $fila_h( $f ) ), asde_html_permitido() ); ?>
										<label><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <select name="sentido"><option value="rebaja" selected><?php esc_html_e( 'Discount or scholarship', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option><option value="recargo"><?php esc_html_e( 'Surcharge', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option></select></label>
										<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" required></label>
										<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
										<button type="submit" class="button"><?php esc_html_e( 'Save the adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								</details>
							<?php endif; ?>
							<?php if ( $gestionable ) : ?>
								<details class="asde-accion">
									<summary><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo wp_kses( $campos( 'evento_baja', $fila_h( $f ) ), asde_html_permitido() ); ?>
										<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
										<button type="submit" class="button"><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
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
		<h3 class="asde-card__title"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Waiting list (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $espera ) ) ); ?></h3>
		<ol class="asde-lista-espera">
			<?php foreach ( $espera as $f ) : ?>
				<li>
					<?php echo esc_html( $nombre( $f ) ); ?> <?php echo wp_kses( Secciones::badge( $f['seccion'] ), asde_html_permitido() ); ?>
					<?php if ( Inscripciones::con_oferta( $f ) ) : ?>
						<span class="asde-badge asde-badge--inscripcion-plaza"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Place offered until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y H:i', (int) strtotime( $f['oferta_hasta'] . ' UTC' ) ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( $gestionable ) : ?>
						<details class="asde-accion">
							<summary><?php esc_html_e( 'Take off the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
							<form method="post" class="asde-form asde-form--compacta">
								<?php echo wp_kses( $campos( 'evento_baja', $fila_h( $f ) ), asde_html_permitido() ); ?>
								<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
								<button type="submit" class="button"><?php esc_html_e( 'Take off the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
							</form>
						</details>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'When a place is freed up, it is offered in order: they have %d days to confirm it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::dias_oferta() ) ); ?></p>
	</section>
<?php endif; ?>

<?php if ( $sin_respuesta ) : ?>
	<section class="asde-card">
		<details>
			<summary><strong><?php echo esc_html( sprintf( 'convocatoria' === $e['modalidad'] ? /* translators: %d: number */ __( 'No answer (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : /* translators: %d: number */ __( 'Invited but not signed up (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $sin_respuesta ) ) ); ?></strong></summary>
			<ul class="asde-lista-nombres">
				<?php foreach ( $sin_respuesta as $p ) : ?>
					<?php $s = Inscripciones::seccion_de( $e, $p ); ?>
					<li><?php echo esc_html( $p->nombre_completo() ); ?> <?php echo wp_kses( $s ? Secciones::badge( $s ) : '', asde_html_permitido() ); ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
	</section>
<?php endif; ?>

<?php if ( $fuera ) : ?>
	<section class="asde-card">
		<details>
			<summary><strong><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Withdrawals and "not going" (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $fuera ) ) ); ?></strong></summary>
			<ul class="asde-lista-nombres">
				<?php foreach ( $fuera as $f ) : ?>
					<li>
						<?php echo esc_html( $nombre( $f ) ); ?> · <?php echo esc_html( ( InscripcionesTable::estados()[ $f['estado'] ] ) ); ?>
						<?php if ( InscripcionesTable::BAJA === $f['estado'] ) : ?>
							<span class="asde-muted"><?php echo esc_html( $cuando( $f['baja_en'] ) . ( '' !== $f['baja_motivo'] ? ' · ' . asde_guardado( $f['baja_motivo'] ) : '' ) ); ?></span>
						<?php endif; ?>
						<?php if ( $e['con_coste'] && ( $cobros[ $f['id'] ]['devuelto'] ?? 0 ) > 0 ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Refunded: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $cobros[ $f['id'] ]['devuelto'] ) ) ); ?></span>
						<?php endif; ?>
						<?php if ( $e['con_coste'] && ( $cobros[ $f['id'] ]['neto'] ?? 0 ) > 0 ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'They had paid %s: Treasury decides on the refund.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $cobros[ $f['id'] ]['neto'] ) ) ); ?></span>
							<?php if ( $economia && $abiertas ) : ?>
								<?php echo wp_kses( $devolver( $f, $cobros[ $f['id'] ] ), asde_html_permitido() ); ?>
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
		<h3 class="asde-card__title"><?php esc_html_e( 'Sign someone up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'On behalf of their family: if they are a minor, the authorisation arrives on paper and you mark it here when you have it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_inscribir' ), asde_html_permitido() ); ?>
			<?php if ( $convocados ) : ?>
				<label><?php esc_html_e( 'Of the invited sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $selector( 'socio', $convocados, true ), asde_html_permitido() ); ?></label>
			<?php endif; ?>
			<?php if ( $otros ) : ?>
				<details>
					<summary><?php esc_html_e( 'Another person from the group (exception, with a reason)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<label><?php esc_html_e( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $selector( 'otro', $otros, true ), asde_html_permitido() ); ?></label>
					<label><?php esc_html_e( 'Reason for the exception', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255"></label>
				</details>
			<?php endif; ?>
			<?php foreach ( array( 'transporte_ida' => __( 'Outward', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'transporte_vuelta' => __( 'Return', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) as $k => $etiqueta ) : ?>
				<label><?php echo esc_html( $etiqueta ); ?>
					<select name="<?php echo esc_attr( $k ); ?>">
						<?php foreach ( ( InscripcionesTable::transportes() ) as $t => $txt ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $txt ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endforeach; ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Sign up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( $economia && $familias ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Payment from a family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'A single payment for siblings: it is split among their sign-ups with something pending, in order of sign-up.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_pago_familia' ), asde_html_permitido() ); ?>
			<label><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<select name="familia">
					<?php foreach ( $familias as $id => $x ) : ?>
						<option value="<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( /* translators: 1: text, 2: number, 3: text */ __( '%1$s · %2$d sign-ups · pending %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $x['familia']['numero'], count( $x['filas'] ), Calculadora::euros( $x['pendiente'] ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" required></label>
			<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
			<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
			<label><?php esc_html_e( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia" maxlength="120"></label>
			<label><?php esc_html_e( 'Note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" maxlength="255"></label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Record the payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	</section>
<?php endif; ?>
