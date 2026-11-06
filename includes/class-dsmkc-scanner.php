<?php
/**
 * DSMK Legal — Escáner de servicios y cookies.
 *
 * @package   DSMK_Legal
 * @file      includes/class-dsmkc-scanner.php
 * @since     1.0.0
 * @author    DSMK Agencia Digital <hola@dsmk.es>
 * @license   GPL-2.0-or-later
 *
 * Responsabilidad:
 *   Detectar automáticamente qué servicios y cookies usa la web, en dos fases:
 *
 *   1. Escaneo de servidor (también semanal por WP-Cron): pide varias URL de
 *      la web con la cabecera secreta X-DSMKC-Scan (sin bloqueos) y analiza
 *      el HTML (scripts, iframes, píxeles) y las cabeceras Set-Cookie.
 *   2. Escaneo de navegador (botón en el panel): el navegador del administrador
 *      carga las páginas en iframes ocultos con ?dsmkc_scan=NONCE, ejecuta todo
 *      el JavaScript y devuelve las cookies propias, las claves de localStorage
 *      y los recursos cargados (dominios de terceros inyectados por GTM, etc.).
 *
 *   Resultado: servicios detectados (documentados en la política y mostrados
 *   en el panel) y elementos desconocidos para que el administrador los
 *   clasifique. Si aparecen servicios nuevos que requieren consentimiento se
 *   incrementa la versión de la política (se vuelve a preguntar) y se avisa
 *   por email.
 *
 * Seguridad:
 *   - Peticiones solo a home_url() (mismo host); nunca a URLs de terceros.
 *   - Los datos que llegan del navegador se sanean y se limitan en número y tamaño.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Escáner.
 *
 * @since 1.0.0
 */
final class DSMKC_Scanner {

	/** @var int Máximo de URL por escaneo. */
	const MAX_URLS = 10;

	/** @var int Elementos sin clasificar que se conservan (los más recientes). @since 2.2.0 */
	const MAX_UNKNOWN = 200;

	/**
	 * Registra hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function hooks() {
		add_action( 'dsmkc_weekly_scan', array( __CLASS__, 'cron' ) );
	}

	/**
	 * Tarea semanal.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function cron() {
		if ( DSMKC_Settings::get( 'escaneo_semanal' ) ) {
			// Desde 2.1.0 el escaneo semanal es una auditoría completa que queda en el histórico.
			DSMKC_Audit::run( 'automatico' );
		}
	}

	/**
	 * URL representativas de la web que se van a analizar.
	 *
	 * @since  1.0.0
	 * @return string[]
	 */
	public static function urls() {
		$urls = array( home_url( '/' ) );

		foreach ( array( 'page', 'post', 'product' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 'page' === $type ? 5 : 2,
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = get_permalink( $id );
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
			$urls[] = wc_get_page_permalink( 'cart' );
		}
		$contact = get_page_by_path( 'contacto' );
		if ( $contact ) {
			$urls[] = get_permalink( $contact );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_filter(
			array_unique( array_filter( $urls ) ),
			static function ( $url ) use ( $host ) {
				return wp_parse_url( $url, PHP_URL_HOST ) === $host; // Solo el propio dominio.
			}
		);

		/**
		 * Permite añadir URL al escaneo.
		 *
		 * @since 1.0.0
		 * @param string[] $urls URL.
		 */
		return array_slice( array_values( apply_filters( 'dsmkc_scan_urls', $urls ) ), 0, self::MAX_URLS );
	}

	/**
	 * GET a una URL de la propia web siguiendo redirecciones SOLO dentro del dominio.
	 *
	 * Seguridad (2.2.0): con `redirection => 3` WordPress reenviaba la cabecera
	 * X-DSMKC-Scan (token) a cualquier dominio al que redirigiera una página.
	 * Aquí se siguen a mano hasta 3 redirecciones y solo si el destino es el
	 * mismo host (se admite el cambio http→https y con/sin «www.»).
	 *
	 * @since  2.2.0
	 * @param  string $url  URL inicial (de DSMKC_Scanner::urls(), siempre de la web).
	 * @param  array  $args Argumentos de wp_remote_get() (se fuerza redirection = 0).
	 * @return array|WP_Error Respuesta final.
	 */
	public static function request( $url, array $args ) {
		$args['redirection'] = 0;
		$home                = self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$response            = null;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				return $response;
			}
			$headers  = wp_remote_retrieve_headers( $response );
			$location = isset( $headers['location'] ) ? $headers['location'] : '';
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
			if ( '' === $location ) {
				return $response;
			}
			$next = class_exists( 'WP_Http' ) ? WP_Http::make_absolute_url( $location, $url ) : $location;
			if ( self::bare_host( (string) wp_parse_url( $next, PHP_URL_HOST ) ) !== $home ) {
				return $response; // Redirección a otro dominio: no se sigue (el token no sale de la web).
			}
			$url = $next;
		}
		return $response;
	}

	/**
	 * Host en minúsculas sin «www.» inicial.
	 *
	 * @since  2.2.0
	 * @param  string $host Host.
	 * @return string
	 */
	private static function bare_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Añade un parámetro único para que ninguna caché sirva ni guarde la respuesta del escáner.
	 *
	 * @since  2.2.0
	 * @param  string $url URL.
	 * @return string
	 */
	public static function nocache_url( $url ) {
		return add_query_arg( 'dsmkc_nc', wp_generate_password( 10, false, false ), $url );
	}

	/**
	 * Escaneo desde el servidor.
	 *
	 * @since  1.0.0
	 * @param  bool $notify Enviar email si hay novedades.
	 * @return array Resumen del escaneo.
	 */
	public static function server_scan( $notify = false ) {
		$found    = array();
		$cookies  = array();
		$errors   = array();
		$token    = DSMKC_Settings::scan_token();
		$scanned  = 0;

		foreach ( self::urls() as $url ) {
			$response = self::request(
				self::nocache_url( $url ),
				array(
					'timeout'     => 20,
					'headers'     => array( 'X-DSMKC-Scan' => $token, 'Cache-Control' => 'no-cache' ),
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // Igual que las peticiones loopback del core.
					'user-agent'  => 'DSMK-Cookies-Scanner/' . DSMKC_VERSION . '; ' . home_url(),
					'cookies'     => array(),
				)
			);
			if ( is_wp_error( $response ) ) {
				$errors[] = $url . ': ' . $response->get_error_message();
				continue;
			}
			++$scanned;
			$analysis = DSMKC_Blocker::analyze( (string) wp_remote_retrieve_body( $response ) );
			foreach ( $analysis['services'] as $id => $cat ) {
				$found[ $id ] = 'servidor';
			}
			foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
				$cookies[] = $cookie->name;
			}
		}

		return self::merge_results(
			array(
				'services' => $found,
				'cookies'  => array_unique( $cookies ),
				'storage'  => array(),
				'domains'  => array(),
				'via'      => 'servidor',
				'scanned'  => $scanned,
				'errors'   => $errors,
			),
			$notify
		);
	}

	/**
	 * Recibe y procesa el resultado del escaneo hecho en el navegador.
	 *
	 * @since  1.0.0
	 * @param  array $payload { cookies:string[], storage:string[], resources:string[], pages:int }.
	 * @return array Resumen.
	 */
	public static function browser_results( array $payload ) {
		$clean = static function ( $list, $max, $len ) {
			$out = array();
			foreach ( array_slice( (array) $list, 0, $max ) as $item ) {
				$item = substr( sanitize_text_field( (string) $item ), 0, $len );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return array_values( array_unique( $out ) );
		};

		$cookies   = $clean( isset( $payload['cookies'] ) ? $payload['cookies'] : array(), 300, 120 );
		$storage   = $clean( isset( $payload['storage'] ) ? $payload['storage'] : array(), 300, 120 );
		$resources = $clean( isset( $payload['resources'] ) ? $payload['resources'] : array(), 2000, 500 );

		$services = array();
		$domains  = array();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $resources as $url ) {
			$match = DSMKC_Services::match( $url, 'src', true );
			if ( ! $match ) {
				$match = DSMKC_Services::match( $url, 'iframe', true );
			}
			if ( ! $match ) {
				$match = DSMKC_Services::match( $url, 'img', true );
			}
			if ( $match ) {
				$services[ $match['s'] ] = 'navegador';
				continue;
			}
			$res_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $res_host && $res_host !== $host && ! self::is_harmless_host( $res_host ) ) {
				$domains[] = $res_host;
			}
		}

		return self::merge_results(
			array(
				'services' => $services,
				'cookies'  => $cookies,
				'storage'  => $storage,
				'domains'  => array_unique( $domains ),
				'via'      => 'navegador',
				'scanned'  => isset( $payload['pages'] ) ? absint( $payload['pages'] ) : 0,
				'errors'   => array(),
			),
			false
		);
	}

	/**
	 * Dominios de terceros que no instalan cookies (CDN de recursos estáticos).
	 *
	 * Solo informativo: estos dominios no se listan como «desconocidos».
	 *
	 * @since  1.0.0
	 * @param  string $host Dominio.
	 * @return bool
	 */
	private static function is_harmless_host( $host ) {
		foreach ( array( 'fonts.gstatic.com', 'fonts.googleapis.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 's.w.org', 'ajax.googleapis.com', 'code.jquery.com', 'secure.gravatar.com', 'gravatar.com', 'i0.wp.com', 'i1.wp.com', 'i2.wp.com', 'c0.wp.com' ) as $safe ) {
			if ( $host === $safe || substr( $host, -strlen( '.' . $safe ) ) === '.' . $safe ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fusiona un resultado con el estado guardado y aplica consecuencias.
	 *
	 * Público desde 2.1.0 (lo usa DSMKC_Audit).
	 *
	 * @since  1.0.0
	 * @param  array $result Resultado del escaneo.
	 * @param  bool  $notify Enviar email si hay novedades.
	 * @return array Resumen { new_services, new_unknown, total_services, scanned, errors, bumped }.
	 */
	public static function merge_results( array $result, $notify ) {
		$data     = DSMKC_Settings::data();
		$now      = time();
		$detected = (array) $data['detected'];
		$unknown  = (array) $data['unknown'];
		$ignored  = (array) $data['ignored'];
		$new_svc  = array();
		$new_unk  = array();

		foreach ( $result['services'] as $id => $via ) {
			if ( ! isset( $detected[ $id ] ) ) {
				$detected[ $id ] = array( 'first' => $now, 'via' => $via );
				$new_svc[]       = $id;
			}
			$detected[ $id ]['last'] = $now;
		}

		// Cookies: si pertenecen a un servicio conocido, se marca el servicio como detectado.
		$items = array();
		foreach ( $result['cookies'] as $name ) {
			$items[] = array( $name, 'cookie' );
		}
		foreach ( $result['storage'] as $name ) {
			$items[] = array( $name, 'storage' );
		}
		foreach ( $result['domains'] as $name ) {
			$items[] = array( $name, 'domain' );
		}
		foreach ( $items as $item ) {
			list( $name, $type ) = $item;
			if ( in_array( $name, $ignored, true ) ) {
				continue;
			}
			if ( 'cookie' === $type ) {
				$svc = DSMKC_Services::service_for_cookie( $name );
				if ( $svc ) {
					if ( ! isset( $detected[ $svc ] ) ) {
						$detected[ $svc ] = array( 'first' => $now, 'via' => $result['via'] );
						$new_svc[]        = $svc;
					}
					$detected[ $svc ]['last'] = $now;
					continue;
				}
			}
			if ( DSMKC_Services::is_manual( $name ) ) {
				continue;
			}
			if ( 'storage' === $type && self::is_known_storage( $name ) ) {
				continue;
			}
			if ( ! isset( $unknown[ $name ] ) ) {
				$unknown[ $name ] = array( 'type' => $type, 'first' => $now, 'via' => $result['via'] );
				$new_unk[]        = $name;
			}
			$unknown[ $name ]['last'] = $now;
		}

		// Tope (2.2.0): dsmkc_data se lee en cada visita; la lista no puede crecer sin límite.
		if ( count( $unknown ) > self::MAX_UNKNOWN ) {
			uasort(
				$unknown,
				static function ( $a, $b ) {
					return (int) $b['last'] - (int) $a['last'];
				}
			);
			$unknown = array_slice( $unknown, 0, self::MAX_UNKNOWN, true );
		}

		DSMKC_Settings::update_data(
			array(
				'detected'      => $detected,
				'unknown'       => $unknown,
				'last_scan'     => $now,
				'last_scan_log' => array(
					'date'    => $now,
					'via'     => $result['via'],
					'scanned' => $result['scanned'],
					'errors'  => array_slice( $result['errors'], 0, 10 ),
					'new'     => $new_svc,
					'unknown' => $new_unk,
				),
			)
		);
		DSMKC_Services::reset();

		// Si hay servicios opcionales nuevos se vuelve a pedir el consentimiento.
		$bumped = DSMKC_Plugin::sync_services();
		DSMKC_Settings::update_data( array( 'services_hash' => DSMKC_Services::hash() ) );
		DSMKC_Legal::record_banner_version();
		DSMKC_Plugin::purge_caches();

		if ( $notify && ( $new_svc || $new_unk ) ) {
			self::notify( $new_svc, $new_unk );
		}

		return array(
			'new_services'   => $new_svc,
			'new_unknown'    => $new_unk,
			'total_services' => count( $detected ),
			'scanned'        => $result['scanned'],
			'errors'         => $result['errors'],
			'bumped'         => $bumped,
		);
	}

	/**
	 * Claves de localStorage conocidas que no requieren documentarse aparte.
	 *
	 * @since  1.0.0
	 * @param  string $key Clave.
	 * @return bool
	 */
	private static function is_known_storage( $key ) {
		foreach ( array( 'elementor', 'wp-', 'WP_DATA_', 'wc_', 'woocommerce', 'dsmkc', 'tk_', 'jetpack', 'ai_', 'ytidb', 'yt-', '_hj', '_gcl', '_cs_', 'clarity', 'debug' ) as $prefix ) {
			if ( 0 === stripos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Aviso por email de novedades del escaneo semanal.
	 *
	 * @since  1.0.0
	 * @param  string[] $services Servicios nuevos.
	 * @param  string[] $unknown  Elementos sin clasificar.
	 * @return void
	 */
	private static function notify( array $services, array $unknown ) {
		$to = (string) DSMKC_Settings::get( 'email_avisos', '' );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );
		$catalogue = DSMKC_Services::catalogue();
		$lines     = array();
		foreach ( $services as $id ) {
			$lines[] = '- ' . ( isset( $catalogue[ $id ] ) ? $catalogue[ $id ]['name'] : $id );
		}
		/* translators: %s: dominio. */
		$subject = sprintf( __( '[DSMK Legal] Cambios en las cookies de %s', 'dsmk-legal' ), DSMKC_Legal::domain() );
		$body    = __( 'El escaneo semanal ha detectado cambios en la web.', 'dsmk-legal' ) . "\n\n";
		if ( $lines ) {
			$body .= __( 'Servicios nuevos (ya documentados y bloqueados hasta el consentimiento):', 'dsmk-legal' ) . "\n" . implode( "\n", $lines ) . "\n\n";
		}
		if ( $unknown ) {
			$body .= __( 'Cookies o recursos sin clasificar (revísalos en el panel):', 'dsmk-legal' ) . "\n- " . implode( "\n- ", $unknown ) . "\n\n";
		}
		$body .= admin_url( 'admin.php?page=dsmk-legal&tab=cookies_servicios' );
		wp_mail( $to, $subject, $body );
	}
}
