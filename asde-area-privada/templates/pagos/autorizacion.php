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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;

defined( 'ABSPATH' ) || exit;

$d       = $mandato['datos'];
$activo  = Mandatos::ACTIVO === $mandato['estado'];
$fecha   = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$dir_acr = trim( $acreedor['calle'] . ', ' . trim( $acreedor['cp'] . ' ' . $acreedor['poblacion'] ), ' ,' );
$dir_deu = trim( implode( ', ', array_filter( array( $d['calle'], trim( $d['cp'] . ' ' . $d['poblacion'] ), $d['provincia'], $d['pais'] ) ) ) );
?>
<p class="asde-back asde-no-print"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Mis pagos' ); ?></a></p>

<section class="asde-card asde-autorizacion">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Orden de domiciliación de adeudo directo SEPA' ); ?></h2>
		<span class="asde-badge asde-badge--<?php echo $activo ? 'estado-activo' : 'estado-desactivado'; ?>"><?php echo $activo ? esc_html( asde_t( 'Vigente' ) ) : esc_html( asde_t( 'Anulada' ) ); ?></span>
	</header>

	<dl class="asde-datos">
			<div><dt><?php asde_e( 'Referencia de la orden de domiciliación' ); ?></dt><dd><strong><?php echo esc_html( $mandato['referencia'] ); ?></strong></dd></div>
			<div><dt><?php asde_e( 'Identificador del acreedor' ); ?></dt><dd><?php echo esc_html( $acreedor['ics'] ); ?></dd></div>
			<div><dt><?php asde_e( 'Nombre del acreedor' ); ?></dt><dd><?php echo esc_html( $acreedor['nombre'] ); ?></dd></div>
			<?php if ( '' !== $dir_acr ) : ?>
				<div><dt><?php asde_e( 'Dirección del acreedor' ); ?></dt><dd><?php echo esc_html( $dir_acr ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php asde_e( 'Nombre del deudor' ); ?></dt><dd><?php echo $cuenta && '' !== $cuenta['titular'] ? esc_html( $cuenta['titular'] ) : '—'; ?></dd></div>
			<?php if ( '' !== $dir_deu ) : ?>
				<div><dt><?php asde_e( 'Dirección del deudor' ); ?></dt><dd><?php echo esc_html( $dir_deu ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php asde_e( 'Cuenta de adeudo (IBAN)' ); ?></dt><dd><?php echo esc_html( $completo && $cuenta ? Iban::formatear( $cuenta['iban'] ) : $mandato['iban_mask'] ); ?></dd></div>
			<div><dt><?php asde_e( 'Tipo de pago' ); ?></dt><dd><?php asde_e( 'Recurrente' ); ?></dd></div>
			<div><dt><?php asde_e( 'Familia (Tesorería)' ); ?></dt><dd><?php echo esc_html( $familia['numero'] ); ?></dd></div>
			<div><dt><?php asde_e( 'Fecha y localidad de la firma' ); ?></dt><dd><?php echo esc_html( $fecha( $mandato['fecha_firma'] ) . ( '' !== $d['lugar'] ? ', ' . $d['lugar'] : '' ) ); ?></dd></div>
			<div>
			<dt><?php asde_e( 'Firma' ); ?></dt>
			<dd>
					<?php asde_e( 'Firmada electrónicamente desde el área privada' ); ?>
					<?php echo $firmante ? esc_html( sprintf( asde_t( 'por la cuenta %s' ), $firmante->user_login ) ) : ''; ?>
					<?php echo esc_html( sprintf( asde_t( 'el %1$s a las %2$s' ), get_date_from_gmt( $mandato['firmado_en'], 'd/m/Y' ), get_date_from_gmt( $mandato['firmado_en'], 'H:i' ) ) ); ?>
					<?php asde_e( '(texto de la autorización, versión' ); ?> <?php echo esc_html( $mandato['texto_version'] ); ?>).
				</dd>
		</div>
			<?php if ( ! $activo && $mandato['revocado_en'] ) : ?>
				<div><dt><?php asde_e( 'Anulada' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $mandato['revocado_en'], 'd/m/Y' ) . ( '' !== $mandato['motivo'] ? ' · ' . $mandato['motivo'] : '' ) ); ?></dd></div>
			<?php endif; ?>
		</dl>

	<?php foreach ( $texto as $parrafo ) : ?>
		<p><?php echo esc_html( $parrafo ); ?></p>
	<?php endforeach; ?>

	<p class="asde-no-print"><button type="button" class="button" data-asde-imprimir-pagina><?php asde_e( 'Imprimir o guardar como PDF' ); ?></button></p>
</section>
