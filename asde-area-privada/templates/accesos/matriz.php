<?php
/**
 * Gestión de accesos · permisos por rol (v0.53, doc 56): matriz rol × permiso, agrupada por módulo.
 *
 * @var array<string, array{nombre:string, tiene:array<string,bool>, fabrica:array<string,bool>}> $roles Los roles de gestión y sus permisos
 * @var array<string, string>   $modulos  módulo => nombre
 * @var array<string, string[]> $catalogo permiso => permisos de los que basta uno (para el navegador)
 * @var string[]                $mis      Roles de gestión que tiene quien mira (a esos no se les puede añadir nada)
 * @var string                  $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Accesos\Catalogo;

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-accesos" data-asde-accesos="<?php echo esc_attr( wp_json_encode( $catalogo ) ); ?>">
	<?php wp_nonce_field( 'asde_accesos_matriz' ); ?>
	<input type="hidden" name="asde_action" value="accesos_matriz">
	<?php foreach ( array_keys( $roles ) as $rol ) : ?>
		<input type="hidden" name="roles[]" value="<?php echo esc_attr( $rol ); ?>">
	<?php endforeach; ?>

	<section class="asde-card" data-asde-no-plegable>
		<header class="asde-card__header">
			<h2 class="asde-card__title"><?php asde_e( 'Permisos por rol' ); ?></h2>
		</header>
		<p><?php asde_e( 'Cada rol trae estos permisos. Marca o desmarca y guarda: vale para todas las personas con ese rol. Los permisos de acción necesitan el de ver de su módulo.' ); ?>
			<span class="asde-badge asde-badge--sensible"><?php asde_e( 'Sensible' ); ?></span> <?php asde_e( 'abre datos de salud, dinero o cuentas de acceso.' ); ?>
			<span class="asde-acc-cambiado" aria-hidden="true">●</span> <?php asde_e( 'cambiado respecto a los permisos de fábrica.' ); ?></p>
		<?php if ( $mis ) : ?>
			<p class="asde-help"><?php asde_e( 'En tu propio rol solo puedes quitar permisos: añadírtelos tendría que hacerlo otra persona.' ); ?></p>
		<?php endif; ?>
	</section>

	<?php foreach ( $modulos as $modulo => $nombre_modulo ) : ?>
		<section class="asde-card" data-asde-plegable="accesos-matriz-<?php echo esc_attr( $modulo ); ?>" data-asde-inicio="cerrado">
			<header class="asde-card__header">
				<h2 class="asde-card__title"><?php echo esc_html( asde_t( $nombre_modulo ) ); ?></h2>
			</header>
			<table class="asde-table asde-table--matriz">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Permiso' ); ?></th>
						<?php foreach ( $roles as $rol => $r ) : ?>
							<th scope="col"><?php echo esc_html( $r['nombre'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Catalogo::del_modulo( $modulo ) as $p ) : ?>
						<tr>
							<th scope="row" data-label="<?php echo esc_attr( asde_a( 'Permiso' ) ); ?>">
								<?php echo esc_html( Catalogo::descripcion( $p ) ); ?>
								<span class="asde-muted">· <?php echo Catalogo::VER === Catalogo::tipo( $p ) ? esc_html( asde_t( 'ver' ) ) : esc_html( asde_t( 'acción' ) ); ?></span>
								<?php if ( Catalogo::sensible( $p ) ) : ?>
									<span class="asde-badge asde-badge--sensible"><?php asde_e( 'Sensible' ); ?></span>
								<?php endif; ?>
							</th>
							<?php foreach ( $roles as $rol => $r ) : ?>
								<?php
								$marcado  = ! empty( $r['tiene'][ $p ] );
								$cambiado = $marcado !== ! empty( $r['fabrica'][ $p ] );
								$bloqueo  = ! $marcado && in_array( $rol, $mis, true );
								?>
								<td data-label="<?php echo esc_attr( $r['nombre'] ); ?>"<?php echo $cambiado ? ' class="is-cambiado"' : ''; ?>>
									<label>
										<input type="checkbox" name="perm[<?php echo esc_attr( $rol ); ?>][<?php echo esc_attr( $p ); ?>]" value="1"
											data-asde-acc-rol="<?php echo esc_attr( $rol ); ?>" data-asde-acc-permiso="<?php echo esc_attr( $p ); ?>"
											<?php checked( $marcado ); ?><?php echo $bloqueo ? ' disabled title="' . esc_attr( asde_a( 'Es tu rol: no puedes añadirte permisos' ) ) . '"' : ''; ?>>
										<span class="screen-reader-text"><?php echo esc_html( $r['nombre'] . ': ' . Catalogo::descripcion( $p ) ); ?></span>
										<?php if ( $cambiado ) : ?><span class="asde-acc-cambiado" aria-hidden="true" title="<?php echo esc_attr( asde_a( 'Cambiado respecto a los permisos de fábrica' ) ); ?>">●</span><?php endif; ?>
									</label>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endforeach; ?>

	<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Guardar los permisos' ); ?></button></p>
</form>

<section class="asde-card" data-asde-plegable="accesos-restaurar" data-asde-inicio="cerrado">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php asde_e( 'Volver a los permisos de fábrica' ); ?></h2>
	</header>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Devolver este rol a sus permisos de fábrica? Se pierden los cambios que se hicieron en él.' ) ); ?>">
		<?php wp_nonce_field( 'asde_accesos_restaurar' ); ?>
		<input type="hidden" name="asde_action" value="accesos_restaurar">
		<label for="asde-acc-restaurar"><?php asde_e( 'Rol' ); ?></label>
		<select id="asde-acc-restaurar" name="rol">
			<?php foreach ( $roles as $rol => $r ) : ?>
				<option value="<?php echo esc_attr( $rol ); ?>"><?php echo esc_html( $r['nombre'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Restaurar' ); ?></button>
	</form>
</section>
