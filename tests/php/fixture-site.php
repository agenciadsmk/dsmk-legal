<?php
/**
 * Web simulada para la auditoría: responde como WordPress con el plugin activo.
 * - Visitante: HTML filtrado por el bloqueador (salvo una fuga a propósito si $GLOBALS['__leak']).
 * - Escáner (cabecera X-DSMKC-Scan): HTML sin filtrar.
 *
 * @package DSMK_Legal
 * @file    tests/php/fixture-site.php
 * @since   2.1.0
 */
$GLOBALS['__leak'] = isset( $GLOBALS['__leak'] ) ? $GLOBALS['__leak'] : false;
$GLOBALS['__http'] = function ( $url, $args ) {
	$scanner = isset( $args['headers']['X-DSMKC-Scan'] );
	$raw     = '<!DOCTYPE html><html><head>'
		. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
		. '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>'
		. '</head><body><main>'
		. '<form class="contact"><input name="your-name"><input type="email" name="your-email"><textarea name="msg"></textarea><button>Enviar</button></form>'
		. '<form class="newsletter"><input type="email" name="email"><label><input type="checkbox"> Acepto la <a href="/privacy-policy/">política de privacidad</a></label></form>'
		. '<form role="search"><input name="s"></form>'
		. '<iframe src="https://www.youtube.com/embed/xyz"></iframe>'
		. '</main><footer><a href="' . $GLOBALS['__home'] . '/aviso-legal/">Aviso legal</a> <a href="' . $GLOBALS['__home'] . '/politica-de-cookies/">Cookies</a></footer>'
		. '<div id="dsmkc-root"></div></body></html>';
	$body = $scanner ? $raw : ( new DSMKC_Blocker() )->filter_html( $raw );
	if ( ! $scanner && $GLOBALS['__leak'] ) {
		$body = str_replace( '</body>', '<script src="https://connect.facebook.net/en_US/fbevents.js"></script></body>', $body );
	}
	$cookies = array( (object) array( 'name' => 'wordpress_test_cookie' ) );
	if ( ! $scanner && $GLOBALS['__leak'] ) {
		$cookies[] = (object) array( 'name' => '_ga' );
	}
	return array( 'body' => $body, 'code' => 200, 'cookies' => $cookies, 'headers' => array( 'x-frame-options' => 'SAMEORIGIN', 'x-content-type-options' => 'nosniff' ) );
};
