<?php
/**
 * DSMK Legal — Servidor de la web de prueba (php -S).
 *
 * Uso: php -S 127.0.0.1:8765 tests/e2e/router.php
 *
 * Simula una web WordPress con el plugin activo usando las clases reales
 * (bloqueo, banner, configuración) y rastreadores LOCALES que instalan
 * cookies de verdad, para comprobar en un navegador real que nada se
 * instala sin consentimiento.
 *
 * @package DSMK_Legal
 * @file    tests/e2e/router.php
 * @since   1.0.0
 */

require dirname( __DIR__ ) . '/php/bootstrap.php';

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$log  = sys_get_temp_dir() . '/dsmkc-e2e-log.jsonl';

// Rastreadores locales: cada uno instala una cookie propia al ejecutarse.
$trackers = array(
	'/localtracker.js' => "document.cookie='_lt_analytics=1; path=/; max-age=86400';window.__lt_analytics=true;",
	'/localads.js'     => "document.cookie='_lt_ads=1; path=/; max-age=86400';window.__lt_ads=true;",
	'/dyn-tracker.js'  => "document.cookie='_lt_dyn=1; path=/; max-age=86400';window.__lt_dyn=true;",
	'/injector.js'     => "(function(){var s=document.createElement('script');s.src='/dyn-tracker.js';document.head.appendChild(s);window.__injector=true;})();",
);
if ( isset( $trackers[ $path ] ) ) {
	header( 'Content-Type: application/javascript' );
	echo $trackers[ $path ];
	return true;
}
if ( '/pixel.gif' === $path ) {
	// Píxel de seguimiento local: instala una cookie con Set-Cookie al descargarse.
	header( 'Set-Cookie: _lt_px=1; Path=/; Max-Age=86400' );
	header( 'Content-Type: image/gif' );
	echo base64_decode( 'R0lGODlhAQABAAAAACw=' );
	return true;
}
if ( '/embed-video.html' === $path ) {
	echo "<!doctype html><html><body style='margin:0;background:#111;color:#fff;font:16px sans-serif;display:flex;align-items:center;justify-content:center;height:100vh'><script>document.cookie='_lt_video=1; path=/; max-age=86400';</script>▶ Vídeo de terceros cargado</body></html>";
	return true;
}
if ( 0 === strpos( $path, '/plugin/' ) ) {
	$file = DSMKC_DIR . substr( $path, strlen( '/plugin/' ) );
	if ( is_file( $file ) ) {
		header( 'Content-Type: ' . ( '.css' === substr( $file, -4 ) ? 'text/css' : 'application/javascript' ) );
		readfile( $file );
		return true;
	}
}
$fail_flag = sys_get_temp_dir() . '/dsmkc-e2e-faillog';
if ( '/__faillog' === $path ) {
	if ( ! empty( $_GET['on'] ) ) { touch( $fail_flag ); } else { @unlink( $fail_flag ); }
	echo 'ok';
	return true;
}
if ( '/wp-json/dsmkc/v1/consent' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] && is_file( $fail_flag ) ) {
	http_response_code( 500 ); // Simula un fallo de la base de datos.
	echo '{"code":"dsmkc_db"}';
	return true;
}
if ( '/wp-json/dsmkc/v1/consent' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	file_put_contents( $log, file_get_contents( 'php://input' ) . "\n", FILE_APPEND );
	http_response_code( 201 );
	header( 'Content-Type: application/json' );
	echo '{"ok":true}';
	return true;
}
if ( '/__log' === $path ) {
	header( 'Content-Type: text/plain' );
	echo is_file( $log ) ? file_get_contents( $log ) : '';
	return true;
}
if ( '/__reset' === $path ) {
	@unlink( $log );
	echo 'ok';
	return true;
}

// ---------------------------------------------------------------- Página
$settings = array(
	'patrones_extra'  => "localtracker.js | analytics | Tracker local\nlocalads.js | marketing | Anuncios local\ndyn-tracker.js | analytics | Tracker dinámico\nembed-video.html | marketing | Vídeo local\npixel.gif | marketing | Píxel local",
	'banner_posicion' => isset( $_GET['pos'] ) ? $_GET['pos'] : 'caja_izquierda',
	'estilo_modo'     => isset( $_GET['mode'] ) ? $_GET['mode'] : 'heredar',
	'gpc'             => isset( $_GET['gpc'] ) ? 1 : 0,
);
$data = array(
	'manual' => array(
		array( 'name' => '_lt_analytics', 'category' => 'analytics', 'provider' => 'Propia', 'purpose' => 'Prueba analítica', 'duration' => '1 día', 'party' => 'propia' ),
		array( 'name' => '_lt_dyn', 'category' => 'analytics', 'provider' => 'Propia', 'purpose' => 'Prueba analítica dinámica', 'duration' => '1 día', 'party' => 'propia' ),
		array( 'name' => '_lt_ads', 'category' => 'marketing', 'provider' => 'Propia', 'purpose' => 'Prueba publicidad', 'duration' => '1 día', 'party' => 'propia' ),
		array( 'name' => '_lt_video', 'category' => 'marketing', 'provider' => 'Propia', 'purpose' => 'Prueba vídeo', 'duration' => '1 día', 'party' => 'propia' ),
	),
);
if ( isset( $_GET['v'] ) ) {
	$data['policy_version'] = (int) $_GET['v'];
}
dsmkc_test_setup( $settings, $data );

$docs_routes = array( '/terminos/' => array( 'Términos y condiciones del servicio', 'terms' ), '/condiciones/' => array( 'Condiciones generales de venta', 'conditions' ), '/desistimiento/' => array( 'Derecho de desistimiento', 'withdrawal' ) );
if ( isset( $docs_routes[ $path ] ) ) {
	$GLOBALS['__opts']['dsmkc_settings'] = array_merge( $GLOBALS['__opts']['dsmkc_settings'], array( 'doc_terminos' => 1, 'doc_condiciones' => 1, 'doc_desistimiento' => 1, 'serv_descripcion' => 'Identidad de marca, diseño gráfico y contenido audiovisual.', 'serv_pago' => '50 % al aceptar el presupuesto y 50 % a la entrega.', 'serv_revisiones' => 'dos rondas de cambios', 'tienda_productos' => 'Láminas y obra gráfica original.', 'tienda_ambito' => 'España peninsular y Baleares', 'tienda_plazo_envio' => '48-72 horas laborables', 'tienda_pagos' => array( 'tarjeta', 'bizum', 'paypal' ), 'tienda_excepciones' => array( 'personalizados' ), 'jurisdiccion' => 'Madrid' ) );
	DSMKC_Settings::flush();
}
$frontend = new DSMKC_Frontend();
$is_policy = '/politica-de-cookies/' === $path || isset( $docs_routes[ $path ] );

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $is_policy ? 'Política de cookies' : 'Inicio'; ?> — Web de Prueba DSMK</title>
<?php $frontend->print_head(); ?>
<link rel="stylesheet" href="/plugin/assets/css/dsmkc-banner.css">
<style><?php echo DSMKC_Frontend::css_vars(); ?></style>
<style>
	body{margin:0;font-family:system-ui,sans-serif;color:#222;background:#faf7f2}
	.elementor-kit-7{--e-global-color-primary:#7a1f5c;--e-global-color-text:#2b2b2b;--e-global-color-accent:#b5487f;--e-global-typography-text-font-family:"Georgia"}
	header{background:#7a1f5c;color:#fff;padding:18px 32px;font:700 20px Georgia,serif}
	main{max-width:960px;margin:0 auto;padding:32px}
	.hero{height:220px;border-radius:12px;background:linear-gradient(120deg,#e9d8e3,#f5ebe0);display:flex;align-items:center;padding:32px;font:600 28px Georgia,serif;margin-bottom:24px}
	.video{max-width:560px}
	footer{padding:24px 32px;font-size:14px;color:#666}
</style>
<script src="/localtracker.js"></script>
<script>window.__inline_tracker_ran = true; /* carga localtracker.js */</script>
</head>
<body class="elementor-kit-7">
<header>Web de Prueba DSMK</header>
<main>
<?php if ( $is_policy ) : ?>
	<?php if ( isset( $docs_routes[ $path ] ) ) : ?>
	<h1><?php echo esc_html( $docs_routes[ $path ][0] ); ?></h1>
	<?php echo call_user_func( array( 'DSMKC_Documents', $docs_routes[ $path ][1] ) ); ?>
	<?php else : ?>
	<h1>Política de cookies</h1>
	<?php echo DSMKC_Legal::cookie_policy(); ?>
	<?php endif; ?>
<?php else : ?>
	<div class="hero">Fotografía y vídeo en Sevilla</div>
	<p>Página de prueba con rastreadores locales que instalan cookies reales.</p>
	<div class="video"><iframe width="560" height="315" src="/embed-video.html" title="Vídeo"></iframe></div>
<?php endif; ?>
</main>
<footer><?php echo DSMKC_Legal::links_markup(); ?></footer>
<script src="/injector.js"></script>
<script async src="/localads.js"></script>
<?php $frontend->print_banner(); ?>
<script>window.dsmkcConfig=<?php echo wp_json_encode( DSMKC_Frontend::js_config() ); ?>;</script>
<script src="/plugin/assets/js/dsmkc-consent.js" defer></script>
</body>
</html>
<?php
$html = ob_get_clean();
echo ( new DSMKC_Blocker() )->filter_html( $html );
return true;
