<?php
/**
 * Gestión de accesos · los accesos de una persona (v0.53, doc 56): lo que le da su rol, lo que tiene de más y lo que se le puede dar.
 *
 * @var WP_User                $user
 * @var string                 $nombre  Del CRM ('' si no se ha podido leer)
 * @var string                 $roles   Etiquetas de sus roles
 * @var array[]                $mapa    permiso => origen ('rol'|'extra'), caduca, id, motivo, por
 * @var array<int, string>     $nombres user_id => nombre
 * @var array<string, string>  $sin     permiso => lo que le falta antes para poder dárselo
 * @var bool                   $soy_yo  Es quien está mirando
 * @var array<string, string>  $modulos
 * @var string                 $hoy     Y-m-d
 * @var string                 $url     Esta persona
 * @var string                 $volver  El listado
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Accesos\Catalogo;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
?>
<section class="asde-card" data-asde-no-plegable>
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php echo esc_html( '' !== $nombre ? $nombre : $user->user_login ); ?></h2>
	</header>
	<p><?php echo esc_html( $roles ); ?><?php echo '' !== $nombre ? ' · <span class="asde-muted">' . esc_html( $user->user_login ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?> · <a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( 'Volver al listado' ); ?></a></p>
	<?php if ( $soy_yo ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Esta eres tú: puedes ver tus accesos y quitarte permisos sueltos, pero no concedértelos. Que lo haga otra persona de Coordinación, Secretaría o Tesorería.' ); ?></div>
	<?php endif; ?>
</section>

<?php foreach ( $modulos as $modulo => $nombre_modulo ) : ?>
	<section class="asde-card" data-asde-plegable="accesos-persona-<?php echo esc_attr( $modulo ); ?>" data-asde-inicio="abierto">
		<header class="asde-card__header">
			<h2 class="asde-card__title"><?php echo esc_html( asde_t( $nombre_modulo ) ); ?></h2>
		</header>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Permiso' ); ?></th>
					<th scope="col"><?php asde_e( 'De dónde le viene' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Catalogo::del_modulo( $modulo ) as $p ) : ?>
					<?php $t = $mapa[ $p ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Permiso' ) ); ?>">
							<?php echo esc_html( Catalogo::descripcion( $p ) ); ?>
							<span class="asde-muted">· <?php echo Catalogo::VER === Catalogo::tipo( $p ) ? esc_html( asde_t( 'ver' ) ) : esc_html( asde_t( 'acción' ) ); ?></span>
							<?php if ( Catalogo::sensible( $p ) ) : ?>
								<span class="asde-badge asde-badge--sensible"><?php asde_e( 'Sensible' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'De dónde le viene' ) ); ?>">
							<?php if ( ! $t ) : ?>
								<span class="asde-muted">—</span>
							<?php elseif ( 'rol' === $t['origen'] ) : ?>
								<?php asde_e( 'Por su rol' ); ?>
							<?php else : ?>
								<strong><?php asde_e( 'Suelto' ); ?></strong>
								<?php echo $t['caduca'] ? esc_html( sprintf( asde_t( 'hasta el %s' ), $fecha( $t['caduca'] ) ) ) : esc_html( asde_t( 'sin caducidad' ) ); ?>
								<br><span class="asde-muted">
									<?php
									echo esc_html( sprintf( asde_t( 'Lo concedió %s' ), $nombres[ $t['por'] ] ?? ( get_userdata( $t['por'] ) ? get_userdata( $t['por'] )->user_login : '—' ) ) );
									echo '' !== $t['motivo'] ? ' · ' . esc_html( $t['motivo'] ) : '';
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( $t && 'extra' === $t['origen'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Quitar este permiso?' ) ); ?>">
									<?php wp_nonce_field( 'asde_accesos_retirar_' . $t['id'] ); ?>
									<input type="hidden" name="asde_action" value="accesos_retirar">
									<input type="hidden" name="usuario" value="<?php echo (int) $user->ID; ?>">
									<input type="hidden" name="concesion" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="button"><?php asde_e( 'Quitar' ); ?></button>
								</form>
							<?php elseif ( ! $t && ! $soy_yo && isset( $sin[ $p ] ) ) : ?>
								<span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Antes necesita: %s' ), $sin[ $p ] ) ); ?></span>
							<?php elseif ( ! $t && ! $soy_yo ) : ?>
								<details class="asde-acc-conceder">
									<summary><?php asde_e( 'Conceder' ); ?></summary>
									<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta" data-asde-accesos-conceder>
										<?php wp_nonce_field( 'asde_accesos_conceder_' . $user->ID ); ?>
										<input type="hidden" name="asde_action" value="accesos_conceder">
										<input type="hidden" name="usuario" value="<?php echo (int) $user->ID; ?>">
										<input type="hidden" name="permiso" value="<?php echo esc_attr( $p ); ?>">
										<p class="asde-form__fila">
											<label><?php asde_e( 'Vale' ); ?>
												<select name="caduca_tipo" data-asde-acc-tipo>
													<option value="sin"><?php asde_e( 'Sin caducidad' ); ?></option>
													<option value="fecha"><?php asde_e( 'Hasta una fecha' ); ?></option>
												</select>
											</label>
											<label data-asde-acc-fecha><?php asde_e( 'Fecha' ); ?> <input type="date" name="caduca" min="<?php echo esc_attr( $hoy ); ?>"></label>
											<label><?php asde_e( 'Motivo' ); ?> <input type="text" name="motivo" maxlength="255"<?php echo Catalogo::sensible( $p ) ? ' required' : ''; ?>></label>
											<button type="submit" class="button button-primary"><?php asde_e( 'Conceder' ); ?></button>
										</p>
									</form>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endforeach; ?>
