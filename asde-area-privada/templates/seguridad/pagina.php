<?php
/**
 * Seguridad de la cuenta (verificación en dos pasos de Wordfence).
 *
 * @var bool   $disponible
 * @var bool   $activo
 * @var bool   $pendiente
 * @var ?int   $limite
 * @var string $wordfence  HTML del gestor de Wordfence
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<h2><?php asde_e( 'Seguridad de la cuenta' ); ?></h2>
	<?php if ( ! $disponible ) : ?>
		<p><?php asde_e( 'La verificación en dos pasos no está disponible ahora mismo.' ); ?></p>
	<?php else : ?>
		<?php if ( $activo ) : ?>
			<div class="asde-notice asde-notice--success" role="status"><?php asde_e( 'Tienes la verificación en dos pasos' ); ?> <strong><?php asde_e( 'activada' ); ?></strong><?php asde_e( '. ¡Bien hecho!' ); ?></div>
		<?php elseif ( $pendiente ) : ?>
			<div class="asde-notice asde-notice--warning" role="status">
				<?php asde_e( 'Para tu rol la verificación en dos pasos es' ); ?> <strong><?php asde_e( 'obligatoria' ); ?></strong><?php echo $limite && $limite > time() ? esc_html( ' ' . sprintf( asde_t( 'a partir del %s' ), wp_date( 'd/m/Y', $limite ) ) ) : ''; ?><?php asde_e( '. Después de esa fecha no podrás entrar sin ella.' ); ?>
			</div>
		<?php endif; ?>
		<p><?php asde_e( 'La verificación en dos pasos protege los datos del grupo: además de tu contraseña, al entrar te pedirá un código de 6 cifras de una app en tu móvil.' ); ?></p>
		<ol>
			<li><?php asde_e( 'Instala en tu móvil una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy…).' ); ?></li>
			<li><?php asde_e( 'Escanea con ella el código QR de abajo.' ); ?></li>
			<li><?php asde_e( 'Escribe el código de 6 cifras que te muestra la app y pulsa «Activate».' ); ?></li>
			<li><?php asde_e( 'Guarda los' ); ?> <strong><?php asde_e( 'códigos de recuperación' ); ?></strong> <?php asde_e( 'en un lugar seguro: te servirán si pierdes el móvil.' ); ?></li>
		</ol>
	<?php endif; ?>
</section>

<?php if ( $disponible ) : ?>
	<section class="asde-card asde-wordfence">
		<?php echo $wordfence; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML generado por Wordfence. ?>
	</section>
<?php endif; ?>
