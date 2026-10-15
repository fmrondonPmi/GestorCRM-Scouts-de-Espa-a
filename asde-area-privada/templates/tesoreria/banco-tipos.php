<?php
/**
 * Tipos de gasto e ingreso de los movimientos del banco que no son cuotas (v0.20).
 * Con lo que suma cada tipo: sirve de resumen de en qué se gasta y de dónde entra.
 *
 * @var array<int, array> $tipos
 * @var array<int, array{gastos:int, ingresos:int, total:float}> $usos
 * @var string            $url  Listado del banco
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$aqui       = add_query_arg( 'tipos', '1', $url );
$naturaleza = static function ( string $nombre, string $actual, string $id ): string {
	$h = '<select name="' . esc_attr( $nombre ) . '" id="' . esc_attr( $id ) . '">';
	foreach ( TiposMovimiento::NATURALEZAS as $k => $etiqueta ) {
		$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $actual, $k, false ) . '>' . esc_html( asde_t( $etiqueta ) ) . '</option>';
	}
	return $h . '</select>';
};
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Banco' ); ?></a></p>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Tipos de gasto e ingreso' ); ?></h2>
	<p class="asde-muted"><?php asde_e( 'Para clasificar los movimientos del banco que no son cuotas de las familias. Cada tipo es de gasto, de ingreso o de las dos cosas (por ejemplo, devoluciones). Un tipo que usa algún movimiento no se puede borrar, pero sí cambiarle el nombre.' ); ?></p>

	<form method="post" action="<?php echo esc_url( $aqui ); ?>" class="asde-form__fila asde-form__fila--compacta">
		<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
		<input type="hidden" name="asde_action" value="tipo_crear">
		<label for="asde-bt-nuevo" class="screen-reader-text"><?php asde_e( 'Nombre del tipo nuevo' ); ?></label>
		<input type="text" id="asde-bt-nuevo" name="nombre" maxlength="80" required placeholder="<?php echo esc_attr( asde_a( 'Nombre del tipo nuevo' ) ); ?>">
		<label for="asde-bt-nuevo-nat" class="screen-reader-text"><?php asde_e( 'Clase' ); ?></label>
		<?php echo $naturaleza( 'naturaleza', TiposMovimiento::GASTO, 'asde-bt-nuevo-nat' ); // phpcs:ignore WordPress.Security.EscapeOutput -- construido arriba con esc_* ?>
		<button type="submit" class="button button-primary"><?php asde_e( 'Crear tipo' ); ?></button>
	</form>

	<?php if ( ! $tipos ) : ?>
		<p><?php asde_e( 'Todavía no hay ningún tipo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--banco-tipos">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Nombre y clase' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Movimientos' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Suma' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tipos as $t ) : ?>
					<?php
					$u = $usos[ $t['id'] ] ?? array(
						'gastos'   => 0,
						'ingresos' => 0,
						'total'    => 0.0,
					);
					$n = $u['gastos'] + $u['ingresos'];
					?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre y clase' ) ); ?>">
							<form method="post" action="<?php echo esc_url( $aqui ); ?>" class="asde-form__fila asde-form__fila--compacta">
								<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
								<input type="hidden" name="asde_action" value="tipo_cambiar">
								<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
								<label class="screen-reader-text" for="asde-bt-<?php echo (int) $t['id']; ?>"><?php echo esc_html( sprintf( asde_t( 'Nombre del tipo «%s»' ), $t['nombre'] ) ); ?></label>
								<input type="text" id="asde-bt-<?php echo (int) $t['id']; ?>" name="nombre" maxlength="80" required value="<?php echo esc_attr( $t['nombre'] ); ?>">
								<label class="screen-reader-text" for="asde-bt-nat-<?php echo (int) $t['id']; ?>"><?php asde_e( 'Clase' ); ?></label>
								<?php echo $naturaleza( 'naturaleza', $t['naturaleza'], 'asde-bt-nat-' . (int) $t['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- construido arriba con esc_* ?>
								<button type="submit" class="asde-btn-link"><?php asde_e( 'Guardar' ); ?></button>
							</form>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Movimientos' ) ); ?>" class="asde-num">
							<?php if ( $n ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'estado' => 'ignorado', 'clase' => $t['id'] ), $url ) ); ?>"><?php echo (int) $n; ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Suma' ) ); ?>" class="asde-num asde-importe <?php echo $u['total'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo $n ? esc_html( ( $u['total'] > 0 ? '+' : '' ) . Calculadora::euros( $u['total'] ) ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
							<?php if ( ! $n ) : ?>
								<form method="post" action="<?php echo esc_url( $aqui ); ?>">
									<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
									<input type="hidden" name="asde_action" value="tipo_borrar">
									<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Borrar' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted"><?php asde_e( 'En uso' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
