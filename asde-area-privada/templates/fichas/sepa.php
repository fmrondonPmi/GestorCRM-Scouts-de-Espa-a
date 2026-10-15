<?php
/**
 * Ficha · datos bancarios y domiciliación SEPA (v0.33, doc 25 §9, P-131): la cuenta está en la
 * ficha del CRM (iban_c, iban_titular_c, iban_entidad_c) y aquí solo se enseña, enmascarada.
 * Se cambia firmando una autorización en «Mis pagos», que la guarda en la ficha de cada miembro
 * de la familia.
 *
 * @var array{iban:string, titular:string, entidad:string}|null $cuenta  La de esta ficha (null: no tiene)
 * @var array|null  $familia  Familia de Tesorería de esta persona
 * @var array|null  $mandato  Autorización vigente de la familia
 * @var bool        $coincide La cuenta de la ficha es la autorizada
 * @var string      $url_gestion «Mis pagos» (familia) o Tesorería → Domiciliaciones ('' si no hay enlace)
 * @var string      $texto_gestion
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Iban;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
?>
<section class="asde-card asde-ficha-sepa" data-asde-plegable="ficha-sepa">
	<h3 class="asde-card__title"><?php asde_e( 'Datos bancarios y domiciliación SEPA' ); ?></h3>
	<dl class="asde-datos">
		<?php if ( $cuenta ) : ?>
			<div><dt><?php asde_e( 'Cuenta (IBAN)' ); ?></dt><dd><?php echo esc_html( Iban::enmascarar( $cuenta['iban'] ) ); ?></dd></div>
			<div><dt><?php asde_e( 'Titular' ); ?></dt><dd><?php echo esc_html( '' !== $cuenta['titular'] ? $cuenta['titular'] : '—' ); ?></dd></div>
			<div><dt><?php asde_e( 'Entidad' ); ?></dt><dd><?php echo esc_html( '' !== $cuenta['entidad'] ? $cuenta['entidad'] : '—' ); ?></dd></div>
		<?php else : ?>
			<div><dt><?php asde_e( 'Cuenta (IBAN)' ); ?></dt><dd><?php asde_e( 'Sin datos bancarios en la ficha' ); ?></dd></div>
		<?php endif; ?>
		<div>
			<dt><?php asde_e( 'Autorización de cobro (SEPA)' ); ?></dt>
			<dd>
				<?php if ( $mandato ) : ?>
					<?php /* translators: 1: referencia, 2: fecha */ echo esc_html( sprintf( asde_t( 'Vigente · %1$s · firmada el %2$s' ), $mandato['referencia'], $fecha( (string) $mandato['fecha_firma'] ) ) ); ?>
					<?php if ( ! $coincide ) : ?>
						<br><span class="asde-help asde-help--warning"><?php asde_e( '⚠️ La cuenta de esta ficha no es la autorizada: no se cobrará hasta que se firme una autorización nueva.' ); ?></span>
					<?php endif; ?>
				<?php elseif ( $familia ) : ?>
					<?php asde_e( 'Sin autorización: la familia paga por transferencia.' ); ?>
				<?php else : ?>
					<?php asde_e( 'No está en ninguna familia de Tesorería.' ); ?>
				<?php endif; ?>
			</dd>
		</div>
	</dl>
	<p class="asde-help"><?php asde_e( 'Los datos bancarios se guardan en la ficha del CRM de Scouts de España (en la de cada miembro de la familia). Para ponerlos o cambiarlos, la madre, el padre, el tutor/a o el propio asociado mayor de edad firma una autorización en «Mis pagos».' ); ?></p>
	<?php if ( '' !== $url_gestion ) : ?>
		<p><a href="<?php echo esc_url( $url_gestion ); ?>"><?php echo esc_html( $texto_gestion ); ?></a></p>
	<?php endif; ?>
</section>
