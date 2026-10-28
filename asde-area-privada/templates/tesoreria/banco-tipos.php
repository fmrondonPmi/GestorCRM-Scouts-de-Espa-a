<?php
/**
 * Tipos de gasto e ingreso de los movimientos del banco que no son cuotas (v0.20).
 * Con lo que suma cada tipo: sirve de resumen de en qué se gasta y de dónde entra.
 *
 * @var array<int, array> $tipos
 * @var array<int, array{gastos:int, ingresos:int, total:float}> $usos
 * @var string            $url  Listado del banco
 * @var string            $volver «Marcar como revisado» del que se viene con su «+» (sin JavaScript, v0.34), o ''
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$aqui       = add_query_arg( array_filter( array( 'tipos' => '1', 'volver' => '' !== $volver ? rawurlencode( $volver ) : '' ) ), $url );
$naturaleza = static function ( string $nombre, string $actual, string $id ): string {
	$h = '<select name="' . esc_attr( $nombre ) . '" id="' . esc_attr( $id ) . '">';
	foreach ( TiposMovimiento::naturalezas() as $k => $etiqueta ) {
		$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $actual, $k, false ) . '>' . esc_html(  $etiqueta  ) . '</option>';
	}
	return $h . '</select>';
};
?>
<?php if ( '' !== $volver ) : ?>
	<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back to "Mark as reviewed"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<?php else : ?>
	<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<?php endif; ?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'Expense and income types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p class="asde-muted"><?php esc_html_e( 'To classify the bank transactions that are not families\' fees. Each type is for expenses, for income or for both (for example, refunds). A type used by any transaction cannot be deleted, but it can be renamed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="post" action="<?php echo esc_url( $aqui ); ?>" class="asde-form__fila asde-form__fila--compacta">
		<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
		<input type="hidden" name="asde_action" value="tipo_crear">
		<label for="asde-bt-nuevo" class="screen-reader-text"><?php esc_html_e( 'Name of the new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-bt-nuevo" name="nombre" maxlength="80" required placeholder="<?php echo esc_attr( esc_attr__( 'Name of the new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label for="asde-bt-nuevo-nat" class="screen-reader-text"><?php esc_html_e( 'Class', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<?php echo wp_kses( $naturaleza( 'naturaleza', TiposMovimiento::GASTO, 'asde-bt-nuevo-nat' ), asde_html_permitido() ); ?>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Create type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $tipos ) : ?>
		<p><?php esc_html_e( 'There is no type yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--banco-tipos">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name and class', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Sum', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
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
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name and class', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<form method="post" action="<?php echo esc_url( $aqui ); ?>" class="asde-form__fila asde-form__fila--compacta">
								<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
								<input type="hidden" name="asde_action" value="tipo_cambiar">
								<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
								<label class="screen-reader-text" for="asde-bt-<?php echo (int) $t['id']; ?>"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Name of the type "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $t['nombre'] ) ); ?></label>
								<input type="text" id="asde-bt-<?php echo (int) $t['id']; ?>" name="nombre" maxlength="80" required value="<?php echo esc_attr( $t['nombre'] ); ?>">
								<label class="screen-reader-text" for="asde-bt-nat-<?php echo (int) $t['id']; ?>"><?php esc_html_e( 'Class', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
								<?php echo wp_kses( $naturaleza( 'naturaleza', $t['naturaleza'], 'asde-bt-nat-' . (int) $t['id'] ), asde_html_permitido() ); ?>
								<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
							</form>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num">
							<?php if ( $n ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'estado' => 'ignorado', 'clase' => $t['id'] ), $url ) ); ?>"><?php echo (int) $n; ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sum', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num asde-importe <?php echo $u['total'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo $n ? esc_html( ( $u['total'] > 0 ? '+' : '' ) . Calculadora::euros( $u['total'] ) ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( ! $n ) : ?>
								<form method="post" action="<?php echo esc_url( $aqui ); ?>">
									<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
									<input type="hidden" name="asde_action" value="tipo_borrar">
									<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Delete', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'In use', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
