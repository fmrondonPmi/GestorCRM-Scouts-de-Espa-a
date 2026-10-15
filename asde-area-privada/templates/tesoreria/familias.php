<?php
/**
 * Familias: listado, sugerencias y socios sin familia.
 *
 * @var array<int, array{id:int, numero:string, miembros:string[]}> $familias Las que encajan con el buscador (todas, sin buscar)
 * @var int                                                        $total    Todas las familias del grupo
 * @var string                                                     $q        Lo buscado (v0.34)
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>      $personas
 * @var array<int, string>                                         $cuentas  familia_id => usuario de la cuenta familiar
 * @var AsdeBalears\AreaPrivada\Domain\Persona[][]                 $sugerencias
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]                   $sin_fam  Educandos activos sin familia
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]                   $sin_fam_adultos Scouters/voluntariado sin familia
 * @var string                                                     $siguiente
 * @var string                                                     $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$fila_persona = static function ( $p ) {
	$s = $p->secciones ? Secciones::badge( $p->secciones[0] ) : '';
	return esc_html( $p->nombre_completo() ) . \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) . ' ' . $s . ( null !== $p->edad() ? ' <span class="asde-muted">' . esc_html( sprintf( asde_t( '%d años' ), $p->edad() ) ) . '</span>' : '' );
};
?>
<section class="asde-card">
	<h2><?php asde_e( 'Familias' ); ?></h2>
	<p><?php asde_e( 'Una familia agrupa a hermanos (y, si se quiere, a sus padres scouters) para calcular la cuota con descuento por hermanos. Su número (el próximo será' ); ?> <strong><?php echo esc_html( $siguiente ); ?></strong><?php asde_e( ') sirve de referencia en las transferencias. En la web solo se guardan números de socio: los nombres se leen del CRM.' ); ?></p>
</section>

<?php if ( $sugerencias ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Sugerencias (' ); ?><?php echo (int) count( $sugerencias ); ?>)</h3>
		<p class="asde-help"><?php asde_e( 'Educandos sin familia que comparten algún email en el CRM. Revisa y confirma cada una.' ); ?></p>
		<?php foreach ( $sugerencias as $grupo ) : ?>
			<form method="post" class="asde-sugerencia">
				<?php wp_nonce_field( 'asde_familias' ); ?>
				<input type="hidden" name="asde_action" value="crear">
				<ul>
					<?php foreach ( $grupo as $p ) : ?>
						<li>
							<label class="asde-choice">
								<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>" checked>
								<span><?php echo $fila_persona( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="submit" class="button"><?php asde_e( 'Crear familia' ); ?></button>
			</form>
		<?php endforeach; ?>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3><?php asde_e( 'Familias (' ); ?><?php echo (int) $total; ?>)</h3>
	<?php if ( ! $total ) : ?>
		<p><?php asde_e( 'Todavía no hay familias. Empieza por las sugerencias o crea una con los socios sin familia de abajo.' ); ?></p>
	<?php else : ?>
		<?php /* v0.34: buscador como el de Personas (parcial.js: solo cambia la lista, y busca al escribir desde la 3.ª letra). */ ?>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="familias-lista" role="search">
			<span class="asde-filter__buscar">
				<label for="asde-q-familias" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
				<input type="search" id="asde-q-familias" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nº de familia, nombre o nº de socio' ) ); ?>" data-asde-buscar autocomplete="off">
				<button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button>
			</span>
		</form>
		<div data-asde-parcial="familias-lista">
		<?php if ( ! $familias ) : ?>
			<p><?php echo esc_html( sprintf( asde_t( 'Ninguna familia coincide con «%s».' ), $q ) ); ?></p>
		<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Nº familia' ); ?></th>
					<th scope="col"><?php asde_e( 'Socios' ); ?></th>
					<th scope="col"><?php asde_e( 'Cuenta familiar' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $familias as $id => $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Nº familia' ) ); ?>"><strong><?php echo esc_html( $f['numero'] ); ?></strong></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Socios' ) ); ?>">
							<?php if ( ! $f['miembros'] ) : ?>
								<span class="asde-muted"><?php asde_e( 'Sin socios' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $f['miembros'] as $n ) : ?>
								<?php $p = $personas[ $n ] ?? null; ?>
								<span class="asde-miembro<?php echo ( ! $p || ! $p->tiene_membresia_activa() ) ? ' asde-miembro--fuera' : ''; ?>"><?php echo $p ? $fila_persona( $p ) : esc_html( 'Nº ' . $n ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<?php endforeach; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cuenta familiar' ) ); ?>"><?php echo $cuentas[ $id ] ? esc_html( $cuentas[ $id ] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label=""><a class="button" href="<?php echo esc_url( add_query_arg( 'familia', $id, $url ) ); ?>"><?php asde_e( 'Editar' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php if ( '' !== $q ) : ?>
			<p class="asde-muted"><?php echo esc_html( sprintf( asde_t( '%1$d de %2$d familias' ), count( $familias ), $total ) ); ?></p>
		<?php endif; ?>
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Educandos sin familia (' ); ?><?php echo (int) count( $sin_fam ); ?>)</h3>
	<?php if ( ! $sin_fam ) : ?>
		<p><?php asde_e( 'Todos los educandos con membresía activa tienen familia. 👍' ); ?></p>
	<?php else : ?>
		<p class="asde-help"><?php asde_e( 'No se les calcula cuota hasta que estén en una familia. Marca los que forman una familia y créala.' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="crear">
			<div class="asde-columnas">
				<?php foreach ( $sin_fam as $p ) : ?>
					<label class="asde-choice">
						<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
						<span><?php echo $fila_persona( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php if ( $sin_fam_adultos ) : ?>
				<details>
					<summary><?php asde_e( 'Añadir también a un scouter o voluntario (p. ej. el padre o la madre)' ); ?></summary>
					<div class="asde-columnas">
						<?php foreach ( $sin_fam_adultos as $p ) : ?>
							<label class="asde-choice">
								<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
								<span><?php echo $fila_persona( $p ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>
			<p><button type="submit" class="button"><?php asde_e( 'Crear familia con los marcados' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
