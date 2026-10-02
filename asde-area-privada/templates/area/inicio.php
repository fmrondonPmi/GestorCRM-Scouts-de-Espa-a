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

<div class="asde-tiles">
	<?php if ( $ambito->persona ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'ficha' ) ); ?>">
			<strong>Mis datos</strong>
			Tu ficha del grupo: consulta tus datos y actualiza tu email y teléfono.
		</a>
	<?php endif; ?>
	<?php foreach ( $ambito->representados as $n => $p ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'ficha', array( 'socio' => $n ) ) ); ?>">
			<strong>Ficha de <?php echo esc_html( $p->nombre ); ?></strong>
			Consulta sus datos y propón cambios (los revisa el grupo).
		</a>
	<?php endforeach; ?>
	<?php if ( ( $ambito->persona || $ambito->representados ) && current_user_can( 'asde_mis_pagos' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'pagos' ) ); ?>">
			<strong>Mis pagos</strong>
			Lo que ha registrado Tesorería: la cuota del grupo, sus plazos y los pagos del uniforme.
		</a>
	<?php endif; ?>
	<?php if ( ! empty( $pide_uniforme ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'uniforme' ) ); ?>">
			<strong>Uniforme</strong>
			Pide la camisa scout y las insignias, y sigue tu pedido.
		</a>
	<?php endif; ?>
	<?php if ( ! empty( $gestiona_uniformes ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'uniformes' ) ); ?>">
			<strong>Pedidos de uniforme</strong>
			Pagos, pedido al proveedor y entregas de las camisas del grupo.
		</a>
	<?php endif; ?>
	<?php if ( current_user_can( 'asde_aprobar_solicitudes' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'solicitudes' ) ); ?>">
			<strong>Solicitudes de acceso</strong>
			Aprueba a las familias y socios que piden entrar con el enlace del grupo.
		</a>
	<?php endif; ?>
	<?php if ( current_user_can( 'asde_approve_changes' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'cambios' ) ); ?>">
			<strong>Cambios propuestos</strong>
			Revisa los cambios que piden las familias en las fichas.
		</a>
	<?php endif; ?>
	<?php if ( current_user_can( 'asde_reports' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'informes' ) ); ?>">
			<strong>Informes</strong>
			<?php echo $ambito->vision_global() ? 'Listados de todo el grupo con las columnas que elijas.' : 'Listados de tu sección con las columnas que elijas.'; ?>
		</a>
	<?php endif; ?>
	<?php if ( current_user_can( 'asde_tesoreria_acceso' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'tesoreria' ) ); ?>">
			<strong>Tesorería</strong>
			Cuotas de las familias, pagos, familias y coste federativo.
		</a>
	<?php endif; ?>
	<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'personas' ) ); ?>">
			<strong>Personas del grupo</strong>
			Sus fichas, darles acceso y revisar los números de socio.
		</a>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'alta' ) ); ?>">
			<strong>Dar acceso</strong>
			Busca a un socio y dale acceso a él o ella, o a su familia.
		</a>
		<a class="asde-tile" href="<?php echo esc_url( $url( 'usuarios' ) ); ?>">
			<strong>Usuarios del área privada</strong>
			Quién tiene acceso, invitaciones y bajas.
		</a>
	<?php endif; ?>
	<a class="asde-tile" href="<?php echo esc_url( $url( 'seguridad' ) ); ?>">
		<strong>Seguridad de la cuenta</strong>
		Verificación en dos pasos.
	</a>
</div>
