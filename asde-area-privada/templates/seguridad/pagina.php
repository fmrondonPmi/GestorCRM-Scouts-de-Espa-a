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
	<h2>Seguridad de la cuenta</h2>
	<?php if ( ! $disponible ) : ?>
		<p>La verificación en dos pasos no está disponible ahora mismo.</p>
	<?php else : ?>
		<?php if ( $activo ) : ?>
			<div class="asde-notice asde-notice--success" role="status">Tienes la verificación en dos pasos <strong>activada</strong>. ¡Bien hecho!</div>
		<?php elseif ( $pendiente ) : ?>
			<div class="asde-notice asde-notice--warning" role="status">
				Para tu rol la verificación en dos pasos es <strong>obligatoria</strong><?php echo $limite && $limite > time() ? esc_html( ' a partir del ' . wp_date( 'd/m/Y', $limite ) ) : ''; ?>.
				Después de esa fecha no podrás entrar sin ella.
			</div>
		<?php endif; ?>
		<p>La verificación en dos pasos protege los datos del grupo: además de tu contraseña, al entrar te pedirá un código de 6 cifras de una app en tu móvil.</p>
		<ol>
			<li>Instala en tu móvil una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy…).</li>
			<li>Escanea con ella el código QR de abajo.</li>
			<li>Escribe el código de 6 cifras que te muestra la app y pulsa «Activate».</li>
			<li>Guarda los <strong>códigos de recuperación</strong> en un lugar seguro: te servirán si pierdes el móvil.</li>
		</ol>
	<?php endif; ?>
</section>

<?php if ( $disponible ) : ?>
	<section class="asde-card asde-wordfence">
		<?php echo $wordfence; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML generado por Wordfence. ?>
	</section>
<?php endif; ?>
