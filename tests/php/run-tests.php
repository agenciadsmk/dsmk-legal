<?php
/**
 * DSMK Legal — Pruebas unitarias (sin dependencias).
 *
 * Uso: php tests/php/run-tests.php
 *
 * @package DSMK_Legal
 * @file    tests/php/run-tests.php
 * @since   1.0.0
 */

require __DIR__ . '/bootstrap.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = array();

/**
 * Aserción simple.
 *
 * @param bool   $cond  Condición.
 * @param string $label Descripción.
 */
function ok( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['pass'];
		echo "  ✔ {$label}\n";
	} else {
		$GLOBALS['fail'][] = $label;
		echo "  ✘ {$label}\n";
	}
}

/* ------------------------------------------------------------------ */
echo "\n[1] Bloqueo en el HTML (servidor)\n";
dsmkc_test_setup();
$html = <<<'HTML'
<!DOCTYPE html><html><head>
<script id="dsmkc-head" data-dsmkc-ignore>window.dsmkcHead={"rules":[{"p":["connect.facebook.net"]}]};</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-ABC123');</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-123456"></script>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];var j=d.createElement(s);j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-XYZ');</script>
<script>!function(f,b,e,v){}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','123');</script>
<script type="application/ld+json">{"@type":"Organization","note":"fbq('init' no es código"}</script>
<script src="https://www.google.com/recaptcha/api.js?render=xyz"></script>
<script type="module" src="https://static.hotjar.com/c/hotjar-1.js"></script>
<script src="/wp-content/plugins/elementor/assets/js/frontend.min.js"></script>
</head><body>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=123&amp;ev=PageView&amp;noscript=1"/></noscript>
<iframe width="560" height="315" src="https://www.youtube.com/embed/abc?rel=0" allowfullscreen></iframe>
<iframe class="lazy" data-src="https://www.google.com/maps/embed?pb=xyz" src="about:blank"></iframe>
<iframe src="https://player.vimeo.com/video/1"></iframe>
<iframe src="https://www.ejemplo.com/form"></iframe>
<img src="/wp-content/uploads/foto.jpg" alt="foto">
</body></html>
HTML;

$before  = DSMKC_Blocker::analyze( $html );
$blocker = new DSMKC_Blocker();
$out     = $blocker->filter_html( $html );
$after   = DSMKC_Blocker::analyze( $out );

ok( count( $before['leaks'] ) >= 8, 'Antes del filtro se detectan los rastreadores ejecutables (' . count( $before['leaks'] ) . ')' );
ok( 0 === count( $after['leaks'] ), 'Después del filtro: 0 rastreadores ejecutables' );
ok( false !== strpos( $out, 'type="text/plain" data-dsmkc-category="analytics" data-dsmkc-service="ga4" async data-dsmkc-src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"' ), 'GA4 (gtag.js G-) → analytics, src movido a data-dsmkc-src' );
ok( false !== strpos( $out, 'data-dsmkc-service="google_ads"' ) && false !== strpos( $out, 'gtag/js?id=AW-123456' ), 'Google Ads (gtag.js AW-) → marketing' );
ok( false !== strpos( $out, 'data-dsmkc-service="gtm"' ), 'Snippet en línea de GTM bloqueado' );
ok( false !== strpos( $out, 'data-dsmkc-service="meta_pixel"' ), 'Snippet de Meta Pixel bloqueado' );
ok( false !== strpos( $out, "gtag('config','G-ABC123');</script>" ) && false === strpos( $out, 'data-dsmkc-category="analytics">window.dataLayer' ), 'Configuración gtag en línea (inerte) sin tocar' );
ok( false !== strpos( $out, '<script type="application/ld+json">' ), 'JSON-LD intacto aunque contenga el patrón' );
ok( false !== strpos( $out, '<script src="https://www.google.com/recaptcha/api.js?render=xyz"></script>' ), 'reCAPTCHA (técnica) intacto' );
ok( false !== strpos( $out, 'data-dsmkc-type="module"' ) && false !== strpos( $out, 'data-dsmkc-service="hotjar"' ), 'Hotjar como módulo: bloqueado y conserva el tipo' );
ok( false !== strpos( $out, '<script src="/wp-content/plugins/elementor/assets/js/frontend.min.js"></script>' ), 'Scripts propios de la web intactos' );
ok( false !== strpos( $out, 'id="dsmkc-head" data-dsmkc-ignore' ) && false === strpos( $out, 'id="dsmkc-head" data-dsmkc-ignore data-dsmkc' ), 'Script propio del plugin ignorado' );
ok( (bool) preg_match( '#<iframe width="560" height="315" src="about:blank" allowfullscreen data-dsmkc-src="https://www.youtube.com/embed/abc\?rel=0" data-dsmkc-category="marketing" data-dsmkc-service="youtube" class="dsmkc-blocked">#', $out ), 'iframe de YouTube bloqueado (src about:blank)' );
ok( false !== strpos( $out, 'data-dsmkc-src="https://www.google.com/maps/embed?pb=xyz"' ) && false === strpos( $out, 'data-src="https://www.google.com/maps' ), 'iframe con carga diferida (data-src) de Google Maps bloqueado' );
ok( false !== strpos( $out, 'data-dsmkc-service="vimeo"' ), 'iframe de Vimeo → analytics' );
ok( false !== strpos( $out, '<iframe src="https://www.ejemplo.com/form">' ), 'iframe desconocido intacto' );
ok( false !== strpos( $out, 'data-dsmkc-src="https://www.facebook.com/tr?id=123&amp;ev=PageView&amp;noscript=1"' ) && false === strpos( $out, ' src="https://www.facebook.com/tr' ), 'Píxel <img> de Meta en <noscript> bloqueado' );
ok( false !== strpos( $out, '<img src="/wp-content/uploads/foto.jpg" alt="foto">' ), 'Imágenes normales intactas' );
ok( $out === $blocker->filter_html( $out ), 'Idempotente: filtrar dos veces no cambia nada' );

/* ------------------------------------------------------------------ */
echo "\n[2] Reglas adicionales y correcciones de categoría\n";
dsmkc_test_setup( array( 'patrones_extra' => "widget.ejemplo.com | preferences | Widget Ejemplo\n.js | marketing" ), array( 'overrides' => array( 'vimeo' => 'excluded', 'youtube' => 'necessary' ) ) );
$out2 = ( new DSMKC_Blocker() )->filter_html( '<html><script src="https://widget.ejemplo.com/a.js"></script><script src="/tema.js"></script><iframe src="https://player.vimeo.com/video/1"></iframe><iframe src="https://www.youtube.com/embed/x"></iframe></html>' );
ok( false !== strpos( $out2, 'data-dsmkc-category="preferences"' ), 'Regla adicional del usuario bloquea con su categoría' );
ok( false !== strpos( $out2, '<script src="/tema.js"></script>' ), 'Reglas demasiado genéricas («.js») se ignoran' );
ok( false !== strpos( $out2, '<iframe src="https://player.vimeo.com/video/1">' ), 'Servicio excluido no se bloquea' );
ok( false !== strpos( $out2, '<iframe src="https://www.youtube.com/embed/x">' ), 'Servicio reclasificado como necesario no se bloquea' );

/* ------------------------------------------------------------------ */
echo "\n[3] Saneado de ajustes\n";
$f = DSMKC_Settings::fields();
ok( '' === DSMKC_Settings::sanitize_field( 'UA-123', $f['ga4_id'] ), 'ID de GA4 inválido se descarta' );
ok( 'G-ABC123' === DSMKC_Settings::sanitize_field( 'g-abc123', $f['ga4_id'] ), 'ID de GA4 se normaliza a mayúsculas' );
ok( 'abc123xyz' === DSMKC_Settings::sanitize_field( 'ABC123XYZ', $f['clarity_id'] ), 'ID de Clarity se normaliza a minúsculas' );
ok( 730 === DSMKC_Settings::sanitize_field( '9999', $f['consent_dias'] ), 'Validez del consentimiento limitada a 730 días (24 meses)' );
ok( 'caja_izquierda' === DSMKC_Settings::sanitize_field( 'hack', $f['banner_posicion'] ), 'Select con valor no permitido → por defecto' );
$css = DSMKC_Settings::sanitize_css( '.x{color:red}</style><script>alert(1)</script>@import url(x);.y{background:url(javascript:alert(1))}' );
ok( false === stripos( $css, '<' ) && false === stripos( $css, '@import' ) && false === stripos( $css, 'javascript:' ), 'CSS adicional sin etiquetas, @import ni javascript:' );
ok( '#ffffff' === DSMKC_Settings::sanitize_field( 'red;}', $f['color_fondo'] ), 'Color inválido → por defecto' );

/* ------------------------------------------------------------------ */
echo "\n[4] Política de cookies: apartados exigidos (LSSI / AEPD)\n";
dsmkc_test_setup();
$policy = DSMKC_Legal::cookie_policy();
$plain  = strip_tags( $policy );
ok( false !== strpos( $GLOBALS['__pages'][11]['content'], '[dsmk_politica_cookies]' ), '«Apartado específico para las políticas de cookies» (página propia)' );
ok( false !== strpos( $plain, '¿Qué son las cookies y para qué sirven?' ) && false !== strpos( $plain, 'Una cookie es un pequeño archivo' ), '«Definición y función genérica de las cookies»' );
ok( false !== strpos( $plain, 'Cookies propias:' ) && false !== strpos( $plain, 'Cookies de terceros:' ) && false !== strpos( $plain, 'Técnicas (necesarias):' ) && false !== strpos( $plain, 'De análisis o medición:' ) && false !== strpos( $plain, 'De publicidad comportamental:' ), '«Definición según origen y tipo (propias, terceros, técnicas, analíticas, publicitarias)»' );
ok( (bool) preg_match( '#<code>_ga</code></td><td>Google Ireland Limited<br><small>Google Analytics</small></td><td>Propia</td><td>Distingue usuarios únicos[^<]*</td><td>2 años</td>#', $policy ), '«Tipo de cookies, finalidad, propia o de terceros» (_ga)' );
ok( (bool) preg_match( '#<code>VISITOR_INFO1_LIVE</code></td><td>Google Ireland Limited<br><small>YouTube</small></td><td>De terceros</td>#', $policy ), 'Cookies de terceros con su titular (YouTube)' );
ok( false !== strpos( $plain, 'Cómo aceptar, rechazar o retirar tu consentimiento' ) && false !== strpos( $policy, 'href="#dsmk-cookies"' ), '«Cómo aceptar, denegar o revocar el consentimiento en cualquier momento»' );
ok( false !== strpos( $plain, 'Duración' ) && false !== strpos( $policy, '<td>24 horas</td>' ) && false !== strpos( $policy, '<td>1 año</td>' ), '«Plazo de conservación o caducidad de las cookies»' );
ok( false !== strpos( $plain, 'Transferencia internacional: EE. UU.' ), 'Transferencias internacionales informadas' );
ok( false !== strpos( $plain, 'Taller Ejemplo SLU' ) && false !== strpos( $plain, 'B00000000' ), 'Responsable identificado' );
ok( (bool) preg_match( '#<code>dsmkc_consent</code></td><td>Web de Prueba DSMK</td><td>Propia</td>#', $policy ), 'Cookies propias de la web con el nombre de la web como titular' );
ok( false === strpos( $policy, '[pendiente' ), 'Sin datos pendientes' );
dsmkc_test_setup( array( 'titular_nif' => '' ) );
ok( false !== strpos( DSMKC_Legal::legal_notice(), '[pendiente: NIF]' ), 'Dato vacío → marcador visible «[pendiente: NIF]»' );

/* ------------------------------------------------------------------ */
echo "\n[5] Banner\n";
dsmkc_test_setup();
$t = DSMKC_Legal::banner_texts();
ok( false !== strpos( $t['text'], 'analizar de forma estadística' ) && false !== strpos( $t['text'], 'publicidad personalizada' ), 'Texto automático con las finalidades de las categorías activas' );
ok( false !== strpos( $t['text'], 'Pueden instalarlas: Google Ireland Limited.' ), 'Texto automático identifica a los terceros' );
ok( false !== strpos( $t['text'], 'href="' . $GLOBALS['__home'] . '/politica-de-cookies/"' ), 'Enlace a la política de cookies en la primera capa' );
$markup = DSMKC_Frontend::banner_markup();
ok( 2 === substr_count( substr( $markup, 0, strpos( $markup, 'dsmkc-overlay' ) ), 'dsmkc-btn dsmkc-btn--primary' ), 'Aceptar y Rechazar en la 1ª capa con la MISMA clase de botón' );
ok( false !== strpos( $markup, 'data-dsmkc-action="reject"' ) && false !== strpos( $markup, 'data-dsmkc-action="accept"' ) && false !== strpos( $markup, 'data-dsmkc-action="settings"' ), 'Botones aceptar, rechazar y configurar presentes' );
ok( false === strpos( $markup, 'checked' ), 'Ninguna casilla premarcada' );
ok( false !== strpos( $markup, 'data-dsmkc-toggle="analytics"' ) && false !== strpos( $markup, 'data-dsmkc-toggle="marketing"' ) && false === strpos( $markup, 'data-dsmkc-toggle="preferences"' ), 'Solo se muestran las categorías con servicios' );
ok( false !== strpos( $markup, 'class="dsmkc-banner" role="dialog"' ) && false !== strpos( $markup, ' hidden>' ), 'Banner oculto en el HTML hasta que el JS decide (compatible con caché)' );
$h1 = DSMKC_Legal::banner_hash();
$GLOBALS['__opts']['dsmkc_settings']['btn_aceptar'] = 'Acepto';
DSMKC_Settings::flush();
ok( $h1 !== DSMKC_Legal::banner_hash(), 'La huella del banner cambia si cambian los textos' );

/* ------------------------------------------------------------------ */
echo "\n[6] Estilos\n";
dsmkc_test_setup();
$vars = DSMKC_Frontend::css_vars();
ok( false !== strpos( $vars, 'var(--e-global-color-primary' ) && false !== strpos( $vars, 'var(--e-global-typography-text-font-family' ), 'Modo heredar usa colores y tipografía globales de Elementor' );
dsmkc_test_setup( array( 'estilo_modo' => 'personalizado', 'color_primario' => '#7a1f5c', 'css_extra' => '.dsmkc-banner{border:1px solid red}' ) );
$vars = DSMKC_Frontend::css_vars();
ok( false !== strpos( $vars, '--dsmkc-primary:#7a1f5c' ) && false !== strpos( $vars, '.dsmkc-banner{border:1px solid red}' ), 'Modo personalizado + CSS adicional' );

/* ------------------------------------------------------------------ */
echo "\n[7] Cookies y servicios\n";
dsmkc_test_setup();
ok( 'ga4' === DSMKC_Services::service_for_cookie( '_ga_ABC123' ), 'Cookie con comodín (_ga_*) → Google Analytics' );
ok( 'meta_pixel' === DSMKC_Services::service_for_cookie( '_fbp' ), '_fbp → Meta Pixel' );
ok( null === DSMKC_Services::service_for_cookie( 'cookie_rara' ), 'Cookie desconocida → sin servicio' );
ok( '1 año' === DSMKC_Services::consent_duration_label(), 'Duración legible del consentimiento' );
$cfg = DSMKC_Frontend::js_config();
ok( in_array( '_ga', $cfg['del']['analytics'], true ) && in_array( '_fbp', $cfg['del']['marketing'], true ), 'Lista de cookies a borrar al revocar por categoría' );
ok( ! in_array( 'IDE', $cfg['del']['marketing'], true ), 'No intenta borrar cookies de terceros (otro dominio)' );

/* ------------------------------------------------------------------ */
echo "\n[8] Registro de documentos y creación automática de páginas (2.0)\n";
dsmkc_test_setup();
ok( array( 'aviso', 'privacidad', 'cookies' ) === array_keys( DSMKC_Documents::enabled() ), 'Por defecto: aviso legal, privacidad y cookies' );
dsmkc_test_setup( array( 'doc_terminos' => 1, 'doc_condiciones' => 1, 'doc_desistimiento' => 1, 'pagina_terminos' => 0 ) );
$created = DSMKC_Documents::create_pages();
ok( array( 'terminos', 'condiciones', 'desistimiento' ) === array_keys( $created ), 'Crea solo las páginas que faltan de los documentos activos' );
ok( false !== strpos( $GLOBALS['__pages'][ $created['condiciones'] ]['content'], '[dsmk_condiciones_venta]' ), 'Cada página nueva lleva su shortcode' );
ok( (int) DSMKC_Settings::get( 'pagina_condiciones' ) === $created['condiciones'], 'El ID de la página queda asignado en los ajustes' );
ok( array() === DSMKC_Documents::create_pages(), 'Idempotente: la segunda vez no crea nada' );
ok( (int) get_option( 'wp_page_for_privacy_policy' ) === 12, 'Asigna la página de privacidad de WordPress' );
class WooCommerce {}
DSMKC_Documents::sync_core_pages();
ok( (int) get_option( 'woocommerce_terms_page_id' ) === $created['condiciones'], 'Asigna las condiciones de venta como «términos» de WooCommerce (casilla del checkout)' );
dsmkc_test_setup( array( 'doc_desistimiento' => 1, 'desist_modo' => 'externo' ) );
ok( ! isset( DSMKC_Documents::create_pages()['desistimiento'] ), 'Desistimiento en modo externo: no crea página (la pone el otro plugin)' );
dsmkc_test_setup( array( 'doc_terminos' => 1 ) );
add_filter( 'dsmkc_legal_documents', function ( $docs ) { $docs['envios'] = array( 'title' => 'Política de envíos', 'shortcode' => 'otro_envios', 'setting' => '', 'toggle' => '', 'footer' => 'Envíos', 'external' => true ); return $docs; } );
ok( isset( DSMKC_Documents::enabled()['envios'] ), 'Otros plugins pueden registrar documentos (filtro dsmkc_legal_documents)' );
$GLOBALS['__pages'][14] = array( 'url' => $GLOBALS['__home'] . '/terminos/', 'content' => '[dsmk_terminos_servicio]' );
$GLOBALS['__opts']['dsmkc_settings']['pagina_terminos'] = 14; DSMKC_Settings::flush();
$links = DSMKC_Legal::links_markup();
ok( false !== strpos( $links, '>Términos y condiciones</a>' ) && false === strpos( $links, 'Condiciones de venta' ), 'Enlaces del pie: solo documentos activos con página' );

/* ------------------------------------------------------------------ */
echo "\n[9] Términos y condiciones del servicio\n";
dsmkc_test_setup( array( 'doc_terminos' => 1, 'serv_descripcion' => "Identidad de marca.\n\nFotografía y vídeo.", 'serv_revisiones' => 'dos rondas de cambios', 'serv_propiedad' => 'cesion', 'jurisdiccion' => 'Madrid' ) );
$t = DSMKC_Documents::terms();
ok( false !== strpos( $t, '<p>Identidad de marca.</p><p>Fotografía y vídeo.</p>' ), 'Descripción de servicios en párrafos (escapada)' );
ok( false !== strpos( $t, 'exclusivamente a empresas y profesionales' ) && false !== strpos( $t, 'Ley 3/2004' ) && false === strpos( $t, 'Derecho de desistimiento de los consumidores' ), 'B2B: sin desistimiento, con intereses de demora y límite de responsabilidad' );
ok( false !== strpos( $t, 'dos rondas de cambios' ) && false !== strpos( $t, 'cede al cliente los derechos de explotación' ) && false !== strpos( $t, 'portfolio' ), 'Revisiones, cesión de derechos y portfolio' );
ok( false !== strpos( $t, 'tribunales de Madrid' ), 'Jurisdicción configurada' );
dsmkc_test_setup( array( 'doc_terminos' => 1, 'serv_clientes' => 'consumidores', 'serv_propiedad' => 'licencia', 'serv_portfolio' => 0 ) );
$t = DSMKC_Documents::terms();
ok( false !== strpos( $t, 'Derecho de desistimiento de los consumidores' ) && false !== strpos( $t, 'art. 103.a' ) && false === strpos( $t, 'Ley 3/2004' ), 'Con consumidores: desistimiento 14 días y excepción del art. 103.a' );
ok( false !== strpos( $t, 'licencia de uso no exclusiva' ) && false === strpos( $t, 'portfolio' ), 'Licencia de uso y sin portfolio' );

/* ------------------------------------------------------------------ */
echo "\n[10] Condiciones de venta y desistimiento\n";
dsmkc_test_setup( array( 'doc_condiciones' => 1, 'doc_desistimiento' => 1, 'tienda_pagos' => array( 'tarjeta', 'bizum' ), 'tienda_desist_dias' => 30, 'tienda_devolucion_coste' => 'tienda', 'tienda_excepciones' => array( 'personalizados' ), 'tienda_ambito' => 'España peninsular', 'tienda_plazo_envio' => '48 h' ) );
$GLOBALS['__pages'][15] = array( 'url' => $GLOBALS['__home'] . '/desistimiento/', 'content' => '[dsmk_desistimiento]' );
$GLOBALS['__opts']['dsmkc_settings']['pagina_desistimiento'] = 15; DSMKC_Settings::flush();
$c = DSMKC_Documents::conditions();
ok( false !== strpos( $c, 'tarjeta de crédito o débito y Bizum' ), 'Formas de pago configuradas' );
ok( false !== strpos( $c, 'plazo de 30 días naturales' ) && false !== strpos( $c, 'Los gastos de devolución corren de nuestra cuenta' ), 'Plazo de desistimiento ampliado y devolución gratuita' );
ok( false !== strpos( $c, 'tres años desde la entrega' ), 'Garantía legal de 3 años (RDL 7/2021)' );
ok( false !== strpos( $c, 'máximo de 30 días naturales' ) && false !== strpos( $c, 'España peninsular' ), 'Entrega: plazo máximo legal y zonas' );
ok( false !== strpos( $c, 'confeccionados conforme a las especificaciones' ), 'Excepciones del art. 103' );
ok( false !== strpos( $c, 'href="' . $GLOBALS['__home'] . '/desistimiento/"' ), 'Enlaza la página de desistimiento' );
ok( false === stripos( $c, 'ec.europa.eu/consumers/odr' ), 'No enlaza la plataforma ODR (derogada en 2025)' );
$w = DSMKC_Documents::withdrawal();
ok( false !== strpos( $w, 'Formulario modelo de desistimiento' ) && false !== strpos( $w, 'Táchese lo que no proceda' ), 'Formulario modelo (anexo B)' );
ok( false !== strpos( $w, 'mailto:hola@ejemplo.es?subject=' ) && false !== strpos( $w, 'Desistir del contrato aquí' ), 'Sin plugin de desistimiento: botón por email con asunto preparado' );
add_filter( 'dsmkc_withdrawal_form', function () { return '<form id="arturo">Botón de Arturo</form>'; } );
$w = DSMKC_Documents::withdrawal();
ok( false !== strpos( $w, '<form id="arturo">' ) && false === strpos( $w, 'mailto:' ), 'Con el plugin de desistimiento: usa su formulario (filtro dsmkc_withdrawal_form)' );

/* ------------------------------------------------------------------ */
echo "\n[11] Privacidad y cláusulas adicionales\n";
dsmkc_test_setup( array( 'doc_condiciones' => 1, 'doc_terminos' => 1, 'finalidades' => array( 'contacto' ), 'extra_privacidad' => '<p>Cláusula <strong>especial</strong></p><script>alert(1)</script>' ) );
$GLOBALS['__opts']; 
function wp_kses_post_test( $t ) { return $t; }
$pp = DSMKC_Legal::privacy_policy();
ok( false !== strpos( $pp, 'Clientes y pedidos de la tienda online' ) && false !== strpos( $pp, 'Clientes de servicios' ), 'Activar condiciones/términos añade sus tratamientos a la privacidad' );
ok( false !== strpos( $pp, '<h2>Otras condiciones</h2>' ) && false !== strpos( $pp, 'Cláusula <strong>especial</strong>' ), 'Cláusulas adicionales al final del documento' );

/* ------------------------------------------------------------------ */
echo "\n[12] Herramientas: importar, exportar, IA y autodetección\n";
require DSMKC_DIR . 'includes/class-dsmkc-tools.php';
require DSMKC_DIR . 'includes/class-dsmkc-plugin.php';
dsmkc_test_setup( array( 'titular_telefono' => '600000000' ) );
$ai_reply = "Aquí tienes:\n```json\n{\"titular_nombre\": \"Estudio Nuevo SL\", \"titular_telefono\": \"611222333\", \"doc_condiciones\": true, \"tienda_pagos\": [\"paypal\",\"cripto\"], \"pagina_cookies\": 999, \"campo_inventado\": 1, \"ga4_id\": \"g-xyz123\"}\n```\nFaltan los datos registrales.";
$json = DSMKC_Tools::parse_json( $ai_reply );
ok( is_array( $json ) && 'Estudio Nuevo SL' === $json['titular_nombre'], 'Extrae el JSON de una respuesta de IA con texto y bloque ```json' );
$r = DSMKC_Tools::import_payload( $json, true );
ok( 'Taller Ejemplo SLU' === DSMKC_Settings::get( 'titular_nombre' ) && '600000000' === DSMKC_Settings::get( 'titular_telefono' ), '«Solo campos vacíos» no sobrescribe datos existentes' );
ok( 1 === DSMKC_Settings::get( 'doc_condiciones' ) && 'G-XYZ123' === DSMKC_Settings::get( 'ga4_id' ), 'Aplica campos vacíos (booleanos y IDs normalizados)' );
ok( 11 === (int) DSMKC_Settings::get( 'pagina_cookies' ) && in_array( 'pagina_cookies', $r['ignored'], true ) && in_array( 'campo_inventado', $r['ignored'], true ), 'Ignora IDs de página y campos desconocidos, y lo informa' );
$r = DSMKC_Tools::import_payload( $json, false );
ok( 'Estudio Nuevo SL' === DSMKC_Settings::get( 'titular_nombre' ), 'Importación normal sobrescribe' );
ok( array( 'paypal' ) === DSMKC_Settings::get( 'tienda_pagos' ), 'Valores no permitidos se descartan («cripto»)' );
$old = array( 'plugin' => 'dsmk-cookies', 'version' => '1.0.0', 'settings' => array( 'banner_posicion' => 'modal' ), 'manual' => array( array( 'name' => '_x', 'category' => 'analytics', 'provider' => 'Propia', 'purpose' => 'p', 'duration' => '1 día', 'party' => 'propia' ) ) );
DSMKC_Tools::import_payload( $old );
ok( 'modal' === DSMKC_Settings::get( 'banner_posicion' ) && 1 === count( DSMKC_Settings::data()['manual'] ), 'Importa JSON de DSMK Cookies 1.x (ajustes y cookies manuales)' );
$exp = DSMKC_Tools::export_payload();
ok( 'dsmk-legal' === $exp['plugin'] && ! isset( $exp['settings']['pagina_cookies'] ) && isset( $exp['settings']['doc_terminos'] ), 'Exportación sin IDs de página y con todos los campos nuevos' );
$brief = DSMKC_Tools::ai_brief();
ok( false !== strpos( $brief, '`doc_condiciones` (0/1)' ) && false !== strpos( $brief, '`serv_clientes` (opción)' ) && false !== strpos( $brief, '`empresas` = Solo empresas' ) && false !== strpos( $brief, 'No inventes NIF' ), 'Briefing IA: campos, tipos, valores permitidos e instrucciones' );
ok( false !== strpos( $brief, '**Actual:** Estudio Nuevo SL' ), 'Briefing IA incluye los valores actuales' );
ok( false === strpos( $brief, 'css_extra' ) && false === strpos( $brief, 'pagina_' ), 'Briefing IA omite estilo e IDs de página' );

dsmkc_test_setup( array( 'titular_nombre' => '', 'titular_nif' => '', 'titular_domicilio' => '', 'titular_email' => '', 'titular_registro' => '' ) );
$GLOBALS['__pages'] = array( 50 => array( 'url' => '/aviso/', 'title' => 'Aviso legal', 'slug' => 'aviso-legal', 'content' => '', 'meta' => array( '_elementor_data' => '[{"settings":{"editor":"<p>Titular: Taller Ejemplo SLU<\/p><p>NIF: B00000000<\/p><p>Domicilio: Calle Mayor, 1 – 2 B, 28001 Madrid<\/p><p>Email: hola@ejemplo.es · Tel. +34 600 111 222<\/p><p>Inscrita en el Registro Mercantil de Madrid, Tomo 1, Folio 2, Hoja M-3<\/p>"}}]' ) ) );
$GLOBALS['__opts']['woocommerce_store_address'] = 'Calle Tienda 1'; $GLOBALS['__opts']['woocommerce_store_postcode'] = '41003'; $GLOBALS['__opts']['woocommerce_store_city'] = 'Sevilla';
$GLOBALS['__opts']['woocommerce_bacs_settings'] = array( 'enabled' => 'yes' ); $GLOBALS['__opts']['woocommerce_stripe_settings'] = array( 'enabled' => 'yes' ); $GLOBALS['__opts']['woocommerce_cod_settings'] = array( 'enabled' => 'no' );
$GLOBALS['__opts']['admin_email'] = 'admin@ejemplo.es';
$d = DSMKC_Tools::detect();
ok( 'B00000000' === ( $d['titular_nif']['value'] ?? '' ), 'Detecta el NIF en el aviso legal hecho con Elementor' );
ok( 'Calle Tienda 1, 41003 Sevilla' === ( $d['titular_domicilio']['value'] ?? '' ), 'La dirección de WooCommerce tiene prioridad' );
ok( false !== strpos( $d['titular_registro']['value'] ?? '', 'Registro Mercantil de Madrid, Tomo 1' ), 'Detecta datos registrales' );
ok( 'Taller Ejemplo SLU' === ( $d['titular_nombre']['value'] ?? '' ) && 'sociedad' === ( $d['titular_tipo']['value'] ?? 'sociedad' ), 'Detecta titular (y forma jurídica)' );
ok( array( 'tarjeta', 'transferencia' ) === ( $d['tienda_pagos']['value'] ?? null ), 'Formas de pago desde las pasarelas activas de WooCommerce' );
ok( 1 === ( $d['doc_condiciones']['value'] ?? 0 ) && 1 === ( $d['doc_desistimiento']['value'] ?? 0 ), 'Con WooCommerce propone condiciones de venta y desistimiento' );
ok( 2 === DSMKC_Tools::apply_detected( array( 'titular_nif', 'titular_registro' ) ) && 'B00000000' === DSMKC_Settings::get( 'titular_nif' ), 'Aplica solo las sugerencias marcadas' );

/* ------------------------------------------------------------------ */
echo "\n[13] Páginas solo con botón y sin duplicados (2.0.1)\n";
dsmkc_test_setup();
ok( 0 === DSMKC_Settings::defaults()['paginas_auto'], 'Creación automática desactivada por defecto' );
dsmkc_test_setup( array( 'doc_terminos' => 1, 'paginas_auto' => 0 ) );
ok( array() === DSMKC_Documents::maybe_create_pages(), 'Al guardar no se crea ninguna página' );
$GLOBALS['__opts']['dsmkc_settings']['paginas_auto'] = 1; unset( $GLOBALS['__opts']['dsmkc_plugin_version'] );
DSMKC_Settings::flush(); DSMKC_Plugin::upgrade();
ok( 0 === DSMKC_Settings::get( 'paginas_auto' ) && DSMKC_VERSION === get_option( 'dsmkc_plugin_version' ), 'Al actualizar desde 2.0.0 se desactiva la creación automática' );
$GLOBALS['__opts']['dsmkc_settings']['paginas_auto'] = 1; DSMKC_Settings::flush(); DSMKC_Plugin::upgrade();
ok( 1 === DSMKC_Settings::get( 'paginas_auto' ), 'Si después se activa a mano, se respeta (la migración solo se hace una vez)' );
$r = DSMKC_Tools::import_payload( array( 'settings' => array( 'paginas_auto' => 1 ) ) );
dsmkc_test_setup();
$r = DSMKC_Tools::import_payload( array( 'settings' => array( 'paginas_auto' => 1, 'titular_nombre' => 'X' ) ) );
ok( 0 === DSMKC_Settings::get( 'paginas_auto' ) && in_array( 'paginas_auto', $r['ignored'], true ), 'El importador nunca activa la creación automática' );

dsmkc_test_setup( array( 'doc_terminos' => 1, 'doc_condiciones' => 1, 'pagina_aviso' => 0, 'pagina_privacidad' => 0 ) );
$GLOBALS['__pages'][60] = array( 'url' => '/aviso-legal/', 'title' => 'Aviso legal', 'slug' => 'aviso-legal', 'content' => '<p>Texto antiguo</p>', 'meta' => array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[{"x":"texto"}]' ) );
$GLOBALS['__pages'][61] = array( 'url' => '/privacidad/', 'title' => 'Política de Privacidad', 'slug' => 'politica-de-privacidad', 'content' => '<p>Privacidad antigua</p>' );
$GLOBALS['__pages'][62] = array( 'url' => '/condiciones-de-venta/', 'title' => 'Condiciones generales de venta', 'slug' => 'condiciones-de-venta', 'content' => '' );
unset( $GLOBALS['__pages'][12], $GLOBALS['__pages'][13] );
ok( 60 === DSMKC_Documents::suggest_existing( 'aviso' ) && 61 === DSMKC_Documents::suggest_existing( 'privacidad' ), 'Detecta las páginas legales que ya tiene la web' );
ok( 62 === DSMKC_Documents::suggest_existing( 'condiciones' ) && 0 === DSMKC_Documents::suggest_existing( 'terminos' ), '«Condiciones de venta» no se confunde con términos de servicio' );
DSMKC_Documents::$last_skipped = array();
$created = DSMKC_Documents::create_pages( null, true );
ok( array( 'terminos' ) === array_keys( $created ) && array( 'aviso', 'privacidad', 'condiciones' ) === array_keys( DSMKC_Documents::$last_skipped ), '«Crear las que faltan» no duplica: solo crea términos y propone asignar las demás' );
ok( 1 === count( DSMKC_Documents::create_pages( 'aviso', false ) ), 'Botón por documento: crea solo ese (aunque haya parecida, tras confirmar)' );
dsmkc_test_setup( array( 'pagina_aviso' => 0 ) );
$GLOBALS['__pages'][60] = array( 'url' => '/aviso-legal/', 'title' => 'Aviso legal', 'slug' => 'aviso-legal', 'content' => '<p>Texto antiguo</p>', 'meta' => array( '_elementor_edit_mode' => 'builder', '_elementor_data' => '[{"x":"texto"}]' ) );
ok( DSMKC_Documents::assign_page( 'aviso', 60, false ) && 60 === (int) DSMKC_Settings::get( 'pagina_aviso' ) && '<p>Texto antiguo</p>' === $GLOBALS['__pages'][60]['content'], 'Usar página existente sin sustituir: se enlaza y conserva su texto' );
ok( ! DSMKC_Documents::page_has_shortcode( 60, 'dsmk_aviso_legal' ), 'Detecta que esa página no muestra el texto del plugin' );
DSMKC_Documents::assign_page( 'aviso', 60, true );
ok( false !== strpos( $GLOBALS['__pages'][60]['content'], '[dsmk_aviso_legal]' ) && '<p>Texto antiguo</p>' === $GLOBALS['__pages'][60]['meta']['_dsmkc_previous_content'], 'Sustituir: pone el shortcode y guarda copia del texto anterior' );
ok( ! isset( $GLOBALS['__pages'][60]['meta']['_elementor_edit_mode'] ) && 'builder' === $GLOBALS['__pages'][60]['meta']['_dsmkc_previous_elementor_edit_mode'] && DSMKC_Documents::page_has_shortcode( 60, 'dsmk_aviso_legal' ), 'Página de Elementor: desactiva el constructor solo ahí (reversible) y ya muestra el texto del plugin' );
ok( ! DSMKC_Documents::assign_page( 'aviso', 9999, false ), 'No asigna páginas inexistentes' );

/* ------------------------------------------------------------------ */
echo "\n[14] Auditoría técnica, histórico e informe (2.1.0)\n";
foreach ( array( 'scanner', 'report', 'audit' ) as $c ) {
	require_once DSMKC_DIR . "includes/class-dsmkc-$c.php";
}
require __DIR__ . '/fixture-site.php';
dsmkc_test_setup( array( 'doc_terminos' => 1 ) );
$visitor = call_user_func( $GLOBALS['__http'], home_url( '/' ), array() );
$page    = DSMKC_Audit::analyze_page( home_url( '/' ), array( 'ok' => true, 'status' => 200, 'ms' => 120, 'bytes' => strlen( $visitor['body'] ), 'body' => $visitor['body'], 'headers' => array(), 'cookies' => array( 'wordpress_test_cookie', '_ga', 'rara' ), 'error' => '' ) );
ok( 0 === count( $page['leaks'] ) && $page['banner'], 'Página filtrada: 0 rastreadores ejecutables y banner presente' );
ok( array( '_ga', 'rara' ) === $page['bad_cookies'] && 'necessary' === $page['cookies']['wordpress_test_cookie'], 'Cookies del servidor: técnica aceptada; _ga y desconocida señaladas' );
ok( 2 === count( $page['forms'] ) && ! $page['forms'][0]['privacy_link'] && $page['forms'][1]['privacy_link'] && $page['forms'][1]['checkbox'], 'Formularios: ignora el buscador; detecta el que no enlaza la privacidad y el que sí (con casilla)' );
ok( array( 'contacto' ) !== $page['forms'][0]['fields'] && in_array( 'email', $page['forms'][0]['fields'], true ) && in_array( 'mensaje', $page['forms'][0]['fields'], true ), 'Identifica los campos de datos personales del formulario' );
ok( $page['google_fonts'] && isset( $page['external']['fonts.googleapis.com'] ) && ! isset( $page['external']['www.googletagmanager.com'] ) && ! isset( $page['external']['www.youtube.com'] ), 'Recursos de terceros antes de consentir: cuenta Google Fonts, no lo bloqueado (GA, YouTube)' );
ok( in_array( 'privacidad', $page['missing_links'], true ) && in_array( 'aviso', $page['legal_links'], true ), 'Enlaces legales: detecta el que falta en el pie (privacidad)' );

$GLOBALS['__opts']['dsmkc_audits'] = array();
$r1 = DSMKC_Audit::run( 'manual' );
ok( 1 === $r1['totals']['pages'] && 0 === $r1['totals']['leaks'] && isset( $r1['totals']['services']['ga4'] ) && isset( $r1['totals']['services']['youtube'] ), 'Auditoría: visitante sin fugas y servicios detectados con el escáner' );
ok( 'leonor' === $r1['user'] && 'manual' === $r1['trigger'] && $r1['score'] > 0 && $r1['score'] <= 100, 'Registro con usuario, origen y puntuación' );
$ids = wp_list_pluck( $r1['checks'], 'id' );
ok( ! array_diff( array( 'leaks', 'server_cookies', 'banner_all', 'legal_links', 'forms', 'external', 'google_fonts', 'https', 'headers', 'titular', 'banner' ), $ids ), 'Incluye comprobaciones de configuración y de la auditoría' );
$by = array_column( $r1['checks'], 'status', 'id' );
ok( 'ok' === $by['leaks'] && 'warn' === $by['forms'] && 'warn' === $by['google_fonts'] && 'warn' === $by['legal_links'] && 'fail' === $by['https'], 'Estados correctos (formularios y fuentes a revisar, sin HTTPS = no cumple)' );
ok( isset( $r1['checks'][0]['group'] ) && ! array_diff( array_unique( wp_list_pluck( $r1['checks'], 'group' ) ), array_keys( DSMKC_Report::groups() ) ), 'Todas las comprobaciones tienen área' );
$GLOBALS['__leak'] = true;
$r2 = DSMKC_Audit::run( 'automatico' );
$by2 = array_column( $r2['checks'], 'status', 'id' );
ok( 'fail' === $by2['leaks'] && 'fail' === $by2['server_cookies'] && $r2['score'] < $r1['score'], 'Detecta una fuga real (píxel de Meta sin bloquear + cookie _ga) y baja la puntuación' );
$GLOBALS['__leak'] = false;
$hist = DSMKC_Audit::history();
ok( 2 === count( $hist ) && $r2['id'] === $hist[0]['id'] && $r1['id'] === DSMKC_Audit::get( $r1['id'] )['id'], 'Histórico ordenado (más reciente primero) y consulta por ID' );
for ( $i = 0; $i < 11; $i++ ) { DSMKC_Audit::run( 'manual' ); }
$hist = DSMKC_Audit::history();
ok( 13 === count( $hist ) && isset( $hist[9]['pages'] ) && ! isset( $hist[10]['pages'] ) && isset( $hist[10]['totals'] ), 'Detalle completo solo en las 10 últimas; resumen en el resto' );
ok( 100 === DSMKC_Audit::score( array( 'ok' => 4, 'warn' => 0, 'fail' => 0 ) ) && 50 === DSMKC_Audit::score( array( 'ok' => 1, 'warn' => 1, 'fail' => 1 ) ), 'Puntuación: cumple 1, revisar 0,5, no cumple 0' );
$decl = DSMKC_Report::declaration_text( $r1 );
ok( false !== strpos( $decl, $r1['id'] ) && false === stripos( $decl, 'kit digital' ) && false === stripos( $decl, 'red.es' ), 'Declaración de cumplimiento universal con referencia de la auditoría' );
exec( 'php ' . escapeshellarg( __DIR__ . '/render-report.php' ) . ' 2>&1', $lines );
$html = implode( "\n", $lines );
$sections = array( 'Resumen y acciones recomendadas', 'Comprobaciones por área', 'Escaneo por página', 'Servicios y terceros', 'Dominios de terceros cargados antes del consentimiento', 'Inventario de cookies', 'Banner y gestión del consentimiento', 'Registro de consentimientos', 'Documentos legales', 'Entorno técnico', 'Histórico de auditorías', 'Declaración técnica de cumplimiento' );
$missing_sections = array_filter( $sections, static function ( $sec ) use ( $html ) { return false === strpos( $html, $sec ); } );
ok( ! $missing_sections && false === stripos( $html, 'Fatal' ) && false === stripos( $html, 'Warning' ), 'Informe técnico: 12 secciones, sin errores PHP' . ( $missing_sections ? ' (faltan: ' . implode( ', ', $missing_sections ) . ')' : '' ) );
ok( false === stripos( $html, 'kit digital' ) && false === stripos( $html, 'red.es' ) && false === stripos( $html, 'cámara de comercio' ), 'Informe sin referencias a Kit Digital, Red.es ni Cámara de Comercio' );
file_put_contents( sys_get_temp_dir() . '/dsmkc-informe.html', $html );


/* ------------------------------------------------------------------ */
echo "\n[15] Revisión de seguridad 2.2.0\n";
dsmkc_test_setup();

// Flags de constructores visuales: solo para quien puede editar.
$_GET['bricks'] = '1';
DSMKC_Blocker::reset_scan_request();
ok( false === DSMKC_Blocker::bypass(), '?bricks=1 de un visitante anónimo NO desactiva el bloqueo' );
$GLOBALS['__logged'] = true; $GLOBALS['__can'] = true;
ok( true === DSMKC_Blocker::bypass(), '?bricks=1 de un editor con sesión sí lo desactiva (vista del constructor)' );
unset( $_GET['bricks'] ); $GLOBALS['__logged'] = false; $GLOBALS['__can'] = false;

// Token de escaneo efímero.
delete_transient( 'dsmkc_scan_token' ); // Lo dejaron las auditorías de [14].
$_SERVER['HTTP_X_DSMKC_SCAN'] = 'TOKEN';
DSMKC_Blocker::reset_scan_request();
ok( '' === DSMKC_Settings::current_scan_token() && false === DSMKC_Blocker::is_scan_request(), 'Sin escaneo en curso no hay token válido (ni el antiguo «TOKEN»)' );
$tok = DSMKC_Settings::scan_token();
$_SERVER['HTTP_X_DSMKC_SCAN'] = $tok;
DSMKC_Blocker::reset_scan_request();
ok( strlen( $tok ) >= 32 && true === DSMKC_Blocker::is_scan_request(), 'Durante un escaneo, la cabecera con el token efímero se acepta' );
ok( $tok === DSMKC_Settings::scan_token(), 'El token se reutiliza mientras dura el escaneo (no rompe auditorías simultáneas)' );
ok( ! isset( DSMKC_Settings::data()['scan_token'] ), 'El token ya no se guarda de forma permanente en dsmkc_data' );
unset( $_SERVER['HTTP_X_DSMKC_SCAN'] );
$_GET['dsmkc_scan'] = wp_create_nonce( 'dsmkc_scan_view' );
DSMKC_Blocker::reset_scan_request();
ok( false === DSMKC_Blocker::is_scan_request(), 'Escaneo en navegador: el nonce sin sesión de administrador no sirve' );
$GLOBALS['__logged'] = true; $GLOBALS['__can'] = true;
DSMKC_Blocker::reset_scan_request();
ok( true === DSMKC_Blocker::is_scan_request(), 'Escaneo en navegador: nonce + administrador con sesión' );
$_GET['dsmkc_scan'] = $tok;
DSMKC_Blocker::reset_scan_request();
ok( false === DSMKC_Blocker::is_scan_request(), 'El token del servidor no vale en la URL' );
unset( $_GET['dsmkc_scan'] ); $GLOBALS['__logged'] = false; $GLOBALS['__can'] = false;
DSMKC_Blocker::reset_scan_request();

// Peticiones del escáner: sin caché y redirecciones solo dentro del dominio.
ok( false !== strpos( DSMKC_Scanner::nocache_url( 'https://web.test/a/' ), 'dsmkc_nc=' ), 'Las peticiones del escáner llevan un parámetro único (no se sirven ni guardan en caché)' );
$seen = array();
$prev = $GLOBALS['__http'];
$GLOBALS['__http'] = function ( $url, $args ) use ( &$seen ) {
	$seen[] = array( $url, isset( $args['headers']['X-DSMKC-Scan'] ), $args['redirection'] );
	if ( false !== strpos( $url, '/vieja/' ) ) {
		return array( 'body' => '', 'code' => 301, 'cookies' => array(), 'headers' => array( 'location' => str_replace( 'http://', 'https://www.', $GLOBALS['__home'] ) . '/nueva/' ) );
	}
	if ( false !== strpos( $url, '/nueva/' ) ) {
		return array( 'body' => '', 'code' => 302, 'cookies' => array(), 'headers' => array( 'location' => 'https://otro-dominio.example/robar' ) );
	}
	return array( 'body' => 'ok', 'code' => 200, 'cookies' => array(), 'headers' => array() );
};
$res = DSMKC_Scanner::request( $GLOBALS['__home'] . '/vieja/', array( 'headers' => array( 'X-DSMKC-Scan' => 'x' ) ) );
ok( 2 === count( $seen ) && 0 === $seen[0][2] && false !== strpos( $seen[1][0], '/nueva/' ), 'Sigue la redirección dentro del dominio (http→https y www) con redirection = 0' );
ok( 302 === $res['code'] && ! array_filter( $seen, static function ( $r ) { return false !== strpos( $r[0], 'otro-dominio' ); } ), 'No sigue la redirección a otro dominio: el token nunca sale de la web' );
$GLOBALS['__http'] = $prev;

// Ajustes: sin doble unslash y sin avisos con arrays.
$css = DSMKC_Settings::sanitize_field( '.x:before{content:"\201C"}', array( 'type' => 'css', 'default' => '' ) );
ok( false !== strpos( $css, '\201C' ), 'Las barras invertidas legítimas del CSS se conservan' );
$warn = false;
set_error_handler( static function () use ( &$warn ) { $warn = true; return true; } );
$t = DSMKC_Settings::sanitize_field( array( 'a' ), array( 'type' => 'text', 'default' => '' ) );
$n = DSMKC_Settings::sanitize_field( array( 1 ), array( 'type' => 'int', 'default' => 365 ) );
$c = DSMKC_Settings::sanitize_field( array( 'x' ), array( 'type' => 'select', 'default' => 'b', 'options' => array( 'a' => 'A', 'b' => 'B' ) ) );
restore_error_handler();
ok( ! $warn && '' === $t && 365 === $n && 'b' === $c, 'Un array en un campo de texto/número/selección se descarta sin avisos de PHP' );

// Registro de consentimientos.
require_once DSMKC_DIR . 'includes/class-dsmkc-consent-log.php';
$log = new DSMKC_Consent_Log();
$GLOBALS['__opts']['dsmkc_settings']['log_activo'] = 1;
DSMKC_Settings::flush();
$base = array( 'id' => '0f8e2c3a-1b2c-4d5e-8f90-1a2b3c4d5e6f', 'action' => 'accept', 'c' => array( 'analytics' => 1 ), 'v' => 3 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$r = $log->handle( new DSMKC_Test_Request( array_merge( $base, array( 'id' => $base['id'] . "\n" ) ) ) );
ok( $r instanceof WP_Error && 'dsmkc_invalid' === $r->get_error_code(), 'Id con salto de línea final rechazado (antes no cabía en la columna y se perdía)' );
$warn = false;
set_error_handler( static function () use ( &$warn ) { $warn = true; return true; } );
$r = $log->handle( new DSMKC_Test_Request( array_merge( $base, array( 's' => array_merge( array( array( 'x' ) ), array_fill( 0, 40, str_repeat( 'servicio_largo_', 5 ) ) ), 'url' => array( 'x' ) ) ) ) );
restore_error_handler();
$row = end( $GLOBALS['wpdb']->rows );
ok( ! $warn && $r instanceof WP_REST_Response && 201 === $r->status && strlen( $row['services'] ) <= 255, 'Servicios recortados a la columna y valores no escalares ignorados sin avisos' );
$GLOBALS['wpdb']->fail = true;
$r = $log->handle( new DSMKC_Test_Request( $base ) );
$GLOBALS['wpdb']->fail = false;
ok( $r instanceof WP_Error && 500 === $r->data['status'], 'Si la base de datos no guarda la prueba responde 500 (antes 201) y el navegador la reintenta' );
for ( $i = 0; $i < 70; $i++ ) { $last = $log->handle( new DSMKC_Test_Request( $base ) ); }
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$other = $log->handle( new DSMKC_Test_Request( $base ) );
ok( $last instanceof WP_Error && 'dsmkc_rate' === $last->get_error_code() && $other instanceof WP_REST_Response, 'Límite por IP completa (hash): otra IP del mismo /24 no se ve afectada' );
$row = end( $GLOBALS['wpdb']->rows );
ok( '203.0.113.0' === $row['ip_anon'], 'En la base de datos la IP sigue anonimizada' );
unset( $_SERVER['REMOTE_ADDR'] );

// Consent Mode avanzado: las etiquetas de Google no se bloquean (ellas respetan el consentimiento).
dsmkc_test_setup( array( 'consent_mode' => 1, 'consent_mode_avanzado' => 1 ) );
DSMKC_Services::reset();
$ids = wp_list_pluck( DSMKC_Services::rules(), 's' );
ok( ! in_array( 'gtm', $ids, true ) && ! in_array( 'ga4', $ids, true ) && in_array( 'meta_pixel', $ids, true ), 'Modo avanzado: GTM/GA4/Ads libres; el píxel de Meta (p. ej. desde GTM) sigue bloqueado' );
dsmkc_test_setup();
DSMKC_Services::reset();
ok( in_array( 'gtm', wp_list_pluck( DSMKC_Services::rules(), 's' ), true ), 'Modo básico: GTM bloqueado hasta el consentimiento' );
$cfg = DSMKC_Frontend::head_config();
$fb  = array_values( array_filter( $cfg['rules'], static function ( $r ) { return 'meta_pixel' === $r['s']; } ) );
ok( $fb && in_array( 'facebook.com/tr', $fb[0]['i'], true ) && ! in_array( 'facebook.com/tr', $fb[0]['p'], true ), 'El script de cabecera recibe aparte los patrones de píxeles <img>' );

// Lista de elementos sin clasificar con tope.
$many = array();
for ( $i = 0; $i < 260; $i++ ) { $many[] = 'cookie_rara_' . $i; }
DSMKC_Scanner::merge_results( array( 'services' => array(), 'cookies' => $many, 'storage' => array(), 'domains' => array(), 'via' => 'test', 'scanned' => 1, 'errors' => array() ), false );
ok( DSMKC_Scanner::MAX_UNKNOWN === count( (array) DSMKC_Settings::data()['unknown'] ), 'La lista de elementos sin clasificar no crece sin límite (' . DSMKC_Scanner::MAX_UNKNOWN . ')' );


/* ------------------------------------------------------------------ */
echo "\n[16] Convivencia con «DSMK Cookies» 1.0.0 de Arturo (2.2.1)\n";
dsmkc_test_setup();
ok( (bool) preg_match( "/define\( 'DSMKC_DB_VERSION', '2' \)/", file_get_contents( DSMKC_DIR . 'dsmk-legal.php' ) ), 'Versión de base de datos distinta de la de DSMK Cookies («1»): la tabla se completa al actualizar' );
// Ajustes anidados del otro plugin en la misma opción.
$GLOBALS['__opts']['dsmkc_settings'] = array(
	'company'  => array( 'owner' => 'Tienda Ejemplo', 'nif' => '12345678Z', 'address' => 'C/ Ejemplo 1, Sevilla', 'email' => 'info@ejemplo.es', 'website' => 'x' ),
	'banner'   => array( 'layout' => 'box-left' ),
	'advanced' => array( 'updater_url' => '' ),
	'titular_email' => 'ya@relleno.es',
);
$GLOBALS['__opts']['dsmkc_plugin_version'] = '2.2.0';
DSMKC_Settings::flush();
DSMKC_Plugin::upgrade();
$saved = $GLOBALS['__opts']['dsmkc_settings'];
ok( 'Tienda Ejemplo' === DSMKC_Settings::get( 'titular_nombre' ) && '12345678Z' === DSMKC_Settings::get( 'titular_nif' ) && 'C/ Ejemplo 1, Sevilla' === DSMKC_Settings::get( 'titular_domicilio' ), 'Importa titular, NIF y domicilio de DSMK Cookies' );
ok( 'ya@relleno.es' === DSMKC_Settings::get( 'titular_email' ), 'No pisa un campo de DSMK Legal que ya tenía valor' );
ok( ! isset( $saved['company'] ) && ! isset( $saved['banner'] ) && ! isset( $saved['advanced'] ), 'Quita las claves anidadas del otro plugin (exportación JSON limpia)' );
ok( ! isset( DSMKC_Tools::export_payload()['settings']['company'] ), 'La exportación ya no arrastra datos de DSMK Cookies' );
// Registros de consentimiento antiguos.
$m = new ReflectionMethod( 'DSMKC_Consent_Log', 'migrate_dsmk_cookies' );
$m->setAccessible( true );
$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->columns = array( 'id', 'consent_id', 'categories', 'policy_version' );
$m->invoke( null );
ok( 0 === count( $GLOBALS['wpdb']->queries ), 'Tabla propia de DSMK Legal: no se toca' );
$GLOBALS['wpdb']->columns = array( 'id', 'consent_id', 'preferences', 'statistics', 'marketing', 'revision', 'categories', 'policy_version' );
$m->invoke( null );
$q = implode( ' ', $GLOBALS['wpdb']->queries );
ok( 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $q, "',analytics:', statistics" ) && false !== strpos( $q, "WHERE categories = ''" ), 'Tabla de DSMK Cookies: las filas antiguas reciben categorías y versión (sin tocar la acción original)' );


/* ------------------------------------------------------------------ */
echo "\n[17] Actualizaciones desde el servidor de DSMK (2.3.0)\n";
dsmkc_test_setup();
DSMKC_Updater::clear();
delete_site_transient( 'update_plugins' );
// Par de claves de prueba.
$kp   = sodium_crypto_sign_keypair();
$sign = static function ( array $j ) use ( $kp ) {
	$j['signature'] = base64_encode( sodium_crypto_sign_detached( DSMKC_Updater::signed_message( $j['version'], $j['sha256'] ), sodium_crypto_sign_secretkey( $kp ) ) );
	return $j;
};
$zipbody = 'PK-contenido-del-zip-2.9.0';
$good    = $sign( array(
	'slug'           => 'dsmk-legal',
	'version'        => '2.9.0',
	'download_url'   => 'https://agenciadsmk.github.io/dsmk-legal/dsmk-legal-2.9.0.zip',
	'sha256'         => hash( 'sha256', $zipbody ),
	'requires'       => '6.0',
	'requires_php'   => '7.4',
	'tested'         => '6.8',
	'last_updated'   => '2026-11-06 22:00:00',
	'changelog_html' => '<h3>2.9.0</h3><ul><li>Mejora</li></ul><script>alert(1)</script>',
) );
$served = array( 'json' => $good, 'zip' => $zipbody, 'code' => 200 );
$GLOBALS['__safe_http'] = array();
$GLOBALS['__http'] = function ( $url, $args ) use ( &$served ) {
	if ( '.json' === substr( $url, -5 ) ) {
		return array( 'body' => json_encode( $served['json'] ), 'code' => $served['code'], 'cookies' => array(), 'headers' => array() );
	}
	return array( 'body' => $served['zip'], 'code' => 200, 'cookies' => array(), 'headers' => array() );
};
$fresh = static function () { return (object) array( 'response' => array(), 'no_update' => array(), 'checked' => array( 'dsmk-legal/dsmk-legal.php' => DSMKC_VERSION ) ); };

ok( '' === DSMKC_Updater::PUBLIC_KEY || 32 === strlen( (string) base64_decode( DSMKC_Updater::PUBLIC_KEY, true ) ), 'DSMKC_Updater::PUBLIC_KEY vacía (aún sin generar) o una clave Ed25519 válida' );
if ( '' === DSMKC_Updater::PUBLIC_KEY ) {
	ok( is_wp_error( DSMKC_Updater::validate( $good ) ), 'Sin clave pública el plugin no acepta ninguna actualización (falla cerrado)' );
}
// A partir de aquí, la constante de wp-config sustituye a la clave del plugin por la de prueba.
define( 'DSMK_LEGAL_UPDATE_PUBKEY', base64_encode( sodium_crypto_sign_publickey( $kp ) ) );
ok( DSMKC_Updater::DEFAULT_URL === DSMKC_Updater::url(), 'Sin configurar consulta el JSON de GitHub Pages del repositorio' );
$GLOBALS['__opts']['dsmkc_settings']['update_url'] = 'http://dsmk.es/info.json';
DSMKC_Settings::flush();
ok( '' === DSMKC_Updater::url(), 'Una URL http (sin cifrar) se rechaza' );
$GLOBALS['__opts']['dsmkc_settings']['update_url'] = 'https://dsmk.es/actualizaciones/dsmk-legal/info.json';
DSMKC_Settings::flush();
ok( 'https://dsmk.es/actualizaciones/dsmk-legal/info.json' === DSMKC_Updater::url(), 'El ajuste «URL de actualizaciones» permite un servidor propio' );
$GLOBALS['__multisite'] = true;
ok( DSMKC_Updater::DEFAULT_URL === DSMKC_Updater::url() && false === DSMKC_Updater::settings_allowed(), 'Multisite: el ajuste de un subsitio se ignora (manda la red)' );
$GLOBALS['__multisite'] = false;
unset( $GLOBALS['__opts']['dsmkc_settings']['update_url'] );
DSMKC_Settings::flush();

// Validación y firma.
ok( is_array( DSMKC_Updater::validate( $good ) ), 'Versión firmada con la clave de DSMK: válida' );
$nosig = $good;
unset( $nosig['signature'] );
ok( is_wp_error( DSMKC_Updater::validate( $nosig ) ), 'Sin firma no se ofrece la actualización' );
$other = sodium_crypto_sign_keypair();
$forged = $good;
$forged['signature'] = base64_encode( sodium_crypto_sign_detached( DSMKC_Updater::signed_message( '2.9.0', $good['sha256'] ), sodium_crypto_sign_secretkey( $other ) ) );
ok( is_wp_error( DSMKC_Updater::validate( $forged ) ), 'Firmada con otra clave (servidor comprometido): rechazada' );
ok( is_wp_error( DSMKC_Updater::validate( array_merge( $good, array( 'sha256' => hash( 'sha256', 'otro zip' ) ) ) ) ), 'Huella cambiada tras firmar: rechazada' );
ok( is_wp_error( DSMKC_Updater::validate( array_merge( $good, array( 'version' => '9.9.9' ) ) ) ), 'Versión cambiada tras firmar: rechazada' );
$bad = DSMKC_Updater::validate( array_merge( $good, array( 'sha256' => '' ) ) );
ok( is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'SHA-256' ), 'Sin huella SHA-256 no se ofrece la actualización' );
ok( is_wp_error( DSMKC_Updater::validate( array_merge( $good, array( 'download_url' => 'http://x.es/a.zip' ) ) ) ), 'Enlace de descarga http rechazado' );
ok( is_wp_error( DSMKC_Updater::validate( array_merge( $good, array( 'slug' => 'otro-plugin' ) ) ) ), 'JSON de otro plugin rechazado' );
ok( is_wp_error( DSMKC_Updater::validate( array_merge( $good, array( 'version' => '2.9' ) ) ) ), 'Versión que no es X.Y.Z rechazada' );
$v = DSMKC_Updater::validate( $good );
ok( is_array( $v ) && false === strpos( $v['changelog_html'], '<script' ), 'El changelog se limpia (sin <script>)' );

// WordPress comprueba actualizaciones (filtro pre_set_site_transient_update_plugins).
$t    = DSMKC_Updater::check( $fresh() );
$item = isset( $t->response['dsmk-legal/dsmk-legal.php'] ) ? $t->response['dsmk-legal/dsmk-legal.php'] : null;
ok( $item && '2.9.0' === $item->new_version && $good['download_url'] === $item->package && $good['sha256'] === $item->dsmkc_sha256, 'Versión nueva → WordPress muestra «Hay una nueva versión disponible» (con la huella junto al paquete)' );
$calls = count( $GLOBALS['__safe_http'] );
DSMKC_Updater::check( $fresh() );
ok( count( $GLOBALS['__safe_http'] ) === $calls, 'La respuesta se guarda en caché (no consulta en cada comprobación)' );
$after = $fresh();
$after->checked['dsmk-legal/dsmk-legal.php'] = '2.9.0';
$t = DSMKC_Updater::check( $after );
ok( empty( $t->response ) && '2.9.0' === $t->no_update['dsmk-legal/dsmk-legal.php']->new_version, 'Tras actualizarse sola no vuelve a ofrecer la misma versión (compara con la versión en disco)' );

$served['json'] = $sign( array_merge( $good, array( 'version' => DSMKC_VERSION ) ) );
DSMKC_Updater::clear();
$t = DSMKC_Updater::check( $fresh() );
ok( empty( $t->response ) && isset( $t->no_update['dsmk-legal/dsmk-legal.php'] ), 'Misma versión → sin aviso, pero con el interruptor de actualizaciones automáticas' );

$served['code'] = 404;
DSMKC_Updater::clear();
$before = (object) array( 'response' => array( 'otro/otro.php' => (object) array() ), 'no_update' => array() );
$t      = DSMKC_Updater::check( clone $before );
$st     = DSMKC_Updater::status();
ok( array_keys( $t->response ) === array( 'otro/otro.php' ) && false !== strpos( $st['error'], '404' ), 'Servidor caído → WordPress sigue igual y el error se ve en Herramientas' );
$calls = count( $GLOBALS['__safe_http'] );
DSMKC_Updater::check( clone $before );
ok( count( $GLOBALS['__safe_http'] ) === $calls, 'Tras un error tampoco reintenta en cada carga (caché de 3 h)' );
$served = array( 'json' => $good, 'zip' => $zipbody, 'code' => 200 );
DSMKC_Updater::clear();

// Ventana «Ver detalles».
$info = DSMKC_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'dsmk-legal' ) );
ok( is_object( $info ) && '2.9.0' === $info->version && false !== strpos( $info->sections['changelog'], 'Mejora' ) && true === $info->external, '«Ver detalles» muestra versión y changelog, sin enlace a wordpress.org' );
ok( false === DSMKC_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'woocommerce' ) ), 'No interfiere con los detalles de otros plugins' );

// Descarga verificada (filtro upgrader_pre_download).
$extra = array( 'plugin' => 'dsmk-legal/dsmk-legal.php' );
set_site_transient( 'update_plugins', DSMKC_Updater::check( $fresh() ) );
DSMKC_Updater::clear(); // La huella viaja con el paquete: no hace falta la caché del JSON.
$GLOBALS['__safe_http'] = array();
$path = DSMKC_Updater::download( false, $good['download_url'], null, $extra );
ok( is_string( $path ) && is_file( $path ) && file_get_contents( $path ) === $zipbody && 1 === count( $GLOBALS['__safe_http'] ), 'ZIP descargado y verificado con su huella firmada → se entrega al instalador' );
@unlink( $path );
$served['zip'] = 'PK-zip-ALTERADO';
$GLOBALS['__safe_http'] = array();
$err = DSMKC_Updater::download( false, $good['download_url'], null, $extra );
$tmp = $GLOBALS['__safe_http'][0][1]['filename'];
ok( is_wp_error( $err ) && 'dsmkc_update_hash' === $err->get_error_code() && ! is_file( $tmp ), 'ZIP alterado → no se instala y se borra el temporal' );
$served['zip'] = $zipbody;
delete_site_transient( 'update_plugins' );
DSMKC_Updater::clear();
$GLOBALS['__safe_http'] = array();
$err = DSMKC_Updater::download( false, 'https://otro-sitio.example/dsmk-legal.zip', null, $extra );
ok( is_wp_error( $err ) && 0 === count( $GLOBALS['__safe_http'] ), 'Nuestro plugin sin huella verificada → falla cerrado (no descarga ni instala)' );
ok( false === DSMKC_Updater::download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ) && 0 === count( $GLOBALS['__safe_http'] ), 'Las descargas de otros plugins siguen el camino normal (sin peticiones extra)' );

// Carpeta distinta de «dsmk-legal» (upgrader_source_selection).
$GLOBALS['wp_filesystem'] = new class() {
	public $moved = array();
	public function move( $from, $to, $over ) { $this->moved[] = array( $from, $to ); return true; }
};
ok( '/tmp/up/dsmk-legal/' === DSMKC_Updater::source_selection( '/tmp/up/dsmk-legal/', '/tmp/up', null, $extra ) && ! $GLOBALS['wp_filesystem']->moved, 'Carpeta normal: no se toca' );
ok( '/tmp/up/x/' === DSMKC_Updater::source_selection( '/tmp/up/x/', '/tmp/up', null, array( 'plugin' => 'otro/otro.php' ) ), 'Otros plugins: no se tocan' );
$GLOBALS['__basename'] = 'dsmk-legal-main/dsmk-legal.php';
$src = DSMKC_Updater::source_selection( '/tmp/up/dsmk-legal/', '/tmp/up', null, array( 'plugin' => 'dsmk-legal-main/dsmk-legal.php' ) );
ok( '/tmp/up/dsmk-legal-main/' === $src && array( array( '/tmp/up/dsmk-legal', '/tmp/up/dsmk-legal-main' ) ) === $GLOBALS['wp_filesystem']->moved, 'Plugin instalado en «dsmk-legal-main/»: la actualización respeta esa carpeta (no se desactiva)' );
unset( $GLOBALS['__basename'] );

// «Comprobar de nuevo» en Escritorio › Actualizaciones.
DSMKC_Updater::remote();
$calls = count( $GLOBALS['__safe_http'] );
$GLOBALS['__is_admin'] = true; $GLOBALS['__can'] = true; $_GET['force-check'] = '1';
DSMKC_Updater::remote();
DSMKC_Updater::remote();
ok( count( $GLOBALS['__safe_http'] ) === $calls + 1, '«Comprobar de nuevo» de WordPress también consulta nuestro servidor (una sola vez por petición)' );
$GLOBALS['__is_admin'] = false; $GLOBALS['__can'] = false; unset( $_GET['force-check'] );

// Clave por web.
$GLOBALS['__safe_http'] = array();
DSMKC_Updater::remote( true );
$h = $GLOBALS['__safe_http'][0][1]['headers'];
ok( ! isset( $h['X-DSMK-Key'] ) && ! isset( $h['X-DSMK-Site'] ), 'Sin clave no se envía ni la clave ni el dominio' );
$GLOBALS['__opts']['dsmkc_settings']['update_key'] = 'clave-secreta-123';
DSMKC_Settings::flush();
$GLOBALS['__safe_http'] = array();
DSMKC_Updater::remote( true );
$h = $GLOBALS['__safe_http'][0][1]['headers'];
ok( 'clave-secreta-123' === $h['X-DSMK-Key'] && '' !== $h['X-DSMK-Site'] && 0 === $GLOBALS['__safe_http'][0][1]['redirection'], 'Con clave viaja en cabeceras al servidor del JSON (sin seguir redirecciones)' );
$served['json'] = $sign( array_merge( $good, array( 'download_url' => 'https://cdn.otro-dominio.example/dsmk-legal-2.9.0.zip' ) ) );
DSMKC_Updater::clear();
set_site_transient( 'update_plugins', DSMKC_Updater::check( $fresh() ) );
$GLOBALS['__safe_http'] = array();
$p = DSMKC_Updater::download( false, 'https://cdn.otro-dominio.example/dsmk-legal-2.9.0.zip', null, $extra );
ok( is_string( $p ) && ! isset( $GLOBALS['__safe_http'][0][1]['headers']['X-DSMK-Key'] ), 'La clave no se envía a un host distinto del del JSON' );
@unlink( $p );
$served = array( 'json' => $good, 'zip' => $zipbody, 'code' => 200 );
DSMKC_Updater::clear();
delete_site_transient( 'update_plugins' );
$export = DSMKC_Tools::export_payload();
ok( ! isset( $export['settings']['update_key'] ) && false === strpos( wp_json_encode( $export ), 'clave-secreta-123' ), 'La clave nunca se exporta' );
ok( false === strpos( DSMKC_Tools::ai_brief(), 'clave-secreta-123' ) && false === strpos( DSMKC_Tools::ai_brief(), 'update_key' ), 'Ni aparece en el briefing para la IA' );
$r = DSMKC_Tools::import_payload( array( 'settings' => array( 'update_key' => 'otra', 'update_url' => 'https://malo.example/info.json', 'update_auto' => 1 ) ), false );
DSMKC_Settings::flush();
ok( 'clave-secreta-123' === DSMKC_Settings::get( 'update_key' ) && '' === (string) DSMKC_Settings::get( 'update_url' ), 'Un JSON importado no puede cambiar la clave ni redirigir las actualizaciones' );
ok( ! isset( $export['settings']['update_url'] ) && ! isset( $export['settings']['update_auto'] ), 'Ni la URL ni las automáticas se exportan' );

// Actualizaciones automáticas.
$mine  = (object) array( 'plugin' => 'dsmk-legal/dsmk-legal.php' );
$other = (object) array( 'plugin' => 'woocommerce/woocommerce.php' );
ok( true === DSMKC_Updater::auto_update( null, $mine ) && null === DSMKC_Updater::auto_update( null, $other ), 'Automáticas activadas por defecto solo para DSMK Legal' );
$GLOBALS['__multisite'] = true;
ok( false === DSMKC_Updater::auto_update( false, $mine ), 'Multisite: manda el interruptor de la red' );
$GLOBALS['__multisite'] = false;
$GLOBALS['__opts']['dsmkc_settings']['update_auto'] = 0;
DSMKC_Settings::flush();
ok( false === DSMKC_Updater::auto_update( false, $mine ), 'Desactivadas: manda el interruptor de WordPress' );

// Cabecera del plugin.
$main = file_get_contents( DSMKC_DIR . 'dsmk-legal.php' );
ok( (bool) preg_match( '#Update URI:\s+https://agenciadsmk\.github\.io/dsmk-legal/#', $main ), 'Cabecera «Update URI»: WordPress nunca lo cambiará por un plugin de wordpress.org con el mismo nombre' );

// Generador del info.json (lo usa la publicación automática).
$tmpd = sys_get_temp_dir() . '/dsmkc-rel-' . getmypid();
@mkdir( $tmpd );
$zipf = $tmpd . '/dsmk-legal-' . DSMKC_VERSION . '.zip';
file_put_contents( $zipf, 'zip de prueba' );
$gen = static function ( $zip, $base, $key, $pub = null ) use ( $kp ) {
	$env = null === $key ? '' : 'DSMK_SIGNING_KEY=' . escapeshellarg( $key ) . ' ';
	$pub = null === $pub ? base64_encode( sodium_crypto_sign_publickey( $kp ) ) : $pub;
	exec( $env . 'php ' . escapeshellarg( DSMKC_DIR . 'bin/make-update-json.php' ) . ' --zip=' . escapeshellarg( $zip ) . ' --base-url=' . escapeshellarg( $base ) . ( '' === $pub ? '' : ' --expected-pubkey=' . escapeshellarg( $pub ) ) . ' 2>&1', $o, $rc );
	return $rc;
};
$rc   = $gen( $zipf, 'https://agenciadsmk.github.io/dsmk-legal', base64_encode( sodium_crypto_sign_secretkey( $kp ) ) );
$json = json_decode( (string) @file_get_contents( $tmpd . '/info.json' ), true );
$val  = DSMKC_Updater::validate( $json );
ok( 0 === $rc && is_array( $val ) && DSMKC_VERSION === $val['version'] && hash_file( 'sha256', $zipf ) === $val['sha256'], 'make-update-json: JSON firmado que el actualizador acepta, con la versión y la huella del ZIP' );
ok( false !== strpos( $json['changelog_html'], '<h3>' . DSMKC_VERSION ) && false !== strpos( $json['changelog_html'], '<li>' ), 'make-update-json: incluye el changelog de la versión en HTML' );
$wrong = $tmpd . '/dsmk-legal-0.0.1.zip';
copy( $zipf, $wrong );
ok( 1 === $gen( $zipf, 'http://inseguro.es', base64_encode( sodium_crypto_sign_secretkey( $kp ) ) ) && 1 === $gen( $wrong, 'https://x.es', base64_encode( sodium_crypto_sign_secretkey( $kp ) ) ), 'make-update-json: se niega con URL http o con un ZIP de otra versión' );
ok( 1 === $gen( $zipf, 'https://x.es', null ), 'make-update-json: sin clave de firma no publica nada' );
$otherkey = sodium_crypto_sign_keypair();
ok( 1 === $gen( $zipf, 'https://x.es', base64_encode( sodium_crypto_sign_secretkey( $otherkey ) ) ), 'make-update-json: se niega si la clave que firma no es la pareja de la clave pública del plugin' );
if ( '' === DSMKC_Updater::PUBLIC_KEY ) {
	ok( 1 === $gen( $zipf, 'https://x.es', base64_encode( sodium_crypto_sign_secretkey( $kp ) ), '' ), 'make-update-json: se niega mientras PUBLIC_KEY esté vacía (hay que ejecutar make-signing-key)' );
}
array_map( 'unlink', glob( $tmpd . '/*' ) );
rmdir( $tmpd );

/* ------------------------------------------------------------------ */
$total = $GLOBALS['pass'] + count( $GLOBALS['fail'] );
echo "\nResultado: {$GLOBALS['pass']}/{$total} correctas\n";
if ( $GLOBALS['fail'] ) {
	echo "Fallos:\n - " . implode( "\n - ", $GLOBALS['fail'] ) . "\n";
	exit( 1 );
}
