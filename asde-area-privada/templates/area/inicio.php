<?php
/**
 * Inicio del área privada.
 *
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var string   $grupo
 * @var string[] $roles         Roles vigentes (etiquetas)
 * @var ?string  $num_local
 * @var bool     $pendiente_2fa
 * @var ?int     $limite_2fa
 * @var callable $url           fn( clave, args ) → URL de una página del área
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$user = $ambito->user;
?>
<?php if ( $pendiente_2fa ) : ?>
	<div class="asde-notice asde-notice--warning" role="status">
		<strong><?php esc_html_e( 'Turn on two-step verification.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
		<?php esc_html_e( 'It is mandatory for your role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo $limite_2fa && $limite_2fa > time() ? esc_html( ' ' . sprintf( /* translators: %s: text */ __( 'as of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', $limite_2fa ) ) ) : ''; ?>.
		<a href="<?php echo esc_url( $url( 'seguridad' ) ); ?>"><?php esc_html_e( 'Set it up now', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a> <?php esc_html_e( '(2 minutes, with an app such as Google Authenticator).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</div>
<?php endif; ?>

<section class="asde-card">
	<h2><?php esc_html_e( 'Hello,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ambito->nombre() ); ?></h2>
	<p><?php esc_html_e( 'Welcome to the private area of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $grupo ); ?></strong>.</p>
	<p>
		<?php esc_html_e( 'Your username:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong>
		<?php if ( \AsdeBalears\AreaPrivada\Usuarios\Usuarios::email_origen( $user->ID ) ) : ?>
			<span class="asde-help asde-help--inline"><?php esc_html_e( '(or the email address your invitation was sent to)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php endif; ?>
		<?php foreach ( $roles as $rol ) : ?>
			<span class="asde-badge asde-badge--rol"><?php echo esc_html( $rol ); ?></span>
		<?php endforeach; ?>
	</p>

	<?php if ( $ambito->persona && $ambito->socio_activo() ) : ?>
		<p>
			<?php esc_html_e( 'Your section:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			<?php
			foreach ( $ambito->persona->secciones as $s ) {
				echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
			}
			?>
			<?php if ( $ambito->secciones_scouter() ) : ?>
				<?php esc_html_e( '· You help in:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<?php
				foreach ( $ambito->secciones_scouter() as $s ) {
					echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
				}
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $ambito->representados ) : ?>
		<p><?php esc_html_e( 'Your family in the group:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<ul>
			<?php foreach ( $ambito->representados as $p ) : ?>
				<li>
					<?php echo esc_html( $p->nombre_completo() ); ?>
					<?php echo wp_kses( $p->seccion_educativa() ? Secciones::badge( $p->seccion_educativa() ) : '', asde_html_permitido() ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( ! empty( $por_confirmar ) ) : ?>
	<div class="asde-notice asde-notice--info" role="status">
		<strong><?php echo esc_html( 1 === (int) $por_confirmar ? __( 'The group has handed you a uniform order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( 'The group has handed you %d uniform orders.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $por_confirmar ) ); ?></strong>
		<a href="<?php echo esc_url( $url( 'tienda_pedidos' ) ); ?>"><?php esc_html_e( 'Confirm that you have received it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.
	</div>
<?php endif; ?>

<?php if ( array_filter( $eventos_pendientes ?? array() ) ) : ?>
	<?php $ep = $eventos_pendientes; ?>
	<div class="asde-notice asde-notice--info" role="status">
		<strong><?php esc_html_e( 'You have things pending in your events:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
		<ul>
			<?php if ( $ep['firmar'] ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d authorisation to sign', '%d authorisations to sign', (int) $ep['firmar'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $ep['firmar'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( $ep['plaza'] ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d freed-up place to confirm', '%d freed-up places to confirm', (int) $ep['plaza'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $ep['plaza'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( $ep['responder'] ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d invitation to answer', '%d invitations to answer', (int) $ep['responder'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $ep['responder'] ) ); ?></li>
			<?php endif; ?>
		</ul>
		<a href="<?php echo esc_url( $url( 'mis_eventos' ) ); ?>"><?php esc_html_e( 'Go to My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</div>
<?php endif; ?>

<?php if ( array_filter( $por_revisar ?? array() ) ) : ?>
	<?php
	$uni = static fn( string $estado ) => $url( 'tienda_gestion', array( 'estado' => $estado ) );
	$num = static fn( int $n, string $uno, string $varios ) => '<strong>' . $n . '</strong> ' . esc_html( 1 === $n ? $uno : $varios );
	?>
	<section class="asde-card asde-card--aviso">
		<h3 class="asde-card__title"><?php esc_html_e( 'Pending review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<ul class="asde-pendientes">
			<?php if ( $por_revisar['solicitudes'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'solicitudes' ) ); ?>"><strong><?php echo (int) $por_revisar['solicitudes']; ?></strong> <?php echo 1 === (int) $por_revisar['solicitudes'] ? esc_html( __( 'access request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'access requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['lista_espera'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'lista_espera', array( 'estado' => 'en_espera' ) ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['lista_espera'], esc_html( __( 'person on the waiting list not yet contacted', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'people on the waiting list not yet contacted', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( $por_revisar['cambios'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'cambios' ) ); ?>"><strong><?php echo (int) $por_revisar['cambios']; ?></strong> <?php echo 1 === (int) $por_revisar['cambios'] ? esc_html( __( 'change proposed by a family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'changes proposed by families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pago'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'sin_pagar' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['uniforme_pago'], esc_html( __( 'uniform order pending payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'uniform orders pending payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pedido'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_pedir' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['uniforme_pedido'], esc_html( __( 'uniform order to include in the supplier order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'uniform orders to include in the supplier order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['eventos_validar'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'eventos' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['eventos_validar'], esc_html( __( 'event budget to validate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'event budgets to validate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['eventos_papel'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'eventos' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['eventos_papel'], esc_html( __( 'event authorisation to collect on paper', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'event authorisations to collect on paper', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['eventos_tareas'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'eventos' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['eventos_tareas'], esc_html( __( 'event task overdue or due this week', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'event tasks overdue or due this week', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_entrega'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_entregar' ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['uniforme_entrega'], esc_html( __( 'uniform ordered from the supplier to deliver', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'uniforms ordered from the supplier to deliver', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['compras'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'inventario_compras', array( 'estado' => 'pendiente' ) ) ); ?>"><?php echo wp_kses( $num( (int) $por_revisar['compras'], esc_html( __( 'equipment shopping list awaiting your approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), esc_html( __( 'equipment shopping lists awaiting your approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ), asde_html_permitido() ); ?></a></li>
			<?php endif; ?>
		</ul>
	</section>
<?php endif; ?>

<?php
// v0.15: las pantallas de cada persona o familia y las de gestión del grupo van en bloques distintos.
$tile = static fn( string $href, string $titulo, string $texto ): array => array( $href, $titulo, $texto );

$mias = array();
if ( $ambito->persona ) {
	$mias[] = $tile( $url( 'ficha' ), __( 'My details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Your group record: view your details and update your email and phone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
foreach ( $ambito->representados as $n => $p ) {
	$mias[] = $tile( $url( 'ficha', array( 'socio' => $n ) ), sprintf( /* translators: %s: text */ __( '%s\'s record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre ), __( 'View their details and propose changes (the group reviews them).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( ! empty( $mis_eventos ) ) {
	$mias[] = $tile( $url( 'mis_eventos' ), __( 'My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Outings, camps and summer camps: sign-up, authorisation and what is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( ( $ambito->persona || $ambito->representados ) && current_user_can( 'asde_mis_pagos' ) ) {
	$mias[] = $tile( $url( 'pagos' ), __( 'My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'What Treasury has recorded: the group fee, its instalments and the uniform payments.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( ! empty( $pide_uniforme ) ) {
	$mias[] = $tile( $url( 'tienda_pedidos' ), ! empty( $gestiona_uniformes ) ? __( 'My orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Order the Scout shirt and badges, and follow your order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}

$grupo_tiles = array();
if ( ! empty( $eventos ) ) {
	$grupo_tiles[] = $tile( $url( 'eventos' ), __( 'Events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Outings, camps and summer camps: team, regulations and budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( ! empty( $gestiona_uniformes ) ) {
	$grupo_tiles[] = $tile( $url( 'tienda_gestion' ), __( 'Shop management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Payments, supplier order and deliveries of the group\'s shirts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_aprobar_solicitudes' ) ) {
	$grupo_tiles[] = $tile( $url( 'solicitudes' ), __( 'Access requests', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Approve the families and members who ask to join using the group\'s link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_approve_changes' ) ) {
	$grupo_tiles[] = $tile( $url( 'cambios' ), __( 'Proposed changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Review the changes families request on the records.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_reports' ) ) {
	$grupo_tiles[] = $tile( $url( 'informes' ), __( 'Reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ambito->vision_global() ? __( 'Lists of the whole group with the columns you choose.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Lists of your section with the columns you choose.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP ) ) {
	$grupo_tiles[] = $tile( $url( 'inventario' ), __( 'Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'The group\'s equipment and gear, and the shopping lists.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_lista_espera' ) ) {
	$grupo_tiles[] = $tile( $url( 'lista_espera' ), __( 'Waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Who wants to join the group, the section that corresponds to their age and their passage to the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
	$grupo_tiles[] = $tile( $url( 'tesoreria' ), __( 'Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Families\' fees, payments, families and federation cost.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( current_user_can( 'asde_manage_users' ) ) {
	$grupo_tiles[] = $tile( $url( 'personas' ), __( 'People in the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ambito->es_oficina() ? __( 'View the records of the people in the chosen group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Their records, giving them access and checking member numbers.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	// v0.25: «Dar acceso» y los usuarios del área privada, en una sola sección con sus hojas.
	$grupo_tiles[] = $tile( $url( 'usuarios' ), __( 'Users', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ambito->es_oficina() ? __( 'See who has access to the group\'s area.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Who has access, give access to a member or their family, invitations, withdrawals and the email log.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
// La seguridad de la cuenta es de cada persona: va con lo suyo, o con lo de gestión si no tiene ficha.
$cuenta = $tile( $url( 'seguridad' ), __( 'Account security', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Two-step verification.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
if ( $mias || ! $grupo_tiles ) {
	$mias[] = $cuenta;
} else {
	$grupo_tiles[] = $cuenta;
}
$con_titulos = $mias && $grupo_tiles;
$pintar      = static function ( array $tiles ): void {
	echo '<div class="asde-tiles">';
	foreach ( $tiles as list( $href, $titulo, $texto ) ) {
		echo '<a class="asde-tile" href="' . esc_url( $href ) . '"><strong>' . esc_html( $titulo ) . '</strong> ' . esc_html( $texto ) . '</a>';
	}
	echo '</div>';
};
?>
<?php if ( $mias ) : ?>
	<?php if ( $con_titulos ) : ?>
		<h3 class="asde-tiles__titulo"><?php echo $ambito->persona ? esc_html( __( 'For you', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'For your family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h3>
	<?php endif; ?>
	<?php $pintar( $mias ); ?>
<?php endif; ?>
<?php if ( $grupo_tiles ) : ?>
	<?php if ( $con_titulos ) : ?>
		<h3 class="asde-tiles__titulo"><?php esc_html_e( 'Group management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php endif; ?>
	<?php $pintar( $grupo_tiles ); ?>
<?php endif; ?>
