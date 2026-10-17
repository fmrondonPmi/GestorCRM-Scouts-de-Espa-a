<?php
/**
 * Selector de alérgenos de la ficha de salud (v0.41, doc 49, B3): una ventana con pestañas y casillas, más alérgenos escritos a mano.
 * En el CRM el campo sigue siendo texto, separado por comas. Sin JavaScript queda el campo de texto de siempre.
 *
 * @var string $id        Id del campo de texto
 * @var string $name      Nombre del campo (campo[…])
 * @var string $valor     Lo que hay guardado
 * @var string $extra     Atributos del campo (required…)
 * @var int    $maxlen    Longitud máxima del campo en el CRM (0 = sin límite)
 * @var string $etiqueta  Nombre del campo (para el título de la ventana)
 * @var string $inicial   Pestaña con la que se abre
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\SelectorAlergenos;

defined( 'ABSPATH' ) || exit;
?>
<div class="asde-alerg" data-asde-alerg data-maxlen="<?php echo (int) $maxlen; ?>" data-inicial="<?php echo esc_attr( $inicial ); ?>" data-msg-largo="<?php echo esc_attr( asde_a( 'Es demasiado largo para guardarlo en la ficha: quita alguno.' ) ); ?>" data-msg-vacio="<?php echo esc_attr( asde_a( 'Ninguno elegido' ) ); ?>">
	<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="asde-alerg__texto" value="<?php echo esc_attr( $valor ); ?>"<?php echo ( (int) $maxlen > 0 ? ' maxlength="' . (int) $maxlen . '"' : '' ) . $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<div class="asde-alerg__resumen" hidden>
		<ul class="asde-alerg__chips" data-asde-alerg-chips aria-live="polite"></ul>
		<button type="button" class="button asde-button-secundario" data-asde-alerg-abrir aria-haspopup="dialog"><?php asde_e( 'Elegir…' ); ?></button>
	</div>

	<dialog class="asde-alerg__dialogo" aria-labelledby="<?php echo esc_attr( $id ); ?>-titulo">
		<h3 id="<?php echo esc_attr( $id ); ?>-titulo"><?php echo esc_html( $etiqueta ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Marca lo que corresponda. Si algo no está en la lista, escríbelo abajo y añádelo.' ); ?></p>
		<div class="asde-alerg__tabs" role="tablist" aria-label="<?php echo esc_attr( asde_a( 'Categorías' ) ); ?>">
			<?php foreach ( SelectorAlergenos::PESTANAS as $k => $p ) : ?>
				<button type="button" role="tab" id="<?php echo esc_attr( $id . '-tab-' . $k ); ?>" aria-controls="<?php echo esc_attr( $id . '-panel-' . $k ); ?>" aria-selected="false" tabindex="-1" data-pestana="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( asde_t( $p['titulo'] ) ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php foreach ( SelectorAlergenos::PESTANAS as $k => $p ) : ?>
			<div role="tabpanel" id="<?php echo esc_attr( $id . '-panel-' . $k ); ?>" aria-labelledby="<?php echo esc_attr( $id . '-tab-' . $k ); ?>" class="asde-alerg__panel" hidden>
				<?php foreach ( $p['items'] as $etiqueta_item => $codigos ) : ?>
					<label class="asde-choice asde-choice--inline"><input type="checkbox" data-valor="<?php echo esc_attr( $etiqueta_item ); ?>" data-clave="<?php echo esc_attr( SelectorAlergenos::clave( $etiqueta_item ) ); ?>" data-clave-t="<?php echo esc_attr( SelectorAlergenos::clave( asde_t( $etiqueta_item ) ) ); ?>"><span><?php echo esc_html( asde_t( $etiqueta_item ) ); ?></span></label>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<div class="asde-alerg__propio">
			<label for="<?php echo esc_attr( $id ); ?>-propio"><?php asde_e( 'Otro (escríbelo)' ); ?></label>
			<span class="asde-alerg__propio-fila">
				<input type="text" id="<?php echo esc_attr( $id ); ?>-propio" maxlength="<?php echo (int) SelectorAlergenos::MAX_PROPIO; ?>" data-asde-alerg-propio autocomplete="off">
				<button type="button" class="button asde-button-secundario" data-asde-alerg-anadir><?php asde_e( 'Añadir' ); ?></button>
			</span>
			<ul class="asde-alerg__chips" data-asde-alerg-propios aria-live="polite"></ul>
		</div>
		<p class="asde-alerg__error" data-asde-alerg-error role="alert" hidden></p>
		<p class="asde-alerg__botones">
			<button type="button" class="button" data-asde-alerg-aceptar><?php asde_e( 'Aceptar' ); ?></button>
			<button type="button" class="asde-btn-link" data-asde-alerg-cancelar><?php asde_e( 'Cancelar' ); ?></button>
		</p>
	</dialog>
</div>
