<?php
/**
 * Página «sin conexión» de la PWA (docs/pwa/). La guarda el service worker y se enseña
 * cuando no hay red al abrir una página del área: no lleva datos de nadie, solo el color
 * del grupo y el idioma de quien instaló la app.
 *
 * @var string $lang       Código del idioma (es, ca, eu, gl).
 * @var string $primario   Color principal (#rrggbb).
 * @var string $sobre      Color del texto sobre el principal.
 * @var string $texto      Color del texto.
 * @var string $atenuado   Color del texto secundario.
 */
defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo esc_attr( $primario ); ?>">
<title><?php echo esc_html( asde_t( 'Sin conexión' ) ); ?></title>
<style>
	* { box-sizing: border-box; }
	html, body { margin: 0; height: 100%; }
	body {
		display: flex; align-items: center; justify-content: center; text-align: center;
		padding: 24px; background: #fff; color: <?php echo esc_attr( $texto ); ?>;
		font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
	}
	main { max-width: 420px; }
	.icono { width: 88px; height: 88px; margin: 0 auto 20px; display: block; }
	h1 { margin: 0 0 12px; font-size: 1.5rem; color: <?php echo esc_attr( $primario ); ?>; }
	p { margin: 0 0 12px; }
	.nota { font-size: .9rem; color: <?php echo esc_attr( $atenuado ); ?>; }
	button {
		margin-top: 12px; padding: 12px 28px; border: 0; border-radius: 6px;
		background: <?php echo esc_attr( $primario ); ?>; color: <?php echo esc_attr( $sobre ); ?>; font: inherit; font-weight: 600; cursor: pointer;
	}
	button:focus-visible { outline: 3px solid <?php echo esc_attr( $primario ); ?>; outline-offset: 2px; }
</style>
</head>
<body>
<main>
	<svg class="icono" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
		<rect width="100" height="100" rx="22" fill="<?php echo esc_attr( $primario ); ?>"/>
		<polygon points="50,24 23,70 77,70" fill="<?php echo esc_attr( $sobre ); ?>"/>
		<polygon points="50,46 40,70 60,70" fill="<?php echo esc_attr( $primario ); ?>"/>
		<rect x="16" y="70" width="68" height="4" fill="<?php echo esc_attr( $sobre ); ?>" opacity=".6"/>
	</svg>
	<h1><?php echo esc_html( asde_t( 'Sin conexión' ) ); ?></h1>
	<p><?php echo esc_html( asde_t( 'No hay internet en este momento, y el área privada la necesita para mostrarte los datos del grupo.' ) ); ?></p>
	<p class="nota"><?php echo esc_html( asde_t( 'Por seguridad, las fichas, los pagos y los datos de los socios no se guardan en el móvil.' ) ); ?></p>
	<button type="button" id="reintentar"><?php echo esc_html( asde_t( 'Reintentar' ) ); ?></button>
</main>
<script>
	document.getElementById( 'reintentar' ).addEventListener( 'click', function () { location.reload(); } );
	window.addEventListener( 'online', function () { location.reload(); } );
</script>
</body>
</html>
