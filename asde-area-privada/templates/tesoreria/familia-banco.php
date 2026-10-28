<?php
/**
 * Tesorería · elegir el ingreso del banco con el que paga una familia (v0.20.1).
 * Se abre en la ventana emergente desde «Registrar pago». El buscador filtra
 * mientras se escribe (assets/js/tesoreria.js); sin JavaScript, la ventana es una
 * página, el buscador la vuelve a pedir y «Elegir» vuelve a la ficha con el ingreso.
 *
 * @var array    $familia
 * @var string   $ronda
 * @var array[]  $movs   Ingresos por conciliar, con 'sugerido', 'motivo' y 'texto' (Conciliacion::pendientes_para)
 * @var string[] $claves Nombre y apellido de los socios y tutores de la familia (atajos del buscador)
 * @var string   $q
 * @var string   $url    Acción del buscador
 * @var string   $volver Ficha de la familia
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$aqui   = add_query_arg( 'elegir_banco', '1', $volver );
$grupos = array(
	array( __( 'Suggested for this family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array_filter( $movs, static fn( $m ) => $m['sugerido'] ) ),
	array( __( 'Other deposits to reconcile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array_filter( $movs, static fn( $m ) => ! $m['sugerido'] ) ),
);
$ve     = static fn( array $m ): bool => Conciliacion::coincide( $m['texto'], $q );
$hay    = (bool) array_filter( $movs, $ve );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back to the family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<section class="asde-card asde-banco-elegir" data-asde-banco-elegir data-asde-plegable="familia-banco">
	<h2 class="asde-card__title"><?php /* translators: %s: family number */ echo esc_html( sprintf( __( 'Bank deposits for family %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $familia['numero'] ) ); ?></h2>
	<p class="asde-muted"><?php esc_html_e( 'The imported bank deposits not yet reconciled. Choose this family\'s: the payment is recorded with its date and amount.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta" role="search" data-asde-banco-buscador>
		<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<input type="hidden" name="elegir_banco" value="1">
		<label for="asde-banco-elegir-q" class="screen-reader-text"><?php esc_html_e( 'Search in the bank deposits', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-banco-elegir-q" name="q" value="<?php echo esc_attr( $q ); ?>" autocomplete="off" placeholder="<?php echo esc_attr( esc_attr__( 'Guardian\'s or member\'s name, description, amount…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-banco-q>
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
	<?php if ( $claves ) : ?>
		<p class="asde-banco-elegir__atajos">
			<span class="asde-muted"><?php esc_html_e( 'Search by:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php foreach ( $claves as $clave ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'q', rawurlencode( $clave ), $aqui ) ); ?>" class="asde-banco-elegir__atajo" data-asde-banco-atajo="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $clave ); ?></a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $aqui ); ?>" class="asde-banco-elegir__atajo asde-banco-elegir__atajo--todos" data-asde-banco-atajo=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</p>
	<?php endif; ?>

	<?php foreach ( $grupos as list( $titulo, $lista ) ) : ?>
		<?php
		if ( ! $lista ) {
			continue;
		}
		?>
		<div class="asde-banco-elegir__grupo" data-asde-banco-grupo<?php echo array_filter( $lista, $ve ) ? '' : ' hidden'; ?>>
			<h3><?php echo esc_html( $titulo ); ?></h3>
			<table class="asde-table asde-table--banco-elegir">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lista as $m ) : ?>
						<?php
						$fecha = wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) );
						$euros = Calculadora::euros( $m['importe'] );
						?>
						<tr data-asde-banco-fila data-texto="<?php echo esc_attr( $m['texto'] ); ?>"<?php echo $ve( $m ) ? '' : ' hidden'; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( '' !== $m['ordenante'] ) : ?>
									<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
								<?php endif; ?>
								<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : '—' ); ?>
								<?php if ( '' !== $m['referencia1'] . $m['referencia2'] ) : ?>
									<br><span class="asde-muted"><?php esc_html_e( 'Ref.:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( trim( $m['referencia1'] . ' ' . $m['referencia2'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $m['motivo'] ) : ?>
									<span class="asde-help"><?php esc_html_e( 'Suggested:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $m['motivo'] ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num asde-importe asde-importe--ingreso"><?php echo esc_html( '+' . $euros ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<a class="button" href="<?php echo esc_url( add_query_arg( 'banco', (int) $m['id'], $volver ) ); ?>"
									data-asde-banco-usar="<?php echo (int) $m['id']; ?>"
									data-fecha="<?php echo esc_attr( $m['fecha'] ); ?>"
									data-importe="<?php echo esc_attr( number_format( $m['importe'], 2, '.', '' ) ); ?>"
									data-ref="<?php echo esc_attr( $m['documento'] ); ?>"
									data-resumen="<?php echo esc_attr( $fecha . ' · +' . $euros . ' · ' . mb_strimwidth( trim( $m['ordenante'] . ' ' . $m['concepto'] ), 0, 90, '…' ) ); ?>"
									aria-label="<?php /* translators: 1: date, 2: amount */ echo esc_attr( sprintf( __( 'Choose the transaction of %1$s for %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha, $euros ) ); ?>"><?php esc_html_e( 'Choose', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>
	<p data-asde-banco-vacio<?php echo $hay ? ' hidden' : ''; ?>><?php echo esc_html( $movs ? __( 'No deposit matches the search.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'There are no bank deposits to reconcile.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
</section>
