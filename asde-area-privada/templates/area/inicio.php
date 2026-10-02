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
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$user = $ambito->user;
?>
<?php if ( $pendiente_2fa ) : ?>
	<div class="asde-notice asde-notice--warning" role="status">
		<strong>Activa la verificación en dos pasos.</strong>
		Es obligatoria para tu rol<?php echo $limite_2fa && $limite_2fa > time() ? esc_html( ' a partir del ' . wp_date( 'd/m/Y', $limite_2fa ) ) : ''; ?>.
		<a href="<?php echo esc_url( $url( 'seguridad' ) ); ?>">Configurarla ahora</a> (2 minutos, con una app como Google Authenticator).
	</div>
<?php endif; ?>

<section class="asde-card">
	<h2>Hola, <?php echo esc_html( $ambito->nombre() ); ?></h2>
	<p>Te damos la bienvenida al área privada del <strong><?php echo esc_html( $grupo ); ?></strong>.</p>
	<p>
		Tu usuario: <strong><?php echo esc_html( $user->user_login ); ?></strong>
		<?php if ( \AsdeBalears\AreaPrivada\Usuarios\Usuarios::email_origen( $user->ID ) ) : ?>
			<span class="asde-help asde-help--inline">(o el email al que te llegó la invitación)</span>
		<?php endif; ?>
		<?php foreach ( $roles as $rol ) : ?>
			<span class="asde-badge asde-badge--rol"><?php echo esc_html( $rol ); ?></span>
		<?php endforeach; ?>
	</p>

	<?php if ( $ambito->persona && $ambito->socio_activo() ) : ?>
		<p>
			Tu sección:
			<?php
			foreach ( $ambito->persona->secciones as $s ) {
				echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
			<?php if ( $ambito->secciones_scouter() ) : ?>
				· Colaboras en:
				<?php
				foreach ( $ambito->secciones_scouter() as $s ) {
					echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $ambito->representados ) : ?>
		<p>Tu familia en el grupo:</p>
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
		<strong>El grupo te ha entregado <?php echo 1 === (int) $por_confirmar ? 'un pedido de uniforme' : (int) $por_confirmar . ' pedidos de uniforme'; ?>.</strong>
		<a href="<?php echo esc_url( $url( 'uniforme' ) ); ?>">Confirma que lo has recibido</a>.
	</div>
<?php endif; ?>

<?php if ( array_filter( $por_revisar ?? array() ) ) : ?>
	<?php
	$uni = static fn( string $estado ) => $url( 'uniformes', array( 'estado' => $estado ) );
	$num = static fn( int $n, string $uno, string $varios ) => '<strong>' . $n . '</strong> ' . esc_html( 1 === $n ? $uno : $varios );
	?>
	<section class="asde-card asde-card--aviso">
		<h3 class="asde-card__title">Pendiente de revisar</h3>
		<ul class="asde-pendientes">
			<?php if ( $por_revisar['solicitudes'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'solicitudes' ) ); ?>"><strong><?php echo (int) $por_revisar['solicitudes']; ?></strong> <?php echo 1 === (int) $por_revisar['solicitudes'] ? 'solicitud de acceso' : 'solicitudes de acceso'; ?></a></li>
			<?php endif; ?>
			<?php if ( $por_revisar['cambios'] ) : ?>
				<li><a href="<?php echo esc_url( $url( 'cambios' ) ); ?>"><strong><?php echo (int) $por_revisar['cambios']; ?></strong> <?php echo 1 === (int) $por_revisar['cambios'] ? 'cambio propuesto por una familia' : 'cambios propuestos por las familias'; ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pago'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'sin_pagar' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_pago'], 'pedido de uniforme pendiente de pago', 'pedidos de uniforme pendientes de pago' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_pedido'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_pedir' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_pedido'], 'pedido de uniforme por incluir en el pedido al proveedor', 'pedidos de uniforme por incluir en el pedido al proveedor' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $por_revisar['uniforme_entrega'] ) ) : ?>
				<li><a href="<?php echo esc_url( $uni( 'por_entregar' ) ); ?>"><?php echo $num( (int) $por_revisar['uniforme_entrega'], 'uniforme pedido al proveedor por entregar', 'uniformes pedidos al proveedor por entregar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
			<?php endif; ?>
		</ul>
	</section>
<?php endif; ?>

<?php
// v0.15: las pantallas de cada persona o familia y las de gestión del grupo van en bloques distintos.
$tile = static fn( string $href, string $titulo, string $texto ): array => array( $href, $titulo, $texto );

$mias = array();
if ( $ambito->persona ) {
	$mias[] = $tile( $url( 'ficha' ), 'Mis datos', 'Tu ficha del grupo: consulta tus datos y actualiza tu email y teléfono.' );
}
foreach ( $ambito->representados as $n => $p ) {
	$mias[] = $tile( $url( 'ficha', array( 'socio' => $n ) ), 'Ficha de ' . $p->nombre, 'Consulta sus datos y propón cambios (los revisa el grupo).' );
}
if ( ( $ambito->persona || $ambito->representados ) && current_user_can( 'asde_mis_pagos' ) ) {
	$mias[] = $tile( $url( 'pagos' ), 'Mis pagos', 'Lo que ha registrado Tesorería: la cuota del grupo, sus plazos y los pagos del uniforme.' );
}
if ( ! empty( $pide_uniforme ) ) {
	$mias[] = $tile( $url( 'uniforme' ), ! empty( $gestiona_uniformes ) ? 'Mi uniforme' : 'Uniforme', 'Pide la camisa scout y las insignias, y sigue tu pedido.' );
}

$grupo_tiles = array();
if ( ! empty( $gestiona_uniformes ) ) {
	$grupo_tiles[] = $tile( $url( 'uniformes' ), 'Gestión de uniformes', 'Pagos, pedido al proveedor y entregas de las camisas del grupo.' );
}
if ( current_user_can( 'asde_aprobar_solicitudes' ) ) {
	$grupo_tiles[] = $tile( $url( 'solicitudes' ), 'Solicitudes de acceso', 'Aprueba a las familias y socios que piden entrar con el enlace del grupo.' );
}
if ( current_user_can( 'asde_approve_changes' ) ) {
	$grupo_tiles[] = $tile( $url( 'cambios' ), 'Cambios propuestos', 'Revisa los cambios que piden las familias en las fichas.' );
}
if ( current_user_can( 'asde_reports' ) ) {
	$grupo_tiles[] = $tile( $url( 'informes' ), 'Informes', $ambito->vision_global() ? 'Listados de todo el grupo con las columnas que elijas.' : 'Listados de tu sección con las columnas que elijas.' );
}
if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
	$grupo_tiles[] = $tile( $url( 'tesoreria' ), 'Tesorería', 'Cuotas de las familias, pagos, familias y coste federativo.' );
}
if ( current_user_can( 'asde_manage_users' ) ) {
	$grupo_tiles[] = $tile( $url( 'personas' ), 'Personas del grupo', 'Sus fichas, darles acceso y revisar los números de socio.' );
	$grupo_tiles[] = $tile( $url( 'alta' ), 'Dar acceso', 'Busca a un socio y dale acceso a él o ella, o a su familia.' );
	$grupo_tiles[] = $tile( $url( 'usuarios' ), 'Usuarios del área privada', 'Quién tiene acceso, invitaciones y bajas.' );
}
// La seguridad de la cuenta es de cada persona: va con lo suyo, o con lo de gestión si no tiene ficha.
$cuenta = $tile( $url( 'seguridad' ), 'Seguridad de la cuenta', 'Verificación en dos pasos.' );
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
		<h3 class="asde-tiles__titulo"><?php echo $ambito->persona ? 'Para ti' : 'Para tu familia'; ?></h3>
	<?php endif; ?>
	<?php $pintar( $mias ); ?>
<?php endif; ?>
<?php if ( $grupo_tiles ) : ?>
	<?php if ( $con_titulos ) : ?>
		<h3 class="asde-tiles__titulo">Gestión del grupo</h3>
	<?php endif; ?>
	<?php $pintar( $grupo_tiles ); ?>
<?php endif; ?>
