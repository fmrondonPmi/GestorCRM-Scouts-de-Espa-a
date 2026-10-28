<?php
/**
 * Registro de emails del grupo.
 *
 * @var array[]                                $filas   Página que se muestra
 * @var int                                    $total
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var array<int, string>                     $nombres user_id => nombre (del CRM)
 * @var string                                 $estado  Filtro ('' = todos)
 * @var WP_User|null                           $usuario Filtro por usuario
 * @var string                                 $url
 * @var string                                 $mailpit URL de Mailpit (solo en local)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'fecha'   => __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'usuario' => __( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'tipo'    => __( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'estado'  => __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php esc_html_e( 'Email log', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	</header>
	<p><?php esc_html_e( 'Every invitation, password recovery or rejected-request notice sent by the private area, with its result.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'Sent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'means the mail server accepted it; if it does not arrive, they should check their spam folder or the Secretary\'s office should check the email in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php if ( $mailpit ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong><?php esc_html_e( 'Local environment:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'emails do not go out to the internet (so they do not reach real families). They are collected in Mailpit:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<?php if ( $usuario ) : ?>
			<input type="hidden" name="usuario" value="<?php echo (int) $usuario->ID; ?>">
		<?php endif; ?>
		<label for="asde-estado-email"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-estado-email" name="estado" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( ( RegistroEmails::estados() ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
		<?php if ( $usuario ) : ?>
			<span class="asde-muted"><?php esc_html_e( 'Only of the user', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $usuario->user_login ); ?></strong> · <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'see all', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
		<?php endif; ?>
	</form>

	<?php if ( ! $filas ) : ?>
		<p><?php esc_html_e( 'There are no recorded emails', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $estado || $usuario ? esc_html( __( ' with this filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'Sent to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $f['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<strong><?php echo '' !== $f['login'] ? esc_html( $f['login'] ) : '—'; ?></strong>
							<?php if ( isset( $nombres[ (int) $f['user_id'] ] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $nombres[ (int) $f['user_id'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( RegistroEmails::tipos()[ $f['tipo'] ] ?? $f['tipo'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-badge asde-badge--email-<?php echo esc_attr( $f['estado'] ); ?>"><?php echo esc_html( ( RegistroEmails::estados()[ $f['estado'] ] ?? $f['estado'] ) ); ?></span>
							<?php if ( '' !== $f['error'] ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( $f['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sent to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $f['destino'] ? esc_html( $f['destino'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label=""><a href="<?php echo esc_url( add_query_arg( 'ver', (int) $f['id'], $url ) ); ?>"><?php esc_html_e( 'View email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo wp_kses( $tabla->paginacion(), asde_html_permitido() ); ?>
	<?php endif; ?>
</section>
