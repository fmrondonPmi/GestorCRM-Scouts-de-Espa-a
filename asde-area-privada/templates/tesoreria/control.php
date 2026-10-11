<?php
/**
 * Tesorería · control de pagos de la ronda.
 *
 * @var string $ronda
 * @var array  $cfg
 * @var array  $lista     familia_id => ['familia' => …, 'calc' => …]
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array  $resumen
 * @var int    $sin_fam   Educandos activos sin familia (no se les cobra)
 * @var list<array{socio:string, fin:string, motivo:string, familia:int, numero:string, nombre:string}> $bajas Bajas recientes desde la web de personas de alguna familia (doc 34)
 * @var string $estado
 * @var string $q
 * @var string $url
 * @var string $url_fam
 * @var string $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$pct = $resumen['a_pagar'] > 0 ? round( 100 * $resumen['pagado'] / $resumen['a_pagar'] ) : 0;
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Control de pagos ·' ); ?> <?php echo esc_html( $ronda ); ?></h2>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php asde_e( 'Descargar CSV' ); ?></a>
	</header>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Familias con cuota' ); ?></dt><dd><strong><?php echo (int) $resumen['familias']; ?></strong></dd></div>
		<div><dt><?php asde_e( 'Hijos que pagan' ); ?></dt><dd><?php echo (int) $resumen['hijos']; ?></dd></div>
		<div><dt><?php asde_e( 'Total a cobrar' ); ?></dt><dd><strong><?php echo esc_html( Calculadora::euros( $resumen['a_pagar'] ) ); ?></strong></dd></div>
		<div><dt><?php asde_e( 'Cobrado' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( $resumen['pagado'] ) ); ?> <span class="asde-muted">(<?php echo (int) $pct; ?> %)</span></dd></div>
		<div><dt><?php asde_e( 'Pendiente' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( $resumen['pendiente'] ) ); ?></dd></div>
	</dl>
	<div class="asde-progreso" role="img" aria-label="<?php echo esc_attr( sprintf( asde_t( '%d %% cobrado' ), $pct ) ); ?>"><span style="width:<?php echo (int) min( 100, $pct ); ?>%"></span></div>
	<p class="asde-muted">
		<?php foreach ( $resumen['estados'] as $e => $n ) : ?>
			<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $e ); ?>"><?php echo esc_html( asde_t( Calculadora::ETIQUETAS[ $e ] ) . ': ' . $n ); ?></span>
		<?php endforeach; ?>
	</p>
	<p class="asde-muted">
		<?php asde_e( 'Plazos:' ); ?>
		<?php foreach ( $cfg['plazos'] as $pl ) : ?>
			<?php echo esc_html( $pl['nombre'] . ' (' . sprintf( asde_t( '%s %%, hasta el %s' ), number_format_i18n( (float) $pl['porcentaje'] ), wp_date( 'd/m/Y', strtotime( $pl['fecha'] . ' 12:00' ) ) ) . ')' ); ?> ·
		<?php endforeach; ?>
		<?php asde_e( 'Referencia para transferencias: el nº de familia (p. ej.' ); ?> <?php echo esc_html( ( reset( $lista )['familia']['numero'] ?? 'F684001' ) ); ?>).
	</p>
	<?php if ( $sin_fam ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<?php asde_e( 'Hay' ); ?> <strong><?php echo (int) $sin_fam; ?></strong> <?php asde_e( 'educandos con membresía activa que no están en ninguna familia: no se les está calculando cuota.' ); ?>
			<a href="<?php echo esc_url( $url_fam ); ?>"><?php asde_e( 'Asignarles familia' ); ?></a>.
		</div>
	<?php endif; ?>
	<?php if ( $bajas ) : ?>
		<div class="asde-notice asde-notice--info asde-bajas-recientes" role="status">
			<p><strong><?php asde_e( 'Bajas recientes' ); ?></strong> · <?php asde_e( 'Personas de alguna familia dadas de baja en el CRM desde la web en los últimos 30 días: ya no cuentan en la cuota de su familia.' ); ?></p>
			<ul>
				<?php foreach ( $bajas as $b ) : ?>
					<?php $fin = DateTime::createFromFormat( '!Y-m-d', $b['fin'] ); ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'familia' => $b['familia'], 'ronda' => $ronda ), $url ) ); ?>"><?php echo esc_html( $b['nombre'] ); ?></a>
						· <?php /* translators: %s: nº de familia */ echo esc_html( sprintf( asde_t( 'familia nº %s' ), $b['numero'] ) ); ?>
						<?php if ( $fin ) : ?>
							· <?php /* translators: %s: fecha */ echo esc_html( sprintf( asde_t( 'baja el %s' ), $fin->format( 'd/m/Y' ) ) ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<label for="asde-estado"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-estado" name="estado" onchange="this.form.submit()">
			<?php foreach ( asde_ta( TesoreriaView::FILTROS ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-q-tes" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-q-tes" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nº de familia o nombre' ) ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button>
	</form>

	<?php if ( ! $lista ) : ?>
		<p><?php asde_e( 'No hay familias' ); ?><?php echo '' !== $estado || '' !== $q ? esc_html( asde_t( ' con estos filtros' ) ) : ''; ?>. <a href="<?php echo esc_url( $url_fam ); ?>"><?php asde_e( 'Gestionar familias' ); ?></a></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Familia' ); ?></th>
					<th scope="col"><?php asde_e( 'Socios' ); ?></th>
					<th scope="col"><?php asde_e( 'Cuota' ); ?></th>
					<th scope="col"><?php asde_e( 'Pagado' ); ?></th>
					<th scope="col"><?php asde_e( 'Pendiente' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $id => $fila ) : ?>
					<?php
					$f = $fila['familia'];
					$c = $fila['calc'];
					?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Familia' ) ); ?>"><strong><?php echo esc_html( $f['numero'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Socios' ) ); ?>">
							<?php foreach ( $f['miembros'] as $n ) : ?>
								<?php $p = $personas[ $n ] ?? null; ?>
								<span class="asde-miembro<?php echo in_array( $n, $c['fuera'], true ) ? ' asde-miembro--fuera' : ''; ?>">
									<?php echo esc_html( $p ? $p->nombre_completo() : 'Nº ' . $n ); ?><?php echo $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php echo $p && $p->secciones ? Secciones::badge( $p->secciones[0] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</span>
							<?php endforeach; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cuota' ) ); ?>">
							<?php echo esc_html( Calculadora::euros( $c['a_pagar'] ) ); ?>
							<br><span class="asde-muted"><?php echo esc_html( count( $c['pagan'] ) . ' hijo(s)' . ( $c['ajustes'] ? ' · ajuste ' . Calculadora::euros( $c['ajustes'] ) : '' ) ); ?></span>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Pagado' ) ); ?>"><?php echo esc_html( Calculadora::euros( $c['pagado'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Pendiente' ) ); ?>"><?php echo esc_html( Calculadora::euros( max( 0, $c['pendiente'] ) ) ); ?><?php echo $c['pendiente'] < 0 ? '<br><span class="asde-muted">' . esc_html( sprintf( asde_t( 'Saldo a favor %s' ), Calculadora::euros( -$c['pendiente'] ) ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>"><span class="asde-badge asde-badge--pago-<?php echo esc_attr( $c['estado'] ); ?>"><?php echo esc_html( asde_t( Calculadora::ETIQUETAS[ $c['estado'] ] ) ); ?></span></td>
						<td data-label=""><a class="button" href="<?php echo esc_url( add_query_arg( array( 'familia' => $id, 'ronda' => $ronda ), $url ) ); ?>"><?php asde_e( 'Ver / registrar pago' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
