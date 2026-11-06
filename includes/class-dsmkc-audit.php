<?php
/**
 * DSMK Legal — Auditoría técnica automatizada e histórico de escaneos.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-audit.php
 * @since     2.1.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Ejecutar con un botón (o cada semana por WP-Cron) una auditoría completa de
 *   la web y guardarla en un histórico:
 *     1. Entorno: WordPress, PHP, tema, plugins activos, caché, idioma, HTTPS,
 *        certificado SSL (emisor y caducidad) y cabeceras de seguridad.
 *     2. Cada página representativa se pide DOS veces:
 *        - como visitante anónimo SIN consentimiento → rastreadores ejecutables,
 *          cookies no técnicas enviadas por el servidor, recursos de terceros
 *          cargados antes de consentir (IP enviada a terceros), Google Fonts
 *          externas, presencia del banner y de los enlaces legales, formularios
 *          que recogen datos y si informan de la privacidad;
 *        - como escáner (cabecera secreta, sin bloqueos) → servicios presentes.
 *     3. Los servicios detectados se incorporan al inventario (DSMKC_Scanner).
 *     4. Se calculan las comprobaciones (DSMKC_Report) y una puntuación.
 *   Cada auditoría se guarda con su fecha, origen (manual / automático),
 *   resultados y detalle por página. El informe técnico imprimible se genera a
 *   partir de una auditoría concreta del histórico.
 *
 * Almacenamiento: opción `dsmkc_audits` (sin autoload). Se guardan las 50
 *   últimas; el detalle por página y el entorno solo en las 10 más recientes.
 *
 * Seguridad:
 *   - Solo se piden URL del propio dominio (DSMKC_Scanner::urls()).
 *   - La conexión SSL solo lee el certificado (no envía datos).
 *   - Ejecutable solo por administradores (botón con nonce) o por WP-Cron.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auditoría técnica.
 *
 * @since 2.1.0
 */
final class DSMKC_Audit {

	/** @var string Opción con el histórico. */
	const OPTION = 'dsmkc_audits';

	/** @var int Auditorías guardadas. */
	const KEEP = 50;

	/** @var int Auditorías que conservan el detalle completo. */
	const KEEP_DETAIL = 10;

	/** @var string[] Cabeceras de seguridad revisadas. */
	const SECURITY_HEADERS = array( 'strict-transport-security', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy' );

	/* =====================================================================
	 * Ejecución
	 * ===================================================================== */

	/**
	 * Ejecuta una auditoría completa y la guarda en el histórico.
	 *
	 * @since  2.1.0
	 * @param  string $trigger manual | automatico.
	 * @return array Registro de la auditoría.
	 */
	public static function run( $trigger = 'manual' ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Algunos hostings lo bloquean.
		}
		$start = microtime( true );
		$token = DSMKC_Settings::scan_token();
		$pages = array();
		$found = array();
		$names = array();

		foreach ( DSMKC_Scanner::urls() as $url ) {
			$visitor = self::fetch( $url, array() );
			$scanner = self::fetch( $url, array( 'X-DSMKC-Scan' => $token ) );
			$page    = self::analyze_page( $url, $visitor );

			if ( $scanner['ok'] ) {
				$scan = DSMKC_Blocker::analyze( $scanner['body'] );
				foreach ( $scan['services'] as $id => $cat ) {
					$found[ $id ] = 'auditoría';
				}
				$page['services'] = $scan['services'];
				foreach ( $scanner['cookies'] as $name ) {
					$names[] = $name;
				}
			}
			unset( $page['body'] );
			$pages[] = $page;
		}

		// Incorpora al inventario lo detectado (puede volver a pedir consentimiento si hay servicios nuevos).
		$merge = DSMKC_Scanner::merge_results(
			array(
				'services' => $found,
				'cookies'  => array_unique( $names ),
				'storage'  => array(),
				'domains'  => array(),
				'via'      => 'auditoría',
				'scanned'  => count( $pages ),
				'errors'   => array(),
			),
			'automatico' === $trigger
		);

		$record = array(
			'id'             => gmdate( 'YmdHis' ) . '-' . substr( md5( wp_rand() . microtime() ), 0, 6 ),
			'date'           => time(),
			'trigger'        => $trigger,
			'user'           => function_exists( 'wp_get_current_user' ) && wp_get_current_user()->exists() ? wp_get_current_user()->user_login : '',
			'plugin_version' => DSMKC_VERSION,
			'policy_version' => (int) DSMKC_Settings::data()['policy_version'],
			'banner_hash'    => DSMKC_Legal::banner_hash(),
			'env'            => self::environment(),
			'ssl'            => self::ssl_info(),
			'pages'          => $pages,
			'new_services'   => $merge['new_services'],
			'new_unknown'    => $merge['new_unknown'],
			'bumped'         => $merge['bumped'],
		);
		$record['totals']   = self::totals( $record );
		$record['checks']   = array_merge( DSMKC_Report::checks( false ), self::checks( $record ) );
		$record['summary']  = DSMKC_Report::summary( $record['checks'] );
		$record['score']    = self::score( $record['summary'] );
		$record['duration'] = round( microtime( true ) - $start, 1 );

		self::save( $record );
		return $record;
	}

	/**
	 * Petición HTTP a una URL de la propia web.
	 *
	 * @since  2.1.0
	 * @param  string $url     URL.
	 * @param  array  $headers Cabeceras extra.
	 * @return array{ok:bool, status:int, ms:int, bytes:int, body:string, headers:array, cookies:string[], error:string}
	 */
	private static function fetch( $url, array $headers ) {
		$t0       = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 3,
				'headers'     => array_merge( array( 'Cache-Control' => 'no-cache' ), $headers ),
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Mozilla/5.0 (DSMK-Legal-Audit/' . DSMKC_VERSION . ')',
			)
		);
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'ms' => $ms, 'bytes' => 0, 'body' => '', 'headers' => array(), 'cookies' => array(), 'error' => $response->get_error_message() );
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$cookies = array();
		foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
			$cookies[] = $cookie->name;
		}
		$raw_headers = wp_remote_retrieve_headers( $response );
		$headers_out = array();
		foreach ( self::SECURITY_HEADERS as $h ) {
			$value = is_object( $raw_headers ) || is_array( $raw_headers ) ? ( isset( $raw_headers[ $h ] ) ? $raw_headers[ $h ] : '' ) : '';
			$headers_out[ $h ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'ok'      => true,
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'ms'      => $ms,
			'bytes'   => strlen( $body ),
			'body'    => $body,
			'headers' => $headers_out,
			'cookies' => $cookies,
			'error'   => '',
		);
	}

	/* =====================================================================
	 * Análisis de una página (vista de visitante sin consentimiento)
	 * ===================================================================== */

	/**
	 * Analiza la respuesta que recibe un visitante sin consentimiento.
	 *
	 * Público para poder probarlo sin red.
	 *
	 * @since  2.1.0
	 * @param  string $url      URL.
	 * @param  array  $response Resultado de fetch().
	 * @return array Datos de la página.
	 */
	public static function analyze_page( $url, array $response ) {
		$page = array(
			'url'            => $url,
			'ok'             => $response['ok'],
			'status'         => $response['status'],
			'ms'             => $response['ms'],
			'bytes'          => $response['bytes'],
			'error'          => $response['error'],
			'headers'        => $response['headers'],
			'leaks'          => array(),
			'cookies'        => array(),
			'bad_cookies'    => array(),
			'banner'         => false,
			'legal_links'    => array(),
			'missing_links'  => array(),
			'forms'          => array(),
			'external'       => array(),
			'google_fonts'   => false,
			'services'       => array(),
		);
		if ( ! $response['ok'] ) {
			return $page;
		}
		$html = $response['body'];

		// Rastreadores ejecutables sin consentimiento.
		$analysis      = DSMKC_Blocker::analyze( $html );
		$page['leaks'] = $analysis['leaks'];

		// Cookies que el servidor envía a un visitante nuevo.
		foreach ( $response['cookies'] as $name ) {
			$svc = DSMKC_Services::service_for_cookie( $name );
			$cat = $svc ? DSMKC_Services::category_of( $svc ) : ( DSMKC_Services::is_manual( $name ) ? 'manual' : 'desconocida' );
			$page['cookies'][ $name ] = $cat;
			if ( ! in_array( $cat, array( 'necessary', 'manual' ), true ) ) {
				$page['bad_cookies'][] = $name;
			}
		}

		// Banner y botón para cambiar el consentimiento.
		$page['banner'] = false !== strpos( $html, 'id="dsmkc-root"' );

		// Enlaces a los documentos legales (acceso permanente y fácil, art. 10 LSSI).
		foreach ( DSMKC_Documents::enabled() as $type => $doc ) {
			$doc_url = DSMKC_Legal::page_url( $type );
			if ( '' === $doc_url ) {
				continue;
			}
			$path = (string) wp_parse_url( $doc_url, PHP_URL_PATH );
			$hit  = false !== strpos( $html, 'href="' . $doc_url ) || ( '' !== $path && '/' !== $path && false !== strpos( $html, $path . '"' ) );
			if ( $hit ) {
				$page['legal_links'][] = $type;
			} else {
				$page['missing_links'][] = $type;
			}
		}

		$page['forms']    = self::forms( $html );
		$external         = self::external_resources( $html );
		$page['external'] = $external['hosts'];
		$page['google_fonts'] = $external['google_fonts'];
		return $page;
	}

	/**
	 * Formularios que recogen datos personales y si informan de la privacidad.
	 *
	 * Se ignoran buscadores, carrito y formularios sin campos de datos.
	 *
	 * @since  2.1.0
	 * @param  string $html HTML.
	 * @return array<int, array{fields:string[], privacy_link:bool, checkbox:bool, info:bool}>
	 */
	public static function forms( $html ) {
		$out = array();
		if ( ! preg_match_all( '#<form\b([^>]*)>(.*?)</form>#is', $html, $forms, PREG_SET_ORDER ) ) {
			return $out;
		}
		$privacy_url  = DSMKC_Legal::page_url( 'privacidad' );
		$privacy_path = $privacy_url ? (string) wp_parse_url( $privacy_url, PHP_URL_PATH ) : '';
		foreach ( $forms as $form ) {
			$attrs = strtolower( $form[1] );
			$inner = $form[2];
			if ( false !== strpos( $attrs, 'search' ) || preg_match( '#name=["\']s["\']#i', $inner ) || false !== strpos( $attrs, 'cart' ) ) {
				continue;
			}
			$fields = array();
			if ( preg_match( '#type=["\']email["\']|name=["\'][^"\']*(e-?mail|correo)#i', $inner ) ) {
				$fields[] = 'email';
			}
			if ( preg_match( '#type=["\']tel["\']|name=["\'][^"\']*(phone|tel[eé]fono|movil)#i', $inner ) ) {
				$fields[] = 'teléfono';
			}
			if ( preg_match( '#name=["\'][^"\']*(name|nombre)#i', $inner ) ) {
				$fields[] = 'nombre';
			}
			if ( preg_match( '#<textarea#i', $inner ) ) {
				$fields[] = 'mensaje';
			}
			if ( ! $fields ) {
				continue;
			}
			$text           = strtolower( wp_strip_all_tags( $inner, true ) );
			$privacy_link   = ( '' !== $privacy_path && false !== strpos( $inner, $privacy_path ) ) || (bool) preg_match( '#href=["\'][^"\']*(privacidad|privacy)#i', $inner );
			$checkbox       = (bool) preg_match( '#type=["\']checkbox["\']#i', $inner );
			$info           = false !== strpos( $text, 'responsable' ) || false !== strpos( $text, 'finalidad' ) || false !== strpos( $text, 'protección de datos' ) || false !== strpos( $text, 'proteccion de datos' );
			$out[]          = array(
				'fields'       => $fields,
				'privacy_link' => $privacy_link,
				'checkbox'     => $checkbox,
				'info'         => $info,
			);
		}
		return $out;
	}

	/**
	 * Recursos de terceros que el navegador cargaría ANTES de consentir.
	 *
	 * Cada uno implica enviar la IP del visitante al tercero.
	 *
	 * @since  2.1.0
	 * @param  string $html HTML (ya filtrado por el bloqueador).
	 * @return array{hosts:array<string,int>, google_fonts:bool}
	 */
	public static function external_resources( $html ) {
		$own   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$hosts = array();
		$fonts = false;
		$patterns = array(
			'#<script\b(?![^>]*type=["\']text/plain["\'])[^>]*\ssrc=["\']([^"\']+)["\']#i',
			'#<link\b[^>]*rel=["\'](?:stylesheet|preload|modulepreload)["\'][^>]*href=["\']([^"\']+)["\']#i',
			'#<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\'](?:stylesheet|preload|modulepreload)["\']#i',
			'#<iframe\b[^>]*\ssrc=["\']((?!about:blank)[^"\']+)["\']#i',
			'#<img\b[^>]*\ssrc=["\']([^"\']+)["\']#i',
		);
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $src ) {
				$src  = html_entity_decode( $src, ENT_QUOTES );
				$host = (string) wp_parse_url( 0 === strpos( $src, '//' ) ? 'https:' . $src : $src, PHP_URL_HOST );
				if ( '' === $host || $host === $own || substr( $host, -strlen( '.' . $own ) ) === '.' . $own ) {
					continue;
				}
				$hosts[ $host ] = isset( $hosts[ $host ] ) ? $hosts[ $host ] + 1 : 1;
				if ( false !== strpos( $host, 'fonts.googleapis.com' ) || false !== strpos( $host, 'fonts.gstatic.com' ) ) {
					$fonts = true;
				}
			}
		}
		ksort( $hosts );
		return array( 'hosts' => $hosts, 'google_fonts' => $fonts );
	}

	/* =====================================================================
	 * Entorno técnico
	 * ===================================================================== */

	/**
	 * Datos técnicos de la instalación.
	 *
	 * @since  2.1.0
	 * @return array
	 */
	public static function environment() {
		global $wp_version;
		$theme   = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$plugins = array();
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$all = get_plugins();
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				if ( isset( $all[ $file ] ) ) {
					$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
				}
			}
		}
		$cache = array();
		foreach ( array( 'WP Rocket' => 'rocket_clean_domain', 'W3 Total Cache' => 'w3tc_flush_all', 'WP Super Cache' => 'wp_cache_clear_cache', 'SiteGround Optimizer' => 'sg_cachepress_purge_cache', 'WP Fastest Cache' => 'wpfc_clear_all_cache' ) as $name => $fn ) {
			if ( function_exists( $fn ) ) {
				$cache[] = $name;
			}
		}
		if ( defined( 'LSCWP_V' ) ) {
			$cache[] = 'LiteSpeed Cache';
		}
		return array(
			'site'        => home_url( '/' ),
			'wordpress'   => isset( $wp_version ) ? $wp_version : get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'server'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'theme'       => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (hijo de ' . $theme->parent()->get( 'Name' ) . ')' : '' ) : '',
			'locale'      => function_exists( 'get_locale' ) ? get_locale() : '',
			'timezone'    => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '',
			'multisite'   => function_exists( 'is_multisite' ) && is_multisite(),
			'https'       => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'wp_cache'    => defined( 'WP_CACHE' ) && WP_CACHE,
			'cache'       => $cache,
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'memory'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'plugins'     => $plugins,
		);
	}

	/**
	 * Certificado SSL de la web: emisor, validez y días restantes.
	 *
	 * @since  2.1.0
	 * @return array{checked:bool, valid:bool, issuer:string, valid_to:int, days:int, error:string}
	 */
	public static function ssl_info() {
		$out  = array( 'checked' => false, 'valid' => false, 'issuer' => '', 'valid_to' => 0, 'days' => 0, 'error' => '' );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) || '' === $host || ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
			return $out;
		}
		$context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ) ) );
		$client  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$out['checked'] = true;
		if ( ! $client ) {
			$out['error'] = (string) $errstr;
			return $out;
		}
		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			$out['error'] = 'Sin certificado';
			return $out;
		}
		$cert            = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		$out['issuer']   = isset( $cert['issuer']['O'] ) ? $cert['issuer']['O'] : ( isset( $cert['issuer']['CN'] ) ? $cert['issuer']['CN'] : '' );
		$out['valid_to'] = isset( $cert['validTo_time_t'] ) ? (int) $cert['validTo_time_t'] : 0;
		$out['days']     = $out['valid_to'] ? (int) floor( ( $out['valid_to'] - time() ) / DAY_IN_SECONDS ) : 0;
		$out['valid']    = $out['days'] > 0;
		return $out;
	}

	/* =====================================================================
	 * Resultados
	 * ===================================================================== */

	/**
	 * Totales de una auditoría.
	 *
	 * @since  2.1.0
	 * @param  array $record Auditoría.
	 * @return array
	 */
	public static function totals( array $record ) {
		$t = array( 'pages' => 0, 'errors' => 0, 'leaks' => 0, 'bad_cookies' => 0, 'without_banner' => 0, 'missing_links' => 0, 'forms' => 0, 'forms_without_privacy' => 0, 'external_hosts' => array(), 'google_fonts' => false, 'services' => array(), 'avg_ms' => 0 );
		$ms = 0;
		foreach ( $record['pages'] as $page ) {
			++$t['pages'];
			if ( ! $page['ok'] || $page['status'] >= 400 ) {
				++$t['errors'];
				continue;
			}
			$ms                 += $page['ms'];
			$t['leaks']         += count( $page['leaks'] );
			$t['bad_cookies']   += count( $page['bad_cookies'] );
			$t['without_banner'] += $page['banner'] ? 0 : 1;
			$t['missing_links'] += count( $page['missing_links'] );
			foreach ( $page['forms'] as $form ) {
				++$t['forms'];
				if ( ! $form['privacy_link'] && ! $form['info'] ) {
					++$t['forms_without_privacy'];
				}
			}
			foreach ( $page['external'] as $host => $n ) {
				$t['external_hosts'][ $host ] = isset( $t['external_hosts'][ $host ] ) ? $t['external_hosts'][ $host ] + $n : $n;
			}
			$t['google_fonts'] = $t['google_fonts'] || $page['google_fonts'];
			foreach ( $page['services'] as $id => $cat ) {
				$t['services'][ $id ] = $cat;
			}
		}
		$ok_pages    = $t['pages'] - $t['errors'];
		$t['avg_ms'] = $ok_pages ? (int) round( $ms / $ok_pages ) : 0;
		ksort( $t['external_hosts'] );
		return $t;
	}

	/**
	 * Comprobaciones propias de la auditoría (complementan DSMKC_Report::checks()).
	 *
	 * @since  2.1.0
	 * @param  array $record Auditoría (con totals).
	 * @return array
	 */
	public static function checks( array $record ) {
		$t      = $record['totals'];
		$env    = $record['env'];
		$ssl    = $record['ssl'];
		$checks = array();
		$c      = static function ( $id, $group, $label, $status, $detail, $req ) use ( &$checks ) {
			$checks[] = compact( 'id', 'group', 'label', 'status', 'detail', 'req' );
		};

		/* translators: 1: páginas, 2: errores. */
		$c( 'pages', 'escaneo', __( 'Páginas analizadas correctamente', 'dsmk-legal' ), $t['pages'] && ! $t['errors'] ? 'ok' : ( $t['pages'] > $t['errors'] ? 'warn' : 'fail' ), sprintf( __( '%1$d páginas, %2$d con error.', 'dsmk-legal' ), $t['pages'], $t['errors'] ), __( 'Auditoría', 'dsmk-legal' ) );

		$c( 'leaks', 'cookies', __( 'Ningún rastreador se ejecuta sin consentimiento (todas las páginas)', 'dsmk-legal' ), $t['leaks'] ? 'fail' : 'ok', $t['leaks'] ? sprintf( __( '%d elementos de seguimiento ejecutables sin consentimiento. Revisa el detalle por página (caché antigua o código fuera de WordPress).', 'dsmk-legal' ), $t['leaks'] ) : __( '0 scripts, iframes o píxeles de seguimiento ejecutables antes de consentir.', 'dsmk-legal' ), 'LSSI art. 22.2 · AEPD' );

		$c( 'server_cookies', 'cookies', __( 'El servidor no envía cookies no técnicas a visitantes nuevos', 'dsmk-legal' ), $t['bad_cookies'] ? 'fail' : 'ok', $t['bad_cookies'] ? sprintf( __( '%d cookies no técnicas o sin clasificar en las cabeceras. Ver detalle por página.', 'dsmk-legal' ), $t['bad_cookies'] ) : __( 'Solo cookies técnicas (o ninguna) antes de consentir.', 'dsmk-legal' ), 'LSSI art. 22.2' );

		$c( 'banner_all', 'cookies', __( 'El banner y el botón para cambiar el consentimiento están en todas las páginas', 'dsmk-legal' ), $t['without_banner'] ? 'fail' : 'ok', $t['without_banner'] ? sprintf( _n( '%d página sin el banner (¿plantilla sin wp_footer() o excluida?).', '%d páginas sin el banner (¿plantilla sin wp_footer() o excluida?).', $t['without_banner'], 'dsmk-legal' ), $t['without_banner'] ) : __( 'Presente en todas las páginas analizadas.', 'dsmk-legal' ), 'AEPD · RGPD art. 7.3' );

		$c( 'legal_links', 'legal', __( 'Enlaces a los textos legales accesibles desde todas las páginas', 'dsmk-legal' ), $t['missing_links'] ? 'warn' : 'ok', $t['missing_links'] ? sprintf( _n( 'Falta %d enlace. Añade [dsmk_enlaces_legales] al pie de la web.', 'Faltan %d enlaces. Añade [dsmk_enlaces_legales] al pie de la web.', $t['missing_links'], 'dsmk-legal' ), $t['missing_links'] ) : __( 'Todos los documentos activos enlazados en todas las páginas.', 'dsmk-legal' ), 'LSSI art. 10 · RGPD art. 12' );

		if ( $t['forms'] ) {
			$c( 'forms', 'privacidad', __( 'Los formularios informan de la protección de datos', 'dsmk-legal' ), $t['forms_without_privacy'] ? 'warn' : 'ok', $t['forms_without_privacy'] ? sprintf( __( '%1$d de %2$d formularios sin enlace a la política de privacidad ni información básica (responsable, finalidad, derechos).', 'dsmk-legal' ), $t['forms_without_privacy'], $t['forms'] ) : sprintf( __( '%d formularios con enlace o información de privacidad.', 'dsmk-legal' ), $t['forms'] ), 'RGPD art. 13 · LOPDGDD art. 11' );
		}

		$hosts = array_keys( $t['external_hosts'] );
		$c( 'external', 'privacidad', __( 'Recursos de terceros cargados antes del consentimiento', 'dsmk-legal' ), $hosts ? 'warn' : 'ok', $hosts ? sprintf( __( 'Se envía la IP del visitante a: %s. Valora alojarlos en la propia web.', 'dsmk-legal' ), implode( ', ', $hosts ) ) : __( 'Ningún recurso de terceros antes de consentir.', 'dsmk-legal' ), 'RGPD art. 6 · 44' );

		if ( $t['google_fonts'] ) {
			$c( 'google_fonts', 'privacidad', __( 'Google Fonts cargadas desde los servidores de Google', 'dsmk-legal' ), 'warn', __( 'Transfiere la IP del visitante a Google sin consentimiento. En Elementor: Ajustes › Rendimiento › cargar Google Fonts localmente.', 'dsmk-legal' ), 'RGPD art. 6' );
		}

		$c( 'https', 'seguridad', __( 'Web servida por HTTPS', 'dsmk-legal' ), $env['https'] ? 'ok' : 'fail', $env['https'] ? __( 'Sí.', 'dsmk-legal' ) : __( 'La web no usa HTTPS: los datos de los formularios viajan sin cifrar.', 'dsmk-legal' ), 'RGPD art. 32' );

		if ( $ssl['checked'] ) {
			$c( 'ssl', 'seguridad', __( 'Certificado SSL válido', 'dsmk-legal' ), ! $ssl['valid'] ? 'fail' : ( $ssl['days'] < 15 ? 'warn' : 'ok' ), $ssl['valid'] ? sprintf( __( 'Emitido por %1$s. Caduca el %2$s (%3$d días).', 'dsmk-legal' ), $ssl['issuer'], wp_date( 'd/m/Y', $ssl['valid_to'] ), $ssl['days'] ) : sprintf( __( 'No se pudo validar: %s', 'dsmk-legal' ), $ssl['error'] ? $ssl['error'] : __( 'caducado', 'dsmk-legal' ) ), 'RGPD art. 32' );
		}

		$home    = isset( $record['pages'][0]['headers'] ) ? $record['pages'][0]['headers'] : array();
		$missing = array();
		foreach ( self::SECURITY_HEADERS as $h ) {
			if ( empty( $home[ $h ] ) ) {
				$missing[] = $h;
			}
		}
		$c( 'headers', 'seguridad', __( 'Cabeceras de seguridad HTTP', 'dsmk-legal' ), count( $missing ) > 3 ? 'warn' : 'ok', $missing ? sprintf( __( 'No presentes: %s.', 'dsmk-legal' ), implode( ', ', $missing ) ) : __( 'Todas presentes.', 'dsmk-legal' ), __( 'Buenas prácticas (RGPD art. 32)', 'dsmk-legal' ) );

		$c( 'versions', 'seguridad', __( 'Versiones de software', 'dsmk-legal' ), version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'warn', sprintf( 'WordPress %1$s · PHP %2$s', $env['wordpress'], $env['php'] ) . ( version_compare( PHP_VERSION, '8.1', '<' ) ? ' — ' . __( 'PHP sin soporte de seguridad: actualizar.', 'dsmk-legal' ) : '' ), __( 'Buenas prácticas (RGPD art. 32)', 'dsmk-legal' ) );

		return $checks;
	}

	/**
	 * Puntuación 0-100: cumple = 1, revisar = 0,5, no cumple = 0.
	 *
	 * @since  2.1.0
	 * @param  array{ok:int, warn:int, fail:int} $summary Resumen.
	 * @return int
	 */
	public static function score( array $summary ) {
		$total = $summary['ok'] + $summary['warn'] + $summary['fail'];
		return $total ? (int) round( ( $summary['ok'] + 0.5 * $summary['warn'] ) / $total * 100 ) : 0;
	}

	/* =====================================================================
	 * Histórico
	 * ===================================================================== */

	/**
	 * Guarda una auditoría al principio del histórico.
	 *
	 * @since  2.1.0
	 * @param  array $record Auditoría.
	 * @return void
	 */
	private static function save( array $record ) {
		$history = self::history();
		array_unshift( $history, $record );
		$history = array_slice( $history, 0, self::KEEP );
		foreach ( $history as $i => $item ) {
			if ( $i >= self::KEEP_DETAIL ) {
				unset( $history[ $i ]['pages'], $history[ $i ]['env'] );
			}
		}
		update_option( self::OPTION, $history, false );
	}

	/**
	 * Histórico completo (más reciente primero).
	 *
	 * @since  2.1.0
	 * @return array[]
	 */
	public static function history() {
		$history = get_option( self::OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Una auditoría por ID (o la última si $id está vacío).
	 *
	 * @since  2.1.0
	 * @param  string $id ID.
	 * @return array|null
	 */
	public static function get( $id = '' ) {
		foreach ( self::history() as $record ) {
			if ( '' === $id || $record['id'] === $id ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Vacía el histórico.
	 *
	 * @since  2.1.0
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
