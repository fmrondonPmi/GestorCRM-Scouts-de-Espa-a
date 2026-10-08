<?php
/**
 * Importar material desde un CSV (doc 32). Se abre en la ventana emergente desde
 * el inventario; sin JavaScript, en su página. Después de importar, el informe:
 * cuántos materiales entraron y qué filas no, con su motivo.
 *
 * @var array|null $resultado   importados, errores[{fila, datos, motivo}], tipos_creados, fichero, filas
 * @var bool       $caducado    Se pidió un informe que ya no está (caduca en una hora)
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ejemplo
 * @var string     $url_errores '' si no hay filas con error
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\Importador;

defined( 'ABSPATH' ) || exit;

$columnas = array_map( static fn( $c ) => asde_t( $c[0] ), Importador::COLUMNAS );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( '← Inventario' ); ?></a></p>

<?php if ( $resultado ) : ?>
	<section class="asde-card asde-inv-importar">
		<h2 class="asde-card__title"><?php asde_e( 'Resultado de la importación' ); ?></h2>
		<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( '%1$s: %2$d fila leída.', '%1$s: %2$d filas leídas.', $resultado['filas'] ), $resultado['fichero'], $resultado['filas'] ) ); ?></p>
		<ul class="asde-resumen">
			<li><strong><?php echo (int) $resultado['importados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['importados'] ? asde_t( 'material importado' ) : asde_t( 'materiales importados' ) ); ?></li>
			<li><strong><?php echo (int) count( $resultado['errores'] ); ?></strong> <?php echo esc_html( 1 === count( $resultado['errores'] ) ? asde_t( 'fila no importada' ) : asde_t( 'filas no importadas' ) ); ?></li>
		</ul>
		<?php if ( $resultado['tipos_creados'] ) : ?>
			<p><?php echo esc_html( sprintf( asde_t( 'Tipos de material nuevos: %s.' ), implode( ', ', $resultado['tipos_creados'] ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( $resultado['errores'] ) : ?>
			<h3 class="asde-card__title"><?php asde_e( 'Filas que no se han importado' ); ?></h3>
			<p class="asde-muted"><?php asde_e( 'Corrígelas y vuelve a importar solo esas filas (las demás ya están en el inventario). Puedes descargarlas en un CSV con el motivo de cada una.' ); ?></p>
			<table class="asde-table asde-table--inv-errores">
				<thead>
					<tr>
						<th scope="col" class="asde-num"><?php asde_e( 'Fila' ); ?></th>
						<th scope="col"><?php asde_e( 'Descripción' ); ?></th>
						<th scope="col"><?php asde_e( 'Motivo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultado['errores'] as $e ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Fila' ) ); ?>" class="asde-num"><?php echo (int) $e['fila']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Descripción' ) ); ?>"><?php echo '' !== $e['datos']['descripcion'] ? esc_html( mb_strimwidth( $e['datos']['descripcion'], 0, 80, '…' ) ) : '<span class="asde-muted">' . esc_html( asde_t( '(vacía)' ) ) . '</span>'; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Motivo' ) ); ?>"><?php echo esc_html( $e['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-form__acciones">
				<a class="button" href="<?php echo esc_url( $url_errores ); ?>" download><?php asde_e( 'Descargar las filas con error (CSV)' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'Importar otro fichero' ); ?></a>
			</p>
		<?php else : ?>
			<p><?php asde_e( 'Todas las filas se han importado.' ); ?></p>
			<p class="asde-form__acciones"><a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php asde_e( 'Importar otro fichero' ); ?></a></p>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card asde-inv-importar">
		<h2 class="asde-card__title"><?php asde_e( 'Importar material desde un CSV' ); ?></h2>
		<?php if ( $caducado ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El informe de esa importación ya no está disponible (se guarda una hora).' ); ?></div>
		<?php endif; ?>
		<p><?php asde_e( 'Prepara el material en una hoja de cálculo, guárdala como CSV y súbela aquí. Primero se comprueba el fichero; después se importan las filas correctas y verás las que no se hayan podido importar, con el motivo.' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $url_ejemplo ); ?>" download><?php asde_e( 'Descargar un fichero de ejemplo' ); ?></a>
			<span class="asde-muted"><?php asde_e( '(ábrelo con Excel o LibreOffice, sustituye las filas de ejemplo por tu material y guárdalo como CSV)' ); ?></span>
		</p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
			<?php wp_nonce_field( 'asde_inventario_importar' ); ?>
			<input type="hidden" name="asde_action" value="material_importar">
			<p>
				<label for="asde-inv-fichero"><?php asde_e( 'Fichero CSV' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="file" id="asde-inv-fichero" name="fichero" accept=".csv,text/csv,text/plain" required>
			</p>
			<label class="asde-choice"><input type="checkbox" name="crear_tipos" value="1" checked><span><?php asde_e( 'Crear los tipos de material que todavía no existan (si no, esas filas no se importan)' ); ?></span></label>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php asde_e( 'Importar' ); ?></button>
			</p>
		</form>
		<details class="asde-inv-columnas">
			<summary><?php asde_e( 'Cómo tiene que ser el fichero' ); ?></summary>
			<ul>
				<li><?php echo esc_html( sprintf( asde_t( 'La primera fila, los nombres de las columnas: %s. En cualquier orden; las que no uses se pueden quitar.' ), implode( ', ', $columnas ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( asde_t( 'Obligatorias: %s. La cantidad, un número entero de 0 en adelante.' ), implode( ', ', array_map( static fn( $c ) => asde_t( Importador::COLUMNAS[ $c ][0] ), Importador::OBLIGATORIAS ) ) ) ); ?></li>
				<li><?php asde_e( 'El precio, con coma o punto para los decimales (12,50). El año de compra, con cuatro cifras o en blanco.' ); ?></li>
				<li><?php asde_e( 'Un nº de serie que ya está en el inventario (o repetido en el fichero) no se vuelve a importar.' ); ?></li>
				<li><?php echo esc_html( sprintf( asde_t( 'Separado por punto y coma o por comas, de hasta %1$d MB y %2$d filas.' ), (int) ( Importador::MAX_BYTES / MB_IN_BYTES ), Importador::MAX_FILAS ) ); ?></li>
			</ul>
		</details>
	</section>
<?php endif; ?>
