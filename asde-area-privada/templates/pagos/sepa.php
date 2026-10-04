<?php
/**
 * Mis pagos · Domiciliación SEPA de una familia: autorización vigente o formulario para firmarla.
 *
 * @var array       $familia   [id, numero, miembros]
 * @var array|null  $mandato   Autorización vigente (Mandatos::activo_de_familia)
 * @var true|string $puede     true si puede firmar/anular; si no, el motivo
 * @var array[]     $proximos  Próximos adeudos de la familia (Remesas::proximos_de_familia)
 * @var bool        $lista     El grupo tiene activadas las domiciliaciones y sus datos completos
 * @var array       $acreedor  Configuración del acreedor (Sepa\Config::de)
 * @var string[]    $texto     Párrafos de la autorización
 * @var array|null  $valores   Lo escrito en el intento anterior (con errores)
 * @var string      $url       Mis pagos
 */
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

if ( ! $mandato && ! $lista ) {
	return; // El grupo aún no ofrece la domiciliación.
}
$v      = is_array( $valores ) ? $valores : array();
$campo  = static fn( string $k, string $defecto = '' ) => isset( $v[ $k ] ) ? (string) $v[ $k ] : $defecto;
$fecha  = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
?>
<section class="asde-card" id="sepa-<?php echo (int) $familia['id']; ?>">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Domiciliación SEPA · familia' ); ?> <?php echo esc_html( $familia['numero'] ); ?></h3>
		<?php if ( $mandato ) : ?>
			<span class="asde-badge asde-badge--pago-pagado"><?php asde_e( 'Autorizada' ); ?></span>
		<?php else : ?>
			<span class="asde-badge asde-badge--pago-pendiente"><?php asde_e( 'Sin autorizar' ); ?></span>
		<?php endif; ?>
	</header>

	<?php if ( $proximos ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong><?php asde_e( 'Próximo cargo:' ); ?></strong>
			<?php foreach ( $proximos as $p ) : ?>
				<br><?php echo esc_html( sprintf( asde_t( '%s · %s en la cuenta %s (autorización %s)' ), $fecha( $p['fecha'] ), Calculadora::euros( $p['centimos'] / 100 ), '' !== $p['iban'] ? $p['iban'] : 'autorizada', $p['umr'] ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $mandato ) : ?>
		<p><?php asde_e( 'Has autorizado a' ); ?> <strong><?php echo esc_html( $acreedor['nombre'] ?: asde_t( 'el grupo' ) ); ?></strong> <?php asde_e( 'a cobrar los pagos del grupo por adeudo directo SEPA en la cuenta' ); ?> <strong><?php echo esc_html( $mandato['iban_mask'] ); ?></strong>.</p>
		<ul class="asde-resumen">
			<li><?php asde_e( 'Referencia:' ); ?> <strong><?php echo esc_html( $mandato['referencia'] ); ?></strong></li>
			<li><?php asde_e( 'Firmada el' ); ?> <strong><?php echo esc_html( $fecha( $mandato['fecha_firma'] ) ); ?></strong></li>
			<?php if ( '' !== $mandato['datos']['titular'] ) : ?>
				<li><?php asde_e( 'Titular:' ); ?> <strong><?php echo esc_html( $mandato['datos']['titular'] ); ?></strong></li>
			<?php endif; ?>
		</ul>
		<div class="asde-actions">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'autorizacion', (int) $mandato['id'], $url ) ); ?>"><?php asde_e( 'Ver e imprimir la autorización' ); ?></a>
			<?php if ( true === $puede ) : ?>
				<form method="post" onsubmit="return confirm(<?php echo esc_attr( asde_js( '¿Anular la autorización? A partir de ahora no se cobrará nada por esta cuenta.' ) ); ?>);">
					<?php wp_nonce_field( 'asde_sepa_anular_' . (int) $mandato['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_anular">
					<input type="hidden" name="mandato" value="<?php echo (int) $mandato['id']; ?>">
					<button type="submit" class="button asde-button-secundario"><?php asde_e( 'Anular la autorización' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( true === $puede && $lista ) : ?>
			<details class="asde-rechazar" <?php echo $v ? 'open' : ''; ?>>
				<summary><?php asde_e( 'Cambiar de cuenta o de titular (firmar otra autorización)' ); ?></summary>
				<?php include __DIR__ . '/sepa-formulario.php'; ?>
			</details>
		<?php endif; ?>
	<?php else : ?>
		<p><?php asde_e( 'Si prefieres no pagar por transferencia, puedes' ); ?> <strong><?php asde_e( 'autorizar el cobro por domiciliación bancaria (adeudo directo SEPA)' ); ?></strong><?php asde_e( ': el grupo cobrará la cuota de esta familia en tu cuenta, siempre avisándote antes con la fecha y el importe. Puedes anularla cuando quieras.' ); ?></p>
		<?php if ( true === $puede ) : ?>
			<details class="asde-rechazar" <?php echo $v ? 'open' : ''; ?>>
				<summary><?php asde_e( 'Rellenar la autorización' ); ?></summary>
				<?php include __DIR__ . '/sepa-formulario.php'; ?>
			</details>
		<?php else : ?>
			<p class="asde-help asde-help--warning"><?php echo esc_html( (string) $puede ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>
