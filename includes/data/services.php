<?php
/**
 * DSMK Legal — Catálogo de servicios y cookies conocidos.
 *
 * @package   DSMK_Legal
 * @file      includes/data/services.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Devuelve el catálogo de servicios de terceros y propios que el plugin sabe
 *   detectar, bloquear y documentar en la política de cookies.
 *
 * Estructura de cada servicio (clave = id interno, [a-z0-9_]):
 *   name      (string) Nombre comercial del servicio.
 *   provider  (string) Titular / proveedor (o "Propia" para cookies del sitio).
 *   category  (string) necessary | preferences | analytics | marketing.
 *   privacy   (string) URL de la política de privacidad del proveedor.
 *   transfer  (string) Transferencia internacional ('' si no hay).
 *   src       (array)  Subcadenas que identifican un <script src>, recurso o URL dinámica.
 *   inline    (array)  Subcadenas que identifican un <script> en línea que carga el servicio.
 *   iframe    (array)  Subcadenas de src de <iframe> (contenidos incrustados).
 *   img       (array)  Subcadenas de src de <img> (píxeles de seguimiento).
 *   cookies   (array)  Lista de [ nombre (admite *), duración, finalidad, propia|tercero ].
 *
 * Reglas de mantenimiento (ver CLAUDE.md):
 *   - El orden importa: la primera coincidencia gana. Los patrones específicos
 *     (p. ej. gtag/js?id=AW-) van ANTES que los genéricos (gtag/js).
 *   - Las duraciones proceden de la documentación pública de cada proveedor;
 *     revisar al menos una vez al año (anotar fecha en CHANGELOG.md).
 *   - Nunca clasificar como "necessary" un servicio cuya finalidad principal
 *     sea analítica o publicitaria.
 *
 * Revisión del catálogo: 2026-11-04.
 */

defined( 'ABSPATH' ) || exit;

$google_privacy = 'https://policies.google.com/privacy';
$google_transfer = 'EE. UU. (Google LLC, adherida al EU-US Data Privacy Framework)';
$meta_transfer   = 'EE. UU. (Meta Platforms Inc., adherida al EU-US Data Privacy Framework)';
$ms_transfer     = 'EE. UU. (Microsoft Corp., adherida al EU-US Data Privacy Framework)';

return array(

	/* =====================================================================
	 * NECESARIAS (técnicas, exentas del art. 22.2 LSSI)
	 * ===================================================================== */

	'dsmk_cookies'     => array(
		'name'     => 'Gestión del consentimiento',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'cookies'  => array(
			array( 'dsmkc_consent', '{consent_duration}', 'Guarda tus preferencias de consentimiento de cookies para no volver a preguntarte.', 'propia' ),
		),
	),
	'wordpress'        => array(
		'name'     => 'WordPress',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'cookies'  => array(
			array( 'wordpress_test_cookie', 'Sesión', 'Comprueba si el navegador acepta cookies.', 'propia' ),
			array( 'wordpress_logged_in_*', 'Sesión o 14 días', 'Mantiene la sesión de usuarios registrados.', 'propia' ),
			array( 'wordpress_sec_*', 'Sesión o 14 días', 'Seguridad de la sesión de usuarios registrados.', 'propia' ),
			array( 'wordpress_*', 'Sesión', 'Autenticación de usuarios registrados en el área privada.', 'propia' ),
			array( 'wp-settings-*', '1 año', 'Preferencias del panel de usuarios registrados.', 'propia' ),
			array( 'wp-settings-time-*', '1 año', 'Fecha de las preferencias del panel de usuarios registrados.', 'propia' ),
			array( 'comment_author_*', '347 días', 'Recuerda tus datos de comentario si marcas la casilla correspondiente.', 'propia' ),
			array( 'wp-postpass_*', '10 días', 'Acceso a contenidos protegidos con contraseña.', 'propia' ),
		),
	),
	'wp_consent_api'   => array(
		'name'     => 'WP Consent API',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( '/plugins/wp-consent-api/' ),
		'cookies'  => array(
			array( 'wp_consent_*', '{consent_duration}', 'Comunica tus preferencias de consentimiento a otros plugins de la web.', 'propia' ),
		),
	),
	'php_session'      => array(
		'name'     => 'Sesión del servidor',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'cookies'  => array(
			array( 'PHPSESSID', 'Sesión', 'Identifica la sesión de navegación en el servidor.', 'propia' ),
		),
	),
	'woocommerce'      => array(
		'name'     => 'WooCommerce',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( '/plugins/woocommerce/assets/' ),
		'cookies'  => array(
			array( 'woocommerce_cart_hash', 'Sesión', 'Detecta cambios en el carrito de la compra.', 'propia' ),
			array( 'woocommerce_items_in_cart', 'Sesión', 'Indica si hay productos en el carrito.', 'propia' ),
			array( 'wp_woocommerce_session_*', '2 días', 'Mantiene el carrito y la sesión de compra.', 'propia' ),
			array( 'woocommerce_recently_viewed', 'Sesión', 'Productos vistos recientemente (widget solicitado por el usuario).', 'propia' ),
			array( 'store_notice*', 'Sesión', 'Recuerda si has cerrado el aviso de la tienda.', 'propia' ),
		),
	),
	'woo_attribution'  => array(
		'name'     => 'WooCommerce · atribución de pedidos',
		'provider' => 'Propia',
		'category' => 'marketing',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( 'sourcebuster', 'order-attribution' ),
		'cookies'  => array(
			array( 'sbjs_*', 'Sesión', 'Registra el origen de la visita (campaña, buscador, red social) para atribuir los pedidos.', 'propia' ),
		),
	),
	'wpml'             => array(
		'name'     => 'WPML',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( '/plugins/sitepress-multilingual-cms/' ),
		'cookies'  => array(
			array( 'wp-wpml_current_language', '1 día', 'Recuerda el idioma elegido por el usuario.', 'propia' ),
			array( '_icl_*', '1 día', 'Recuerda el idioma elegido por el usuario.', 'propia' ),
		),
	),
	'polylang'         => array(
		'name'     => 'Polylang',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( '/plugins/polylang/' ),
		'cookies'  => array(
			array( 'pll_language', '1 año', 'Recuerda el idioma elegido por el usuario.', 'propia' ),
		),
	),
	'translatepress'   => array(
		'name'     => 'TranslatePress',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'src'      => array( '/plugins/translatepress-multilingual/' ),
		'cookies'  => array(
			array( 'trp_language', '30 días', 'Recuerda el idioma elegido por el usuario.', 'propia' ),
		),
	),
	'cloudflare'       => array(
		'name'     => 'Cloudflare',
		'provider' => 'Cloudflare, Inc.',
		'category' => 'necessary',
		'privacy'  => 'https://www.cloudflare.com/privacypolicy/',
		'transfer' => 'EE. UU. (Cloudflare Inc., adherida al EU-US Data Privacy Framework)',
		'cookies'  => array(
			array( '__cf_bm', '30 minutos', 'Distingue personas de robots para proteger la web.', 'propia' ),
			array( 'cf_clearance', '1 año', 'Recuerda que has superado la comprobación de seguridad.', 'propia' ),
			array( '_cfuvid', 'Sesión', 'Limitación de peticiones (seguridad).', 'propia' ),
		),
	),
	'wordfence'        => array(
		'name'     => 'Wordfence',
		'provider' => 'Propia',
		'category' => 'necessary',
		'privacy'  => '',
		'transfer' => '',
		'cookies'  => array(
			array( 'wfwaf-authcookie-*', '12 horas', 'Cortafuegos: identifica a usuarios registrados.', 'propia' ),
			array( 'wf_loginalerted_*', '1 año', 'Seguridad: avisos de inicio de sesión.', 'propia' ),
		),
	),
	'recaptcha'        => array(
		'name'     => 'Google reCAPTCHA',
		'provider' => 'Google Ireland Limited',
		'category' => 'necessary',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'google.com/recaptcha', 'gstatic.com/recaptcha', 'recaptcha.net/recaptcha' ),
		'iframe'   => array( 'google.com/recaptcha' ),
		'cookies'  => array(
			array( '_GRECAPTCHA', '6 meses', 'Seguridad: protege los formularios frente a spam y abusos.', 'tercero' ),
		),
	),
	'stripe'           => array(
		'name'     => 'Stripe',
		'provider' => 'Stripe Payments Europe, Ltd.',
		'category' => 'necessary',
		'privacy'  => 'https://stripe.com/es/privacy',
		'transfer' => 'EE. UU. (Stripe Inc., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'js.stripe.com' ),
		'iframe'   => array( 'js.stripe.com' ),
		'cookies'  => array(
			array( '__stripe_mid', '1 año', 'Prevención del fraude en pagos.', 'propia' ),
			array( '__stripe_sid', '30 minutos', 'Prevención del fraude en pagos.', 'propia' ),
		),
	),
	'paypal'           => array(
		'name'     => 'PayPal',
		'provider' => 'PayPal (Europe) S.à r.l. et Cie, S.C.A.',
		'category' => 'necessary',
		'privacy'  => 'https://www.paypal.com/es/legalhub/privacy-full',
		'transfer' => '',
		'src'      => array( 'paypal.com/sdk/js', 'paypalobjects.com' ),
		'iframe'   => array( 'paypal.com/smart' ),
		'cookies'  => array(
			array( 'ts_c', '3 años', 'Prevención del fraude en pagos (PayPal).', 'tercero' ),
			array( 'x-pp-s', 'Sesión', 'Sesión de pago (PayPal).', 'tercero' ),
		),
	),

	/* =====================================================================
	 * PREFERENCIAS / FUNCIONALES
	 * ===================================================================== */

	'google_translate' => array(
		'name'     => 'Google Translate',
		'provider' => 'Google Ireland Limited',
		'category' => 'preferences',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'translate.google.com/translate_a', 'translate.googleapis.com' ),
		'cookies'  => array(
			array( 'googtrans', 'Sesión', 'Recuerda el idioma de traducción automática elegido.', 'propia' ),
		),
	),
	'tawk'             => array(
		'name'     => 'Tawk.to (chat)',
		'provider' => 'tawk.to inc.',
		'category' => 'preferences',
		'privacy'  => 'https://www.tawk.to/privacy-policy/',
		'transfer' => 'EE. UU.',
		'src'      => array( 'embed.tawk.to' ),
		'inline'   => array( 'embed.tawk.to' ),
		'cookies'  => array(
			array( 'twk_uuid_*', '6 meses', 'Identifica la conversación del chat de atención.', 'propia' ),
			array( 'TawkConnectionTime', 'Sesión', 'Gestiona la conexión del chat.', 'propia' ),
		),
	),
	'crisp'            => array(
		'name'     => 'Crisp (chat)',
		'provider' => 'Crisp IM SAS',
		'category' => 'preferences',
		'privacy'  => 'https://crisp.chat/es/privacy/',
		'transfer' => '',
		'src'      => array( 'client.crisp.chat' ),
		'inline'   => array( 'client.crisp.chat' ),
		'cookies'  => array(
			array( 'crisp-client/*', '6 meses', 'Mantiene la conversación del chat de atención.', 'propia' ),
		),
	),
	'calendly'         => array(
		'name'     => 'Calendly',
		'provider' => 'Calendly LLC',
		'category' => 'preferences',
		'privacy'  => 'https://calendly.com/privacy',
		'transfer' => 'EE. UU. (Calendly LLC, adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'assets.calendly.com' ),
		'iframe'   => array( 'calendly.com/' ),
		'cookies'  => array(
			array( '__cf_bm', '30 minutos', 'Seguridad del widget de reservas (Calendly).', 'tercero' ),
		),
	),

	/* =====================================================================
	 * ANALÍTICAS
	 * ===================================================================== */

	'google_ads'       => array( // Va antes que GA4: comparte cargador gtag.js.
		'name'     => 'Google Ads',
		'provider' => 'Google Ireland Limited',
		'category' => 'marketing',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'gtag/js?id=AW-', 'gtag/js?id=DC-', 'googleadservices.com', 'googleads.g.doubleclick.net', 'doubleclick.net' ),
		'img'      => array( 'googleads.g.doubleclick.net', 'googleadservices.com/pagead/conversion' ),
		'iframe'   => array( 'doubleclick.net' ),
		'cookies'  => array(
			array( '_gcl_au', '3 meses', 'Mide la eficacia de los anuncios de Google en la web (conversiones).', 'propia' ),
			array( '_gcl_aw', '3 meses', 'Guarda el clic en un anuncio de Google para atribuir conversiones.', 'propia' ),
			array( 'IDE', '13 meses', 'Muestra anuncios personalizados según tu navegación (doubleclick.net).', 'tercero' ),
			array( 'test_cookie', '15 minutos', 'Comprueba si el navegador admite cookies publicitarias (doubleclick.net).', 'tercero' ),
		),
	),
	'adsense'          => array(
		'name'     => 'Google AdSense',
		'provider' => 'Google Ireland Limited',
		'category' => 'marketing',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'pagead2.googlesyndication.com', 'googlesyndication.com' ),
		'iframe'   => array( 'googlesyndication.com' ),
		'cookies'  => array(
			array( '__gads', '13 meses', 'Muestra y mide anuncios en la web.', 'propia' ),
			array( '__gpi', '13 meses', 'Muestra y mide anuncios en la web.', 'propia' ),
			array( '__eoi', '6 meses', 'Prevención del fraude publicitario.', 'propia' ),
		),
	),
	'gtm'              => array(
		'name'     => 'Google Tag Manager',
		'provider' => 'Google Ireland Limited',
		'category' => 'analytics', // Ajustable en Integraciones (gtm_categoria).
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'googletagmanager.com/gtm.js' ),
		'inline'   => array( 'googletagmanager.com/gtm.js' ),
		'iframe'   => array( 'googletagmanager.com/ns.html' ),
		'cookies'  => array(),
	),
	'ga4'              => array(
		'name'     => 'Google Analytics',
		'provider' => 'Google Ireland Limited',
		'category' => 'analytics',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/ga.js', 'google-analytics.com/g/collect' ),
		'inline'   => array( 'google-analytics.com/analytics.js' ),
		'cookies'  => array(
			array( '_ga', '2 años', 'Distingue usuarios únicos para elaborar estadísticas de uso de la web.', 'propia' ),
			array( '_ga_*', '2 años', 'Mantiene el estado de la sesión para las estadísticas de Google Analytics.', 'propia' ),
			array( '_gid', '24 horas', 'Distingue usuarios para estadísticas diarias.', 'propia' ),
			array( '_gat*', '1 minuto', 'Limita la frecuencia de peticiones de Google Analytics.', 'propia' ),
		),
	),
	'clarity'          => array(
		'name'     => 'Microsoft Clarity',
		'provider' => 'Microsoft Ireland Operations Limited',
		'category' => 'analytics',
		'privacy'  => 'https://privacy.microsoft.com/es-es/privacystatement',
		'transfer' => $ms_transfer,
		'src'      => array( 'clarity.ms/tag', 'clarity.ms/s/' ),
		'inline'   => array( 'clarity.ms/tag' ),
		'cookies'  => array(
			array( '_clck', '1 año', 'Identifica al usuario para los mapas de calor y grabaciones de sesión.', 'propia' ),
			array( '_clsk', '1 día', 'Agrupa las páginas vistas en una misma sesión.', 'propia' ),
			array( 'CLID', '1 año', 'Identifica la primera visita a la web (clarity.ms).', 'tercero' ),
			array( 'MUID', '1 año', 'Identificador de navegador de Microsoft (clarity.ms / bing.com).', 'tercero' ),
		),
	),
	'hotjar'           => array(
		'name'     => 'Hotjar',
		'provider' => 'Hotjar Ltd.',
		'category' => 'analytics',
		'privacy'  => 'https://www.hotjar.com/legal/policies/privacy/',
		'transfer' => '',
		'src'      => array( 'static.hotjar.com', 'script.hotjar.com' ),
		'inline'   => array( 'static.hotjar.com' ),
		'cookies'  => array(
			array( '_hjSessionUser_*', '1 año', 'Identifica al usuario en las estadísticas y mapas de calor.', 'propia' ),
			array( '_hjSession_*', '30 minutos', 'Datos de la sesión actual para las estadísticas.', 'propia' ),
			array( '_hjIncludedInSessionSample_*', '30 minutos', 'Indica si la sesión forma parte de la muestra analizada.', 'propia' ),
		),
	),
	'matomo'           => array(
		'name'     => 'Matomo',
		'provider' => 'Propia (instalación de Matomo)',
		'category' => 'analytics',
		'privacy'  => 'https://matomo.org/privacy-policy/',
		'transfer' => '',
		'src'      => array( 'matomo.js', 'piwik.js', 'matomo.cloud' ),
		'inline'   => array( '_paq.push' ),
		'cookies'  => array(
			array( '_pk_id.*', '13 meses', 'Identifica al visitante para las estadísticas.', 'propia' ),
			array( '_pk_ses.*', '30 minutos', 'Datos de la visita actual para las estadísticas.', 'propia' ),
		),
	),
	'jetpack_stats'    => array(
		'name'     => 'Jetpack Stats',
		'provider' => 'Automattic Inc.',
		'category' => 'analytics',
		'privacy'  => 'https://automattic.com/privacy/',
		'transfer' => 'EE. UU. (Automattic Inc., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'stats.wp.com', 'pixel.wp.com' ),
		'img'      => array( 'pixel.wp.com' ),
		'cookies'  => array(
			array( 'tk_ai', 'Sesión', 'Identificador anónimo para estadísticas de visitas.', 'propia' ),
			array( 'tk_qs', '30 minutos', 'Estadísticas de visitas.', 'propia' ),
		),
	),
	'yandex'           => array(
		'name'     => 'Yandex Metrica',
		'provider' => 'Yandex LLC',
		'category' => 'analytics',
		'privacy'  => 'https://yandex.com/legal/confidential/',
		'transfer' => 'Rusia (sin decisión de adecuación)',
		'src'      => array( 'mc.yandex.ru', 'mc.yandex.com' ),
		'inline'   => array( 'mc.yandex.ru' ),
		'cookies'  => array(
			array( '_ym_uid', '1 año', 'Identifica al visitante para estadísticas.', 'propia' ),
			array( '_ym_d', '1 año', 'Fecha de la primera visita.', 'propia' ),
			array( '_ym_*', 'Sesión', 'Estadísticas de la sesión.', 'propia' ),
		),
	),
	'vimeo'            => array(
		'name'     => 'Vimeo',
		'provider' => 'Vimeo.com, Inc.',
		'category' => 'analytics',
		'privacy'  => 'https://vimeo.com/privacy',
		'transfer' => 'EE. UU. (Vimeo Inc., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'player.vimeo.com/api' ),
		'iframe'   => array( 'player.vimeo.com' ),
		'cookies'  => array(
			array( 'vuid', '2 años', 'Estadísticas de reproducción de los vídeos de Vimeo.', 'tercero' ),
			array( '__cf_bm', '30 minutos', 'Seguridad del reproductor de Vimeo.', 'tercero' ),
		),
	),

	/* =====================================================================
	 * PUBLICIDAD / MARKETING Y CONTENIDOS DE TERCEROS
	 * ===================================================================== */

	'meta_pixel'       => array(
		'name'     => 'Meta Pixel (Facebook / Instagram)',
		'provider' => 'Meta Platforms Ireland Limited',
		'category' => 'marketing',
		'privacy'  => 'https://www.facebook.com/privacy/policy/',
		'transfer' => $meta_transfer,
		'src'      => array( 'connect.facebook.net/en_US/fbevents.js', 'fbevents.js', 'connect.facebook.net/signals' ),
		'inline'   => array( 'fbevents.js', "fbq('init'", 'fbq("init"' ),
		'img'      => array( 'facebook.com/tr' ),
		'cookies'  => array(
			array( '_fbp', '3 meses', 'Identifica el navegador para mostrar y medir anuncios de Meta.', 'propia' ),
			array( '_fbc', '3 meses', 'Guarda el último clic en un anuncio de Meta.', 'propia' ),
			array( 'fr', '3 meses', 'Publicidad personalizada de Meta (facebook.com).', 'tercero' ),
		),
	),
	'facebook_social'  => array(
		'name'     => 'Facebook (plugins sociales)',
		'provider' => 'Meta Platforms Ireland Limited',
		'category' => 'marketing',
		'privacy'  => 'https://www.facebook.com/privacy/policy/',
		'transfer' => $meta_transfer,
		'src'      => array( 'connect.facebook.net' ),
		'iframe'   => array( 'facebook.com/plugins', 'facebook.com/v' ),
		'cookies'  => array(
			array( 'fr', '3 meses', 'Publicidad personalizada de Meta (facebook.com).', 'tercero' ),
			array( 'datr', '2 años', 'Seguridad e identificación del navegador (facebook.com).', 'tercero' ),
		),
	),
	'instagram'        => array(
		'name'     => 'Instagram (contenidos incrustados)',
		'provider' => 'Meta Platforms Ireland Limited',
		'category' => 'marketing',
		'privacy'  => 'https://privacycenter.instagram.com/policy',
		'transfer' => $meta_transfer,
		'src'      => array( 'instagram.com/embed.js' ),
		'iframe'   => array( 'instagram.com/' ),
		'cookies'  => array(
			array( 'csrftoken', '1 año', 'Seguridad del contenido incrustado (instagram.com).', 'tercero' ),
			array( 'mid', '2 años', 'Identifica el navegador (instagram.com).', 'tercero' ),
			array( 'ig_did', '2 años', 'Identifica el dispositivo (instagram.com).', 'tercero' ),
		),
	),
	'youtube'          => array(
		'name'     => 'YouTube',
		'provider' => 'Google Ireland Limited',
		'category' => 'marketing',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'youtube.com/iframe_api', 'youtube.com/player_api', 'youtube.com/s/player', 'youtube-nocookie.com' ),
		'iframe'   => array( 'youtube.com/embed', 'youtube-nocookie.com/embed', 'youtube.com/v/' ),
		'cookies'  => array(
			array( 'YSC', 'Sesión', 'Estadísticas de los vídeos vistos (youtube.com).', 'tercero' ),
			array( 'VISITOR_INFO1_LIVE', '6 meses', 'Estima el ancho de banda y personaliza recomendaciones y anuncios (youtube.com).', 'tercero' ),
			array( 'VISITOR_PRIVACY_METADATA', '6 meses', 'Guarda el estado de consentimiento del usuario en YouTube (youtube.com).', 'tercero' ),
			array( '__Secure-ROLLOUT_TOKEN', '6 meses', 'Funcionamiento y personalización del reproductor (youtube.com).', 'tercero' ),
		),
	),
	'google_maps'      => array(
		'name'     => 'Google Maps',
		'provider' => 'Google Ireland Limited',
		'category' => 'marketing',
		'privacy'  => $google_privacy,
		'transfer' => $google_transfer,
		'src'      => array( 'maps.googleapis.com', 'maps.google.com' ),
		'iframe'   => array( 'google.com/maps', 'maps.google.', 'maps.googleapis.com' ),
		'cookies'  => array(
			array( 'NID', '6 meses', 'Recuerda preferencias y personaliza anuncios de Google (google.com).', 'tercero' ),
			array( 'AEC', '6 meses', 'Evita que terceros malintencionados usen la sesión (google.com).', 'tercero' ),
		),
	),
	'spotify'          => array(
		'name'     => 'Spotify',
		'provider' => 'Spotify AB',
		'category' => 'marketing',
		'privacy'  => 'https://www.spotify.com/es/legal/privacy-policy/',
		'transfer' => '',
		'iframe'   => array( 'open.spotify.com/embed' ),
		'cookies'  => array(
			array( 'sp_t', '1 año', 'Identifica al usuario del reproductor de Spotify (spotify.com).', 'tercero' ),
			array( 'sp_landing', '1 día', 'Analítica del reproductor de Spotify (spotify.com).', 'tercero' ),
		),
	),
	'soundcloud'       => array(
		'name'     => 'SoundCloud',
		'provider' => 'SoundCloud Global Limited & Co. KG',
		'category' => 'marketing',
		'privacy'  => 'https://soundcloud.com/pages/privacy',
		'transfer' => '',
		'iframe'   => array( 'w.soundcloud.com/player' ),
		'cookies'  => array(
			array( 'sc_anonymous_id', '10 años', 'Identifica al usuario del reproductor (soundcloud.com).', 'tercero' ),
		),
	),
	'twitter'          => array(
		'name'     => 'X (Twitter)',
		'provider' => 'Twitter International Unlimited Company',
		'category' => 'marketing',
		'privacy'  => 'https://x.com/es/privacy',
		'transfer' => 'EE. UU.',
		'src'      => array( 'platform.twitter.com', 'static.ads-twitter.com' ),
		'inline'   => array( 'static.ads-twitter.com' ),
		'iframe'   => array( 'platform.twitter.com' ),
		'img'      => array( 'analytics.twitter.com', 't.co/i/adsct' ),
		'cookies'  => array(
			array( 'personalization_id', '13 meses', 'Personalización de contenidos y anuncios de X (x.com).', 'tercero' ),
			array( 'muc_ads', '13 meses', 'Medición de campañas publicitarias de X (t.co).', 'tercero' ),
		),
	),
	'tiktok'           => array(
		'name'     => 'TikTok Pixel',
		'provider' => 'TikTok Technology Limited',
		'category' => 'marketing',
		'privacy'  => 'https://www.tiktok.com/legal/page/eea/privacy-policy/es',
		'transfer' => 'EE. UU., Singapur y otros (cláusulas contractuales tipo)',
		'src'      => array( 'analytics.tiktok.com' ),
		'inline'   => array( 'analytics.tiktok.com' ),
		'iframe'   => array( 'tiktok.com/embed' ),
		'cookies'  => array(
			array( '_ttp', '13 meses', 'Mide y personaliza anuncios de TikTok.', 'propia' ),
			array( '_tt_enable_cookie', '13 meses', 'Indica si el navegador admite las cookies de TikTok.', 'propia' ),
		),
	),
	'linkedin'         => array(
		'name'     => 'LinkedIn Insight Tag',
		'provider' => 'LinkedIn Ireland Unlimited Company',
		'category' => 'marketing',
		'privacy'  => 'https://es.linkedin.com/legal/privacy-policy',
		'transfer' => 'EE. UU. (LinkedIn Corp., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'snap.licdn.com', 'platform.linkedin.com' ),
		'inline'   => array( 'snap.licdn.com' ),
		'img'      => array( 'px.ads.linkedin.com' ),
		'cookies'  => array(
			array( 'li_fat_id', '30 días', 'Atribución de conversiones de campañas de LinkedIn.', 'propia' ),
			array( 'bcookie', '1 año', 'Identifica el navegador (linkedin.com).', 'tercero' ),
			array( 'lidc', '24 horas', 'Selección del centro de datos (linkedin.com).', 'tercero' ),
			array( 'UserMatchHistory', '30 días', 'Sincronización de identificadores publicitarios (linkedin.com).', 'tercero' ),
		),
	),
	'pinterest'        => array(
		'name'     => 'Pinterest Tag',
		'provider' => 'Pinterest Europe Ltd.',
		'category' => 'marketing',
		'privacy'  => 'https://policy.pinterest.com/es/privacy-policy',
		'transfer' => 'EE. UU. (Pinterest Inc., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 's.pinimg.com/ct', 'assets.pinterest.com/js' ),
		'inline'   => array( 's.pinimg.com/ct' ),
		'img'      => array( 'ct.pinterest.com' ),
		'cookies'  => array(
			array( '_pin_unauth', '1 año', 'Mide y personaliza anuncios de Pinterest.', 'propia' ),
			array( '_epik', '1 año', 'Atribución de conversiones de Pinterest.', 'propia' ),
		),
	),
	'bing_uet'         => array(
		'name'     => 'Microsoft Advertising (UET)',
		'provider' => 'Microsoft Ireland Operations Limited',
		'category' => 'marketing',
		'privacy'  => 'https://privacy.microsoft.com/es-es/privacystatement',
		'transfer' => $ms_transfer,
		'src'      => array( 'bat.bing.com' ),
		'inline'   => array( 'bat.bing.com' ),
		'img'      => array( 'bat.bing.com/action' ),
		'cookies'  => array(
			array( '_uetsid', '1 día', 'Mide conversiones de anuncios de Microsoft.', 'propia' ),
			array( '_uetvid', '13 meses', 'Mide conversiones de anuncios de Microsoft.', 'propia' ),
			array( 'MUID', '1 año', 'Identificador de navegador de Microsoft (bing.com).', 'tercero' ),
		),
	),
	'hubspot'          => array(
		'name'     => 'HubSpot',
		'provider' => 'HubSpot Ireland Limited',
		'category' => 'marketing',
		'privacy'  => 'https://legal.hubspot.com/es/privacy-policy',
		'transfer' => 'EE. UU. (HubSpot Inc., adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'js.hs-scripts.com', 'js.hs-analytics.net', 'js.hsforms.net', 'js.usemessages.com' ),
		'cookies'  => array(
			array( '__hstc', '6 meses', 'Seguimiento de visitantes para marketing (HubSpot).', 'propia' ),
			array( 'hubspotutk', '6 meses', 'Identifica al visitante y lo asocia a contactos (HubSpot).', 'propia' ),
			array( '__hssc', '30 minutos', 'Seguimiento de sesiones (HubSpot).', 'propia' ),
			array( '__hssrc', 'Sesión', 'Detecta reinicios del navegador (HubSpot).', 'propia' ),
		),
	),
	'mailchimp'        => array(
		'name'     => 'Mailchimp',
		'provider' => 'The Rocket Science Group LLC (Intuit)',
		'category' => 'marketing',
		'privacy'  => 'https://www.intuit.com/privacy/statement/',
		'transfer' => 'EE. UU. (adherida al EU-US Data Privacy Framework)',
		'src'      => array( 'chimpstatic.com', 'list-manage.com' ),
		'cookies'  => array(
			array( '_mcid', '1 año', 'Seguimiento de suscriptores y campañas de email (Mailchimp).', 'propia' ),
		),
	),
);
