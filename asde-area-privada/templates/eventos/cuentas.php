<?php
/**
 * Evento · cuentas (v0.36): balance (previsto y real), gastos e ingresos reales con su
 * justificante, reembolsos, anticipos de caja, fondo de emergencia y cierre.
 *
 * @var array                               $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var bool                                $gestiona   Gestiona el evento (propone gastos)
 * @var array[]                             $gastos     GastosTable::de_evento() (también anulados y rechazados)
 * @var array[]                             $anticipos  AnticiposTable::de_evento()
 * @var array<int, string>                  $personas   user_id => nombre (equipo con cuenta en la web)
 * @var array[]                             $partidas   Partidas del presupuesto
 * @var array                               $balance    Cuentas::balance() o el informe final guardado
 * @var string[]                            $por_cerrar Lo que falta para poder cerrar
 * @var bool                                $economia   Es Tesorería
 * @var bool                                $abiertas   Las cuentas no están cerradas
 * @var array[]                             $banco      Movimientos del banco asociados al evento (BancoTable::revisados_de_evento), solo para Tesorería
 * @var array<int, array>                   $tipos_banco Tipos de gasto e ingreso
 * @var string                              $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$campos = static function ( string $accion, string $extra = '' ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="cuentas">'
		. $extra;
};
$oculto  = static fn( string $n, int $v ): string => '<input type="hidden" name="' . esc_attr( $n ) . '" value="' . (int) $v . '">';
$quien   = static fn( int $id ): string => $personas[ $id ] ?? ( $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '' );
$fecha   = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y', (int) strtotime( substr( $f, 0, 10 ) . ' 12:00:00' ) ) : '';
$hoy     = current_time( 'Y-m-d' );
$euros   = array( Calculadora::class, 'euros' );
$formas  = static function (): string {
	$h = '<select name="forma">';
	foreach ( asde_ta( GastosTable::FORMAS ) as $k => $t ) {
		$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $t ) . '</option>';
	}
	return $h . '</select>';
};
$partida = static function ( string $tipo ) use ( $partidas ): string {
	$h = '<select name="partida"><option value="0">' . esc_html( asde_t( '— Ninguna —' ) ) . '</option>';
	foreach ( $partidas as $p ) {
		if ( $tipo === $p['tipo'] ) {
			$h .= '<option value="' . (int) $p['id'] . '">' . esc_html( $p['concepto'] ) . '</option>';
		}
	}
	return $h . '</select>';
};
$nombres_partida = array_column( $partidas, 'concepto', 'id' );
$abiertos        = array_values( array_filter( $anticipos, array( AnticiposTable::class, 'abierto' ) ) );
$puede_apuntar   = $abiertas && ( $economia || $gestiona );
$yo              = get_current_user_id();
?>
<?php if ( ! $abiertas ) : ?>
	<div class="asde-notice asde-notice--info">
		<?php echo esc_html( sprintf( asde_t( 'Cuentas cerradas el %1$s por %2$s: este es el informe final. Para cambiar algo, Tesorería tiene que reabrirlas.' ), wp_date( 'd/m/Y', (int) strtotime( $e['cerrado_en'] . ' UTC' ) ), $quien( $e['cerrado_por'] ) ) ); ?>
	</div>
	<?php if ( $economia ) : ?>
		<details class="asde-accion asde-no-print">
			<summary><?php asde_e( 'Reabrir las cuentas' ); ?></summary>
			<form method="post" class="asde-form asde-form--compacta" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Reabrir las cuentas del evento? Habrá que volver a cerrarlas.' ) ); ?>">
				<?php echo $campos( 'evento_reabrir_cuentas' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="asde-help"><?php asde_e( 'Se podrá volver a cambiar el evento y sus cuentas. Quién recoge y las observaciones, que se borraron al cerrar, no vuelven.' ); ?></p>
				<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
				<button type="submit" class="button"><?php asde_e( 'Reabrir las cuentas' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
<?php endif; ?>

<section class="asde-card asde-cuentas__balance">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php echo esc_html( $abiertas ? asde_t( 'Balance' ) : asde_t( 'Informe final' ) ); ?></h3>
		<span class="asde-no-print">
			<button type="button" class="button" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button>
			<form method="post" class="asde-inline-form" data-asde-sin-carga><?php echo $campos( 'evento_informe' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button"><?php asde_e( 'Descargar CSV' ); ?></button></form>
		</span>
	</header>
	<p class="asde-muted"><?php echo esc_html( sprintf( asde_t( '%1$s · %2$s · a %3$s' ), $balance['evento'], $balance['nombre'], $balance['fecha'] ) ); ?></p>

	<?php if ( $balance['lineas'] ) : ?>
		<table class="asde-table asde-table--cuentas">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Partida' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Previsto' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Real' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $balance['lineas'] as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>"><?php echo esc_html( $l['concepto'] ); ?><?php echo GastosTable::INGRESO === $l['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( asde_t( 'Ingreso' ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Previsto' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $l['previsto'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Real' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $l['real'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Cobrado a las familias' ); ?></dt><dd>
			<?php echo esc_html( $euros( $balance['cobros']['neto'] ) ); ?>
			<?php if ( $balance['cobros']['devuelto'] > 0 ) : ?>
				<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( '(pagado %1$s, devuelto %2$s)' ), $euros( $balance['cobros']['pagado'] ), $euros( $balance['cobros']['devuelto'] ) ) ); ?></span>
			<?php endif; ?>
		</dd></div>
		<div><dt><?php asde_e( 'Pendiente de cobro' ); ?></dt><dd><?php echo esc_html( $euros( $balance['cobros']['pendiente'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Otros ingresos' ); ?></dt><dd><?php echo esc_html( $euros( $balance['ingresos'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Gastos' ); ?></dt><dd>
			<?php echo esc_html( $euros( $balance['gastos'] ) ); ?>
			<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( '(previstos %s)' ), $euros( $balance['previsto']['gastos'] ) ) ); ?></span>
		</dd></div>
		<div><dt><?php asde_e( 'Fondo de emergencia' ); ?></dt><dd>
			<?php echo esc_html( sprintf( asde_t( 'Recaudado %1$s · usado %2$s · queda %3$s' ), $euros( $balance['fondo']['recaudado'] ), $euros( $balance['fondo']['usado'] ), $euros( $balance['fondo']['disponible'] ) ) ); ?>
		</dd></div>
		<div class="asde-cuentas__resultado"><dt><?php asde_e( 'Resultado' ); ?></dt><dd>
			<strong><?php echo esc_html( $euros( $balance['resultado'] ) ); ?></strong>
			<span class="asde-muted"><?php echo esc_html( $balance['resultado'] < 0 ? asde_t( 'Lo asume el grupo.' ) : asde_t( 'Se queda en el grupo.' ) ); ?></span>
		</dd></div>
	</dl>
	<p class="asde-help"><?php asde_e( 'Resultado = lo cobrado a las familias (menos lo devuelto) + otros ingresos − gastos validados. Los gastos por revisar no cuentan hasta que Tesorería los valide.' ); ?></p>
</section>

<?php if ( ! empty( $banco ) ) : ?>
	<section class="asde-card" data-asde-plegable="evento-banco" data-asde-inicio="cerrado">
		<h3 class="asde-card__title"><?php asde_e( 'Movimientos del banco asociados' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Los ha asociado Tesorería al marcarlos como revisados en el banco. Son solo una referencia: no cambian el balance de arriba.' ); ?></p>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Fecha' ); ?></th><th scope="col"><?php asde_e( 'Tipo' ); ?></th><th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $banco as $b ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $b['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>"><?php echo esc_html( isset( $tipos_banco[ (int) $b['tipo_id'] ] ) ? $tipos_banco[ (int) $b['tipo_id'] ]['nombre'] : asde_t( 'Sin tipo' ) ); ?><?php echo '' !== $b['nota'] ? ' · ' . esc_html( $b['nota'] ) : ''; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num"><?php echo esc_html( ( $b['importe'] > 0 ? '+' : '' ) . \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( (float) $b['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Gastos e ingresos' ); ?></h3>
	<?php if ( $abiertas ) : ?>
		<?php if ( $balance['por_revisar'] ) : ?>
			<p class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( asde_t( 'Hay %d gasto(s) por revisar.' ), $balance['por_revisar'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $balance['reembolsos']['n'] ) : ?>
			<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( asde_t( 'Reembolsos pendientes: %1$d (%2$s).' ), $balance['reembolsos']['n'], $euros( $balance['reembolsos']['importe'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( ! $gastos ) : ?>
		<p><?php asde_e( 'Todavía no hay gastos ni ingresos apuntados.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--cuentas">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
					<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<th scope="col" class="asde-no-print"><?php asde_e( 'Acciones' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gastos as $g ) : ?>
					<?php
					$anulado = null !== $g['anulado_en'];
					$estado  = $anulado ? 'anulado' : $g['estado'];
					?>
					<tr class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $g['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
							<strong><?php echo esc_html( $g['concepto'] ); ?></strong><?php echo GastosTable::INGRESO === $g['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( asde_t( 'Ingreso' ) ) . '</span>' : ''; ?>
							<?php if ( '' !== $g['proveedor'] ) : ?>
								· <?php echo esc_html( $g['proveedor'] ); ?>
							<?php endif; ?>
							<br><span class="asde-muted">
								<?php
								$detalle = array( asde_t( GastosTable::FORMAS[ $g['forma'] ] ?? $g['forma'] ) );
								if ( $g['partida_id'] && isset( $nombres_partida[ $g['partida_id'] ] ) ) {
									$detalle[] = sprintf( asde_t( 'partida: %s' ), $nombres_partida[ $g['partida_id'] ] );
								}
								if ( GastosTable::PAGO_PERSONA === $g['pagado_por'] ) {
									$detalle[] = sprintf( asde_t( 'lo pagó %s' ), $quien( $g['persona'] ) );
								} elseif ( GastosTable::PAGO_ANTICIPO === $g['pagado_por'] ) {
									$detalle[] = sprintf( asde_t( 'con el anticipo nº %d' ), $g['anticipo_id'] );
								}
								if ( $g['con_fondo'] ) {
									$detalle[] = asde_t( 'con cargo al fondo de emergencia' );
								}
								echo esc_html( implode( ' · ', $detalle ) );
								?>
							</span>
							<?php if ( $g['creado_por'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Apuntado por %s' ), $quien( $g['creado_por'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $g['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<span class="asde-badge asde-badge--gasto-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( $anulado ? asde_t( 'Anulado' ) : asde_t( GastosTable::ESTADOS[ $g['estado'] ] ?? $g['estado'] ) ); ?></span>
							<?php if ( '' !== $g['motivo'] && ( $anulado || GastosTable::RECHAZADO === $g['estado'] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Motivo: %s' ), $g['motivo'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $g['reembolsado_en'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Reembolsado el %1$s (%2$s)' ), $fecha( $g['reembolsado_en'] ), asde_t( GastosTable::FORMAS[ $g['reembolso_forma'] ] ?? $g['reembolso_forma'] ) ) ); ?></span>
							<?php elseif ( GastosTable::por_reembolsar( $g ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Pendiente de reembolsar a %s' ), $quien( $g['persona'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>" class="asde-no-print">
							<?php if ( '' !== $g['justificante'] ) : ?>
								<form method="post" class="asde-inline-form" target="_blank"><?php echo $campos( 'evento_justificante', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="asde-btn-link"><?php asde_e( 'Ver justificante' ); ?></button></form>
							<?php endif; ?>
							<?php if ( $abiertas && ! $anulado ) : ?>
								<?php if ( GastosTable::por_revisar( $g ) && $economia ) : ?>
									<form method="post" class="asde-inline-form"><?php echo $campos( 'evento_gasto_validar', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button button-primary"><?php asde_e( 'Validar' ); ?></button></form>
									<details class="asde-accion">
										<summary><?php asde_e( 'Rechazar' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo $campos( 'evento_gasto_rechazar', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
											<button type="submit" class="button"><?php asde_e( 'Rechazar el gasto' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
								<?php if ( GastosTable::por_revisar( $g ) && ( $yo === $g['creado_por'] || $economia ) ) : ?>
									<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Retirar este gasto? No contará en las cuentas.' ) ); ?>"><?php echo $campos( 'evento_gasto_retirar', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="asde-btn-link"><?php asde_e( 'Retirar' ); ?></button></form>
								<?php endif; ?>
								<?php if ( GastosTable::por_reembolsar( $g ) && $economia ) : ?>
									<details class="asde-accion">
										<summary><?php asde_e( 'Reembolsar' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo $campos( 'evento_gasto_reembolsar', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
											<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
											<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( asde_t( 'Reembolsar %s' ), $euros( $g['importe'] ) ) ); ?></button>
										</form>
									</details>
								<?php endif; ?>
								<?php if ( GastosTable::vale( $g ) && null === $g['reembolsado_en'] && $economia ) : ?>
									<details class="asde-accion">
										<summary><?php asde_e( 'Anular' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo $campos( 'evento_gasto_anular', $oculto( 'gasto', $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
											<button type="submit" class="button"><?php asde_e( 'Anular' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $puede_apuntar ) : ?>
	<section class="asde-card asde-no-print">
		<h3 class="asde-card__title"><?php asde_e( 'Apuntar un gasto' ); ?></h3>
		<p class="asde-help"><?php echo esc_html( $economia ? asde_t( 'Lo que apunta Tesorería queda validado.' ) : asde_t( 'Tesorería lo revisa: hasta que lo valide no cuenta en el balance. Si lo pagaste tú, te lo reembolsará.' ) ); ?></p>
		<form method="post" enctype="multipart/form-data" class="asde-form asde-form--compacta">
			<?php echo $campos( 'evento_gasto' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
				<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
				<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
			</p>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Concepto' ); ?> <input type="text" name="concepto" maxlength="120" required placeholder="<?php echo esc_attr( asde_a( 'p. ej. Compra de comida' ) ); ?>"></label>
				<label><?php asde_e( 'Proveedor' ); ?> <input type="text" name="proveedor" maxlength="120"></label>
				<label><?php asde_e( 'Partida del presupuesto' ); ?> <?php echo $partida( GastosTable::GASTO ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
			</p>
			<fieldset>
				<legend><?php asde_e( '¿Quién lo pagó?' ); ?></legend>
				<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_GRUPO ); ?>" checked><span><?php echo esc_html( asde_t( GastosTable::PAGADORES[ GastosTable::PAGO_GRUPO ] ) ); ?></span></label>
				<?php if ( $abiertos ) : ?>
					<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_ANTICIPO ); ?>"><span><?php echo esc_html( asde_t( GastosTable::PAGADORES[ GastosTable::PAGO_ANTICIPO ] ) ); ?></span></label>
					<label><?php asde_e( 'Anticipo' ); ?>
						<select name="anticipo">
							<?php foreach ( $abiertos as $an ) : ?>
								<option value="<?php echo (int) $an['id']; ?>"><?php echo esc_html( sprintf( asde_t( 'Nº %1$d · %2$s · %3$s' ), $an['id'], $quien( $an['persona'] ), $euros( $an['importe'] ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( ! $economia || $personas ) : ?>
					<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_PERSONA ); ?>"><span><?php echo esc_html( $economia ? asde_t( GastosTable::PAGADORES[ GastosTable::PAGO_PERSONA ] ) : asde_t( 'Yo, de mi bolsillo (Tesorería me lo reembolsará)' ) ); ?></span></label>
				<?php endif; ?>
				<?php if ( $economia && $personas ) : ?>
					<label><?php asde_e( 'A quién hay que reembolsárselo' ); ?>
						<select name="persona">
							<?php foreach ( $personas as $uid => $nombre ) : ?>
								<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</fieldset>
			<label class="asde-choice"><input type="checkbox" name="con_fondo" value="1"><span><?php asde_e( 'Con cargo al fondo de emergencia (un imprevisto)' ); ?></span></label>
			<p><label><?php asde_e( 'Justificante (foto del tique o la factura, o PDF; hasta 5 MB)' ); ?> <input type="file" name="justificante" accept="image/jpeg,image/png,image/webp,application/pdf" required></label></p>
			<p><button type="submit" class="button button-primary"><?php echo esc_html( $economia ? asde_t( 'Apuntar el gasto' ) : asde_t( 'Enviar a Tesorería' ) ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $abiertas && $economia ) : ?>
	<section class="asde-card asde-no-print">
		<details>
			<summary><strong><?php asde_e( 'Apuntar un ingreso (subvención, donación…)' ); ?></strong></summary>
			<p class="asde-help"><?php asde_e( 'Los pagos de las familias no se apuntan aquí: se registran en la pestaña «Inscripciones».' ); ?></p>
			<form method="post" class="asde-form asde-form--compacta">
				<?php echo $campos( 'evento_ingreso' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
					<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
					<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				</p>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Concepto' ); ?> <input type="text" name="concepto" maxlength="120" required></label>
					<label><?php asde_e( 'De quién' ); ?> <input type="text" name="proveedor" maxlength="120"></label>
					<label><?php asde_e( 'Partida del presupuesto' ); ?> <?php echo $partida( GastosTable::INGRESO ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				</p>
				<p><button type="submit" class="button"><?php asde_e( 'Apuntar el ingreso' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>

<?php if ( $anticipos || ( $abiertas && $economia ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Anticipos de caja' ); ?></h3>
		<?php if ( ! $anticipos ) : ?>
			<p><?php asde_e( 'No se ha dado ningún anticipo.' ); ?></p>
		<?php else : ?>
			<ul class="asde-lista-nombres">
				<?php foreach ( $anticipos as $an ) : ?>
					<?php
					$con    = array_filter( $gastos, static fn( $g ) => $g['anticipo_id'] === $an['id'] && GastosTable::PAGO_ANTICIPO === $g['pagado_por'] && GastosTable::vale( $g ) );
					$usado  = round( array_sum( array_column( $con, 'importe' ) ), 2 );
					$anulado = null !== $an['anulado_en'];
					?>
					<li class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<strong><?php echo esc_html( sprintf( asde_t( 'Nº %1$d · %2$s' ), $an['id'], $quien( $an['persona'] ) ) ); ?></strong>
						· <?php echo esc_html( sprintf( asde_t( '%1$s el %2$s (%3$s)' ), $euros( $an['importe'] ), $fecha( $an['fecha'] ), asde_t( GastosTable::FORMAS[ $an['forma'] ] ?? $an['forma'] ) ) ); ?>
						<?php if ( '' !== $an['nota'] ) : ?>
							· <span class="asde-muted"><?php echo esc_html( $an['nota'] ); ?></span>
						<?php endif; ?>
						<br>
						<?php if ( $anulado ) : ?>
							<span class="asde-muted"><?php asde_e( 'Anulado' ); ?></span>
						<?php elseif ( AnticiposTable::LIQUIDADO === $an['estado'] ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Liquidado el %1$s: gastos %2$s, devuelto %3$s.' ), wp_date( 'd/m/Y', (int) strtotime( $an['liquidado_en'] . ' UTC' ) ), $euros( $usado ), $euros( $an['devuelto'] ) ) ); ?><?php echo '' !== $an['liquidacion_nota'] ? ' ' . esc_html( sprintf( asde_t( 'Nota: %s' ), $an['liquidacion_nota'] ) ) : ''; ?></span>
						<?php else : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Por liquidar: gastos validados %1$s; tendría que devolver %2$s.' ), $euros( $usado ), $euros( max( 0, $an['importe'] - $usado ) ) ) ); ?></span>
							<?php if ( $abiertas && $economia ) : ?>
								<details class="asde-accion asde-no-print">
									<summary><?php asde_e( 'Liquidar' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo $campos( 'evento_anticipo_liquidar', $oculto( 'anticipo', $an['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<label><?php asde_e( 'Devuelto (€)' ); ?> <input type="text" name="devuelto" inputmode="decimal" value="<?php echo esc_attr( number_format( max( 0, $an['importe'] - $usado ), 2, ',', '' ) ); ?>" required></label>
										<label><?php asde_e( 'Nota (obligatoria si no cuadra)' ); ?> <input type="text" name="nota" maxlength="255"></label>
										<button type="submit" class="button button-primary"><?php asde_e( 'Liquidar el anticipo' ); ?></button>
									</form>
								</details>
								<?php if ( ! $con ) : ?>
									<form method="post" class="asde-inline-form asde-no-print" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Anular este anticipo? Hazlo solo si se apuntó por error.' ) ); ?>"><?php echo $campos( 'evento_anticipo_anular', $oculto( 'anticipo', $an['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="asde-btn-link"><?php asde_e( 'Anular' ); ?></button></form>
								<?php endif; ?>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $abiertas && $economia ) : ?>
			<?php if ( ! $personas ) : ?>
				<p class="asde-help"><?php asde_e( 'Para dar un anticipo, la persona tiene que estar en el equipo del evento y tener cuenta en la web.' ); ?></p>
			<?php else : ?>
				<details class="asde-no-print">
					<summary><?php asde_e( 'Dar un anticipo' ); ?></summary>
					<form method="post" class="asde-form asde-form--compacta">
						<?php echo $campos( 'evento_anticipo' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<label><?php asde_e( 'A quién' ); ?>
							<select name="persona">
								<?php foreach ( $personas as $uid => $nombre ) : ?>
									<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $nombre ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
						<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
						<label><?php asde_e( 'Forma de pago' ); ?> <?php echo $formas(); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
						<label><?php asde_e( 'Nota' ); ?> <input type="text" name="nota" maxlength="255"></label>
						<button type="submit" class="button button-primary"><?php asde_e( 'Dar el anticipo' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $abiertas ) : ?>
	<section class="asde-card asde-no-print">
		<h3 class="asde-card__title"><?php asde_e( 'Cerrar las cuentas' ); ?></h3>
		<?php if ( ! $economia ) : ?>
			<p><?php asde_e( 'Las cierra Tesorería cuando el evento ha terminado y todo está revisado: el balance queda como informe final.' ); ?></p>
		<?php elseif ( $por_cerrar ) : ?>
			<p><?php asde_e( 'Todavía no se pueden cerrar:' ); ?></p>
			<ul class="asde-comprobacion">
				<?php foreach ( $por_cerrar as $motivo ) : ?>
					<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $motivo ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php asde_e( 'Al cerrar, el balance de hoy queda como informe final, se borran los datos de recogida y las observaciones de las inscripciones y el evento queda en solo lectura. Si después hace falta cambiar algo, Tesorería puede reabrirlas (lo borrado no vuelve).' ); ?></p>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Cerrar las cuentas del evento? Quién recoge y las observaciones de las inscripciones se borran y no vuelven.' ) ); ?>">
				<?php echo $campos( 'evento_cerrar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<button type="submit" class="button button-primary"><?php asde_e( 'Cerrar las cuentas' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
