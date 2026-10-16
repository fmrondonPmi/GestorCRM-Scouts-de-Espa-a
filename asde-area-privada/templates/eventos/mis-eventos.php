<?php
/**
 * Mis eventos: los eventos de la persona y de los menores que representa.
 *
 * @var array[]                             $lista        [ evento, personas => [ nº => [ persona, fila|null, convocada ] ] ]
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var array                               $cfg          Configuración de eventos
 * @var array<int, array>                   $cobros       inscripción => Cobros::de()
 * @var array<int, string>                  $refs         inscripción => referencia de pago
 * @var array<int, int>                     $puestos      inscripción => puesto en la lista de espera
 * @var string                              $autorizacion Texto de la autorización
 * @var array|null                          $valores      Lo escrito en un formulario que no se pudo guardar
 * @var string                              $url
 * @var callable                            $url_ficha    fn( nº ) → su ficha
 * @var string                              $url_pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( int $t ): string => wp_date( 'd/m/Y', $t );
$utc    = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$campos = static function ( array $e, string $accion, string $extra = '' ): string {
	return wp_nonce_field( 'asde_mis_eventos_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. $extra;
};
$boton  = static function ( array $e, string $accion, string $texto, string $extra, string $clase = 'button', string $confirmar = '' ) use ( $campos ): string {
	return '<form method="post" class="asde-inline-form"' . ( '' !== $confirmar ? ' data-asde-confirmar="' . esc_attr( $confirmar ) . '"' : '' ) . '>'
		. $campos( $e, $accion, $extra )
		. '<button type="submit" class="' . esc_attr( $clase ) . '">' . esc_html( $texto ) . '</button></form>';
};
$oculto = static fn( string $k, string $v ): string => '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
/** El texto de la autorización, con a quién y a qué evento se autoriza. */
$texto_autorizacion = static function ( array $e, string $nombre ) use ( $autorizacion ): string {
	return '<div class="asde-autorizacion">'
		. '<p><strong>' . esc_html( sprintf( asde_t( 'Autorización para que %1$s participe en «%2$s» (%3$s).' ), $nombre, $e['nombre'], EventosTable::cuando( $e ) ) ) . '</strong></p>'
		. wp_kses_post( wpautop( esc_html( $autorizacion ) ) )
		. '</div>';
};
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Mis eventos' ); ?></h2>
	<p><?php echo wp_kses_post( $ambito->representados ? asde_h( 'Las salidas, acampadas y campamentos del grupo a los que estáis convocados. Inscribe desde aquí: al inscribir a un menor firmas su <strong>autorización de participación</strong>.' ) : asde_h( 'Las salidas, acampadas y campamentos del grupo a los que estás convocado/a. Inscríbete desde aquí.' ) ); ?></p>
	<p class="asde-muted"><?php asde_e( 'Lo que hay que pagar y lo pagado de cada evento lo ves también en' ); ?> <a href="<?php echo esc_url( $url_pagos ); ?>"><?php asde_e( 'Mis pagos' ); ?></a>.</p>
</section>

<?php if ( ! $lista ) : ?>
	<section class="asde-card">
		<p><?php asde_e( 'Ahora mismo no hay ningún evento abierto para ti ni para tu familia. Cuando el grupo publique uno, te llegará un email.' ); ?></p>
	</section>
<?php endif; ?>

<?php foreach ( $lista as $item ) : ?>
	<?php
	$e         = $item['evento'];
	$plazo     = Inscripciones::plazo( $e );
	$cancelado = EventosTable::CANCELADO === $e['estado'];
	if ( $cancelado ) {
		$badge = array( 'cancelado', asde_t( 'Cancelado' ) );
	} elseif ( 'abierta' === $plazo ) {
		$badge = array( 'publicado', sprintf( asde_t( 'Inscripción abierta hasta el %s' ), $fecha( Inscripciones::cierra( $e ) ) ) );
	} elseif ( 'antes' === $plazo ) {
		$badge = array( 'validado', sprintf( asde_t( 'La inscripción se abre el %s' ), $fecha( Inscripciones::abre( $e ) ) ) );
	} else {
		$badge = array( 'borrador', asde_t( 'Inscripción cerrada' ) );
	}
	?>
	<section class="asde-card asde-mis-evento" id="evento-<?php echo (int) $e['id']; ?>">
		<header class="asde-card__header asde-card__header--split">
			<h3 class="asde-card__title"><?php echo esc_html( $e['nombre'] ); ?></h3>
			<span class="asde-badge asde-badge--evento-<?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
		</header>
		<p>
			<strong><?php echo esc_html( EventosTable::cuando( $e ) ); ?></strong>
			<?php if ( '' !== $e['lugar'] ) : ?>
				· <?php echo esc_html( $e['lugar'] ); ?>
			<?php endif; ?>
			<?php if ( '' !== $e['mapa'] ) : ?>
				· <a href="<?php echo esc_url( $e['mapa'] ); ?>" target="_blank" rel="noopener"><?php asde_e( 'Ver en el mapa' ); ?></a>
			<?php endif; ?>
			<br><span class="asde-muted"><?php echo esc_html( EventosTable::numero( $e ) . ' · ' . Config::nombre_tipo( $cfg, $e['tipo'] ) ); ?></span>
		</p>
		<?php if ( $cancelado ) : ?>
			<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( asde_t( 'Cancelado. Motivo: %s' ), $e['cancelado_motivo'] ) ); ?> <?php asde_e( 'Si ya habías pagado, Tesorería se pondrá en contacto contigo para la devolución.' ); ?></div>
		<?php endif; ?>
		<?php if ( ! $cancelado && ( '' !== $e['salida'] || '' !== $e['llegada'] || '' !== trim( $e['descripcion'] ) || '' !== trim( $e['material'] ) ) ) : ?>
			<details class="asde-mis-evento__info">
				<summary><?php asde_e( 'Información práctica' ); ?></summary>
				<dl class="asde-datos">
					<?php if ( '' !== $e['salida'] ) : ?>
						<div><dt><?php asde_e( 'Salida' ); ?></dt><dd><?php echo esc_html( $e['salida'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== $e['llegada'] ) : ?>
						<div><dt><?php asde_e( 'Llegada' ); ?></dt><dd><?php echo esc_html( $e['llegada'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== trim( $e['descripcion'] ) ) : ?>
						<div><dt><?php asde_e( 'Descripción' ); ?></dt><dd><?php echo nl2br( esc_html( $e['descripcion'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
					<?php endif; ?>
					<?php if ( '' !== trim( $e['material'] ) ) : ?>
						<div><dt><?php asde_e( 'Material personal' ); ?></dt><dd><?php echo nl2br( esc_html( $e['material'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
					<?php endif; ?>
				</dl>
			</details>
		<?php endif; ?>

		<ul class="asde-mis-evento__personas">
			<?php foreach ( $item['personas'] as $num => $x ) : ?>
				<?php
				$p        = $x['persona'];
				$f        = $x['fila'];
				$familia  = isset( $ambito->representados[ $num ] );
				$adulta   = Inscripciones::adulta_en( $e, $p );
				$estado   = $f ? Inscripciones::estado( $f ) : ( 'convocatoria' === $e['modalidad'] && 'abierta' === $plazo ? 'sin_respuesta' : '' );
				$seccion  = Inscripciones::seccion_de( $e, $p );
				$precio   = $f ? $f['precio'] : Inscripciones::precio( $e, (string) $seccion );
				$cobro    = $f ? ( $cobros[ $f['id'] ] ?? null ) : null;
				$activa   = $f && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true );
				$puede    = ! $cancelado && 'abierta' === $plazo && $x['convocada'] && ( ! $f || ! $activa ) && Inscripciones::puede_inscribirse_sola( $ambito, $e, $p );
				$clave    = $e['id'] . '|' . $num;
				$v        = $valores && $clave === $valores['clave'] ? $valores['datos'] : array();
				$extra    = $oculto( 'socio', (string) $num );
				?>
				<li class="asde-mis-evento__persona">
					<p class="asde-mis-evento__quien">
						<strong><?php echo esc_html( $familia ? $p->nombre_completo() : sprintf( asde_t( '%s (tú)' ), $p->nombre_completo() ) ); ?></strong>
						<?php echo $seccion ? Secciones::badge( $seccion ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php if ( '' !== $estado ) : ?>
							<span class="asde-badge asde-badge--inscripcion-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( InscripcionesTable::ESTADOS[ $estado ] ?? $estado ) ); ?></span>
						<?php endif; ?>
					</p>

					<?php if ( $e['con_coste'] && ! $cancelado && ( $activa || $puede ) ) : ?>
						<p class="asde-muted">
							<?php echo esc_html( sprintf( asde_t( 'Precio: %s' ), Calculadora::euros( $cobro ? $cobro['total'] : $precio ) ) ); ?>
							<?php if ( $cobro && $cobro['pagado'] > 0 ) : ?>
								· <?php echo esc_html( sprintf( asde_t( 'pagado: %s' ), Calculadora::euros( $cobro['pagado'] ) ) ); ?>
							<?php endif; ?>
							<?php if ( $cobro && $cobro['devuelto'] > 0 ) : ?>
								· <?php echo esc_html( sprintf( asde_t( 'devuelto: %s' ), Calculadora::euros( $cobro['devuelto'] ) ) ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( $f && 'plaza' === $estado ) : ?>
						<div class="asde-notice asde-notice--info">
							<?php echo esc_html( sprintf( asde_t( 'Se ha liberado una plaza. Confírmala antes del %s; si no, pasará a la siguiente persona de la lista de espera.' ), $utc( $f['oferta_hasta'] ) ) ); ?>
							<?php echo $boton( $e, 'inscripcion_plaza', esc_html( asde_t( 'Confirmar la plaza' ) ), $oculto( 'fila', (string) $f['id'] ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
					<?php elseif ( $f && InscripcionesTable::ESPERA === $f['estado'] ) : ?>
						<p><?php echo esc_html( sprintf( asde_t( 'No quedaban plazas: está en la lista de espera (puesto %d). Si se libera una, os avisaremos por email.' ), (int) ( $puestos[ $f['id'] ] ?? 0 ) ) ); ?></p>
					<?php elseif ( $f && InscripcionesTable::CONFIRMADA === $f['estado'] ) : ?>
						<p><?php asde_e( 'Todo listo: la inscripción está confirmada.' ); ?></p>
					<?php elseif ( $f && InscripcionesTable::PENDIENTE === $f['estado'] && ! $cancelado ) : ?>
						<p><?php asde_e( 'Para confirmar la inscripción falta:' ); ?></p>
						<ul class="asde-falta">
							<?php if ( '' === $f['autorizacion'] ) : ?>
								<li>
									<?php if ( $familia ) : ?>
										<?php asde_e( 'firmar la autorización de participación (o entregarla en papel al equipo).' ); ?>
									<?php else : ?>
										<?php asde_e( 'que tu familia firme la autorización de participación desde su cuenta (o la entregue en papel al equipo).' ); ?>
									<?php endif; ?>
								</li>
							<?php endif; ?>
							<?php if ( $cobro && $cobro['pendiente'] > 0 ) : ?>
								<li><?php echo wp_kses_post( sprintf( asde_h( 'pagar <strong>%1$s</strong>, con la referencia <strong>%2$s</strong> si es por transferencia o Bizum.' ), esc_html( Calculadora::euros( $cobro['pendiente'] ) ), esc_html( $refs[ $f['id'] ] ?? '' ) ) ); ?></li>
							<?php endif; ?>
						</ul>
						<?php if ( $cobro && $cobro['pendiente'] > 0 && '' !== trim( $cfg['texto_pago'] ) ) : ?>
							<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
						<?php endif; ?>
					<?php elseif ( $f && InscripcionesTable::NO_PARTICIPA === $f['estado'] ) : ?>
						<p><?php asde_e( 'Habéis respondido que no va. Si cambia de idea, todavía puede inscribirse.' ); ?></p>
					<?php elseif ( $f && InscripcionesTable::BAJA === $f['estado'] && ! $cancelado ) : ?>
						<p><?php asde_e( 'Está dado/a de baja de este evento.' ); ?></p>
					<?php elseif ( ! $f && ! $cancelado ) : ?>
						<?php if ( 'antes' === $plazo ) : ?>
							<p><?php echo esc_html( sprintf( asde_t( 'La inscripción se abre el %s.' ), $fecha( Inscripciones::abre( $e ) ) ) ); ?></p>
						<?php elseif ( 'cerrada' === $plazo ) : ?>
							<p><?php asde_e( 'La inscripción está cerrada. Si queréis apuntaros todavía, hablad con el equipo de la sección.' ); ?></p>
						<?php elseif ( ! Inscripciones::puede_inscribirse_sola( $ambito, $e, $p ) ) : ?>
							<p><?php asde_e( 'Te tiene que inscribir tu familia desde su cuenta.' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $f && $familia && '' === $f['autorizacion'] && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::ESPERA ), true ) && ! $cancelado ) : ?>
						<details class="asde-inscribir">
							<summary class="button button-primary"><?php asde_e( 'Firmar la autorización' ); ?></summary>
							<form method="post" class="asde-form asde-form--inscribir">
								<?php echo $campos( $e, 'inscripcion_firmar', $oculto( 'fila', (string) $f['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php echo $texto_autorizacion( $e, $p->nombre_completo() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<label class="asde-check"><input type="checkbox" name="acepto" value="1" required> <?php asde_e( 'He leído la autorización y la firmo.' ); ?></label>
								<p class="asde-help"><?php asde_e( 'Queda registrado quién la firma, cuándo y qué versión del texto.' ); ?></p>
								<p><button type="submit" class="button button-primary"><?php asde_e( 'Firmar' ); ?></button></p>
							</form>
						</details>
					<?php endif; ?>

					<?php if ( $puede ) : ?>
						<details class="asde-inscribir"<?php echo $v ? ' open' : ''; ?>>
							<summary class="button button-primary"><?php echo esc_html( $familia ? sprintf( asde_t( 'Inscribir a %s' ), $p->nombre ) : ( $adulta ? asde_t( 'Inscribirme' ) : asde_t( 'Preinscribirme' ) ) ); ?></summary>
							<form method="post" class="asde-form asde-form--inscribir">
								<?php echo $campos( $e, 'inscripcion_crear', $extra ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<fieldset class="asde-fieldset">
									<legend><?php asde_e( 'Transporte' ); ?></legend>
									<?php foreach ( array( 'transporte_ida' => asde_t( 'Ida' ), 'transporte_vuelta' => asde_t( 'Vuelta' ) ) as $k => $etiqueta ) : ?>
										<label><?php echo esc_html( $etiqueta ); ?>
											<select name="<?php echo esc_attr( $k ); ?>">
												<?php foreach ( asde_ta( InscripcionesTable::TRANSPORTES ) as $t => $txt ) : ?>
													<option value="<?php echo esc_attr( $t ); ?>"<?php selected( (string) ( $v[ $k ] ?? 'grupo' ), $t ); ?>><?php echo esc_html( $txt ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<?php if ( ! $adulta ) : ?>
									<label><?php asde_e( 'Personas autorizadas a recogerle (nombre y DNI)' ); ?>
										<textarea name="recoge" rows="2" maxlength="1000"><?php echo esc_textarea( (string) ( $v['recoge'] ?? '' ) ); ?></textarea>
									</label>
								<?php endif; ?>
								<label><?php asde_e( 'Observaciones para el equipo' ); ?>
									<textarea name="observaciones" rows="2" maxlength="1000"><?php echo esc_textarea( (string) ( $v['observaciones'] ?? '' ) ); ?></textarea>
								</label>
								<p class="asde-help"><?php asde_e( 'Esto solo lo ve el equipo del evento y se borra al cerrarlo. La salud y la alimentación no se escriben aquí: van en la ficha.' ); ?></p>
								<label class="asde-check"><input type="checkbox" name="salud" value="1" required<?php checked( ! empty( $v['salud'] ) ); ?>> <?php echo esc_html( $familia ? sprintf( asde_t( 'He revisado la ficha de salud y alimentación de %s y está al día.' ), $p->nombre ) : asde_t( 'He revisado mi ficha de salud y alimentación y está al día.' ) ); ?></label>
								<p class="asde-help"><a href="<?php echo esc_url( $url_ficha( (string) $num ) ); ?>"><?php asde_e( 'Ver la ficha y proponer cambios' ); ?></a></p>
								<?php if ( $familia && ! $adulta ) : ?>
									<?php echo $texto_autorizacion( $e, $p->nombre_completo() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<label class="asde-check"><input type="checkbox" name="acepto" value="1" required> <?php asde_e( 'He leído la autorización y la firmo.' ); ?></label>
									<p class="asde-help"><?php asde_e( 'Al inscribir firmas la autorización: queda registrado quién la firma, cuándo y qué versión del texto.' ); ?></p>
								<?php elseif ( ! $adulta ) : ?>
									<p class="asde-help"><?php asde_e( 'Es una preinscripción: tu familia tendrá que firmar la autorización desde su cuenta (o entregarla en papel).' ); ?></p>
								<?php endif; ?>
								<p><button type="submit" class="button button-primary"><?php echo esc_html( $familia ? asde_t( 'Inscribir' ) : ( $adulta ? asde_t( 'Inscribirme' ) : asde_t( 'Preinscribirme' ) ) ); ?></button></p>
							</form>
						</details>
						<?php if ( 'convocatoria' === $e['modalidad'] && ( ! $f || InscripcionesTable::NO_PARTICIPA !== $f['estado'] ) ) : ?>
							<?php echo $boton( $e, 'inscripcion_no', esc_html( $familia ? sprintf( asde_t( '%s no va' ), $p->nombre ) : asde_t( 'No voy' ) ), $extra, 'asde-btn-link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $activa && 'abierta' === $plazo && ! $cancelado ) : ?>
						<p class="asde-mis-evento__baja">
							<?php echo $boton( $e, 'inscripcion_baja', esc_html( InscripcionesTable::ESPERA === $f['estado'] ? asde_t( 'Salir de la lista de espera' ) : asde_t( 'Darse de baja' ) ), $oculto( 'fila', (string) $f['id'] ), 'asde-btn-link', esc_attr( asde_js( '¿Dar de baja esta inscripción? Lo que ya se haya pagado lo revisará Tesorería.' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endforeach; ?>
