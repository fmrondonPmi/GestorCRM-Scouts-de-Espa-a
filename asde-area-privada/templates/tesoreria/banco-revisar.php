<?php
/**
 * Marcar como revisados (no son cuota) uno o varios movimientos del banco, cada
 * uno con su tipo de gasto o de ingreso (v0.20, doc 16). Se abre en la ventana
 * emergente desde el listado; sin JavaScript, en su página.
 *
 * @var array[]           $movs        Los elegidos (pendientes o ya revisados, para cambiarles el tipo)
 * @var array<int, array> $tipos       Tipos del grupo
 * @var array<int, string> $eventos     Eventos a los que se puede asociar (id => nombre; vacío si el módulo está apagado)
 * @var array<int, string> $familias    Familias a las que se puede asociar (id => etiqueta)
 * @var string            $url
 * @var string            $volver      Listado del que se viene
 * @var int               $conciliados Elegidos que no se pueden revisar (conciliados con una familia)
 * @var string            $url_tipos
 * @var string            $url_mas     El «+» de cada fila (v0.34): los tipos, con la vuelta a esta ventana
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$gastos   = array_filter( $movs, static fn( $m ) => $m['importe'] < 0 );
$ingresos = count( $movs ) - count( $gastos );
$titulo   = 1 === count( $movs ) ? asde_t( 'Marcar como revisado' ) : asde_t( 'Marcar como revisados' );
// Grupos del desplegable: Gastos, Ingresos y «Gasto o ingreso».
$opciones = static function ( float $importe, ?int $elegido ) use ( $tipos ): string {
	$h      = '<option value="">' . esc_html( asde_t( 'Elige el tipo…' ) ) . '</option>';
	$grupos = array();
	foreach ( TiposMovimiento::para( $importe ) as $t ) {
		$grupos[ $t['naturaleza'] ][] = $t;
	}
	foreach ( $grupos as $nat => $lista ) {
		$h .= '<optgroup label="' . esc_attr( asde_t( TiposMovimiento::NATURALEZAS[ $nat ] ) ) . '" data-naturaleza="' . esc_attr( $nat ) . '">';
		foreach ( $lista as $t ) {
			$h .= '<option value="' . (int) $t['id'] . '"' . selected( $elegido, $t['id'], false ) . '>' . esc_html( $t['nombre'] ) . '</option>';
		}
		$h .= '</optgroup>';
	}
	return $h;
};
?>
<section class="asde-card asde-banco-revisar" data-asde-plegable="banco-revisar">
	<h2 class="asde-card__title"><?php echo esc_html( $titulo ); ?></h2>
	<?php if ( $conciliados ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( asde_n( '%d movimiento elegido ya está conciliado con una familia y no sale aquí: para cambiarlo, deshaz antes la conciliación.', '%d movimientos elegidos ya están conciliados con una familia y no salen aquí: para cambiarlos, deshaz antes la conciliación.', $conciliados ), $conciliados ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $movs ) : ?>
		<p><?php asde_e( 'No hay ningún movimiento que marcar. Elige en el listado los que no son cuotas.' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
	<?php elseif ( ! $tipos ) : ?>
		<p><?php asde_e( 'Todavía no hay tipos de gasto ni de ingreso.' ); ?> <a href="<?php echo esc_url( $url_tipos ); ?>"><?php asde_e( 'Créalos primero' ); ?></a>.</p>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Para los movimientos que no son cuotas de las familias: gastos (material, transporte, comisiones…) o ingresos (donativos, subvenciones…). El tipo sirve para saber en qué se gasta y de dónde entra el dinero.' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_banco_revisar' ); ?>
			<input type="hidden" name="asde_action" value="revisar">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<?php if ( count( $movs ) > 1 ) : ?>
				<p class="asde-form__fila">
					<label for="asde-tipo-todos"><?php asde_e( 'El mismo tipo para todos' ); ?></label>
					<select id="asde-tipo-todos" data-asde-tipo-todos>
						<option value=""><?php asde_e( 'Elige…' ); ?></option>
						<?php foreach ( $tipos as $t ) : ?>
							<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['nombre'] . ' (' . mb_strtolower( asde_t( TiposMovimiento::NATURALEZAS[ $t['naturaleza'] ] ) ) . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php asde_e( 'Se pone en los que encaje (un tipo de gasto, solo en los gastos). Después puedes cambiar el de cada uno.' ); ?></span>
				</p>
			<?php endif; ?>
			<table class="asde-table asde-table--banco-revisar">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
						<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th>
						<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
						<?php if ( $eventos ) : ?>
							<th scope="col"><?php asde_e( 'Evento' ); ?></th>
						<?php endif; ?>
						<?php if ( $familias ) : ?>
							<th scope="col"><?php asde_e( 'Familia' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $movs as $m ) : ?>
						<?php $ingreso = $m['importe'] > 0; ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
								<?php echo '' !== $m['ordenante'] ? '<strong>' . esc_html( $m['ordenante'] ) . '</strong><br>' : ''; ?>
								<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : '—' ); ?>
								<?php echo BancoTable::IGNORADO === $m['estado'] ? '<br><span class="asde-muted">' . esc_html( asde_t( 'Ya revisado: se le cambia el tipo.' ) ) . '</span>' : ''; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num asde-importe <?php echo $ingreso ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $ingreso ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>">
								<label class="screen-reader-text" for="asde-tipo-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Tipo' ); ?></label>
								<span class="asde-banco-tipo">
									<select id="asde-tipo-<?php echo (int) $m['id']; ?>" name="tipo[<?php echo (int) $m['id']; ?>]" required data-asde-tipo-fila data-asde-signo="<?php echo $ingreso ? 'ingreso' : 'gasto'; ?>">
										<?php echo $opciones( $m['importe'], $m['tipo_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- construido arriba con esc_html/esc_attr ?>
									</select>
									<a class="button asde-button-secundario asde-banco-mas" href="<?php echo esc_url( $url_mas ); ?>" data-asde-banco-tipo-nuevo title="<?php echo esc_attr( asde_a( 'Crear un tipo nuevo' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Crear un tipo nuevo' ) ); ?>">+</a>
								</span>
							</td>
							<?php if ( $eventos ) : ?>
								<td data-label="<?php echo esc_attr( asde_a( 'Evento' ) ); ?>">
									<label class="screen-reader-text" for="asde-evento-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Evento' ); ?></label>
									<select id="asde-evento-<?php echo (int) $m['id']; ?>" name="evento[<?php echo (int) $m['id']; ?>]">
										<option value=""><?php asde_e( 'Ninguno' ); ?></option>
										<?php foreach ( $eventos as $eid => $nombre ) : ?>
											<option value="<?php echo (int) $eid; ?>" <?php selected( (int) ( $m['evento_id'] ?? 0 ), (int) $eid ); ?>><?php echo esc_html( $nombre ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endif; ?>
							<?php if ( $familias ) : ?>
								<td data-label="<?php echo esc_attr( asde_a( 'Familia' ) ); ?>">
									<label class="screen-reader-text" for="asde-familia-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Familia' ); ?></label>
									<select id="asde-familia-<?php echo (int) $m['id']; ?>" name="familia[<?php echo (int) $m['id']; ?>]">
										<option value=""><?php asde_e( 'Ninguna' ); ?></option>
										<?php foreach ( $familias as $fid => $etiqueta ) : ?>
											<option value="<?php echo (int) $fid; ?>" <?php selected( (int) ( $m['familia_id'] ?? 0 ), (int) $fid ); ?>><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-banco-nota"><?php asde_e( 'Nota (opcional)' ); ?></label>
				<input type="text" id="asde-banco-nota" name="nota" maxlength="255" value="<?php echo esc_attr( 1 === count( $movs ) ? $movs[0]['nota'] : '' ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: factura 2026/031 de la tienda de deportes' ) ); ?>">
			</p>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php echo esc_html( $titulo ); ?></button>
				<a class="asde-solo-pagina" href="<?php echo esc_url( $volver ); ?>"><?php asde_e( 'Cancelar' ); ?></a>
			</p>
			<p class="asde-help">
				<?php echo esc_html( sprintf( asde_t( '%1$d gastos y %2$d ingresos.' ), count( $gastos ), $ingresos ) ); ?>
				<a href="<?php echo esc_url( $url_tipos ); ?>" class="asde-solo-pagina"><?php asde_e( 'Gestionar los tipos de gasto e ingreso' ); ?></a>
			</p>
		</form>
		<?php // v0.34: la ventana pequeña del «+» (banco.js la abre encima; sin JavaScript no se usa y el «+» va a los tipos). ?>
		<template data-asde-banco-tipo-plantilla>
			<form method="post" action="<?php echo esc_url( $url_tipos ); ?>" class="asde-form asde-banco-tipo-nuevo">
				<h3 id="asde-bt-mini-titulo"><?php asde_e( 'Tipo nuevo' ); ?></h3>
				<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
				<input type="hidden" name="asde_action" value="tipo_crear">
				<p>
					<label for="asde-bt-mini-nombre"><?php asde_e( 'Nombre del tipo nuevo' ); ?></label>
					<input type="text" id="asde-bt-mini-nombre" name="nombre" maxlength="80" required>
				</p>
				<p>
					<label for="asde-bt-mini-clase"><?php asde_e( 'Clase' ); ?></label>
					<select id="asde-bt-mini-clase" name="naturaleza">
						<?php foreach ( TiposMovimiento::NATURALEZAS as $k => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="asde-notice asde-notice--error" role="alert" data-asde-error hidden></p>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php asde_e( 'Crear tipo' ); ?></button>
					<button type="button" class="button asde-button-secundario" data-asde-cerrar><?php asde_e( 'Cancelar' ); ?></button>
				</p>
			</form>
		</template>
	<?php endif; ?>
</section>
