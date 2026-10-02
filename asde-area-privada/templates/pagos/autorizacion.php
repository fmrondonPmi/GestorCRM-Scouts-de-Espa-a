<?php
/**
 * Autorización (orden de domiciliación de adeudo directo SEPA) para ver e imprimir.
 *
 * @var array       $mandato
 * @var array       $familia
 * @var array       $acreedor
 * @var string[]    $texto
 * @var bool        $completo  Se enseña el IBAN entero (quien puede firmar); si no, enmascarado
 * @var WP_User|null $firmante
 * @var string      $url       Mis pagos
 */
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;

defined( 'ABSPATH' ) || exit;

$d       = $mandato['datos'];
$activo  = Mandatos::ACTIVO === $mandato['estado'];
$fecha   = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$dir_acr = trim( $acreedor['calle'] . ', ' . trim( $acreedor['cp'] . ' ' . $acreedor['poblacion'] ), ' ,' );
$dir_deu = trim( implode( ', ', array_filter( array( $d['calle'], trim( $d['cp'] . ' ' . $d['poblacion'] ), $d['provincia'], $d['pais'] ) ) ) );
?>
<p class="asde-back asde-no-print"><a href="<?php echo esc_url( $url ); ?>">← Mis pagos</a></p>

<section class="asde-card asde-autorizacion">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Orden de domiciliación de adeudo directo SEPA</h2>
		<span class="asde-badge asde-badge--<?php echo $activo ? 'estado-activo' : 'estado-desactivado'; ?>"><?php echo $activo ? 'Vigente' : 'Anulada'; ?></span>
	</header>

	<dl class="asde-datos">
			<div><dt>Referencia de la orden de domiciliación</dt><dd><strong><?php echo esc_html( $mandato['referencia'] ); ?></strong></dd></div>
			<div><dt>Identificador del acreedor</dt><dd><?php echo esc_html( $acreedor['ics'] ); ?></dd></div>
			<div><dt>Nombre del acreedor</dt><dd><?php echo esc_html( $acreedor['nombre'] ); ?></dd></div>
			<?php if ( '' !== $dir_acr ) : ?>
				<div><dt>Dirección del acreedor</dt><dd><?php echo esc_html( $dir_acr ); ?></dd></div>
			<?php endif; ?>
			<div><dt>Nombre del deudor</dt><dd><?php echo '' !== $d['titular'] ? esc_html( $d['titular'] ) : '—'; ?></dd></div>
			<?php if ( '' !== $dir_deu ) : ?>
				<div><dt>Dirección del deudor</dt><dd><?php echo esc_html( $dir_deu ); ?></dd></div>
			<?php endif; ?>
			<div><dt>Cuenta de adeudo (IBAN)</dt><dd><?php echo esc_html( $completo && '' !== $d['iban'] ? Iban::formatear( $d['iban'] ) : $mandato['iban_mask'] ); ?></dd></div>
			<div><dt>Tipo de pago</dt><dd>Recurrente</dd></div>
			<div><dt>Familia (Tesorería)</dt><dd><?php echo esc_html( $familia['numero'] ); ?></dd></div>
			<div><dt>Fecha y localidad de la firma</dt><dd><?php echo esc_html( $fecha( $mandato['fecha_firma'] ) . ( '' !== $d['lugar'] ? ', ' . $d['lugar'] : '' ) ); ?></dd></div>
			<div>
			<dt>Firma</dt>
			<dd>
					Firmada electrónicamente desde el área privada
					<?php echo $firmante ? esc_html( 'por la cuenta ' . $firmante->user_login ) : ''; ?>
					el <?php echo esc_html( get_date_from_gmt( $mandato['firmado_en'], 'd/m/Y \a \l\a\s H:i' ) ); ?>
					(texto de la autorización, versión <?php echo esc_html( $mandato['texto_version'] ); ?>).
				</dd>
		</div>
			<?php if ( ! $activo && $mandato['revocado_en'] ) : ?>
				<div><dt>Anulada</dt><dd><?php echo esc_html( get_date_from_gmt( $mandato['revocado_en'], 'd/m/Y' ) . ( '' !== $mandato['motivo'] ? ' · ' . $mandato['motivo'] : '' ) ); ?></dd></div>
			<?php endif; ?>
		</dl>

	<?php foreach ( $texto as $parrafo ) : ?>
		<p><?php echo esc_html( $parrafo ); ?></p>
	<?php endforeach; ?>

	<p class="asde-no-print"><button type="button" class="button" onclick="window.print()">Imprimir o guardar como PDF</button></p>
</section>
