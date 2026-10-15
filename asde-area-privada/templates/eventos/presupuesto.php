<?php
/**
 * Evento · presupuesto: gastos e ingresos previstos, el precio que sale para cada
 * sección (coste repartido + fondo de emergencia) y la validación de Tesorería.
 *
 * @var array                               $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var array[]                             $partidas
 * @var array                               $calculo  Presupuesto::calcular (o el validado, guardado)
 * @var string                              $url
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$editable = Servicio::puede_editar_presupuesto( $e, $ambito );
$campos   = static function ( string $accion ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="presupuesto">';
};
$quien    = static fn( int $id ): string => $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '';
$reparto  = static function ( array $p ): string {
	if ( PartidasTable::INGRESO === $p['tipo'] ) {
		return asde_t( 'Se resta de lo común' );
	}
	$texto = asde_t( Presupuesto::REPARTOS[ $p['reparto'] ] ?? $p['reparto'] );
	return '' !== $p['seccion'] ? sprintf( asde_t( '%1$s (%2$s)' ), $texto, Secciones::nombre( $p['seccion'] ) ) : $texto;
};
?>
<?php if ( ! $e['con_coste'] ) : ?>
	<section class="asde-card">
		<p><?php asde_e( 'Este evento no tiene coste para las familias. Si lo tiene, márcalo en «Editar los datos» y prepara aquí el presupuesto.' ); ?></p>
	</section>
	<?php return; ?>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Presupuesto' ); ?></h3>
	<?php if ( null !== $e['validado_en'] ) : ?>
		<p class="asde-notice asde-notice--success">
			<?php echo esc_html( sprintf( '' !== $quien( $e['validado_por'] ) ? asde_t( 'Validado por %1$s el %2$s: el precio por sección ya es fijo.' ) : asde_t( 'Validado el %2$s: el precio por sección ya es fijo.' ), $quien( $e['validado_por'] ), wp_date( 'd/m/Y', strtotime( $e['validado_en'] . ' UTC' ) ) ) ); ?>
		</p>
	<?php elseif ( null !== $e['validacion_pedida_en'] ) : ?>
		<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( asde_t( 'Enviado a Tesorería el %s: falta que lo valide.' ), wp_date( 'd/m/Y', strtotime( $e['validacion_pedida_en'] . ' UTC' ) ) ) ); ?></p>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Escribe los gastos previstos y cómo se reparte cada uno. El precio de cada sección sale del coste (el del equipo, repartido entre los participantes) más el margen del fondo de emergencia, redondeado al euro. Cuando esté listo, envíalo a Tesorería para que lo valide.' ); ?></p>
	<?php endif; ?>

	<?php if ( $partidas ) : ?>
		<table class="asde-table asde-table--partidas">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
					<th scope="col"><?php asde_e( 'Cómo se reparte' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
					<?php if ( $editable ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Quitar' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $partidas as $p ) : ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Concepto' ); ?>"><?php echo esc_html( $p['concepto'] ); ?><?php echo PartidasTable::INGRESO === $p['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( asde_t( 'Ingreso' ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo asde_a( 'Cómo se reparte' ); ?>"><?php echo esc_html( $reparto( $p ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Importe' ); ?>"><?php echo esc_html( Calculadora::euros( $p['importe'] ) . ( in_array( $p['reparto'], array( 'participante', 'participante_equipo', 'equipo' ), true ) && PartidasTable::GASTO === $p['tipo'] ? ' ' . asde_t( 'por persona' ) : '' ) ); ?></td>
						<?php if ( $editable ) : ?>
							<td data-label="<?php echo asde_a( 'Quitar' ); ?>">
								<form method="post" class="asde-inline-form">
									<?php echo $campos( 'evento_partida_quitar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="partida" value="<?php echo (int) $p['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Quitar' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php asde_e( 'Todavía no hay gastos previstos.' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Añadir una partida' ); ?></h3>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo $campos( 'evento_partida_anadir' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<fieldset>
				<legend class="screen-reader-text"><?php asde_e( 'Gasto o ingreso' ); ?></legend>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="tipo" value="gasto" checked><span><?php asde_e( 'Gasto' ); ?></span></label>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="tipo" value="ingreso"><span><?php asde_e( 'Ingreso (subvención, aportación del grupo)' ); ?></span></label>
			</fieldset>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Concepto' ); ?> <input type="text" name="concepto" maxlength="160" required placeholder="<?php echo asde_a( 'p. ej. Autobús' ); ?>"></label>
				<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
			</p>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Cómo se reparte (los gastos)' ); ?>
					<select name="reparto">
						<?php foreach ( asde_ta( Presupuesto::REPARTOS ) as $k => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php asde_e( 'Sección (si es solo de una)' ); ?>
					<select name="seccion">
						<option value=""><?php asde_e( '— Todas —' ); ?></option>
						<?php foreach ( $e['secciones'] as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<p><button type="submit" class="button"><?php asde_e( 'Añadir la partida' ); ?></button></p>
			<p class="asde-help"><?php asde_e( '«Por persona: participantes y equipo» es lo que cuesta cada persona (albergue, comida): lo del equipo se reparte entre los participantes. «Solo el equipo» es lo que cuesta un scouter (p. ej. su plaza en un evento externo).' ); ?></p>
		</form>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Previsión' ); ?></h3>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo $campos( 'evento_prevision' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Personas del equipo previstas' ); ?> <input type="number" name="equipo_previsto" value="<?php echo (int) $e['equipo_previsto']; ?>" min="0" max="999" inputmode="numeric"></label>
				<label><?php asde_e( 'Margen del fondo de emergencia (%)' ); ?> <input type="text" name="margen_pct" value="<?php echo esc_attr( number_format( $e['margen_pct'], 2, ',', '' ) ); ?>" inputmode="decimal" size="6"></label>
			</p>
			<label class="asde-choice"><input type="checkbox" name="margen_activo" value="1" <?php checked( $e['margen_activo'] ); ?>><span><?php asde_e( 'Aplicar el margen del fondo de emergencia' ); ?></span></label>
			<p><button type="submit" class="button"><?php asde_e( 'Guardar y recalcular' ); ?></button></p>
			<p class="asde-help"><?php asde_e( 'Los participantes previstos de cada sección se cambian en «Editar los datos».' ); ?></p>
		</form>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php echo null !== $e['validado_en'] ? asde_t( 'Precio validado' ) : asde_t( 'Precio que sale' ); ?></h3>
	<?php if ( ! empty( $calculo['errores'] ) ) : ?>
		<ul class="asde-comprobacion">
			<?php foreach ( $calculo['errores'] as $error ) : ?>
				<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $error ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( ! empty( $calculo['precios'] ) ) : ?>
		<table class="asde-table asde-table--precios">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Sección' ); ?></th>
					<th scope="col"><?php asde_e( 'Participantes previstos' ); ?></th>
					<th scope="col"><?php asde_e( 'Coste por participante' ); ?></th>
					<th scope="col"><?php asde_e( 'Precio' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $calculo['precios'] as $s => $precio ) : ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Sección' ); ?>"><?php echo Secciones::badge( (string) $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo asde_a( 'Participantes previstos' ); ?>"><?php echo (int) ( $calculo['previstos'][ $s ] ?? 0 ); ?></td>
						<td data-label="<?php echo asde_a( 'Coste por participante' ); ?>"><?php echo esc_html( Calculadora::euros( (float) ( $calculo['costes'][ $s ] ?? 0 ) ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Precio' ); ?>"><strong><?php echo esc_html( Calculadora::euros( (float) $precio ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<dl class="asde-datos">
			<div><dt><?php asde_e( 'Gastos previstos' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['gastos'] ) ); ?></dd></div>
			<?php if ( (float) $calculo['ingresos'] > 0 ) : ?>
				<div><dt><?php asde_e( 'Ingresos previstos' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['ingresos'] ) ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php asde_e( 'Recaudación prevista' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['recaudacion'] ) ); ?></dd></div>
			<div><dt><?php asde_e( 'Fondo de emergencia previsto' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['fondo'] ) ); ?> <span class="asde-muted"><?php echo esc_html( (float) $calculo['margen'] > 0 ? sprintf( asde_t( '(margen del %s %% y redondeo)' ), number_format_i18n( (float) $calculo['margen'], 2 ) ) : asde_t( '(solo el redondeo: margen apagado)' ) ); ?></span></dd></div>
			<div><dt><?php asde_e( 'Equipo' ); ?></dt><dd><?php echo esc_html( sprintf( asde_n( '%d persona, que no paga: su coste está repartido en el precio.', '%d personas, que no pagan: su coste está repartido en el precio.', (int) $calculo['equipo'] ), (int) $calculo['equipo'] ) ); ?></dd></div>
		</dl>
		<p class="asde-help"><?php asde_e( 'El precio se calcula con los participantes previstos y, una vez validado, ya no cambia: lo que sobre o falte lo asume el grupo.' ); ?></p>
	<?php endif; ?>

	<?php
	$botones = array();
	if ( Servicio::puede_pedir_validacion( $e, $ambito ) ) {
		$botones[] = '<form method="post" class="asde-inline-form">' . $campos( 'evento_pedir_validacion' ) . '<button type="submit" class="button">' . esc_html( asde_t( 'Enviar a Tesorería para validar' ) ) . '</button></form>';
	}
	if ( Servicio::puede_validar( $e ) ) {
		$botones[] = '<form method="post" class="asde-inline-form" onsubmit="return confirm(\'' . esc_js( asde_t( '¿Validar el presupuesto? El precio por sección quedará fijo.' ) ) . '\');">' . $campos( 'evento_validar' ) . '<button type="submit" class="button button-primary">' . esc_html( asde_t( 'Validar el presupuesto' ) ) . '</button></form>';
	}
	if ( Servicio::puede_reabrir( $e ) ) {
		$botones[] = '<form method="post" class="asde-inline-form" onsubmit="return confirm(\'' . esc_js( asde_t( '¿Reabrir el presupuesto? Habrá que volver a validarlo antes de publicar.' ) ) . '\');">' . $campos( 'evento_reabrir' ) . '<button type="submit" class="asde-btn-link">' . esc_html( asde_t( 'Reabrir el presupuesto' ) ) . '</button></form>';
	}
	?>
	<?php if ( $botones ) : ?>
		<p class="asde-actions"><?php echo implode( ' ', $botones ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
	<?php endif; ?>
</section>
