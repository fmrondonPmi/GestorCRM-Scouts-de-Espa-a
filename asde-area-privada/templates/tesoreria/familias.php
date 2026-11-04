<?php
/**
 * Familias: listado, sugerencias y socios sin familia.
 *
 * @var array<int, array{id:int, numero:string, miembros:string[]}> $familias Las que encajan con el buscador (todas, sin buscar)
 * @var int                                                        $total    Todas las familias del grupo
 * @var string                                                     $q        Lo buscado (v0.34)
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>      $personas
 * @var array<int, string>                                         $cuentas  familia_id => usuario de la cuenta familiar
 * @var array<int, string>                                         $borrar   Familias sin socios: familia_id => '' si se puede eliminar, o el motivo (v0.35.1)
 * @var AsdeBalears\AreaPrivada\Domain\Persona[][]                 $sugerencias
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]                   $sin_fam  Educandos activos sin familia
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]                   $sin_fam_adultos Scouters/voluntariado sin familia
 * @var string                                                     $siguiente
 * @var string                                                     $url
 * @var string                                                     $url_mov  Tesorería: de ahí sale la ventana con los movimientos de una familia (v0.62.1)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$fila_persona = static function ( $p ) {
	$s = $p->secciones ? Secciones::badge( $p->secciones[0] ) : '';
	return esc_html( $p->nombre_completo() ) . \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) . ' ' . $s . ( null !== $p->edad() ? ' <span class="asde-muted">' . esc_html( sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->edad() ) ) . '</span>' : '' );
};
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'A family groups siblings (and, if wanted, their scouter parents) to calculate the fee with a sibling discount. Its number (the next will be', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $siguiente ); ?></strong><?php esc_html_e( ') serves as a reference in transfers. Only member numbers are stored on the website: names are read from the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</section>

<?php if ( $sugerencias ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Suggestions (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) count( $sugerencias ); ?>)</h3>
		<p class="asde-help"><?php esc_html_e( 'Youth members without a family who share an email in the CRM. Review and confirm each one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php foreach ( $sugerencias as $grupo ) : ?>
			<form method="post" class="asde-sugerencia">
				<?php wp_nonce_field( 'asde_familias' ); ?>
				<input type="hidden" name="asde_action" value="crear">
				<ul>
					<?php foreach ( $grupo as $p ) : ?>
						<li>
							<label class="asde-choice">
								<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>" checked>
								<span><?php echo wp_kses( $fila_persona( $p ), asde_html_permitido() ); ?></span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="submit" class="button"><?php esc_html_e( 'Create family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php endforeach; ?>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3><?php esc_html_e( 'Families (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) $total; ?>)</h3>
	<?php if ( ! $total ) : ?>
		<p><?php esc_html_e( 'There are no families yet. Start with the suggestions or create one with the members without a family below.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<?php /* v0.34: buscador como el de Personas (parcial.js: solo cambia la lista, y busca al escribir desde la 3.ª letra). */ ?>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="familias-lista" role="search">
			<span class="asde-filter__buscar">
				<label for="asde-q-familias" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="search" id="asde-q-familias" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Family no., name or member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-buscar autocomplete="off">
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</span>
		</form>
		<div data-asde-parcial="familias-lista">
		<?php if ( ! $familias ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'No family matches "%s".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $q ) ); ?></p>
		<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Family no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Family account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $familias as $id => $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php // v0.62.1: el nº de familia abre en una ventana sus movimientos. ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'familia' => $id, 'movimientos' => '1' ), $url_mov ) ); ?>" data-asde-modal title="<?php echo esc_attr( esc_attr__( 'See the family\'s transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $f['numero'] ); ?></strong></a></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( ! $f['miembros'] ) : ?>
								<span class="asde-muted"><?php esc_html_e( 'No members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $f['miembros'] as $n ) : ?>
								<?php $p = $personas[ $n ] ?? null; ?>
								<span class="asde-miembro<?php echo ( ! $p || ! $p->tiene_membresia_activa() ) ? ' asde-miembro--fuera' : ''; ?>"><?php echo wp_kses( $p ? $fila_persona( $p ) : esc_html( 'Nº ' . $n ), asde_html_permitido() ); ?></span>
							<?php endforeach; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $cuentas[ $id ] ? esc_html( $cuentas[ $id ] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="">
							<a class="button" href="<?php echo esc_url( add_query_arg( 'familia', $id, $url ) ); ?>"><?php esc_html_e( 'Edit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php if ( isset( $borrar[ $id ] ) && '' === $borrar[ $id ] ) : ?>
								<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Delete family %s? It has no members. This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['numero'] ) ); ?>">
									<?php wp_nonce_field( 'asde_familias' ); ?>
									<input type="hidden" name="asde_action" value="borrar">
									<input type="hidden" name="familia" value="<?php echo (int) $id; ?>">
									<input type="hidden" name="desde" value="lista">
									<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Delete', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php elseif ( isset( $borrar[ $id ] ) ) : ?>
								<span class="asde-help"><?php echo esc_html( $borrar[ $id ] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php if ( '' !== $q ) : ?>
			<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( '%1$d of %2$d families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $familias ), $total ) ); ?></p>
		<?php endif; ?>
		</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Youth members without a family (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) count( $sin_fam ); ?>)</h3>
	<?php if ( ! $sin_fam ) : ?>
		<p><?php esc_html_e( 'All youth members with an active membership have a family. 👍', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<p class="asde-help"><?php esc_html_e( 'No fee is calculated for them until they are in a family. Tick those who form a family and create it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="crear">
			<div class="asde-columnas">
				<?php foreach ( $sin_fam as $p ) : ?>
					<label class="asde-choice">
						<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
						<span><?php echo wp_kses( $fila_persona( $p ), asde_html_permitido() ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php if ( $sin_fam_adultos ) : ?>
				<details>
					<summary><?php esc_html_e( 'Also add a scouter or volunteer (e.g. the father or mother)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<div class="asde-columnas">
						<?php foreach ( $sin_fam_adultos as $p ) : ?>
							<label class="asde-choice">
								<input type="checkbox" name="socios[]" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
								<span><?php echo wp_kses( $fila_persona( $p ), asde_html_permitido() ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Create a family with the ticked ones', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
