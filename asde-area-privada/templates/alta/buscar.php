<?php
/**
 * Alta de usuarios · búsqueda de socios activos en el CRM.
 *
 * @var string                                   $q
 * @var AsdeBalears\AreaPrivada\Domain\Persona[] $resultados
 * @var array<string, array{numero:?string, estado:string}> $numeros nº Scouts de España => nº local
 * @var array<string, WP_User>                   $usuarios nº Scouts de España => usuario web
 * @var string                                   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<h2><?php asde_e( 'Dar acceso' ); ?></h2>
	<p><?php asde_e( 'Busca al socio en el CRM del grupo (solo aparecen socios con membresía activa). Desde su ficha podrás darle acceso a él o ella, o a su familia.' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-search" role="search">
		<label for="asde-q" class="screen-reader-text"><?php asde_e( 'Buscar socio' ); ?></label>
		<input type="search" id="asde-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre y apellidos, nº de socio, email o DNI' ) ); ?>" minlength="3" required>
		<button type="submit" class="button"><?php asde_e( 'Buscar' ); ?></button>
	</form>
</section>

<?php if ( '' !== $q && mb_strlen( $q ) < 3 ) : ?>
	<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Escribe al menos 3 caracteres.' ); ?></div>
<?php elseif ( '' !== $q ) : ?>
	<section class="asde-card" aria-live="polite">
		<?php if ( ! $resultados ) : ?>
			<p><?php asde_e( 'No hay ningún socio con membresía activa que coincida con «' ); ?><?php echo esc_html( $q ); ?>».</p>
		<?php else : ?>
			<p class="asde-muted">
				<?php echo esc_html( sprintf( asde_n( '%d socio encontrado', '%d socios encontrados', count( $resultados ) ), count( $resultados ) ) ); ?>
				<?php echo count( $resultados ) >= 30 ? esc_html( asde_t( ' (se muestran los 30 primeros: afina la búsqueda)' ) ) : ''; ?>
			</p>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Nombre' ); ?></th>
						<th scope="col"><?php asde_e( 'Nº socio/a' ); ?></th>
						<th scope="col"><?php asde_e( 'Sección' ); ?></th>
						<th scope="col"><?php asde_e( 'Edad' ); ?></th>
						<th scope="col"><?php asde_e( 'Acceso web' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultados as $p ) : ?>
						<?php
						$u = $usuarios[ $p->num_socio_asde ] ?? null;
						$n = $numeros[ $p->num_socio_asde ] ?? array( 'numero' => null, 'estado' => 'falta' );
						?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>"><strong><?php echo esc_html( $p->nombre_completo() ); ?></strong></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Nº socio/a' ) ); ?>"><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::html( $p, $n ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Sección' ) ); ?>">
								<?php
								foreach ( $p->secciones as $s ) {
									echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML escapado en badge().
								}
								?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Edad' ) ); ?>"><?php echo null === $p->edad() ? '—' : esc_html( sprintf( asde_t( '%d años' ), $p->edad() ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Acceso web' ) ); ?>">
								<?php if ( $u ) : ?>
									<?php foreach ( Usuarios::roles_asde( $u ) as $rol ) : ?>
										<span class="asde-badge asde-badge--rol"><?php echo esc_html( Roles::label( $rol ) ); ?></span>
									<?php endforeach; ?>
									<span class="asde-muted"><?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $u ) ) ); ?></span>
								<?php else : ?>
									<span class="asde-muted"><?php asde_e( 'Sin usuario' ); ?></span>
								<?php endif; ?>
								<?php if ( Usuarios::tutores_de( $p->num_socio_asde ) ) : ?>
									<br><span class="asde-muted"><?php asde_e( 'Con cuenta familiar' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="">
								<a class="button" href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url ) ); ?>" data-asde-modal><?php echo $u ? esc_html( asde_t( 'Gestionar acceso' ) ) : esc_html( asde_t( 'Dar acceso' ) ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endif; ?>
