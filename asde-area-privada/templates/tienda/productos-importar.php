<?php
/**
 * Cargar productos y existencias desde un CSV (doc 48, fase 2). Después de cargar, el informe:
 * cuántos productos y variantes entraron o cambiaron y qué filas no, con su motivo.
 *
 * @var array|null $resultado   productos_creados, productos_actualizados, variantes_creadas, variantes_actualizadas, errores[{fila, datos, motivo}], fichero, filas
 * @var bool       $caducado    Se pidió un informe que ya no está (caduca en una hora)
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ejemplo
 * @var string     $url_errores '' si no hay filas con error
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\ImportadorProductos;

defined( 'ABSPATH' ) || exit;

$columnas = array_map( static fn( $c ) => asde_t( $c[0] ), ImportadorProductos::COLUMNAS );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( '← Productos' ); ?></a></p>

<?php if ( $resultado ) : ?>
	<section class="asde-card asde-inv-importar" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php asde_e( 'Resultado de la carga' ); ?></h2>
		<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( '%1$s: %2$d fila leída.', '%1$s: %2$d filas leídas.', $resultado['filas'] ), $resultado['fichero'], $resultado['filas'] ) ); ?></p>
		<ul class="asde-resumen">
			<li><strong><?php echo (int) $resultado['productos_creados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['productos_creados'] ? asde_t( 'producto nuevo' ) : asde_t( 'productos nuevos' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['productos_actualizados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['productos_actualizados'] ? asde_t( 'producto actualizado' ) : asde_t( 'productos actualizados' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['variantes_creadas']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['variantes_creadas'] ? asde_t( 'variante nueva' ) : asde_t( 'variantes nuevas' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['variantes_actualizadas']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['variantes_actualizadas'] ? asde_t( 'variante actualizada' ) : asde_t( 'variantes actualizadas' ) ); ?></li>
			<li><strong><?php echo (int) count( $resultado['errores'] ); ?></strong> <?php echo esc_html( 1 === count( $resultado['errores'] ) ? asde_t( 'fila no cargada' ) : asde_t( 'filas no cargadas' ) ); ?></li>
		</ul>

		<?php if ( $resultado['errores'] ) : ?>
			<h3 class="asde-card__title"><?php asde_e( 'Filas que no se han cargado' ); ?></h3>
			<p class="asde-muted"><?php asde_e( 'Corrígelas y vuelve a cargar solo esas filas (las demás ya están en la tienda). Puedes descargarlas en un CSV con el motivo de cada una.' ); ?></p>
			<table class="asde-table asde-table--inv-errores">
				<thead>
					<tr>
						<th scope="col" class="asde-num"><?php asde_e( 'Fila' ); ?></th>
						<th scope="col"><?php asde_e( 'Producto' ); ?></th>
						<th scope="col"><?php asde_e( 'Motivo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultado['errores'] as $e ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Fila' ) ); ?>" class="asde-num"><?php echo (int) $e['fila']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Producto' ) ); ?>"><?php echo '' !== $e['datos']['producto'] ? esc_html( mb_strimwidth( $e['datos']['producto'], 0, 80, '…' ) ) : '<span class="asde-muted">' . esc_html( asde_t( '(vacía)' ) ) . '</span>'; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Motivo' ) ); ?>"><?php echo esc_html( $e['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-form__acciones">
				<a class="button no-transition" href="<?php echo esc_url( $url_errores ); ?>" download><?php asde_e( 'Descargar las filas con error (CSV)' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'Cargar otro fichero' ); ?></a>
			</p>
		<?php else : ?>
			<p><?php asde_e( 'Todas las filas se han cargado.' ); ?></p>
			<p class="asde-form__acciones"><a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'Cargar otro fichero' ); ?></a></p>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card asde-inv-importar" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php asde_e( 'Cargar productos desde un CSV' ); ?></h2>
		<?php if ( $caducado ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El informe de esa carga ya no está disponible (se guarda una hora).' ); ?></div>
		<?php endif; ?>
		<p><?php asde_e( 'Prepara los productos en una hoja de cálculo, guárdala como CSV y súbela aquí. Cada fila es una variante (una talla, un color…); varias filas con el mismo producto son variantes del mismo producto. Primero se comprueba el fichero; después se cargan las filas correctas y verás las que no, con el motivo.' ); ?></p>
		<p>
			<a class="no-transition" href="<?php echo esc_url( $url_ejemplo ); ?>" download><?php asde_e( 'Descargar un fichero de ejemplo' ); ?></a>
			<span class="asde-muted"><?php asde_e( '(ábrelo con Excel o LibreOffice, sustituye las filas de ejemplo por tus productos y guárdalo como CSV)' ); ?></span>
		</p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
			<?php wp_nonce_field( 'asde_tienda_importar' ); ?>
			<input type="hidden" name="asde_action" value="productos_importar">
			<p>
				<label for="asde-tp-fichero"><?php asde_e( 'Fichero CSV' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="file" id="asde-tp-fichero" name="fichero" accept=".csv,text/csv,text/plain" required>
			</p>
			<label class="asde-choice"><input type="checkbox" name="sumar" value="1"><span><?php asde_e( 'Sumar las existencias a las que ya hay (si no, las existencias del fichero sustituyen a las actuales)' ); ?></span></label>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php asde_e( 'Cargar' ); ?></button>
			</p>
		</form>
		<details class="asde-inv-columnas">
			<summary><?php asde_e( 'Cómo tiene que ser el fichero' ); ?></summary>
			<ul>
				<li><?php echo esc_html( sprintf( asde_t( 'La primera fila, los nombres de las columnas: %s. En cualquier orden; las que no uses se pueden quitar.' ), implode( ', ', $columnas ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( asde_t( 'Obligatoria: %s.' ), implode( ', ', array_map( static fn( $c ) => asde_t( ImportadorProductos::COLUMNAS[ $c ][0] ), ImportadorProductos::OBLIGATORIAS ) ) ) ); ?></li>
				<li><?php asde_e( 'Visibilidad: «todos» o «socios». El precio, con coma o punto para los decimales (12,50); vacío = precio por confirmar. Existencias: un número entero o «ilimitado».' ); ?></li>
				<li><?php asde_e( 'Un producto que ya existe (mismo nombre) se actualiza solo con las celdas rellenas: una celda vacía no borra nada. Una variante que ya existe cambia sus existencias.' ); ?></li>
				<li><?php echo esc_html( sprintf( asde_t( 'Separado por punto y coma o por comas, de hasta %1$d MB y %2$d filas.' ), (int) ( ImportadorProductos::MAX_BYTES / MB_IN_BYTES ), ImportadorProductos::MAX_FILAS ) ); ?></li>
			</ul>
		</details>
	</section>
<?php endif; ?>
