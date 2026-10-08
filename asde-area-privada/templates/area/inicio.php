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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$user = $ambito->user;
?>
<?php if ( $pendiente_2fa ) : ?>
	<div class="asde-notice asde-notice--warning" role="status">
		<strong><?php asde_e( 'Activa la verificación en dos pasos.' ); ?></strong>
		<?php asde_e( 'Es obligatoria para tu rol' ); ?><?php echo $limite_2fa && $limite_2fa > time() ? esc_html( ' ' . sprintf( asde_t( 'a partir del %s' ), wp_date( 'd/m/Y', $limite_2fa ) ) ) : ''; ?>.
		<a href="<?php echo esc_url( $url( 'seguridad' ) ); ?>"><?php asde_e( 'Configurarla ahora' ); ?></a> <?php asde_e( '(2 minutos, con una app como Google Authenticator).' ); ?>
	</div>
<?php endif; ?>

<section class="asde-card">
	<h2><?php asde_e( 'Hola,' ); ?> <?php echo esc_html( $ambito->nombre() ); ?></h2>
	<p><?php asde_e( 'Te damos la bienvenida al área privada del' ); ?> <strong><?php echo esc_html( $grupo ); ?></strong>.</p>
	<p>
		<?php asde_e( 'Tu usuario:' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong>
		<?php if ( \AsdeBalears\AreaPrivada\Usuarios\Usuarios::email_origen( $user->ID ) ) : ?>
			<span class="asde-help asde-help--inline"><?php asde_e( '(o el email al que te llegó la invitación)' ); ?></span>
		<?php endif; ?>
		<?php foreach ( $roles as $rol ) : ?>
			<span class="asde-badge asde-badge--rol"><?php echo esc_html( $rol ); ?></span>
		<?php endforeach; ?>
	</p>

	<?php if ( $ambito->persona && $ambito->socio_activo() ) : ?>
		<p>
			<?php asde_e( 'Tu sección:' ); ?>
			<?php
			foreach ( $ambito->persona->secciones as $s ) {
				echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
			<?php if ( $ambito->secciones_scouter() ) : ?>
				<?php asde_e( '· Colaboras en:' ); ?>
				<?php
				foreach ( $ambito->secciones_scouter() as $s ) {
					echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $ambito->representados ) : ?>
		<p><?php asde_e( 'Tu familia en el grupo:' ); ?></p>
		<ul>
			<?php foreach ( $ambito->representados as $p ) : ?>
				<li>
					<?php echo esc_html( $p->nombre_completo() ); ?>
					<?php echo $p->seccion_educativa() ? Secciones::badge( $p->seccion_educativa() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( ! empty( $por_confirmar ) ) : ?>
	<div class="asde-notice asde-notice--info" role="status">
		<strong><?php echo esc_html( 1 === (int) $por_confirmar ? asde_t( 'El grupo te ha entregado un pedido de uniforme.' ) : sprintf( asde_t( 'El grupo te ha entregado %d pedidos de uniforme.' ), (int) $por_confirmar ) ); ?></strong>
		<a href="<?php echo esc_url( $url( 'uniforme' ) ); ?>"><?php asde_e( 'Confirma que lo has recibido' ); ?></a>.
	</div>
<?php endif; ?>

<?php if ( array_filter( $por_revisar ?? array() ) ) : ?>
	<?php
	$uni = static fn( string $estado ) => $url( 'uniformes', array( 'estado' => $estado ) );
	$num = static fn( int $n, string $uno, string $varios ) => '<strong>' . $n . '</strong> ' . esc_html( 1 === $n ? $uno : $varios );
	?>
	<section class="asde-card asde-card--aviso">
		<h3 class="asde-card__title"><?php asde_e( 'Pendiente de revisar' ); ?></h3>
		<ul class="asde-pendientes">
			<?php if ( $por_revisar['solicitudes'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'solicitudes' ) ); ?>"><strong><?php echo (int) $por_revisar['solicitudes']; ?></strong> <?php echo 1 === (int) $por_revisar['solicitudes'] ? esc_html( asde_t( 'solicitud de acceso' ) ) : esc_html( asde_t( 'solicitudes de acceso' ) ); ?></a></li>
			<?php endif; ?>
			<?php if ( $por_revisar['cambios'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'cambios' ) ); ?>"><strong><?php echo (int) $por_revisar['cambios']; ?></strong> <?php echo 1 === (int) $por_revisar['cambios'] ? esc_html( asde_t( 'cambio propuesto por una familia' ) ) : esc_html( asde_t( 'cambios propuestos por las familias' ) ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pago'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'sin_pagar' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_pago'], esc_html( asde_t( 'pedido de uniforme pendiente de pago' ) ), esc_html( asde_t( 'pedidos de uniforme pendientes de pago' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pedido'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_pedir' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_pedido'], esc_html( asde_t( 'pedido de uniforme por incluir en el pedido al proveedor' ) ), esc_html( asde_t( 'pedidos de uniforme por incluir en el pedido al proveedor' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_entrega'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_entregar' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_entrega'], esc_html( asde_t( 'uniforme pedido al proveedor por entregar' ) ), esc_html( asde_t( 'uniformes pedidos al proveedor por entregar' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['compras'] ) ) : ?>
				<li><a href="<?php echo esc_url( $url( 'inventario_compras', array( 'estado' => 'pendiente' ) ) ); ?>"><?php echo $num( (int) $por_revisar['compras'], esc_html( asde_t( 'lista de compra de material pendiente de tu visto bueno' ) ), esc_html( asde_t( 'listas de compra de material pendientes de tu visto bueno' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
		</ul>
	</section>
<?php endif; ?>

<?php
// v0.15: las pantallas de cada persona o familia y las de gestión del grupo van en bloques distintos.
$tile = static fn( string $href, string $titulo, string $texto ): array => array( $href, $titulo, $texto );

$mias = array();
if ( $ambito->persona ) {
	$mias[] = $tile( $url( 'ficha' ), asde_t( 'Mis datos' ), asde_t( 'Tu ficha del grupo: consulta tus datos y actualiza tu email y teléfono.' ) );
}
foreach ( $ambito->representados as $n => $p ) {
	$mias[] = $tile( $url( 'ficha', array( 'socio' => $n ) ), sprintf( asde_t( 'Ficha de %s' ), $p->nombre ), asde_t( 'Consulta sus datos y propón cambios (los revisa el grupo).' ) );
}
if ( ( $ambito->persona || $ambito->representados ) && current_user_can( 'asde_mis_pagos' ) ) {
	$mias[] = $tile( $url( 'pagos' ), asde_t( 'Mis pagos' ), asde_t( 'Lo que ha registrado Tesorería: la cuota del grupo, sus plazos y los pagos del uniforme.' ) );
}
if ( ! empty( $pide_uniforme ) ) {
	$mias[] = $tile( $url( 'uniforme' ), ! empty( $gestiona_uniformes ) ? asde_t( 'Mi uniforme' ) : asde_t( 'Uniforme' ), asde_t( 'Pide la camisa scout y las insignias, y sigue tu pedido.' ) );
}

$grupo_tiles = array();
if ( ! empty( $gestiona_uniformes ) ) {
	$grupo_tiles[] = $tile( $url( 'uniformes' ), asde_t( 'Gestión de uniformes' ), asde_t( 'Pagos, pedido al proveedor y entregas de las camisas del grupo.' ) );
}
if ( current_user_can( 'asde_aprobar_solicitudes' ) ) {
	$grupo_tiles[] = $tile( $url( 'solicitudes' ), asde_t( 'Solicitudes de acceso' ), asde_t( 'Aprueba a las familias y socios que piden entrar con el enlace del grupo.' ) );
}
if ( current_user_can( 'asde_approve_changes' ) ) {
	$grupo_tiles[] = $tile( $url( 'cambios' ), asde_t( 'Cambios propuestos' ), asde_t( 'Revisa los cambios que piden las familias en las fichas.' ) );
}
if ( current_user_can( 'asde_reports' ) ) {
	$grupo_tiles[] = $tile( $url( 'informes' ), asde_t( 'Informes' ), $ambito->vision_global() ? asde_t( 'Listados de todo el grupo con las columnas que elijas.' ) : asde_t( 'Listados de tu sección con las columnas que elijas.' ) );
}
if ( current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP ) ) {
	$grupo_tiles[] = $tile( $url( 'inventario' ), asde_t( 'Inventario' ), asde_t( 'El material y el equipamiento del grupo, y las listas de compra.' ) );
}
if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
	$grupo_tiles[] = $tile( $url( 'tesoreria' ), asde_t( 'Tesorería' ), asde_t( 'Cuotas de las familias, pagos, familias y coste federativo.' ) );
}
if ( current_user_can( 'asde_manage_users' ) ) {
	$grupo_tiles[] = $tile( $url( 'personas' ), asde_t( 'Personas del grupo' ), asde_t( 'Sus fichas, darles acceso y revisar los números de socio.' ) );
	// v0.25: «Dar acceso» y los usuarios del área privada, en una sola sección con sus hojas.
	$grupo_tiles[] = $tile( $url( 'usuarios' ), asde_t( 'Usuarios' ), asde_t( 'Quién tiene acceso, dar acceso a un socio o a su familia, invitaciones, bajas y el registro de emails.' ) );
}
// La seguridad de la cuenta es de cada persona: va con lo suyo, o con lo de gestión si no tiene ficha.
$cuenta = $tile( $url( 'seguridad' ), asde_t( 'Seguridad de la cuenta' ), asde_t( 'Verificación en dos pasos.' ) );
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
		<h3 class="asde-tiles__titulo"><?php echo $ambito->persona ? esc_html( asde_t( 'Para ti' ) ) : esc_html( asde_t( 'Para tu familia' ) ); ?></h3>
	<?php endif; ?>
	<?php $pintar( $mias ); ?>
<?php endif; ?>
<?php if ( $grupo_tiles ) : ?>
	<?php if ( $con_titulos ) : ?>
		<h3 class="asde-tiles__titulo"><?php asde_e( 'Gestión del grupo' ); ?></h3>
	<?php endif; ?>
	<?php $pintar( $grupo_tiles ); ?>
<?php endif; ?>
