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
	<p><?php echo esc_html( $roles ); ?><?php echo wp_kses( '' !== $nombre ? ' · <span class="asde-muted">' . esc_html( $user->user_login ) . '</span>' : '', asde_html_permitido() ); ?> · <a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Back to the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php if ( $soy_yo ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'This is you: you can see your access and remove individual permissions, but not grant them to yourself. Another person from Coordination, the Secretary\'s office or Treasury must do it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
</section>

<?php foreach ( $modulos as $modulo => $nombre_modulo ) : ?>
	<section class="asde-card" data-asde-plegable="accesos-persona-<?php echo esc_attr( $modulo ); ?>" data-asde-inicio="cerrado">
		<header class="asde-card__header">
			<h2 class="asde-card__title"><?php echo esc_html( asde_guardado( $nombre_modulo ) ); ?></h2>
		</header>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Permission', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where it comes from', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Catalogo::del_modulo( $modulo ) as $p ) : ?>
					<?php $t = $mapa[ $p ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Permission', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( Catalogo::descripcion( $p ) ); ?>
							<span class="asde-muted">· <?php echo Catalogo::VER === Catalogo::tipo( $p ) ? esc_html( __( 'view', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'action', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span>
							<?php if ( Catalogo::sensible( $p ) ) : ?>
								<span class="asde-badge asde-badge--sensible"><?php esc_html_e( 'Sensitive', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Where it comes from', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( ! $t ) : ?>
								<span class="asde-muted">—</span>
							<?php elseif ( 'rol' === $t['origen'] ) : ?>
								<?php esc_html_e( 'Through their role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'Individual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
								<?php echo $t['caduca'] ? esc_html( sprintf( /* translators: %s: text */ __( 'until %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $t['caduca'] ) ) ) : esc_html( __( 'no expiry', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
								<br><span class="asde-muted">
									<?php
									echo esc_html( sprintf( /* translators: %s: text */ __( 'Granted by %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombres[ $t['por'] ] ?? ( get_userdata( $t['por'] ) ? get_userdata( $t['por'] )->user_login : '—' ) ) );
									echo '' !== $t['motivo'] ? ' · ' . esc_html( $t['motivo'] ) : '';
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( $t && 'extra' === $t['origen'] ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( __( 'Remove this permission?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php wp_nonce_field( 'asde_accesos_retirar_' . $t['id'] ); ?>
									<input type="hidden" name="asde_action" value="accesos_retirar">
									<input type="hidden" name="usuario" value="<?php echo (int) $user->ID; ?>">
									<input type="hidden" name="concesion" value="<?php echo (int) $t['id']; ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php elseif ( ! $t && ! $soy_yo && isset( $sin[ $p ] ) ) : ?>
								<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'It first needs: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $sin[ $p ] ) ); ?></span>
							<?php elseif ( ! $t && ! $soy_yo ) : ?>
								<details class="asde-acc-conceder">
									<summary><?php esc_html_e( 'Grant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta" data-asde-accesos-conceder>
										<?php wp_nonce_field( 'asde_accesos_conceder_' . $user->ID ); ?>
										<input type="hidden" name="asde_action" value="accesos_conceder">
										<input type="hidden" name="usuario" value="<?php echo (int) $user->ID; ?>">
										<input type="hidden" name="permiso" value="<?php echo esc_attr( $p ); ?>">
										<p class="asde-form__fila">
											<label><?php esc_html_e( 'OK', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
												<select name="caduca_tipo" data-asde-acc-tipo>
													<option value="sin"><?php esc_html_e( 'No expiry', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
													<option value="fecha"><?php esc_html_e( 'Until a date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
												</select>
											</label>
											<label data-asde-acc-fecha><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="caduca" min="<?php echo esc_attr( $hoy ); ?>"></label>
											<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255"<?php echo Catalogo::sensible( $p ) ? ' required' : ''; ?>></label>
											<button type="submit" class="button button-primary"><?php esc_html_e( 'Grant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
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
