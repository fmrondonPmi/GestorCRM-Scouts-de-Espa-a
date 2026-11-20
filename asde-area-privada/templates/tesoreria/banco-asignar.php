<?php
/**
 * Asignación guiada (v0.64.17, petición del usuario: «al procesar un fichero del banco, si un movimiento contiene el código de un
 * evento, de un pedido o de una familia, que aparezca una ventana para confirmar la asignación del pago»). Se abre sola al importar
 * un fichero con códigos y desde el botón de la lista del banco; sin JavaScript, en su página. Nada se asigna sin confirmar.
 *
 * @var bool                  $hecho      Ya se ha confirmado (desde la ventana): solo el aviso
 * @var array<int, array>     $propuestas Por id de movimiento: mov, kinds, como, marcada, avisos (AsignacionGuiada::propuestas)
 * @var array<int, array>     $tipos      Tipos de ingreso
 * @var int                   $tipo       Tipo preseleccionado
 * @var array<int, string>    $familias   Etiquetas de las familias, por id
 * @var string                $url
 * @var string                $volver     Listado del que se viene
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\AsignacionGuiada;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : ' 12:00' ) ) );
$nombre = static function ( string $como, array $d ) use ( $familias ): string {
	if ( AsignacionGuiada::COMO_FAMILIA === $como ) {
		/* translators: %s: family */
		return sprintf( __( 'Fee payment of family %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $familias[ (int) $d['familia']['id'] ] ?? $d['familia']['numero'] );
	}
	if ( AsignacionGuiada::COMO_PEDIDO === $como ) {
		/* translators: 1: order number, 2: amount */
		return sprintf( __( 'Order %1$s paid (%2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), PedidosTable::numero( $d['pedido'] ), Calculadora::euros( $d['a_cobrar'] ) );
	}
	/* translators: 1: event number, 2: event name */
	$txt = sprintf( __( 'Payment of event %1$s · %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), EventosTable::numero( $d['evento'] ), $d['evento']['nombre'] );
	return $d['familia'] ? $txt . ' — ' . ( $familias[ (int) $d['familia']['id'] ] ?? $d['familia']['numero'] ) : $txt;
};
?>
<?php if ( $hecho ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Assignment done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'Close this window to go back to the list: it now shows the update.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php elseif ( ! $propuestas ) : ?>
	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Guided assignment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'There is no deposit to reconcile with a family, order or event code.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php else : ?>
	<section class="asde-card asde-banco-asignar" data-asde-no-plegable>
		<h2 class="asde-card__title"><?php esc_html_e( 'Guided assignment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p class="asde-muted"><?php esc_html_e( 'These deposits carry a family, order or event code. Nothing is assigned until you confirm: tick the ones you want and press Confirm. The reliable ones come ticked; the rest need a look first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_banco_asignar' ); ?>
			<input type="hidden" name="asde_action" value="asignar_guiada">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<table class="asde-table" data-asde-paginar>
				<thead>
					<tr>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Assign', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Assign to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $propuestas as $id => $p ) : ?>
						<?php $m = $p['mov']; ?>
						<tr>
							<td data-label="">
								<input type="checkbox" name="ok[<?php echo (int) $id; ?>]" value="1" id="asde-asig-<?php echo (int) $id; ?>" <?php checked( $p['marcada'] ); ?>>
								<label class="screen-reader-text" for="asde-asig-<?php echo (int) $id; ?>"><?php esc_html_e( 'Assign', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $m['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : ( Norma43::conceptos()[ $m['concepto_comun'] ] ?? '—' ) ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe">+<?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Assign to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( count( $p['kinds'] ) > 1 ) : ?>
									<label class="screen-reader-text" for="asde-como-<?php echo (int) $id; ?>"><?php esc_html_e( 'Assign to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<select id="asde-como-<?php echo (int) $id; ?>" name="como[<?php echo (int) $id; ?>]">
										<?php foreach ( $p['kinds'] as $como => $d ) : ?>
											<option value="<?php echo esc_attr( $como ); ?>"><?php echo esc_html( $nombre( $como, $d ) ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<?php $como = (string) array_key_first( $p['kinds'] ); ?>
									<input type="hidden" name="como[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $como ); ?>">
									<?php echo esc_html( $nombre( $como, $p['kinds'][ $como ] ) ); ?>
								<?php endif; ?>
								<?php if ( isset( $p['kinds'][ AsignacionGuiada::COMO_EVENTO ] ) && ! $p['kinds'][ AsignacionGuiada::COMO_EVENTO ]['familia'] ) : ?>
									<br>
									<label class="screen-reader-text" for="asde-asig-fam-<?php echo (int) $id; ?>"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<select id="asde-asig-fam-<?php echo (int) $id; ?>" name="fam[<?php echo (int) $id; ?>]" class="asde-combobox" data-min="3" data-placeholder="<?php echo esc_attr( esc_attr__( 'Family: no. or surname (3 letters)…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
										<option value=""><?php esc_html_e( 'Choose the family…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
										<?php foreach ( $familias as $fid => $etiqueta ) : ?>
											<option value="<?php echo (int) $fid; ?>"><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
								<?php foreach ( $p['avisos'] as $aviso ) : ?>
									<br><span class="asde-help asde-help--warning"><?php echo esc_html( $aviso ); ?></span>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-asig-tipo"><?php esc_html_e( 'Income type for event payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-asig-tipo" name="tipo">
					<?php foreach ( $tipos as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( $tipo, (int) $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</div>
		</form>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php endif; ?>
