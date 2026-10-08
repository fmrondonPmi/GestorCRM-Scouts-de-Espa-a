<?php
/**
 * Monitor de la corrección de números en segundo plano (en todo el área privada,
 * para quien gestiona Personas). assets/js/tarea.js lo actualiza cada pocos
 * segundos sin recargar la página.
 *
 * @var array<string, mixed> $tarea    TareaNumeracion::monitor()
 * @var string               $ajax     admin-ajax.php
 * @var string               $nonce
 * @var string               $personas URL de Personas
 * @var bool                 $en_personas
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$activa = 'en_curso' === $tarea['estado'];
?>
<div class="asde-tarea asde-tarea--<?php echo esc_attr( $tarea['estado'] ); ?>" data-asde-tarea data-en-personas="<?php echo $en_personas ? '1' : '0'; ?>" data-estado="<?php echo esc_attr( $tarea['estado'] ); ?>" data-ajax="<?php echo esc_url( $ajax ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
	<div class="asde-tarea__fila">
		<strong class="asde-tarea__titulo"><?php echo esc_html( $tarea['accion'] ); ?></strong>
		<span class="asde-tarea__texto" data-campo="texto" role="status" aria-live="polite"><?php echo esc_html( $tarea['texto'] ); ?></span>
	</div>
	<progress class="asde-tarea__barra" data-campo="barra" max="100" value="<?php echo (int) $tarea['avance']; ?>"><?php echo (int) $tarea['avance']; ?> %</progress>
	<div class="asde-tarea__acciones">
		<?php if ( ! $en_personas ) : ?>
			<a href="<?php echo esc_url( $personas ); ?>"><?php asde_e( 'Ver en Personas' ); ?></a>
		<?php endif; ?>
		<button type="button" class="asde-btn-link" data-asde-tarea-accion="recargar"<?php echo $activa || ! $en_personas ? ' hidden' : ''; ?>><?php asde_e( 'Recargar la lista' ); ?></button>
		<button type="button" class="asde-btn-link" data-asde-tarea-accion="detener"<?php echo $activa ? '' : ' hidden'; ?>><?php asde_e( 'Detener' ); ?></button>
		<button type="button" class="asde-btn-link" data-asde-tarea-accion="cerrar"<?php echo $activa ? ' hidden' : ''; ?>><?php asde_e( 'Cerrar' ); ?></button>
	</div>
</div>
